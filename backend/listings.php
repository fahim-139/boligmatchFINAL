<?php
// ============================================================
//  listings.php — Browse & Search All Listings
//  Pulls real rooms from the database with filters + pagination
// ============================================================

session_start();
require_once 'db.php';

$isLoggedIn = isset($_SESSION['user_id']);
$userRole   = $_SESSION['user_role'] ?? null;
$userName   = $_SESSION['user_name'] ?? null;
$userId     = (int)($_SESSION['user_id'] ?? 0);

// ── Sanitise all filter inputs ───────────────────────────────
$filterCity    = in_array($_GET['city'] ?? '', ['','Copenhagen','Aarhus','Aalborg','Odense'])
                    ? ($_GET['city'] ?? '') : '';
$filterDeposit = in_array($_GET['deposit'] ?? '', ['','1','2','3'])
                    ? ($_GET['deposit'] ?? '') : '';
$filterRent    = (int)($_GET['max_rent'] ?? 0);
$filterType    = in_array($_GET['type'] ?? '', ['','single','double'])
                    ? ($_GET['type'] ?? '') : '';
$filterQ       = htmlspecialchars(trim($_GET['q'] ?? ''));
$filterSort    = in_array($_GET['sort'] ?? '', ['newest','rent_asc','rent_desc','deposit_asc'])
                    ? ($_GET['sort'] ?? 'newest') : 'newest';

// Amenity checkboxes
$filterFurnished = isset($_GET['furnished']);
$filterWifi      = isset($_GET['wifi']);
$filterBills     = isset($_GET['bills_included']);

// Pagination
$perPage = 9;
$page    = max(1, (int)($_GET['page'] ?? 1));

// ── Build SQL query ──────────────────────────────────────────
$conditions = ["l.status = 'active'"];
$params     = [];

