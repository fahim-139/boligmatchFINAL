<?php
// ============================================================
//  home-student.php — BoligMatch Student Home Page
//  Shown immediately after a student logs in.
//
//  Shows:
//    - Welcome header with stats
//    - Search bar to find rooms
//    - Latest available rooms (filterable)
//    - Saved/bookmarked rooms
//    - Recent conversations with owners
// ============================================================

session_start();
require_once 'db.php';

// ── Auth: students only ──────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    redirect('../frontend/login.html');
}
if ($_SESSION['user_role'] !== 'student') {
    redirect('home-owner.php');
}

$userId   = (int)$_SESSION['user_id'];
$userName = $_SESSION['user_name'];

// ── Fetch student record ─────────────────────────────────────
$stuStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stuStmt->execute([$userId]);
$student = $stuStmt->fetch();

if (!$student || $student['role'] === 'banned') {
    session_destroy();
    redirect('../frontend/login.html?error=banned');
}

// ── CSRF ─────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken(32);
}
$csrf = $_SESSION['csrf_token'];

// ── Search / filter (passed from the quick search form) ──────
$filterCity    = in_array($_GET['city'] ?? '', ['','Copenhagen','Aarhus','Aalborg','Odense'])
                    ? ($_GET['city'] ?? '') : '';
$filterDeposit = in_array($_GET['deposit'] ?? '', ['','1','2','3'])
                    ? ($_GET['deposit'] ?? '') : '';

// ── Available rooms count & listings ─────────────────────────
$conditions = ["l.status = 'active'"];
$params     = [];
if ($filterCity) {
    $conditions[] = 'l.city = ?'; $params[] = $filterCity;
}
if ($filterDeposit) {
    $conditions[] = 'l.deposit_months <= ?'; $params[] = $filterDeposit;
}
$where = 'WHERE ' . implode(' AND ', $conditions);

$totalRoomsStmt = $pdo->prepare("SELECT COUNT(*) FROM listings l {$where}");
$totalRoomsStmt->execute($params);
$totalRooms = (int)$totalRoomsStmt->fetchColumn();

