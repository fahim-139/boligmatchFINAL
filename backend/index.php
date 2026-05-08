<?php
// ============================================================
//  index.php — BoligMatch Homepage (Dynamic)
//  Pulls real listings from the database.
//  Shows empty states gracefully when no listings exist.
// ============================================================

session_start();
require_once 'db.php';

$isLoggedIn = isset($_SESSION['user_id']);
$userRole   = $_SESSION['user_role'] ?? null;
$userName   = $_SESSION['user_name'] ?? null;

// ── Fetch latest 6 active listings for the preview grid ─────
$featuredStmt = $pdo->query("
    SELECT l.id, l.title, l.city, l.area,
           l.rent_monthly, l.deposit_months,
           l.room_type, l.room_size_m2,
           l.furnished, l.wifi, l.bills_included,
           l.available_from, l.view_count, l.created_at,
           l.photos,
           u.email_verified AS owner_verified
    FROM   listings l
    JOIN   users    u ON u.id = l.owner_id
    WHERE  l.status = 'active'
    ORDER  BY l.created_at DESC
    LIMIT  6
");
$featuredListings = $featuredStmt->fetchAll();

// ── Stats for trust strip ────────────────────────────────────
$totalListings = (int)$pdo->query("SELECT COUNT(*) FROM listings WHERE status = 'active'")->fetchColumn();
$totalOwners   = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'owner' AND email_verified = 1")->fetchColumn();
$totalStudents = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'student'")->fetchColumn();

// ── City counts for search filter ───────────────────────────
$citiesStmt = $pdo->query("
    SELECT city, COUNT(*) AS cnt
    FROM   listings
    WHERE  status = 'active'
    GROUP  BY city
    ORDER  BY cnt DESC
");
$cityCounts = $citiesStmt->fetchAll(PDO::FETCH_KEY_PAIR);


// Card colour palettes per listing (cycles through based on ID)
$palettes = [
    ['#afc8da','#c8d8e8'],['#d4a8c8','#e8c8e0'],
    ['#a8c8b8','#c8e0d4'],['#d4c8a8','#e8e0c8'],
    ['#a8b8d4','#c8d4e8'],['#c8a8a8','#e0c8c8'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>BoligMatch — Affordable Rooms for International Students in Denmark</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{
      --navy:#0d1b2a;--teal:#1a7f6e;--teal-light:#22a08a;
      --teal-pale:rgba(26,127,110,.08);--cream:#f7f3ee;
      --warm-white:#fdfaf6;--gold:#c9a84c;
      --text:#0d1b2a;--text-muted:#6b7280;--border:#e5e0d8;
      --red:#dc2626;--green:#16a34a;
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    html{scroll-behavior:smooth;}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--text);}

    /* NAV */
    nav{background:var(--navy);padding:0 48px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
    .logo{font-family:'Playfair Display',serif;font-size:1.55rem;font-weight:900;color:white;text-decoration:none;}
    .logo span{color:var(--teal-light);}
    .nav-links{display:flex;align-items:center;gap:8px;}
    .nav-link{color:rgba(255,255,255,.65);text-decoration:none;font-size:.88rem;padding:7px 13px;border-radius:7px;transition:all .2s;}
    .nav-link:hover{color:white;background:rgba(255,255,255,.07);}
    .nav-btn{background:var(--teal);color:white;padding:8px 20px;border-radius:8px;text-decoration:none;font-size:.88rem;font-weight:600;transition:background .2s;}
    .nav-btn:hover{background:var(--teal-light);}
    .nav-btn.outline{background:transparent;border:1.5px solid rgba(255,255,255,.25);}
    .nav-btn.outline:hover{background:rgba(255,255,255,.08);}
    .nav-user{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.8);font-size:.84rem;}
    .nav-user-dot{width:8px;height:8px;border-radius:50%;background:var(--teal-light);}

    /* HERO */
    .hero{background:var(--navy);padding:72px 48px 80px;position:relative;overflow:hidden;}
    .hero::before{
      content:'';position:absolute;inset:0;
      background:radial-gradient(ellipse at 70% 50%,rgba(26,127,110,.18) 0%,transparent 60%);
      pointer-events:none;
    }
    .hero-inner{max-width:1200px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:60px;align-items:center;position:relative;z-index:1;}
    .hero-eyebrow{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:2.5px;color:var(--teal-light);margin-bottom:16px;}
    .hero-h1{font-family:'Playfair Display',serif;font-size:3rem;font-weight:900;color:white;line-height:1.15;letter-spacing:-.5px;margin-bottom:18px;}
    .hero-h1 span{color:var(--teal-light);}
    .hero-sub{font-size:1rem;color:rgba(255,255,255,.6);line-height:1.7;margin-bottom:32px;max-width:440px;}

    /* Search bar */
    .hero-search{background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:6px 6px 6px 20px;display:flex;align-items:center;gap:8px;margin-bottom:20px;}
    .hero-search select{flex:1;background:transparent;border:none;color:rgba(255,255,255,.85);font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none;cursor:pointer;}
    .hero-search select option{background:var(--navy);color:white;}
    .hero-search-divider{width:1px;height:24px;background:rgba(255,255,255,.15);}
    .hero-search-btn{padding:10px 22px;background:var(--teal);color:white;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:700;cursor:pointer;transition:background .2s;white-space:nowrap;}
    .hero-search-btn:hover{background:var(--teal-light);}

    .hero-ctas{display:flex;gap:12px;flex-wrap:wrap;}
    .hero-cta-primary{padding:13px 28px;background:var(--teal);color:white;border-radius:9px;text-decoration:none;font-weight:700;font-size:.95rem;transition:background .2s;}
    .hero-cta-primary:hover{background:var(--teal-light);}
    .hero-cta-secondary{padding:13px 28px;background:rgba(255,255,255,.08);color:rgba(255,255,255,.85);border:1.5px solid rgba(255,255,255,.2);border-radius:9px;text-decoration:none;font-weight:600;font-size:.95rem;transition:all .2s;}
    .hero-cta-secondary:hover{background:rgba(255,255,255,.13);color:white;}

    /* Hero right — stat cards */
    .hero-right{display:flex;flex-direction:column;gap:14px;}
    .hero-stat-card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:12px;padding:18px 22px;display:flex;align-items:center;gap:16px;}
    .hsc-icon{font-size:1.6rem;flex-shrink:0;}
    .hsc-val{font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:700;color:white;line-height:1;}
    .hsc-label{font-size:.75rem;color:rgba(255,255,255,.5);margin-top:3px;}
    .hsc-zero{color:rgba(255,255,255,.3);}

    /* TRUST STRIP */
    .trust-strip{background:white;border-bottom:1px solid var(--border);padding:0 48px;}
    .trust-inner{max-width:1200px;margin:0 auto;display:grid;grid-template-columns:repeat(4,1fr);gap:0;}
    .trust-item{display:flex;align-items:center;gap:12px;padding:18px 20px;border-right:1px solid var(--border);}
    .trust-item:last-child{border-right:none;}
    .trust-icon{font-size:1.3rem;flex-shrink:0;}
    .trust-text{font-size:.8rem;font-weight:600;color:var(--navy);line-height:1.4;}
    .trust-sub{font-size:.72rem;color:var(--text-muted);margin-top:1px;}

    /* SECTION */
    .section{padding:64px 48px;}
    .section-inner{max-width:1200px;margin:0 auto;}
    .section-header{display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:36px;}
    .section-eyebrow{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--teal);margin-bottom:6px;}
    .section-title{font-family:'Playfair Display',serif;font-size:1.9rem;font-weight:700;color:var(--navy);line-height:1.2;}
    .section-link{font-size:.84rem;color:var(--teal);text-decoration:none;font-weight:600;white-space:nowrap;}
    .section-link:hover{text-decoration:underline;}

    /* LISTING GRID */
    .listings-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;}

    /* LISTING CARD */
    .listing-card{background:white;border-radius:14px;border:1.5px solid var(--border);overflow:hidden;transition:transform .2s,box-shadow .2s;text-decoration:none;color:inherit;display:flex;flex-direction:column;}
    .listing-card:hover{transform:translateY(-4px);box-shadow:0 12px 40px rgba(13,27,42,.12);border-color:var(--teal);}
    .card-img{height:160px;display:flex;align-items:center;justify-content:center;font-size:2.5rem;position:relative;flex-shrink:0;overflow:hidden;}
    .card-img img{width:100%;height:100%;object-fit:cover;}
    .card-badges{position:absolute;top:10px;left:10px;display:flex;gap:6px;}
    .badge{font-size:.62rem;font-weight:700;padding:3px 9px;border-radius:100px;}
    .badge-new{background:var(--navy);color:white;}
    .badge-verified{background:rgba(22,163,74,.9);color:white;}
    .badge-deposit{background:var(--teal);color:white;}
    .card-body{padding:16px 18px;flex:1;display:flex;flex-direction:column;}
    .card-city{font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:1.2px;color:var(--teal);margin-bottom:5px;}
    .card-title{font-size:.95rem;font-weight:700;color:var(--navy);margin-bottom:7px;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
    .card-tags{display:flex;flex-wrap:wrap;gap:5px;margin-bottom:12px;}
    .card-tag{font-size:.68rem;padding:3px 8px;border-radius:100px;background:var(--cream);color:var(--text-muted);border:1px solid var(--border);}
    .card-footer{display:flex;justify-content:space-between;align-items:center;padding-top:12px;border-top:1px solid var(--border);margin-top:auto;}
    .card-price{font-size:1.05rem;font-weight:700;color:var(--navy);}
    .card-price small{font-size:.7rem;font-weight:400;color:var(--text-muted);}
    .card-deposit{font-size:.71rem;background:var(--teal-pale);color:var(--teal);padding:4px 10px;border-radius:100px;font-weight:600;}

    /* Empty state */
    .empty-grid{grid-column:1/-1;text-align:center;padding:64px 24px;}
    .eg-icon{font-size:3rem;margin-bottom:18px;opacity:.3;}
    .eg-title{font-family:'Playfair Display',serif;font-size:1.4rem;color:var(--navy);margin-bottom:8px;}
    .eg-sub{font-size:.88rem;color:var(--text-muted);line-height:1.7;max-width:380px;margin:0 auto 24px;}
    .eg-btn{display:inline-block;padding:12px 28px;background:var(--teal);color:white;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;}

    /* HOW IT WORKS */
    .how-it-works{background:var(--navy);padding:72px 48px;}
    .hiw-inner{max-width:1200px;margin:0 auto;}
    .hiw-eyebrow{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--teal-light);margin-bottom:10px;text-align:center;}
    .hiw-title{font-family:'Playfair Display',serif;font-size:2rem;font-weight:700;color:white;text-align:center;margin-bottom:50px;}
    .hiw-grid{display:grid;grid-template-columns:1fr 1fr;gap:48px;}
    .hiw-col-title{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:rgba(255,255,255,.4);margin-bottom:24px;padding-bottom:12px;border-bottom:1px solid rgba(255,255,255,.08);}
    .hiw-steps{display:flex;flex-direction:column;gap:18px;}
    .hiw-step{display:flex;gap:16px;}
    .hiw-num{width:34px;height:34px;border-radius:8px;background:rgba(26,127,110,.2);border:1px solid rgba(26,127,110,.3);color:var(--teal-light);font-family:'Playfair Display',serif;font-size:.9rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .hiw-step-title{font-size:.9rem;font-weight:600;color:white;margin-bottom:3px;}
    .hiw-step-sub{font-size:.78rem;color:rgba(255,255,255,.45);line-height:1.55;}

    /* SAFETY */
    .safety-section{background:white;border-top:1px solid var(--border);border-bottom:1px solid var(--border);padding:52px 48px;}
    .safety-inner{max-width:1200px;margin:0 auto;display:grid;grid-template-columns:1fr 1fr;gap:48px;align-items:center;}
    .safety-tips{display:flex;flex-direction:column;gap:12px;}
    .safety-tip{display:flex;align-items:flex-start;gap:12px;padding:13px 16px;border-radius:9px;border:1px solid var(--border);background:var(--cream);}
    .st-icon{font-size:1.1rem;flex-shrink:0;margin-top:1px;}
    .st-text{font-size:.83rem;color:var(--text);line-height:1.5;}
    .st-text strong{display:block;color:var(--navy);margin-bottom:1px;}

    /* CTA BANNER */
    .cta-banner{background:var(--teal);padding:56px 48px;}
    .cta-inner{max-width:900px;margin:0 auto;text-align:center;}
    .cta-title{font-family:'Playfair Display',serif;font-size:2rem;font-weight:700;color:white;margin-bottom:10px;}
    .cta-sub{font-size:.95rem;color:rgba(255,255,255,.75);margin-bottom:30px;line-height:1.6;}
    .cta-btns{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;}
    .cta-btn-primary{padding:14px 32px;background:white;color:var(--teal);border-radius:9px;text-decoration:none;font-weight:700;font-size:.95rem;transition:opacity .2s;}
    .cta-btn-primary:hover{opacity:.9;}
    .cta-btn-secondary{padding:14px 32px;background:transparent;color:white;border:2px solid rgba(255,255,255,.5);border-radius:9px;text-decoration:none;font-weight:600;font-size:.95rem;transition:all .2s;}
    .cta-btn-secondary:hover{background:rgba(255,255,255,.1);border-color:white;}

    /* FOOTER */
    footer{background:var(--navy);padding:40px 48px 28px;}
    .footer-inner{max-width:1200px;margin:0 auto;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:16px;}
    .footer-logo{font-family:'Playfair Display',serif;font-size:1.2rem;font-weight:900;color:white;}
    .footer-logo span{color:var(--teal-light);}
    .footer-note{font-size:.75rem;color:rgba(255,255,255,.3);line-height:1.5;text-align:right;max-width:400px;}

    @keyframes fadeUp{from{opacity:0;transform:translateY(18px);}to{opacity:1;transform:translateY(0);}}
    .listing-card{animation:fadeUp .4s ease both;}
    <?php for($i=0;$i<6;$i++): ?>
    .listing-card:nth-child(<?= $i+1 ?>){animation-delay:<?= $i*.07 ?>s;}
    <?php endfor; ?>
  </style>
</head>
<body>

<!-- NAV -->
<nav>
  <a href="index.php" class="logo">Bolig<span>Match</span></a>
  <div class="nav-links">
    <a href="listings.php" class="nav-link">Browse Rooms</a>
    <?php if ($isLoggedIn): ?>
      <span class="nav-user"><span class="nav-user-dot"></span><?= e($userName) ?></span>
      <a href="dashboard.php" class="nav-btn outline">Dashboard</a>
      <a href="logout.php"    class="nav-link">Sign Out</a>
    <?php else: ?>
      <a href="../frontend/login.html"    class="nav-btn outline">Sign In</a>
      <a href="../frontend/register.html" class="nav-btn">Get Started</a>
    <?php endif; ?>
  </div>
</nav>

<!-- HERO -->
<section class="hero">
  <div class="hero-inner">
    <div>
      <div class="hero-eyebrow">Student housing in Denmark</div>
      <h1 class="hero-h1">Find a room<br/><span>without the stress.</span></h1>
      <p class="hero-sub">Connect directly with room owners offering lower deposits and flexible terms — built specifically for international students in Denmark.</p>

      <!-- Search form -->
      <form action="listings.php" method="GET">
        <div class="hero-search">
          <select name="city">
            <option value="">📍 Any city</option>
            <?php foreach (['Copenhagen','Aarhus','Aalborg','Odense'] as $c): ?>
              <option value="<?= $c ?>"><?= $c ?> <?= isset($cityCounts[$c]) ? '('.$cityCounts[$c].')' : '' ?></option>
            <?php endforeach; ?>
          </select>
          <div class="hero-search-divider"></div>
          <select name="deposit">
            <option value="">💰 Any deposit</option>
            <option value="1">1 month max</option>
            <option value="2">2 months max</option>
          </select>
          <button type="submit" class="hero-search-btn">Search Rooms</button>
        </div>
      </form>

      <div class="hero-ctas">
        <a href="listings.php"  class="hero-cta-primary">Browse <?= $totalListings > 0 ? $totalListings.' ' : '' ?>Rooms →</a>
        <?php if (!$isLoggedIn): ?>
          <a href="../frontend/register.html" class="hero-cta-secondary">I'm an Owner — List a Room</a>
        <?php elseif ($userRole === 'owner'): ?>
          <a href="create-listing.php" class="hero-cta-secondary">+ Add a Listing</a>
        <?php endif; ?>
      </div>
    </div>

    <!-- Hero stats -->
    <div class="hero-right">
      <div class="hero-stat-card">
        <div class="hsc-icon">🏠</div>
        <div>
          <div class="hsc-val <?= $totalListings === 0 ? 'hsc-zero' : '' ?>"><?= $totalListings > 0 ? $totalListings : '—' ?></div>
          <div class="hsc-label"><?= $totalListings > 0 ? 'Active rooms available' : 'No listings yet — be the first!' ?></div>
        </div>
      </div>
      <div class="hero-stat-card">
        <div class="hsc-icon">👥</div>
        <div>
          <div class="hsc-val <?= $totalStudents === 0 ? 'hsc-zero' : '' ?>"><?= $totalStudents > 0 ? $totalStudents : '—' ?></div>
          <div class="hsc-label"><?= $totalStudents > 0 ? 'Students registered' : 'Join as the first student' ?></div>
        </div>
      </div>
      <div class="hero-stat-card">
        <div class="hsc-icon">🛡️</div>
        <div>
          <div class="hsc-val" style="font-size:1rem;margin-bottom:2px">Deposit-first</div>
          <div class="hsc-label">Filter by max deposit — unique to BoligMatch</div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- TRUST STRIP -->
<div class="trust-strip">
  <div class="trust-inner">
    <div class="trust-item"><div class="trust-icon">✅</div><div><div class="trust-text">Email Verified</div><div class="trust-sub">All accounts confirmed</div></div></div>
    <div class="trust-item"><div class="trust-icon">💰</div><div><div class="trust-text">Deposit Transparency</div><div class="trust-sub">No hidden upfront costs</div></div></div>
    <div class="trust-item"><div class="trust-icon">🚩</div><div><div class="trust-text">Report System</div><div class="trust-sub">Flag suspicious listings</div></div></div>
    <div class="trust-item"><div class="trust-icon">💬</div><div><div class="trust-text">Safe Messaging</div><div class="trust-sub">All conversations on-platform</div></div></div>
  </div>
</div>

<!-- FEATURED LISTINGS -->
<section class="section" style="background:var(--warm-white)">
  <div class="section-inner">
    <div class="section-header">
      <div>
        <div class="section-eyebrow">Latest rooms</div>
        <div class="section-title">Recently listed</div>
      </div>
      <?php if ($totalListings > 0): ?>
        <a href="listings.php" class="section-link">View all <?= $totalListings ?> rooms →</a>
      <?php endif; ?>
    </div>

    <div class="listings-grid">
      <?php if (empty($featuredListings)): ?>
        <div class="empty-grid">
          <div class="eg-icon">🏠</div>
          <div class="eg-title">No listings yet</div>
          <div class="eg-sub">BoligMatch is brand new. Room owners can sign up and post their first listing in minutes. Students — check back soon or sign up to get notified.</div>
          <?php if (!$isLoggedIn): ?>
            <a href="../frontend/register.html" class="eg-btn">Sign Up Free →</a>
          <?php elseif ($userRole === 'owner'): ?>
            <a href="create-listing.php" class="eg-btn">Post Your First Room →</a>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <?php foreach ($featuredListings as $i => $l):
          $pal     = $palettes[$i % count($palettes)];
          $isNew   = (time() - strtotime($l['created_at'])) < 86400 * 3;
          $deposit = (int)$l['deposit_months'];
          $tags = [];
          if ($l['furnished'])      $tags[] = '🪑 Furnished';
          if ($l['wifi'])           $tags[] = '📡 WiFi';
          if ($l['bills_included']) $tags[] = '💡 Bills incl.';
        ?>
          <a href="listing-detail.php?id=<?= $l['id'] ?>" class="listing-card">
            <?php
              // Find a usable cover photo (uploads live inside backend/)
              $coverPhoto = null;
              $listingPhotos = json_decode($l['photos'] ?? '[]', true) ?: [];
              foreach ($listingPhotos as $photoPath) {
                  if (file_exists(__DIR__ . '/' . $photoPath)) {
                      $coverPhoto = $photoPath;
                      break;
                  }
              }
            ?>
            <div class="card-img"<?= $coverPhoto ? '' : ' style="background:linear-gradient(135deg,'.$pal[0].' 0%,'.$pal[1].' 100%)"' ?>>
              <?php if ($coverPhoto): ?>
                <img src="<?= e($coverPhoto) ?>" alt="<?= e($l['title']) ?>" loading="lazy"/>
              <?php else: ?>
                <span style="opacity:.4;font-size:3rem">🏠</span>
              <?php endif; ?>
              <div class="card-badges">
                <?php if ($isNew): ?><span class="badge badge-new">New</span><?php endif; ?>
                <?php if ($l['owner_verified']): ?><span class="badge badge-verified">✓ Verified</span><?php endif; ?>
                <?php if ($deposit === 1): ?><span class="badge badge-deposit">1 mo. deposit</span><?php endif; ?>
              </div>
            </div>
            <div class="card-body">
              <div class="card-city">📍 <?= e($l['city']) ?> · <?= e($l['area']) ?></div>
              <div class="card-title"><?= e($l['title']) ?></div>
              <div class="card-tags">
                <span class="card-tag"><?= $l['room_type'] === 'double' ? 'Double room' : 'Single room' ?></span>
                <?php if ($l['room_size_m2']): ?><span class="card-tag"><?= $l['room_size_m2'] ?>m²</span><?php endif; ?>
                <?php foreach (array_slice($tags, 0, 2) as $tag): ?><span class="card-tag"><?= $tag ?></span><?php endforeach; ?>
              </div>
              <div class="card-footer">
                <div class="card-price">DKK <?= number_format($l['rent_monthly']) ?> <small>/month</small></div>
                <div class="card-deposit"><?= $deposit ?> mo. deposit</div>
              </div>
            </div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- HOW IT WORKS -->
<section class="how-it-works">
  <div class="hiw-inner">
    <div class="hiw-eyebrow">Simple process</div>
    <h2 class="hiw-title">How BoligMatch works</h2>
    <div class="hiw-grid">
      <div>
        <div class="hiw-col-title">🎓 For Students</div>
        <div class="hiw-steps">
          <div class="hiw-step"><div class="hiw-num">1</div><div><div class="hiw-step-title">Create a free account</div><div class="hiw-step-sub">Register as a student with your university email. Verify in one click.</div></div></div>
          <div class="hiw-step"><div class="hiw-num">2</div><div><div class="hiw-step-title">Filter by deposit amount</div><div class="hiw-step-sub">Set your maximum deposit — see only rooms you can actually afford upfront.</div></div></div>
          <div class="hiw-step"><div class="hiw-num">3</div><div><div class="hiw-step-title">Message owners directly</div><div class="hiw-step-sub">Contact owners securely within the platform. No external apps needed.</div></div></div>
          <div class="hiw-step"><div class="hiw-num">4</div><div><div class="hiw-step-title">View before you pay</div><div class="hiw-step-sub">Always arrange an in-person viewing before paying any deposit.</div></div></div>
        </div>
      </div>
      <div>
        <div class="hiw-col-title">🏠 For Room Owners</div>
        <div class="hiw-steps">
          <div class="hiw-step"><div class="hiw-num">1</div><div><div class="hiw-step-title">Register as an owner</div><div class="hiw-step-sub">Free account, verified by email. No subscription fees or listing charges.</div></div></div>
          <div class="hiw-step"><div class="hiw-num">2</div><div><div class="hiw-step-title">Create your listing</div><div class="hiw-step-sub">Add photos, set your rent and deposit clearly. Listings are live immediately.</div></div></div>
          <div class="hiw-step"><div class="hiw-num">3</div><div><div class="hiw-step-title">Receive enquiries</div><div class="hiw-step-sub">Students message you directly. Manage all conversations in your dashboard.</div></div></div>
          <div class="hiw-step"><div class="hiw-num">4</div><div><div class="hiw-step-title">Choose your tenant</div><div class="hiw-step-sub">Arrange viewings and select the student that suits you. Full control throughout.</div></div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- SAFETY -->
<section class="safety-section">
  <div class="safety-inner">
    <div>
      <div class="section-eyebrow">Your safety matters</div>
      <h2 class="section-title" style="margin-bottom:12px">Built-in scam protection</h2>
      <p style="font-size:.88rem;color:var(--text-muted);line-height:1.7;margin-bottom:0">
        Rental scams affect 1 in 4 international students in Europe. BoligMatch includes practical protections to keep you safe — while being transparent about what an academic prototype can and cannot verify.
      </p>
    </div>
    <div class="safety-tips">
      <div class="safety-tip"><div class="st-icon">🔍</div><div class="st-text"><strong>Never pay before viewing</strong>Legitimate landlords always allow an in-person viewing first.</div></div>
      <div class="safety-tip"><div class="st-icon">💬</div><div class="st-text"><strong>Keep communication on-platform</strong>Messages are stored and monitored for suspicious phrases.</div></div>
      <div class="safety-tip"><div class="st-icon">🚩</div><div class="st-text"><strong>Report suspicious listings</strong>Every listing has a report button — flagged listings are reviewed by admins.</div></div>
      <div class="safety-tip"><div class="st-icon">📄</div><div class="st-text"><strong>Always get a written contract</strong>Danish Rent Act requires a written lease for all rentals.</div></div>
    </div>
  </div>
</section>

<!-- CTA BANNER -->
<?php if (!$isLoggedIn): ?>
<section class="cta-banner">
  <div class="cta-inner">
    <h2 class="cta-title">Ready to find your room?</h2>
    <p class="cta-sub">Join students and room owners already using BoligMatch. It's free, transparent, and built for your situation.</p>
    <div class="cta-btns">
      <a href="../frontend/register.html?role=student" class="cta-btn-primary">Find a Room — Free Sign Up</a>
      <a href="../frontend/register.html?role=owner"   class="cta-btn-secondary">I'm a Room Owner →</a>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- FOOTER -->
<footer>
  <div class="footer-inner">
    <div class="footer-logo">Bolig<span>Match</span></div>
    <div class="footer-note">
      Academic prototype — BSc Computer Science thesis, Niels Brock Copenhagen Business College, 2026.<br/>
      Not a commercial platform. All listings and users are fictional test data.
    </div>
  </div>
</footer>

</body>
</html>