if ($filterCity)    { $conditions[] = 'l.city = ?';            $params[] = $filterCity; }
if ($filterDeposit) { $conditions[] = 'l.deposit_months <= ?'; $params[] = $filterDeposit; }
if ($filterRent > 0){ $conditions[] = 'l.rent_monthly <= ?';   $params[] = $filterRent; }
if ($filterType)    { $conditions[] = 'l.room_type = ?';       $params[] = $filterType; }
if ($filterFurnished) $conditions[] = 'l.furnished = 1';
if ($filterWifi)      $conditions[] = 'l.wifi = 1';
if ($filterBills)     $conditions[] = 'l.bills_included = 1';
if ($filterQ) {
    $conditions[] = '(l.title LIKE ? OR l.area LIKE ? OR l.description LIKE ?)';
    $like = '%' . $filterQ . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$where   = 'WHERE ' . implode(' AND ', $conditions);
$orderBy = match($filterSort) {
    'rent_asc'    => 'l.rent_monthly ASC',
    'rent_desc'   => 'l.rent_monthly DESC',
    'deposit_asc' => 'l.deposit_months ASC, l.rent_monthly ASC',
    default       => 'l.created_at DESC',
};

// Total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM listings l JOIN users u ON u.id = l.owner_id {$where}");
$countStmt->execute($params);
$totalResults = (int)$countStmt->fetchColumn();
$totalPages   = max(1, ceil($totalResults / $perPage));
$page         = min($page, $totalPages);
$offset       = ($page - 1) * $perPage;

// Fetch listings for this page
$listStmt = $pdo->prepare("
    SELECT  l.id, l.title, l.city, l.area,
            l.rent_monthly, l.deposit_months,
            l.room_type, l.room_size_m2,
            l.furnished, l.wifi, l.bills_included,
            l.washing_machine, l.balcony, l.pets_allowed,
            l.available_from, l.view_count, l.created_at,
            l.photos,
            u.email_verified AS owner_verified
    FROM    listings l
    JOIN    users    u ON u.id = l.owner_id
    {$where}
    ORDER   BY {$orderBy}
    LIMIT   {$perPage} OFFSET {$offset}
");
$listStmt->execute($params);
$listings = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// City counts for sidebar
$cityStmt = $pdo->query("SELECT city, COUNT(*) cnt FROM listings WHERE status='active' GROUP BY city ORDER BY cnt DESC");
$cityCounts = $cityStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Saved IDs for the current student (for heart icons)
$savedIds = [];
if ($isLoggedIn && $userRole === 'student') {
    $s = $pdo->prepare("SELECT listing_id FROM saved_listings WHERE student_id = ?");
    $s->execute([$userId]);
    $savedIds = array_column($s->fetchAll(), 'listing_id');
}

// CSRF token for the save button
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken(32);
}
$csrf = $_SESSION['csrf_token'];

// Gradient palettes
$palettes = [
    ['#afc8da','#c8d8e8'],['#d4a8c8','#e8c8e0'],
    ['#a8c8b8','#c8e0d4'],['#d4c8a8','#e8e0c8'],
    ['#a8b8d4','#c8d4e8'],['#c8a8a8','#e0c8c8'],
];


// Build a URL preserving current filters but swapping one parameter
function filterUrl(string $key, string $val): string {
    $p = $_GET;
    if ($val === '') unset($p[$key]); else $p[$key] = $val;
    $p['page'] = 1;
    return 'listings.php' . ($p ? '?' . http_build_query($p) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= $totalResults ?> Room<?= $totalResults!==1?'s':'' ?> Available<?= $filterCity?" in $filterCity":' in Denmark' ?> — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{
      --navy:#0d1b2a; --teal:#1a7f6e; --teal-light:#22a08a;
      --teal-pale:rgba(26,127,110,.08); --cream:#f7f3ee;
      --warm-white:#fdfaf6; --gold:#c9a84c;
      --text:#0d1b2a; --text-muted:#6b7280; --border:#e5e0d8;
      --red:#dc2626; --red-pale:rgba(220,38,38,.07); --green:#16a34a;
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    html{scroll-behavior:smooth;}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--text);}

    /* ── NAV ─────────────────────────────────────── */
    nav{
      background:rgba(253,250,246,.95); backdrop-filter:blur(12px);
      border-bottom:1px solid var(--border);
      padding:0 56px; height:64px;
      display:flex; align-items:center; justify-content:space-between;
      position:sticky; top:0; z-index:100;
    }
    .logo{font-family:'Playfair Display',serif;font-size:1.6rem;font-weight:900;color:var(--navy);text-decoration:none;letter-spacing:-.5px;}
    .logo span{color:var(--teal);}
    .nav-links{display:flex;align-items:center;gap:8px;list-style:none;}
    .nav-links a{font-size:.88rem;font-weight:500;color:var(--text-muted);text-decoration:none;padding:7px 13px;border-radius:7px;transition:all .2s;}
    .nav-links a:hover{color:var(--navy);background:var(--cream);}
    .nav-links a.active{color:var(--teal);}
    .nav-btn{padding:9px 20px;border-radius:7px;text-decoration:none;font-size:.88rem;font-weight:600;transition:background .2s;}
    .nav-btn.solid{background:var(--navy);color:white;}
    .nav-btn.solid:hover{background:var(--teal);}
    .nav-btn.outline{border:1.5px solid var(--border);color:var(--text-muted);}
    .nav-btn.outline:hover{border-color:var(--teal);color:var(--teal);}

    /* ── PAGE HEADER ─────────────────────────────── */
    .page-header{background:var(--navy);padding:40px 56px 36px;}
    .ph-inner{max-width:1280px;margin:0 auto;}
    .ph-crumb{font-size:.75rem;color:rgba(255,255,255,.4);margin-bottom:12px;display:flex;align-items:center;gap:7px;}
    .ph-crumb a{color:rgba(255,255,255,.5);text-decoration:none;transition:color .2s;}
    .ph-crumb a:hover{color:white;}
    .ph-h1{font-family:'Playfair Display',serif;font-size:1.8rem;font-weight:700;color:white;margin-bottom:6px;}
    .ph-count{font-size:.85rem;color:rgba(255,255,255,.45);}

    /* Search bar in header */
    .search-row{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;}
    .sr-input{flex:1;min-width:200px;padding:10px 16px;border-radius:8px;border:1.5px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:white;font-family:'DM Sans',sans-serif;font-size:.88rem;outline:none;transition:border-color .2s;}
    .sr-input::placeholder{color:rgba(255,255,255,.3);}
    .sr-input:focus{border-color:var(--teal);}
    .sr-select{padding:10px 14px;border-radius:8px;border:1.5px solid rgba(255,255,255,.15);background:rgba(255,255,255,.08);color:rgba(255,255,255,.85);font-family:'DM Sans',sans-serif;font-size:.85rem;outline:none;cursor:pointer;}
    .sr-select option{background:var(--navy);}
    .sr-btn{padding:10px 22px;background:var(--teal);color:white;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.88rem;font-weight:700;cursor:pointer;transition:background .2s;white-space:nowrap;}
    .sr-btn:hover{background:var(--teal-light);}
    .sr-clear{padding:10px 14px;background:transparent;color:rgba(255,255,255,.45);border:1.5px solid rgba(255,255,255,.15);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.85rem;cursor:pointer;text-decoration:none;transition:all .2s;}
    .sr-clear:hover{color:white;border-color:rgba(255,255,255,.4);}

    /* ── MAIN LAYOUT ─────────────────────────────── */
    .page-body{max-width:1280px;margin:0 auto;padding:28px 56px 64px;display:grid;grid-template-columns:248px 1fr;gap:24px;align-items:start;}

    /* ── SIDEBAR ─────────────────────────────────── */
    .sidebar{position:sticky;top:80px;}
    .filter-box{background:white;border:1.5px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:14px;}
    .fb-header{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;}
    .fb-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--navy);}
    .fb-clear{font-size:.72rem;color:var(--teal);text-decoration:none;font-weight:600;}
    .fb-clear:hover{text-decoration:underline;}
    .fb-body{padding:14px 16px;}

    /* City pills in sidebar */
    .city-list{display:flex;flex-direction:column;gap:4px;}
    .city-pill{display:flex;justify-content:space-between;align-items:center;padding:8px 10px;border-radius:7px;text-decoration:none;font-size:.82rem;color:var(--text-muted);transition:all .15s;}
    .city-pill:hover,.city-pill.active{background:var(--teal-pale);color:var(--teal);}
    .cp-count{font-size:.7rem;background:var(--cream);color:var(--text-muted);padding:1px 7px;border-radius:100px;}
    .city-pill.active .cp-count{background:rgba(26,127,110,.15);color:var(--teal);}

    /* Filter form elements */
    .filter-label{display:block;font-size:.71rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:7px;}
    .filter-select,.filter-input{width:100%;padding:9px 11px;border:1.5px solid var(--border);border-radius:7px;font-family:'DM Sans',sans-serif;font-size:.84rem;color:var(--text);background:var(--warm-white);outline:none;transition:border-color .2s;margin-bottom:12px;}
    .filter-select:focus,.filter-input:focus{border-color:var(--teal);}
    .filter-select:last-child,.filter-input:last-child{margin-bottom:0;}

    /* Rent range slider */
    .range-wrap{margin-bottom:12px;}
    .range-labels{display:flex;justify-content:space-between;font-size:.73rem;color:var(--text-muted);margin-bottom:5px;}
    .range-labels strong{color:var(--navy);}
    input[type="range"]{width:100%;accent-color:var(--teal);}

    /* Amenity checkboxes */
    .check-group{display:flex;flex-direction:column;gap:8px;margin-bottom:12px;}
    .check-item{display:flex;align-items:center;gap:9px;font-size:.83rem;color:var(--text);cursor:pointer;}
    .check-item input{accent-color:var(--teal);width:15px;height:15px;cursor:pointer;}

    .apply-btn{width:100%;padding:11px;background:var(--teal);color:white;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.88rem;font-weight:700;cursor:pointer;transition:background .2s;}
    .apply-btn:hover{background:var(--teal-light);}

    /* Safety reminder in sidebar */
    .safety-box{background:rgba(201,168,76,.09);border:1px solid rgba(201,168,76,.25);border-radius:10px;padding:14px 15px;font-size:.76rem;color:#7c5a00;line-height:1.55;}
    .safety-box strong{display:block;margin-bottom:4px;font-size:.78rem;}

    /* ── RESULTS AREA ────────────────────────────── */
    .results-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px;}
    .rb-count{font-size:.87rem;color:var(--text-muted);}
    .rb-count strong{color:var(--navy);}
    .rb-sort{padding:8px 12px;border:1.5px solid var(--border);border-radius:7px;font-family:'DM Sans',sans-serif;font-size:.83rem;color:var(--text);background:white;outline:none;cursor:pointer;}

    /* Active filter tags */
    .active-filters{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:16px;}
    .af-tag{display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:100px;background:var(--navy);color:rgba(255,255,255,.85);font-size:.74rem;font-weight:600;text-decoration:none;}
    .af-tag:hover{background:var(--teal);}
    .af-tag .x{font-size:.65rem;opacity:.6;}

    /* Safety banner */
    .safety-banner{background:rgba(201,168,76,.08);border:1px solid rgba(201,168,76,.2);border-radius:9px;padding:10px 16px;display:flex;align-items:center;gap:10px;margin-bottom:18px;font-size:.79rem;color:#7c5a00;line-height:1.5;}

    /* ── LISTINGS GRID ───────────────────────────── */
    .listings-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(272px,1fr));gap:18px;}

    /* ── LISTING CARD ────────────────────────────── */
    .listing-card{background:white;border-radius:13px;border:1.5px solid var(--border);overflow:hidden;display:flex;flex-direction:column;text-decoration:none;color:inherit;transition:transform .2s,box-shadow .2s,border-color .2s;}
    .listing-card:hover{transform:translateY(-3px);box-shadow:0 10px 32px rgba(13,27,42,.1);border-color:var(--teal);}
    .card-img{height:155px;display:flex;align-items:center;justify-content:center;position:relative;font-size:2.8rem;flex-shrink:0;overflow:hidden;}
    .card-img img{width:100%;height:100%;object-fit:cover;}
    .card-badges{position:absolute;top:8px;left:8px;display:flex;gap:5px;flex-wrap:wrap;}
    .badge{font-size:.6rem;font-weight:700;padding:3px 8px;border-radius:100px;}
    .badge-new{background:var(--navy);color:white;}
    .badge-verified{background:rgba(22,163,74,.9);color:white;}
    .badge-deposit{background:var(--teal);color:white;}
    .save-btn{position:absolute;top:8px;right:8px;background:rgba(255,255,255,.85);border:none;border-radius:50%;width:30px;height:30px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.88rem;transition:all .2s;line-height:1;}
    .save-btn:hover{background:white;transform:scale(1.1);}
    .card-body{padding:14px 16px;flex:1;display:flex;flex-direction:column;}
    .card-city{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--teal);margin-bottom:4px;}
    .card-title{font-size:.91rem;font-weight:700;color:var(--navy);line-height:1.35;margin-bottom:7px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
    .card-tags{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:10px;}
    .card-tag{font-size:.65rem;padding:2px 7px;border-radius:100px;background:var(--cream);color:var(--text-muted);border:1px solid var(--border);}
    .card-footer{display:flex;justify-content:space-between;align-items:center;padding-top:10px;border-top:1px solid var(--border);margin-top:auto;}
    .card-price{font-size:1rem;font-weight:700;color:var(--navy);}
    .card-price small{font-size:.67rem;font-weight:400;color:var(--text-muted);}
    .card-deposit{font-size:.68rem;background:var(--teal-pale);color:var(--teal);padding:3px 9px;border-radius:100px;font-weight:600;}

    /* ── EMPTY STATE ─────────────────────────────── */
    .empty-state{grid-column:1/-1;text-align:center;padding:64px 24px;background:white;border-radius:12px;border:2px dashed var(--border);}
    .es-icon{font-size:2.8rem;margin-bottom:16px;opacity:.3;}
    .es-title{font-family:'Playfair Display',serif;font-size:1.25rem;color:var(--navy);margin-bottom:7px;}
    .es-sub{font-size:.85rem;color:var(--text-muted);line-height:1.7;max-width:360px;margin:0 auto 20px;}
    .es-btn{display:inline-block;padding:10px 22px;background:var(--teal);color:white;border-radius:7px;text-decoration:none;font-weight:600;font-size:.85rem;}

    /* ── PAGINATION ──────────────────────────────── */
    .pagination{display:flex;justify-content:center;gap:6px;margin-top:28px;flex-wrap:wrap;}
    .page-btn{padding:8px 14px;border-radius:7px;border:1.5px solid var(--border);background:white;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:600;color:var(--text-muted);text-decoration:none;transition:all .2s;}
    .page-btn:hover{border-color:var(--teal);color:var(--teal);}
    .page-btn.active{background:var(--navy);border-color:var(--navy);color:white;}
    .page-btn.disabled{opacity:.35;pointer-events:none;}

    @keyframes fadeUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
    .listing-card{animation:fadeUp .35s ease both;}
    <?php for($i=0;$i<9;$i++): ?>.listing-card:nth-child(<?=$i+1?>){animation-delay:<?=$i*.05?>s;}<?php endfor; ?>
  </style>
</head>
<body>

<!-- NAV -->
<nav>
  <a href="../frontend/index.html" class="logo">Bolig<span>Match</span></a>
  <ul class="nav-links">
    <li><a href="../frontend/index.html">Home</a></li>
    <li><a href="listings.php" class="active">Browse Rooms</a></li>
    <li><a href="../frontend/how-it-works.html">How It Works</a></li>
    <li><a href="../frontend/safety-tips.html">Safety Tips</a></li>
  </ul>
  <div style="display:flex;gap:8px">
    <?php if ($isLoggedIn): ?>
      <a href="<?= $userRole==='owner'?'home-owner.php':'home-student.php' ?>" class="nav-btn outline">Dashboard</a>
      <a href="logout.php" class="nav-btn solid">Sign Out</a>
    <?php else: ?>
      <a href="../frontend/login.html"    class="nav-btn outline">Sign In</a>
      <a href="../frontend/register.html" class="nav-btn solid">Register Free</a>
    <?php endif; ?>
  </div>
</nav>

<!-- PAGE HEADER -->
<div class="page-header">
  <div class="ph-inner">
    <div class="ph-crumb">
      <a href="../frontend/index.html">Home</a> › Browse Rooms
    </div>
    <div class="ph-h1">
      Rooms<?= $filterCity ? ' in '.e($filterCity) : ' in Denmark' ?>
    </div>
    <div class="ph-count">
      <?= $totalResults ?> room<?= $totalResults!==1?'s':'' ?> found
      <?= $filterQ ? '— matching "'.e($filterQ).'"' : '' ?>
    </div>

    <!-- Search bar -->
    <form method="GET" action="listings.php" class="search-row">
      <input type="text" name="q" class="sr-input"
             placeholder="🔍 Search by title, area, city…"
             value="<?= e($filterQ) ?>"/>
      <select name="city" class="sr-select" onchange="this.form.submit()">
        <option value="">All cities</option>
        <?php foreach (['Copenhagen','Aarhus','Aalborg','Odense'] as $c): ?>
          <option value="<?= $c ?>" <?= $filterCity===$c?'selected':'' ?>>
            <?= $c ?><?= isset($cityCounts[$c])?' ('.$cityCounts[$c].')':'' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="deposit" class="sr-select" onchange="this.form.submit()">
        <option value="">Any deposit</option>
        <option value="1" <?= $filterDeposit==='1'?'selected':'' ?>>Max 1 month</option>
        <option value="2" <?= $filterDeposit==='2'?'selected':'' ?>>Max 2 months</option>
        <option value="3" <?= $filterDeposit==='3'?'selected':'' ?>>Max 3 months</option>
      </select>
      <button type="submit" class="sr-btn">Search</button>
      <?php if ($filterQ || $filterCity || $filterDeposit || $filterRent || $filterType): ?>
        <a href="listings.php" class="sr-clear">Clear all</a>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- MAIN BODY -->
<div class="page-body">

  <!-- ── SIDEBAR ── -->
  <aside class="sidebar">
    <form method="GET" action="listings.php" id="filter-form">
      <?php if ($filterQ): ?><input type="hidden" name="q" value="<?= e($filterQ) ?>"/><?php endif; ?>

      <!-- City -->
      <div class="filter-box">
        <div class="fb-header"><div class="fb-title">📍 City</div></div>
        <div class="fb-body">
          <div class="city-list">
            <a href="<?= filterUrl('city','') ?>" class="city-pill <?= !$filterCity?'active':'' ?>">
              All cities
              <span class="cp-count"><?= array_sum($cityCounts) ?></span>
            </a>
            <?php foreach (['Copenhagen','Aarhus','Aalborg','Odense'] as $c): ?>
              <a href="<?= filterUrl('city',$c) ?>" class="city-pill <?= $filterCity===$c?'active':'' ?>">
                <?= $c ?>
                <span class="cp-count"><?= $cityCounts[$c] ?? 0 ?></span>
              </a>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Filters -->
      <div class="filter-box">
        <div class="fb-header">
          <div class="fb-title">🎛️ Filters</div>
          <a href="listings.php<?= $filterCity?'?city='.e($filterCity):'' ?>" class="fb-clear">Clear</a>
        </div>
        <div class="fb-body">
          <input type="hidden" name="city" value="<?= e($filterCity) ?>"/>

          <label class="filter-label">Max Monthly Rent</label>
          <div class="range-wrap">
            <div class="range-labels">
              <span>DKK 1,000</span>
              <strong id="rent-display">
                <?= $filterRent > 0 ? 'DKK '.number_format($filterRent) : 'Any' ?>
              </strong>
            </div>
            <input type="range" name="max_rent" id="rent-slider"
                   min="1000" max="12000" step="500"
                   value="<?= $filterRent > 0 ? $filterRent : 12000 ?>"
                   oninput="updateRentLabel(this.value)"/>
          </div>

          <label class="filter-label">Max Deposit</label>
          <select name="deposit" class="filter-select">
            <option value="">Any</option>
            <option value="1" <?= $filterDeposit==='1'?'selected':'' ?>>1 month max</option>
            <option value="2" <?= $filterDeposit==='2'?'selected':'' ?>>2 months max</option>
            <option value="3" <?= $filterDeposit==='3'?'selected':'' ?>>3 months max</option>
          </select>

          <label class="filter-label">Room Type</label>
          <select name="type" class="filter-select">
            <option value="">Any type</option>
            <option value="single" <?= $filterType==='single'?'selected':'' ?>>Single room</option>
            <option value="double" <?= $filterType==='double'?'selected':'' ?>>Double room</option>
          </select>

          <label class="filter-label">Must Include</label>
          <div class="check-group">
            <label class="check-item">
              <input type="checkbox" name="furnished" <?= $filterFurnished?'checked':'' ?>/>
              🪑 Furnished
            </label>
            <label class="check-item">
              <input type="checkbox" name="wifi" <?= $filterWifi?'checked':'' ?>/>
              📡 WiFi
            </label>
            <label class="check-item">
              <input type="checkbox" name="bills_included" <?= $filterBills?'checked':'' ?>/>
              💡 Bills included
            </label>
          </div>

          <label class="filter-label">Sort by</label>
          <select name="sort" class="filter-select">
            <option value="newest"      <?= $filterSort==='newest'?     'selected':'' ?>>Newest first</option>
            <option value="rent_asc"    <?= $filterSort==='rent_asc'?   'selected':'' ?>>Price: low → high</option>
            <option value="rent_desc"   <?= $filterSort==='rent_desc'?  'selected':'' ?>>Price: high → low</option>
            <option value="deposit_asc" <?= $filterSort==='deposit_asc'?'selected':'' ?>>Lowest deposit first</option>
          </select>

          <button type="submit" class="apply-btn">Apply Filters</button>
        </div>
      </div>
    </form>

    <!-- Safety reminder -->
    <div class="safety-box">
      <strong>🛡️ Stay safe</strong>
      Never pay a deposit before viewing in person. All listings are user-submitted.
      <a href="../frontend/safety-tips.html" style="color:#7c5a00;font-weight:600">Safety tips →</a>
    </div>
  </aside>

  <!-- ── RESULTS ── -->
  <div>
    <!-- Active filter tags -->
    <?php
      $activeTags = [];
      if ($filterCity)    $activeTags[] = ['City: '.e($filterCity),    filterUrl('city','')];
      if ($filterDeposit) $activeTags[] = ['Max '.$filterDeposit.' mo. deposit', filterUrl('deposit','')];
      if ($filterRent)    $activeTags[] = ['Max DKK '.number_format($filterRent), filterUrl('max_rent','')];
      if ($filterType)    $activeTags[] = [ucfirst(e($filterType)).' room', filterUrl('type','')];
      if ($filterFurnished)$activeTags[]=['Furnished', filterUrl('furnished','')];
      if ($filterWifi)    $activeTags[] = ['WiFi', filterUrl('wifi','')];
      if ($filterBills)   $activeTags[] = ['Bills incl.', filterUrl('bills_included','')];
    ?>
    <?php if ($activeTags): ?>
      <div class="active-filters">
        <?php foreach ($activeTags as [$label,$url]): ?>
          <a href="<?= $url ?>" class="af-tag"><?= $label ?> <span class="x">✕</span></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- Safety banner -->
    <div class="safety-banner">
      🛡️ <span>
        <strong>Safety reminder:</strong> Never pay before viewing in person.
        All listings are submitted by users and not verified by BoligMatch.
        <a href="../frontend/safety-tips.html" style="color:#7c5a00;font-weight:600">Read safety tips</a>
      </span>
    </div>

    <!-- Results bar -->
    <div class="results-bar">
      <div class="rb-count">
        Showing
        <strong><?= (($page-1)*$perPage)+1 ?>–<?= min($page*$perPage,$totalResults) ?></strong>
        of <strong><?= $totalResults ?></strong>
        room<?= $totalResults!==1?'s':'' ?>
        <?= $filterCity ? ' in <strong>'.e($filterCity).'</strong>' : '' ?>
      </div>
      <form method="GET" action="listings.php" id="sort-form">
        <?php foreach ($_GET as $k=>$v): if ($k!=='sort' && $k!=='page'): ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"/>
        <?php endif; endforeach; ?>
        <select name="sort" class="rb-sort" onchange="document.getElementById('sort-form').submit()">
          <option value="newest"      <?= $filterSort==='newest'?     'selected':'' ?>>Newest first</option>
          <option value="rent_asc"    <?= $filterSort==='rent_asc'?   'selected':'' ?>>Price: low → high</option>
          <option value="rent_desc"   <?= $filterSort==='rent_desc'?  'selected':'' ?>>Price: high → low</option>
          <option value="deposit_asc" <?= $filterSort==='deposit_asc'?'selected':'' ?>>Lowest deposit first</option>
        </select>
      </form>
    </div>

    <!-- Listings grid -->
    <div class="listings-grid">
      <?php if (empty($listings)): ?>
        <div class="empty-state">
          <div class="es-icon">🔍</div>
          <div class="es-title">
            <?= $totalResults===0 && !$filterCity && !$filterQ ? 'No rooms listed yet' : 'No rooms match your filters' ?>
          </div>
          <div class="es-sub">
            <?php if ($totalResults===0 && !$filterCity && !$filterQ): ?>
              No rooms have been posted yet. Check back soon — owners are signing up every day!
            <?php else: ?>
              Try removing some filters or searching in a different city.
            <?php endif; ?>
          </div>
          <?php if ($filterCity || $filterQ || $filterDeposit || $filterRent || $filterType): ?>
            <a href="listings.php" class="es-btn">Clear All Filters</a>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <?php foreach ($listings as $i => $l):
          $pal    = $palettes[$l['id'] % count($palettes)];
          $isNew  = (time()-strtotime($l['created_at'])) < 86400*3;
          $isSaved= in_array($l['id'], $savedIds);
          $tags   = [];
          if ($l['furnished'])      $tags[]='🪑 Furnished';
          if ($l['wifi'])           $tags[]='📡 WiFi';
          if ($l['bills_included']) $tags[]='💡 Bills';
          if ($l['washing_machine'])$tags[]='🫧 Washing';
        ?>
          <a href="listing-detail.php?id=<?= $l['id'] ?>" class="listing-card">
            <?php
              // Try to find a usable cover photo for this listing.
              // Photos are stored as 'uploads/listings/abc.jpg' in DB,
              // and live inside backend/uploads/listings/ on disk.
              $coverPhoto = null;
              $listingPhotos = json_decode($l['photos'] ?? '[]', true) ?: [];
              foreach ($listingPhotos as $photoPath) {
                  if (file_exists(__DIR__ . '/' . $photoPath)) {
                      $coverPhoto = $photoPath;
                      break;
                  }
              }
            ?>
            <div class="card-img"<?= $coverPhoto ? '' : ' style="background:linear-gradient(135deg,'.$pal[0].','.$pal[1].')"' ?>>
              <?php if ($coverPhoto): ?>
                <img src="<?= e($coverPhoto) ?>" alt="<?= e($l['title']) ?>" loading="lazy"/>
              <?php else: ?>
                <span style="opacity:.35">🏠</span>
              <?php endif; ?>
              <div class="card-badges">
                <?php if ($isNew): ?><span class="badge badge-new">New</span><?php endif; ?>
                <?php if ($l['owner_verified']): ?><span class="badge badge-verified">✓ Verified</span><?php endif; ?>
                <?php if ((int)$l['deposit_months']===1): ?><span class="badge badge-deposit">1 mo. deposit</span><?php endif; ?>
              </div>
              <?php if ($isLoggedIn && $userRole==='student'): ?>
                <button type="button" class="save-btn"
                  data-id="<?= $l['id'] ?>"
                  onclick="event.preventDefault(); toggleSave(<?= $l['id'] ?>, this)"
                  title="<?= $isSaved?'Remove from saved':'Save room' ?>">
                  <?= $isSaved ? '❤️' : '🤍' ?>
                </button>
              <?php endif; ?>
            </div>
            <div class="card-body">
              <div class="card-city">📍 <?= e($l['city']) ?> · <?= e($l['area']) ?></div>
              <div class="card-title"><?= e($l['title']) ?></div>
              <div class="card-tags">
                <span class="card-tag"><?= $l['room_type']==='double'?'Double':'Single' ?> room</span>
                <?php if ($l['room_size_m2']): ?><span class="card-tag"><?= $l['room_size_m2'] ?>m²</span><?php endif; ?>
                <?php foreach (array_slice($tags,0,2) as $t): ?><span class="card-tag"><?= $t ?></span><?php endforeach; ?>
              </div>
              <div class="card-footer">
                <div class="card-price">DKK <?= number_format($l['rent_monthly']) ?> <small>/mo</small></div>
                <div class="card-deposit"><?= $l['deposit_months'] ?> mo. deposit</div>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1):
      $buildUrl = fn($p) => 'listings.php?'.http_build_query(array_merge($_GET,['page'=>$p]));
    ?>
      <div class="pagination">
        <a href="<?= $buildUrl($page-1) ?>" class="page-btn <?= $page<=1?'disabled':'' ?>">← Prev</a>
        <?php for ($p=max(1,$page-2); $p<=min($totalPages,$page+2); $p++): ?>
          <a href="<?= $buildUrl($p) ?>" class="page-btn <?= $p===$page?'active':'' ?>"><?= $p ?></a>
        <?php endfor; ?>
        <a href="<?= $buildUrl($page+1) ?>" class="page-btn <?= $page>=$totalPages?'disabled':'' ?>">Next →</a>
      </div>
    <?php endif; ?>
  </div>

</div><!-- /page-body -->

<script>
  // Rent range label
  function updateRentLabel(val) {
    document.getElementById('rent-display').textContent =
      parseInt(val) >= 12000 ? 'Any' : 'DKK ' + parseInt(val).toLocaleString();
  }

  // Save / unsave a listing via AJAX
  async function toggleSave(id, btn) {
    try {
      const res  = await fetch('save-listing.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body: new URLSearchParams({listing_id:id, csrf_token:'<?= $csrf ?>'})
      });
      const data = await res.json();
      if (data.saved !== undefined) {
        btn.textContent = data.saved ? '❤️' : '🤍';
        btn.title       = data.saved ? 'Remove from saved' : 'Save room';
      }
    } catch(e) {}
  }
</script>
</body>
</html>