// Latest 6 listings for home grid
$roomsStmt = $pdo->prepare("
    SELECT l.*, u.email_verified AS owner_verified,
           u.first_name AS owner_first, u.last_name AS owner_last
    FROM   listings l
    JOIN   users    u ON u.id = l.owner_id
    {$where}
    ORDER  BY l.created_at DESC
    LIMIT  6
");
$roomsStmt->execute($params);
$rooms = $roomsStmt->fetchAll();

// ── Saved listings ───────────────────────────────────────────
$savedStmt = $pdo->prepare("
    SELECT l.id, l.title, l.city, l.area, l.rent_monthly,
           l.deposit_months, l.room_type, l.status, l.photos,
           s.saved_at
    FROM   saved_listings s
    JOIN   listings       l ON l.id = s.listing_id
    WHERE  s.student_id = ?
    ORDER  BY s.saved_at DESC
    LIMIT  4
");
$savedStmt->execute([$userId]);
$savedRooms = $savedStmt->fetchAll();

// ── Saved IDs (for heart icons on the grid) ──────────────────
$savedIdStmt = $pdo->prepare("SELECT listing_id FROM saved_listings WHERE student_id = ?");
$savedIdStmt->execute([$userId]);
$savedIds = array_column($savedIdStmt->fetchAll(), 'listing_id');

// Helper: find first valid cover photo (or null) for a listing row
function findCover(array $listing): ?string {
    $photos = json_decode($listing['photos'] ?? '[]', true) ?: [];
    foreach ($photos as $p) {
        if (file_exists(__DIR__ . '/' . $p)) return $p;
    }
    return null;
}

// ── Recent conversations ──────────────────────────────────────
$unreadCount = getUnreadCount($pdo, $userId);

$convoStmt = $pdo->prepare("
    SELECT m.listing_id,
           m.body, m.created_at, m.is_read,
           l.title AS listing_title,
           u.first_name, u.last_name, u.id AS owner_id
    FROM   messages m
    JOIN   listings l ON l.id = m.listing_id
    JOIN   users    u ON u.id = CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END
    WHERE  m.sender_id = ? OR m.receiver_id = ?
    GROUP  BY m.listing_id,
              CASE WHEN m.sender_id = ? THEN m.receiver_id ELSE m.sender_id END
    ORDER  BY MAX(m.created_at) DESC
    LIMIT  4
");
$convoStmt->execute([$userId, $userId, $userId, $userId]);
$conversations = $convoStmt->fetchAll();

// ── City counts for quick filter ─────────────────────────────
$cityStmt  = $pdo->query("SELECT city, COUNT(*) cnt FROM listings WHERE status='active' GROUP BY city ORDER BY cnt DESC");
$cityCounts = $cityStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Flash ─────────────────────────────────────────────────────
$flash     = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

$palettes = [
    ['#afc8da','#c8d8e8'],['#d4a8c8','#e8c8e0'],
    ['#a8c8b8','#c8e0d4'],['#d4c8a8','#e8e0c8'],
    ['#a8b8d4','#c8d4e8'],['#c8a8a8','#e0c8c8'],
];

function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return floor($diff/60).'m ago';
    if ($diff < 86400)  return floor($diff/3600).'h ago';
    if ($diff < 604800) return floor($diff/86400).'d ago';
    return date('d M', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Find a Room — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{
      --navy:#0d1b2a;--teal:#1a7f6e;--teal-light:#22a08a;
      --teal-pale:rgba(26,127,110,.08);--cream:#f7f3ee;--warm-white:#fdfaf6;
      --gold:#c9a84c;--gold-pale:rgba(201,168,76,.1);
      --text:#0d1b2a;--text-muted:#6b7280;--border:#e5e0d8;
      --red:#dc2626;--red-pale:rgba(220,38,38,.07);--green:#16a34a;
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    html{scroll-behavior:smooth;}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--text);min-height:100vh;}

    /* NAV */
    nav{background:var(--navy);height:62px;display:flex;align-items:center;justify-content:space-between;padding:0 40px;position:sticky;top:0;z-index:100;}
    .logo{font-family:'Playfair Display',serif;font-size:1.45rem;font-weight:900;color:white;text-decoration:none;}
    .logo span{color:var(--teal-light);}
    .nav-right{display:flex;align-items:center;gap:10px;}
    .nav-greet{font-size:.82rem;color:rgba(255,255,255,.55);}
    .nav-btn{padding:7px 16px;border-radius:7px;text-decoration:none;font-size:.84rem;font-weight:600;transition:all .2s;}
    .nav-btn.solid{background:var(--teal);color:white;}
    .nav-btn.solid:hover{background:var(--teal-light);}
    .nav-btn.ghost{background:rgba(255,255,255,.08);color:rgba(255,255,255,.8);border:1px solid rgba(255,255,255,.15);}
    .nav-btn.ghost:hover{background:rgba(255,255,255,.14);}
    .nav-badge{position:relative;}
    .badge-dot{position:absolute;top:-3px;right:-3px;width:8px;height:8px;border-radius:50%;background:var(--red);border:1.5px solid var(--navy);}

    /* HERO */
    .hero{background:var(--navy);padding:44px 40px 54px;position:relative;overflow:hidden;}
    .hero::before{content:'';position:absolute;right:0;top:0;width:40%;height:100%;background:radial-gradient(ellipse at right center,rgba(26,127,110,.15) 0%,transparent 70%);pointer-events:none;}
    .hero-inner{max-width:1160px;margin:0 auto;position:relative;z-index:1;}
    .hero-eyebrow{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:2.5px;color:var(--teal-light);margin-bottom:10px;}
    .hero-h1{font-family:'Playfair Display',serif;font-size:2.2rem;font-weight:700;color:white;line-height:1.2;margin-bottom:8px;}
    .hero-h1 span{color:var(--teal-light);}
    .hero-sub{font-size:.9rem;color:rgba(255,255,255,.5);margin-bottom:28px;line-height:1.6;max-width:520px;}

    /* Search bar */
    .search-bar{display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:6px 8px 6px 20px;max-width:620px;}
    .sb-select{flex:1;background:transparent;border:none;color:rgba(255,255,255,.85);font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none;cursor:pointer;padding:4px 0;}
    .sb-select option{background:var(--navy);color:white;}
    .sb-div{width:1px;height:24px;background:rgba(255,255,255,.15);flex-shrink:0;}
    .sb-btn{padding:10px 22px;background:var(--teal);color:white;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.88rem;font-weight:700;cursor:pointer;transition:background .2s;white-space:nowrap;}
    .sb-btn:hover{background:var(--teal-light);}

    /* Stats row */
    .stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:28px;}
    .stat-card{background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:10px;padding:14px 18px;transition:border-color .2s;}
    .stat-card:hover{border-color:rgba(26,127,110,.35);}
    .sc-val{font-family:'Playfair Display',serif;font-size:1.7rem;font-weight:700;color:white;line-height:1;}
    .sc-label{font-size:.72rem;color:rgba(255,255,255,.4);margin-top:4px;}
    .sc-zero{color:rgba(255,255,255,.2);}

    /* VERIFY BANNER */
    .verify-banner{background:rgba(201,168,76,.12);border-bottom:1px solid rgba(201,168,76,.2);padding:11px 40px;display:flex;align-items:center;gap:10px;font-size:.82rem;color:#7c5a00;}
    .verify-banner a{color:#7c5a00;font-weight:700;}

    /* FLASH */
    .flash{padding:11px 40px;font-size:.83rem;display:flex;align-items:center;gap:10px;}
    .flash-success{background:rgba(22,163,74,.08);border-bottom:1px solid rgba(22,163,74,.15);color:var(--green);}
    .flash-error  {background:var(--red-pale);border-bottom:1px solid rgba(220,38,38,.15);color:var(--red);}

    /* MAIN */
    .main{max-width:1160px;margin:0 auto;padding:32px 40px 60px;display:grid;grid-template-columns:1fr 320px;gap:28px;align-items:start;}

    /* SECTION HEADERS */
    .section{margin-bottom:28px;}
    .section-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;}
    .section-title{font-family:'Playfair Display',serif;font-size:1.15rem;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:8px;}
    .section-title span{font-family:'DM Sans',sans-serif;font-size:.68rem;font-weight:600;background:var(--cream);border:1px solid var(--border);color:var(--text-muted);padding:2px 8px;border-radius:100px;}
    .section-link{font-size:.82rem;color:var(--teal);text-decoration:none;font-weight:600;white-space:nowrap;}
    .section-link:hover{text-decoration:underline;}

    /* CITY FILTER PILLS */
    .city-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;}
    .cf-pill{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:100px;border:1.5px solid var(--border);background:white;font-size:.77rem;font-weight:600;color:var(--text-muted);text-decoration:none;transition:all .18s;}
    .cf-pill:hover,.cf-pill.active{background:var(--navy);border-color:var(--navy);color:white;}
    .cf-pill-count{font-size:.65rem;background:var(--cream);color:var(--text-muted);padding:1px 5px;border-radius:100px;transition:all .18s;}
    .cf-pill.active .cf-pill-count,.cf-pill:hover .cf-pill-count{background:rgba(255,255,255,.15);color:white;}

    /* ROOMS GRID */
    .rooms-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px;}

    /* ROOM CARD */
    .room-card{background:white;border-radius:12px;border:1.5px solid var(--border);overflow:hidden;text-decoration:none;color:inherit;transition:transform .2s,box-shadow .2s,border-color .2s;display:flex;flex-direction:column;}
    .room-card:hover{transform:translateY(-3px);box-shadow:0 8px 28px rgba(13,27,42,.1);border-color:var(--teal);}
    .rc-img{height:130px;display:flex;align-items:center;justify-content:center;font-size:2rem;position:relative;flex-shrink:0;overflow:hidden;}
    .rc-img img{width:100%;height:100%;object-fit:cover;}
    .rc-badges{position:absolute;top:8px;left:8px;display:flex;gap:5px;}
    .rc-badge{font-size:.6rem;font-weight:700;padding:2px 8px;border-radius:100px;}
    .rc-badge-new     {background:var(--navy);color:white;}
    .rc-badge-verified{background:rgba(22,163,74,.9);color:white;}
    .rc-badge-deposit {background:var(--teal);color:white;}
    .fav-btn{position:absolute;top:8px;right:8px;background:rgba(255,255,255,.85);border:none;border-radius:50%;width:28px;height:28px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.8rem;transition:all .2s;}
    .fav-btn:hover{background:white;transform:scale(1.1);}
    .rc-body{padding:13px 15px;flex:1;display:flex;flex-direction:column;}
    .rc-city{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--teal);margin-bottom:4px;}
    .rc-title{font-size:.88rem;font-weight:700;color:var(--navy);line-height:1.35;margin-bottom:7px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
    .rc-tags{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:10px;}
    .rc-tag{font-size:.63rem;padding:2px 7px;border-radius:100px;background:var(--cream);color:var(--text-muted);border:1px solid var(--border);}
    .rc-footer{display:flex;justify-content:space-between;align-items:center;padding-top:9px;border-top:1px solid var(--border);margin-top:auto;}
    .rc-price{font-size:.98rem;font-weight:700;color:var(--navy);}
    .rc-price small{font-size:.67rem;font-weight:400;color:var(--text-muted);}
    .rc-deposit{font-size:.68rem;background:var(--teal-pale);color:var(--teal);padding:3px 8px;border-radius:100px;font-weight:600;}

    /* Empty rooms state */
    .empty-rooms{grid-column:1/-1;text-align:center;padding:52px 24px;background:white;border-radius:12px;border:2px dashed var(--border);}
    .er-icon{font-size:2.5rem;margin-bottom:14px;opacity:.3;}
    .er-title{font-size:1rem;font-weight:700;color:var(--navy);margin-bottom:6px;}
    .er-sub{font-size:.82rem;color:var(--text-muted);line-height:1.6;margin-bottom:20px;max-width:320px;margin-left:auto;margin-right:auto;}

    /* SAVED ROOMS */
    .saved-row{display:flex;align-items:center;gap:12px;padding:11px 14px;border-radius:9px;border:1.5px solid var(--border);background:white;text-decoration:none;color:inherit;transition:all .15s;margin-bottom:8px;}
    .saved-row:hover{border-color:var(--teal);background:var(--teal-pale);}
    .sr-img{width:44px;height:44px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.2rem;overflow:hidden;}
    .sr-img img{width:100%;height:100%;object-fit:cover;}
    .sr-info{flex:1;min-width:0;}
    .sr-title{font-size:.84rem;font-weight:600;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:2px;}
    .sr-meta{font-size:.72rem;color:var(--text-muted);}
    .sr-price{font-size:.82rem;font-weight:700;color:var(--teal);flex-shrink:0;}
    .sr-status-expired{font-size:.65rem;background:var(--red-pale);color:var(--red);padding:2px 7px;border-radius:100px;font-weight:600;}

    /* CONVERSATIONS */
    .convo-row{display:flex;align-items:flex-start;gap:11px;padding:12px 14px;border-radius:9px;border:1.5px solid var(--border);background:white;text-decoration:none;color:inherit;transition:all .15s;margin-bottom:7px;}
    .convo-row:hover{border-color:var(--teal);background:var(--teal-pale);}
    .convo-row.unread{border-left:3px solid var(--teal);}
    .cr-avatar{width:36px;height:36px;border-radius:8px;flex-shrink:0;background:linear-gradient(135deg,var(--gold),#8b5e1a);color:white;font-family:'Playfair Display',serif;font-weight:700;font-size:.9rem;display:flex;align-items:center;justify-content:center;}
    .cr-body{flex:1;min-width:0;}
    .cr-top{display:flex;justify-content:space-between;margin-bottom:2px;}
    .cr-name{font-size:.82rem;font-weight:600;color:var(--navy);}
    .cr-time{font-size:.67rem;color:var(--text-muted);}
    .cr-listing{font-size:.71rem;color:var(--teal);margin-bottom:2px;}
    .cr-preview{font-size:.76rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .cr-dot{width:7px;height:7px;border-radius:50%;background:var(--teal);flex-shrink:0;margin-top:5px;}

    /* RIGHT SIDEBAR */
    .right-sidebar{position:sticky;top:78px;display:flex;flex-direction:column;gap:16px;}

    /* Profile card */
    .profile-card{background:white;border:1.5px solid var(--border);border-radius:12px;padding:18px 20px;}
    .pc-top{display:flex;align-items:center;gap:12px;margin-bottom:14px;}
    .pc-avatar{width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,#1a7f6e,#0a2a40);color:white;font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .pc-name{font-size:.9rem;font-weight:700;color:var(--navy);}
    .pc-role{font-size:.72rem;color:var(--text-muted);margin-top:2px;}
    .pc-links{display:flex;flex-direction:column;gap:6px;}
    .pc-link{display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:7px;border:1px solid var(--border);text-decoration:none;font-size:.8rem;color:var(--text-muted);transition:all .15s;}
    .pc-link:hover{border-color:var(--teal);color:var(--teal);background:var(--teal-pale);}
    .pc-link-badge{background:var(--red);color:white;font-size:.6rem;font-weight:700;padding:1px 6px;border-radius:100px;}

    /* Safety card */
    .safety-card{background:var(--gold-pale);border:1.5px solid rgba(201,168,76,.25);border-radius:12px;padding:18px 20px;}
    .safety-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:#7c5a00;margin-bottom:12px;}
    .safety-item{display:flex;align-items:flex-start;gap:8px;font-size:.78rem;color:#7c5a00;line-height:1.45;margin-bottom:8px;}
    .safety-item:last-child{margin-bottom:0;}

    @keyframes fadeUp{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}
    .room-card{animation:fadeUp .4s ease both;}
    .room-card:nth-child(1){animation-delay:.04s;}
    .room-card:nth-child(2){animation-delay:.08s;}
    .room-card:nth-child(3){animation-delay:.12s;}
    .room-card:nth-child(4){animation-delay:.16s;}
    .room-card:nth-child(5){animation-delay:.20s;}
    .room-card:nth-child(6){animation-delay:.24s;}
  </style>
</head>
<body>

<!-- NAV -->
<nav>
  <a href="home-student.php" class="logo">Bolig<span>Match</span></a>
  <div class="nav-right">
    <span class="nav-greet">👋 <?= e($userName) ?></span>
    <a href="listings.php" class="nav-btn ghost">Browse All Rooms</a>
    <a href="inbox.php"    class="nav-btn ghost nav-badge">
      💬 Messages
      <?php if ($unreadCount > 0): ?><span class="badge-dot"></span><?php endif; ?>
    </a>
    <a href="logout.php?token=<?= $csrf ?>" class="nav-btn ghost">Sign Out</a>
  </div>
</nav>

<?php if (!$student['email_verified']): ?>
<div class="verify-banner">
  ⚠️ <span>Email not verified — <strong>verify to unlock messaging.</strong>
  <a href="verify-otp.php?resend=1">Resend verification email →</a></span>
</div>
<?php endif; ?>

<?php if ($flash): ?>
<div class="flash flash-<?= $flashType === 'success' ? 'success' : 'error' ?>">
  <?= e($flash) ?>
</div>
<?php endif; ?>

<!-- HERO -->
<div class="hero">
  <div class="hero-inner">
    <div class="hero-eyebrow">Student Housing · Denmark</div>
    <h1 class="hero-h1">Find your room,<br/><span><?= e($userName) ?>.</span></h1>
    <p class="hero-sub">
      <?php if ($totalRooms > 0): ?>
        There <?= $totalRooms === 1 ? 'is' : 'are' ?> currently <strong style="color:white"><?= $totalRooms ?> room<?= $totalRooms!==1?'s':'' ?></strong> available<?= $filterCity ? ' in '.e($filterCity) : '' ?>. Search by city and deposit to find what you can afford.
      <?php else: ?>
        No rooms available right now<?= $filterCity ? ' in '.e($filterCity) : '' ?>. Check back soon — owners are signing up every day.
      <?php endif; ?>
    </p>

    <!-- Quick search -->
    <form action="home-student.php" method="GET">
      <div class="search-bar">
        <select name="city" class="sb-select" onchange="this.form.submit()">
          <option value="">📍 All cities</option>
          <?php foreach (['Copenhagen','Aarhus','Aalborg','Odense'] as $c): ?>
            <option value="<?= $c ?>" <?= $filterCity===$c?'selected':'' ?>><?= $c ?> <?= isset($cityCounts[$c])? '('.$cityCounts[$c].')':'' ?></option>
          <?php endforeach; ?>
        </select>
        <div class="sb-div"></div>
        <select name="deposit" class="sb-select" onchange="this.form.submit()">
          <option value="">💰 Any deposit</option>
          <option value="1" <?= $filterDeposit==='1'?'selected':'' ?>>Max 1 month deposit</option>
          <option value="2" <?= $filterDeposit==='2'?'selected':'' ?>>Max 2 months deposit</option>
        </select>
        <button type="submit" class="sb-btn">Search</button>
      </div>
    </form>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="sc-val <?= $totalRooms===0?'sc-zero':'' ?>"><?= $totalRooms ?: '0' ?></div>
        <div class="sc-label">Rooms available<?= $filterCity?' in '.$filterCity:' now' ?></div>
      </div>
      <div class="stat-card">
        <div class="sc-val <?= count($savedRooms)===0?'sc-zero':'' ?>"><?= count($savedRooms) ?: '0' ?></div>
        <div class="sc-label">Rooms you've saved</div>
      </div>
      <div class="stat-card">
        <div class="sc-val <?= $unreadCount===0?'sc-zero':'' ?>"><?= $unreadCount ?: '0' ?></div>
        <div class="sc-label">Unread messages</div>
      </div>
      <div class="stat-card">
        <div class="sc-val <?= count($conversations)===0?'sc-zero':'' ?>"><?= count($conversations) ?: '0' ?></div>
        <div class="sc-label">Active conversations</div>
      </div>
    </div>
  </div>
</div>

<!-- MAIN -->
<div class="main">

  <!-- LEFT COLUMN -->
  <div>

    <!-- City filter pills -->
    <div class="city-filters">
      <a href="home-student.php" class="cf-pill <?= !$filterCity?'active':'' ?>">
        All Cities <span class="cf-pill-count"><?= array_sum($cityCounts) ?: 0 ?></span>
      </a>
      <?php foreach (['Copenhagen','Aarhus','Aalborg','Odense'] as $c): ?>
        <a href="home-student.php?city=<?= $c ?><?= $filterDeposit?'&deposit='.$filterDeposit:'' ?>" class="cf-pill <?= $filterCity===$c?'active':'' ?>">
          <?= $c ?> <span class="cf-pill-count"><?= $cityCounts[$c] ?? 0 ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <!-- Available Rooms -->
    <div class="section">
      <div class="section-header">
        <div class="section-title">
          🏠 Available Rooms
          <?php if ($totalRooms > 0): ?><span><?= $totalRooms ?></span><?php endif; ?>
        </div>
        <a href="listings.php<?= $filterCity?'?city='.urlencode($filterCity):'' ?>" class="section-link">
          Browse all <?= $totalRooms ?> rooms →
        </a>
      </div>

      <div class="rooms-grid">
        <?php if (empty($rooms)): ?>
          <div class="empty-rooms">
            <div class="er-icon">🔍</div>
            <div class="er-title">
              <?php if (!$filterCity && !$filterDeposit): ?>No rooms yet<?php else: ?>No rooms found<?php endif; ?>
            </div>
            <div class="er-sub">
              <?php if (!$filterCity && !$filterDeposit): ?>
                BoligMatch is new. Room owners are signing up — check back soon!
              <?php else: ?>
                Try a different city or remove the deposit filter.
              <?php endif; ?>
            </div>
            <?php if ($filterCity || $filterDeposit): ?>
              <a href="home-student.php" style="font-size:.82rem;color:var(--teal);font-weight:600;text-decoration:none">← Clear filters</a>
            <?php endif; ?>
          </div>

        <?php else: ?>
          <?php foreach ($rooms as $i => $r):
            $pal    = $palettes[$r['id'] % count($palettes)];
            $isNew  = (time() - strtotime($r['created_at'])) < 86400 * 3;
            $isSaved = in_array($r['id'], $savedIds);
            $cover  = findCover($r);
            $tags   = [];
            if ($r['furnished'])      $tags[] = '🪑 Furnished';
            if ($r['wifi'])           $tags[] = '📡 WiFi';
            if ($r['bills_included']) $tags[] = '💡 Bills';
          ?>
            <a href="listing-detail.php?id=<?= $r['id'] ?>" class="room-card">
              <div class="rc-img"<?= $cover ? '' : ' style="background:linear-gradient(135deg,'.$pal[0].','.$pal[1].')"' ?>>
                <?php if ($cover): ?>
                  <img src="<?= e($cover) ?>" alt="<?= e($r['title']) ?>" loading="lazy"/>
                <?php else: ?>
                  <span style="opacity:.35;font-size:2rem">🏠</span>
                <?php endif; ?>
                <div class="rc-badges">
                  <?php if ($isNew): ?><span class="rc-badge rc-badge-new">New</span><?php endif; ?>
                  <?php if ($r['owner_verified']): ?><span class="rc-badge rc-badge-verified">✓ Verified</span><?php endif; ?>
                  <?php if ((int)$r['deposit_months']===1): ?><span class="rc-badge rc-badge-deposit">1 mo. dep.</span><?php endif; ?>
                </div>
                <button type="button" class="fav-btn"
                  data-id="<?= $r['id'] ?>"
                  onclick="event.preventDefault(); toggleSave(<?= $r['id'] ?>, this)"
                  title="<?= $isSaved?'Remove from saved':'Save room' ?>">
                  <?= $isSaved ? '❤️' : '🤍' ?>
                </button>
              </div>
              <div class="rc-body">
                <div class="rc-city">📍 <?= e($r['city']) ?> · <?= e($r['area']) ?></div>
                <div class="rc-title"><?= e($r['title']) ?></div>
                <div class="rc-tags">
                  <span class="rc-tag"><?= $r['room_type']==='double'?'Double':'Single' ?></span>
                  <?php if ($r['room_size_m2']): ?><span class="rc-tag"><?= $r['room_size_m2'] ?>m²</span><?php endif; ?>
                  <?php foreach (array_slice($tags,0,2) as $t): ?><span class="rc-tag"><?= $t ?></span><?php endforeach; ?>
                </div>
                <div class="rc-footer">
                  <div class="rc-price">DKK <?= number_format($r['rent_monthly']) ?> <small>/mo</small></div>
                  <div class="rc-deposit"><?= $r['deposit_months'] ?> mo. deposit</div>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <?php if ($totalRooms > 6): ?>
        <div style="margin-top:14px;text-align:center">
          <a href="listings.php<?= $filterCity?'?city='.urlencode($filterCity):'' ?>"
            style="display:inline-flex;align-items:center;gap:8px;padding:11px 26px;border:1.5px solid var(--teal);color:var(--teal);border-radius:8px;text-decoration:none;font-size:.86rem;font-weight:600;background:white;transition:all .2s"
            onmouseover="this.style.background='var(--teal)';this.style.color='white'"
            onmouseout="this.style.background='white';this.style.color='var(--teal)'">
            View all <?= $totalRooms ?> rooms →
          </a>
        </div>
      <?php endif; ?>
    </div>

    <!-- Saved Rooms -->
    <div class="section">
      <div class="section-header">
        <div class="section-title">
          ❤️ Saved Rooms <span><?= count($savedRooms) ?></span>
        </div>
        <?php if (!empty($savedRooms)): ?>
          <a href="dashboard.php?tab=saved" class="section-link">View all →</a>
        <?php endif; ?>
      </div>

      <?php if (empty($savedRooms)): ?>
        <div style="background:white;border:1.5px solid var(--border);border-radius:10px;padding:24px;text-align:center">
          <div style="font-size:1.8rem;margin-bottom:8px;opacity:.3">🤍</div>
          <div style="font-size:.85rem;font-weight:600;color:var(--text-muted)">No saved rooms yet</div>
          <div style="font-size:.76rem;color:#c0bab2;margin-top:3px">Click the ❤️ on any room to save it for later.</div>
        </div>
      <?php else: ?>
        <?php foreach ($savedRooms as $s):
          $pal = $palettes[$s['id'] % count($palettes)];
          $cover = findCover($s);
        ?>
          <a href="listing-detail.php?id=<?= $s['id'] ?>" class="saved-row">
            <div class="sr-img"<?= $cover ? '' : ' style="background:linear-gradient(135deg,'.$pal[0].','.$pal[1].')"' ?>>
              <?php if ($cover): ?>
                <img src="<?= e($cover) ?>" alt="" loading="lazy"/>
              <?php else: ?>
                🏠
              <?php endif; ?>
            </div>
            <div class="sr-info">
              <div class="sr-title"><?= e($s['title']) ?></div>
              <div class="sr-meta">📍 <?= e($s['city']) ?> · <?= e($s['area']) ?> · <?= e(ucfirst($s['room_type'])) ?></div>
            </div>
            <?php if ($s['status'] === 'expired'): ?>
              <span class="sr-status-expired">No longer available</span>
            <?php else: ?>
              <span class="sr-price">DKK <?= number_format($s['rent_monthly']) ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Recent Conversations -->
    <div class="section">
      <div class="section-header">
        <div class="section-title">
          💬 My Conversations
          <?php if ($unreadCount > 0): ?><span><?= $unreadCount ?> unread</span><?php endif; ?>
        </div>
        <?php if (!empty($conversations)): ?>
          <a href="inbox.php" class="section-link">View all →</a>
        <?php endif; ?>
      </div>

      <?php if (empty($conversations)): ?>
        <div style="background:white;border:1.5px solid var(--border);border-radius:10px;padding:24px;text-align:center">
          <div style="font-size:1.8rem;margin-bottom:8px;opacity:.3">💬</div>
          <div style="font-size:.85rem;font-weight:600;color:var(--text-muted)">No conversations yet</div>
          <div style="font-size:.76rem;color:#c0bab2;margin-top:3px;line-height:1.5">
            Find a room above and click <strong>"Send Message"</strong> to contact the owner.
          </div>
        </div>
      <?php else: ?>
        <?php foreach ($conversations as $c): ?>
          <a href="inbox.php?listing=<?= $c['listing_id'] ?>&user=<?= $c['owner_id'] ?>"
             class="convo-row <?= !$c['is_read'] && $c['first_name'] ? 'unread':'' ?>">
            <div class="cr-avatar"><?= strtoupper(substr($c['first_name'],0,1)) ?></div>
            <div class="cr-body">
              <div class="cr-top">
                <span class="cr-name"><?= e($c['first_name'].' '.$c['last_name']) ?></span>
                <span class="cr-time"><?= timeAgo($c['created_at']) ?></span>
              </div>
              <div class="cr-listing">🏠 <?= e($c['listing_title']) ?></div>
              <div class="cr-preview"><?= e(substr($c['body'],0,80)) ?><?= strlen($c['body'])>80?'…':'' ?></div>
            </div>
            <?php if (!$c['is_read']): ?><div class="cr-dot"></div><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  </div><!-- /left column -->

  <!-- RIGHT SIDEBAR -->
  <aside class="right-sidebar">

    <!-- Profile card -->
    <div class="profile-card">
      <div class="pc-top">
        <div class="pc-avatar"><?= strtoupper(substr($student['first_name'],0,1)) ?></div>
        <div>
          <div class="pc-name"><?= e($student['first_name'].' '.$student['last_name']) ?></div>
          <div class="pc-role">
            Student
            <?php if ($student['university']): ?>· <?= e($student['university']) ?><?php endif; ?>
            <br/>
            <?php if ($student['email_verified']): ?>
              <span style="color:var(--green);font-weight:600">✓ Verified</span>
            <?php else: ?>
              <span style="color:var(--red);font-weight:600">✗ Email not verified</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="pc-links">
        <a href="listings.php" class="pc-link">🔍 Browse all rooms <span>→</span></a>
        <a href="inbox.php"    class="pc-link">
          💬 My messages
          <?php if ($unreadCount > 0): ?><span class="pc-link-badge"><?= $unreadCount ?></span><?php endif; ?>
        </a>
        <a href="dashboard.php?tab=saved"   class="pc-link">❤️ Saved rooms <span>(<?= count($savedRooms) ?>)</span></a>
        <a href="dashboard.php?tab=profile" class="pc-link">⚙️ Account settings <span>→</span></a>
        <a href="logout.php?token=<?= $csrf ?>" class="pc-link">🚪 Sign out <span>→</span></a>
      </div>
    </div>

    <!-- Safety tips -->
    <div class="safety-card">
      <div class="safety-title">🛡️ Stay Safe</div>
      <div class="safety-item">✓ <span><strong>Never pay before viewing.</strong> Always see the room in person first.</span></div>
      <div class="safety-item">✓ <span><strong>No wire transfers.</strong> Pay with bank transfer only after signing a contract.</span></div>
      <div class="safety-item">✓ <span><strong>Get a written contract.</strong> You are entitled to one under Danish law.</span></div>
      <div class="safety-item">✓ <span><strong>Report scams.</strong> Use the 🚩 button on any suspicious listing.</span></div>
    </div>

  </aside>

</div><!-- /main -->

<script>
  // ── Save / unsave a room ───────────────────────────────────
  async function toggleSave(listingId, btn) {
    try {
      const res  = await fetch('save-listing.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
          listing_id: listingId,
          csrf_token: '<?= $csrf ?>',
        }),
      });
      const data = await res.json();
      if (data.saved !== undefined) {
        btn.textContent = data.saved ? '❤️' : '🤍';
        btn.title       = data.saved ? 'Remove from saved' : 'Save room';
      }
    } catch (e) {}
  }
</script>
</body>
</html>
