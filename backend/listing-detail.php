<?php
// ============================================================
//  listing-detail.php — Single Room Detail Page
//  Loads one listing from the database by ID.
//  Shows 404 page if the listing doesn't exist or is expired.
// ============================================================

session_start();
require_once 'db.php';

$isLoggedIn = isset($_SESSION['user_id']);
$userId     = (int)($_SESSION['user_id'] ?? 0);
$userRole   = $_SESSION['user_role'] ?? null;

// ── Get listing ID from URL ──────────────────────────────────
$listingId = (int)($_GET['id'] ?? 0);
if (!$listingId) {
    header('Location: listings.php');
    exit;
}

// ── Fetch the listing with owner info ────────────────────────
$stmt = $pdo->prepare("
    SELECT  l.*,
            u.id             AS owner_id,
            u.first_name     AS owner_first,
            u.last_name      AS owner_last,
            u.email          AS owner_email,
            u.phone          AS owner_phone,
            u.email_verified AS owner_verified,
            u.last_login     AS owner_last_login,
            u.created_at     AS owner_since
    FROM    listings l
    JOIN    users    u ON u.id = l.owner_id
    WHERE   l.id = ? AND l.status != 'expired'
    LIMIT   1
");
$stmt->execute([$listingId]);
$listing = $stmt->fetch(PDO::FETCH_ASSOC);

// ── 404 if not found ─────────────────────────────────────────
if (!$listing) { ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <title>Room Not Found — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@400;600&display=swap" rel="stylesheet"/>
  <style>
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'DM Sans',sans-serif;background:#f7f3ee;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;}
    .box{background:white;border-radius:16px;border:1.5px solid #e5e0d8;padding:52px 44px;max-width:420px;text-align:center;}
    .icon{font-size:2.8rem;margin-bottom:18px;}
    h1{font-family:'Playfair Display',serif;font-size:1.5rem;color:#0d1b2a;margin-bottom:10px;}
    p{font-size:.9rem;color:#6b7280;line-height:1.7;margin-bottom:24px;}
    a{display:inline-block;padding:12px 26px;background:#1a7f6e;color:white;border-radius:8px;text-decoration:none;font-weight:600;}
  </style>
</head>
<body>
  <div class="box">
    <div class="icon">🏠</div>
    <h1>Room not found</h1>
    <p>This listing may have been removed, expired, or the link is incorrect. Browse other available rooms below.</p>
    <a href="listings.php">Browse All Rooms →</a>
  </div>
</body>
</html>
<?php
    exit;
}

// ── Increment view count ─────────────────────────────────────
$pdo->prepare("UPDATE listings SET view_count = view_count + 1 WHERE id = ?")->execute([$listingId]);

// ── Is this listing saved by the current student? ────────────
$isSaved = false;
if ($isLoggedIn && $userRole === 'student') {
    $s = $pdo->prepare("SELECT 1 FROM saved_listings WHERE student_id=? AND listing_id=? LIMIT 1");
    $s->execute([$userId, $listingId]);
    $isSaved = (bool)$s->fetchColumn();
}

// ── Has this student already messaged about this listing? ────
$alreadyMessaged = false;
if ($isLoggedIn && $userRole === 'student') {
    $m = $pdo->prepare("SELECT 1 FROM messages WHERE sender_id=? AND listing_id=? LIMIT 1");
    $m->execute([$userId, $listingId]);
    $alreadyMessaged = (bool)$m->fetchColumn();
}

// ── Open report count (shown as a warning badge) ─────────────
$repStmt = $pdo->prepare("SELECT COUNT(*) FROM reports WHERE listing_id=? AND status='open'");
$repStmt->execute([$listingId]);
$reportCount = (int)$repStmt->fetchColumn();

// ── Similar listings (same city, different id) ───────────────
$simStmt = $pdo->prepare("
    SELECT id, title, city, area, rent_monthly, deposit_months, room_type, created_at
    FROM   listings
    WHERE  city=? AND status='active' AND id!=?
    ORDER  BY created_at DESC
    LIMIT  3
");
$simStmt->execute([$listing['city'], $listingId]);
$similar = $simStmt->fetchAll(PDO::FETCH_ASSOC);

// ── CSRF ─────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken(32);
}
$csrf = $_SESSION['csrf_token'];

// ── Flash message ─────────────────────────────────────────────
$flash     = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// ── Helpers ──────────────────────────────────────────────────

$rent          = (float)$listing['rent_monthly'];
$depositMonths = (int)$listing['deposit_months'];
$depositTotal  = $rent * $depositMonths;
$moveInTotal   = $rent + $depositTotal;

// Owner account age
$ownerDays = (int)((time() - strtotime($listing['owner_since'])) / 86400);
$ownerAge  = $ownerDays < 30 ? "{$ownerDays} days" : floor($ownerDays/30).' months';

// Was owner active recently?
$recentlyActive = $listing['owner_last_login'] &&
                  (time() - strtotime($listing['owner_last_login'])) < 86400 * 3;

// Photos from JSON column
$photos = json_decode($listing['photos'] ?? '[]', true) ?: [];

// Gradient palette (same logic as cards)
$palettes = [
    ['#afc8da','#c8d8e8'],['#d4a8c8','#e8c8e0'],
    ['#a8c8b8','#c8e0d4'],['#d4c8a8','#e8e0c8'],
    ['#a8b8d4','#c8d4e8'],['#c8a8a8','#e0c8c8'],
];
$pal = $palettes[$listingId % count($palettes)];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= e($listing['title']) ?> — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{
      --navy:#0d1b2a; --teal:#1a7f6e; --teal-light:#22a08a;
      --teal-pale:rgba(26,127,110,.08); --cream:#f7f3ee; --warm-white:#fdfaf6;
      --gold:#c9a84c; --gold-pale:rgba(201,168,76,.1);
      --text:#0d1b2a; --text-muted:#6b7280; --border:#e5e0d8;
      --red:#dc2626; --red-pale:rgba(220,38,38,.07); --green:#16a34a;
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    html{scroll-behavior:smooth;}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--text);}

    /* NAV */
    nav{background:rgba(253,250,246,.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);padding:0 56px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
    .logo{font-family:'Playfair Display',serif;font-size:1.6rem;font-weight:900;color:var(--navy);text-decoration:none;letter-spacing:-.5px;}
    .logo span{color:var(--teal);}
    .nav-right{display:flex;align-items:center;gap:10px;}
    .nav-link{font-size:.87rem;color:var(--text-muted);text-decoration:none;padding:7px 13px;border-radius:7px;transition:all .2s;}
    .nav-link:hover{color:var(--navy);background:var(--cream);}
    .nav-btn{padding:9px 20px;border-radius:7px;text-decoration:none;font-size:.87rem;font-weight:600;transition:background .2s;}
    .nav-btn.solid{background:var(--navy);color:white;}
    .nav-btn.solid:hover{background:var(--teal);}
    .nav-btn.outline{border:1.5px solid var(--border);color:var(--text-muted);}
    .nav-btn.outline:hover{border-color:var(--teal);color:var(--teal);}

    /* FLASH */
    .flash-bar{padding:11px 56px;font-size:.84rem;display:flex;align-items:center;gap:9px;}
    .flash-bar.ok  {background:rgba(22,163,74,.08);border-bottom:1px solid rgba(22,163,74,.15);color:var(--green);}
    .flash-bar.err {background:var(--red-pale);border-bottom:1px solid rgba(220,38,38,.15);color:var(--red);}

    /* LAYOUT */
    .page-wrap{max-width:1160px;margin:0 auto;padding:28px 56px 64px;display:grid;grid-template-columns:1fr 330px;gap:28px;align-items:start;}

    /* BREADCRUMB */
    .crumb{display:flex;align-items:center;gap:7px;font-size:.76rem;color:var(--text-muted);margin-bottom:18px;flex-wrap:wrap;}
    .crumb a{color:var(--teal);text-decoration:none;}
    .crumb a:hover{text-decoration:underline;}

    /* PHOTO / HERO IMAGE */
    .listing-hero{height:280px;border-radius:14px;overflow:hidden;margin-bottom:22px;position:relative;display:flex;align-items:center;justify-content:center;font-size:5rem;}
    .listing-hero img{width:100%;height:100%;object-fit:cover;}
    .hero-placeholder{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:5rem;opacity:.2;}
    .photo-count-badge{position:absolute;bottom:12px;right:12px;background:rgba(13,27,42,.65);color:white;font-size:.74rem;font-weight:600;padding:5px 13px;border-radius:100px;}
    .gallery-btn{position:absolute;top:50%;transform:translateY(-50%);width:36px;height:36px;border-radius:50%;background:rgba(13,27,42,.55);color:white;border:none;font-size:1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .2s;}
    .gallery-btn:hover{background:rgba(13,27,42,.8);}
    .gallery-btn.prev{left:10px;}
    .gallery-btn.next{right:10px;}
    .gallery-counter{position:absolute;top:12px;right:12px;background:rgba(13,27,42,.55);color:white;font-size:.72rem;font-weight:600;padding:4px 11px;border-radius:100px;}

    /* TITLE AREA */
    .status-pills{display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:10px;}
    .pill{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:100px;font-size:.69rem;font-weight:700;}
    .pill-green{background:rgba(22,163,74,.1);color:var(--green);}
    .pill-teal {background:var(--teal-pale);color:var(--teal);}
    .pill-navy {background:rgba(13,27,42,.06);color:var(--navy);}
    .pill-red  {background:var(--red-pale);color:var(--red);}
    .listing-h1{font-family:'Playfair Display',serif;font-size:1.75rem;font-weight:700;color:var(--navy);line-height:1.25;margin-bottom:6px;}
    .listing-loc{font-size:.88rem;color:var(--text-muted);}

    /* SECTION CARD */
    .card{background:white;border:1.5px solid var(--border);border-radius:12px;padding:22px;margin-bottom:18px;}
    .card-head{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-muted);margin-bottom:16px;display:flex;align-items:center;gap:8px;}
    .card-head::before{content:'';width:3px;height:13px;border-radius:2px;background:var(--teal-light);display:block;}

    /* COST BAR */
    .cost-bar{display:grid;grid-template-columns:repeat(3,1fr);background:var(--navy);border-radius:10px;overflow:hidden;margin-bottom:18px;}
    .cost-cell{padding:16px 18px;text-align:center;border-right:1px solid rgba(255,255,255,.08);}
    .cost-cell:last-child{border-right:none;}
    .cost-label{font-size:.61rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:rgba(255,255,255,.38);margin-bottom:6px;}
    .cost-val{font-size:1.1rem;font-weight:700;color:white;}
    .cost-val.teal{color:var(--teal-light);}
    .cost-val.gold{color:var(--gold);}
    .cost-sub{font-size:.67rem;color:rgba(255,255,255,.28);margin-top:3px;}

    /* DETAILS GRID */
    .details-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;}
    .detail-item{display:flex;align-items:flex-start;gap:10px;padding:10px 12px;border-radius:8px;background:var(--cream);}
    .di-icon{font-size:1rem;flex-shrink:0;margin-top:1px;}
    .di-label{font-size:.66rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.4px;font-weight:600;margin-bottom:1px;}
    .di-val{font-size:.84rem;font-weight:600;color:var(--navy);}

    /* AMENITIES */
    .amenities{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;}
    .am-item{display:flex;align-items:center;gap:7px;padding:9px 11px;border-radius:8px;border:1px solid var(--border);font-size:.8rem;font-weight:500;}
    .am-item.on {background:var(--teal-pale);border-color:rgba(26,127,110,.2);color:var(--teal);}
    .am-item.off{background:var(--cream);color:#c0bab2;}

    /* DESCRIPTION */
    .desc-text{font-size:.87rem;line-height:1.8;color:var(--text);white-space:pre-line;}
    .desc-clamp{display:-webkit-box;-webkit-line-clamp:5;-webkit-box-orient:vertical;overflow:hidden;}
    .read-more{background:none;border:none;color:var(--teal);font-family:'DM Sans',sans-serif;font-size:.84rem;font-weight:600;cursor:pointer;padding:8px 0 0;display:block;}
    .read-more:hover{color:var(--teal-light);}

    /* SAFETY */
    .safety-warn{background:var(--gold-pale);border:1.5px solid rgba(201,168,76,.25);border-radius:12px;padding:18px 20px;margin-bottom:18px;}
    .sw-title{font-size:.77rem;font-weight:700;color:#7c5a00;margin-bottom:11px;display:flex;align-items:center;gap:7px;}
    .sw-list{display:flex;flex-direction:column;gap:7px;}
    .sw-item{display:flex;align-items:flex-start;gap:8px;font-size:.78rem;color:#7c5a00;line-height:1.5;}

    /* REPORT */
    .report-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1.5px solid var(--border);border-radius:7px;background:none;font-family:'DM Sans',sans-serif;font-size:.76rem;font-weight:600;color:var(--text-muted);cursor:pointer;transition:all .2s;}
    .report-btn:hover{border-color:var(--red);color:var(--red);}

    /* SIMILAR */
    .sim-card{display:flex;gap:12px;padding:12px;border-radius:9px;border:1px solid var(--border);background:var(--cream);text-decoration:none;color:inherit;transition:all .18s;margin-bottom:8px;}
    .sim-card:hover{border-color:var(--teal);background:white;}
    .sim-img{width:56px;height:56px;border-radius:7px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.4rem;opacity:.45;}
    .sim-title{font-size:.82rem;font-weight:600;color:var(--navy);margin-bottom:2px;line-height:1.3;}
    .sim-meta{font-size:.71rem;color:var(--text-muted);}
    .sim-price{font-size:.82rem;font-weight:700;color:var(--teal);margin-top:2px;}

    /* ── STICKY CONTACT SIDEBAR ── */
    .sticky-col{position:sticky;top:80px;}

    /* Price card */
    .price-card{background:white;border:1.5px solid var(--border);border-radius:14px;overflow:hidden;box-shadow:0 8px 28px rgba(13,27,42,.08);margin-bottom:14px;}
    .pc-top{background:var(--navy);padding:20px 22px;}
    .pc-price{font-family:'Playfair Display',serif;font-size:1.75rem;font-weight:700;color:white;line-height:1;}
    .pc-price small{font-size:.75rem;font-weight:400;color:rgba(255,255,255,.45);font-family:'DM Sans',sans-serif;}
    .pc-deposit{font-size:.76rem;color:rgba(255,255,255,.45);margin-top:6px;}
    .pc-deposit strong{color:var(--gold);}
    .pc-avail{display:inline-flex;align-items:center;gap:5px;margin-top:10px;padding:4px 11px;border-radius:100px;background:rgba(22,163,74,.15);color:#4ade80;font-size:.73rem;font-weight:600;}
    .pc-avail::before{content:'';width:6px;height:6px;border-radius:50%;background:#4ade80;}
    .pc-body{padding:18px 20px;}

    /* Contact form */
    .cf-label{display:block;font-size:.71rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:7px;}
    .cf-templates{display:flex;flex-direction:column;gap:5px;margin-bottom:11px;}
    .cf-tpl{padding:7px 10px;border-radius:7px;border:1.5px solid var(--border);background:white;font-family:'DM Sans',sans-serif;font-size:.75rem;cursor:pointer;text-align:left;color:var(--text-muted);transition:all .15s;}
    .cf-tpl:hover{border-color:var(--teal);color:var(--teal);background:var(--teal-pale);}
    .cf-textarea{width:100%;padding:11px 13px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.86rem;color:var(--text);background:var(--warm-white);resize:none;outline:none;line-height:1.6;transition:border-color .2s;}
    .cf-textarea:focus{border-color:var(--teal);background:white;}
    .cf-textarea::placeholder{color:#c0bab2;}
    .cf-send{width:100%;padding:13px;background:var(--teal);color:white;border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:.94rem;font-weight:700;cursor:pointer;transition:background .2s;margin-top:9px;}
    .cf-send:hover{background:var(--teal-light);}
    .cf-hint{font-size:.71rem;color:var(--text-muted);text-align:center;margin-top:9px;line-height:1.5;}

    /* Already messaged */
    .already-msg{padding:14px;background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.2);border-radius:8px;font-size:.83rem;color:var(--green);text-align:center;line-height:1.6;}
    .already-msg a{color:var(--teal);font-weight:600;text-decoration:none;}

    /* Login prompt */
    .login-prompt{text-align:center;padding:4px 0 10px;}
    .login-prompt p{font-size:.83rem;color:var(--text-muted);margin-bottom:12px;line-height:1.5;}
    .lp-btn{display:block;text-align:center;padding:12px;border-radius:9px;text-decoration:none;font-weight:700;font-size:.9rem;margin-bottom:8px;transition:background .2s;}
    .lp-btn.primary{background:var(--teal);color:white;}
    .lp-btn.primary:hover{background:var(--teal-light);}
    .lp-btn.secondary{background:var(--cream);color:var(--navy);border:1.5px solid var(--border);}
    .lp-btn.secondary:hover{border-color:var(--teal);}

    /* Owner card */
    .owner-card{background:white;border:1.5px solid var(--border);border-radius:12px;padding:18px 20px;margin-bottom:14px;}
    .oc-row{display:flex;align-items:center;gap:12px;margin-bottom:13px;}
    .oc-avatar{width:42px;height:42px;border-radius:9px;background:linear-gradient(135deg,var(--teal),#0a2a40);color:white;font-family:'Playfair Display',serif;font-size:1.05rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .oc-name{font-size:.9rem;font-weight:700;color:var(--navy);}
    .oc-role{font-size:.71rem;color:var(--text-muted);margin-top:1px;}
    .oc-stats{display:grid;grid-template-columns:repeat(3,1fr);border:1px solid var(--border);border-radius:8px;overflow:hidden;}
    .ocs{padding:9px 7px;text-align:center;border-right:1px solid var(--border);}
    .ocs:last-child{border-right:none;}
    .ocs-val{font-size:.86rem;font-weight:700;color:var(--navy);}
    .ocs-label{font-size:.61rem;color:var(--text-muted);margin-top:1px;}

    /* Save button */
    .save-row{display:flex;}
    .save-btn{flex:1;padding:11px;border:1.5px solid var(--border);border-radius:9px;background:white;font-family:'DM Sans',sans-serif;font-size:.86rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:7px;color:var(--text-muted);transition:all .2s;}
    .save-btn:hover{border-color:var(--red);color:var(--red);}
    .save-btn.saved{border-color:rgba(220,38,38,.3);color:var(--red);}

    /* Report modal */
    .modal-bg{display:none;position:fixed;inset:0;z-index:500;background:rgba(13,27,42,.65);align-items:center;justify-content:center;}
    .modal-bg.open{display:flex;}
    .modal{background:white;border-radius:14px;padding:32px;max-width:400px;width:90%;animation:popIn .3s ease;}
    @keyframes popIn{from{opacity:0;transform:scale(.92);}to{opacity:1;transform:scale(1);}}
    .modal h3{font-family:'Playfair Display',serif;font-size:1.15rem;color:var(--navy);margin-bottom:8px;}
    .modal p{font-size:.82rem;color:var(--text-muted);line-height:1.6;margin-bottom:16px;}
    .modal select,.modal textarea{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.85rem;color:var(--text);background:var(--warm-white);outline:none;margin-bottom:10px;}
    .modal textarea{resize:vertical;min-height:80px;}
    .modal-actions{display:flex;gap:9px;}
    .modal-cancel{flex:1;padding:11px;border:1.5px solid var(--border);border-radius:8px;background:white;font-family:'DM Sans',sans-serif;font-size:.85rem;cursor:pointer;color:var(--text-muted);}
    .modal-submit{flex:2;padding:11px;border:none;border-radius:8px;background:var(--red);color:white;font-family:'DM Sans',sans-serif;font-size:.85rem;font-weight:700;cursor:pointer;}

    @keyframes fadeUp{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}
    .card{animation:fadeUp .4s ease both;}
    .card:nth-child(1){animation-delay:.04s;}
    .card:nth-child(2){animation-delay:.08s;}
    .card:nth-child(3){animation-delay:.12s;}
    .card:nth-child(4){animation-delay:.16s;}
  </style>
</head>
<body>

<!-- NAV -->
<nav>
  <a href="../frontend/index.html" class="logo">Bolig<span>Match</span></a>
  <div class="nav-right">
    <a href="listings.php" class="nav-link">← All Rooms</a>
    <?php if ($isLoggedIn): ?>
      <a href="<?= $userRole==='owner'?'home-owner.php':'home-student.php' ?>" class="nav-btn outline">Dashboard</a>
    <?php else: ?>
      <a href="../frontend/login.html"    class="nav-btn outline">Sign In</a>
      <a href="../frontend/register.html" class="nav-btn solid">Register</a>
    <?php endif; ?>
  </div>
</nav>

<!-- FLASH -->
<?php if ($flash): ?>
  <div class="flash-bar <?= $flashType==='success'?'ok':'err' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<!-- REPORT MODAL -->
<div class="modal-bg" id="report-modal">
  <div class="modal">
    <h3>🚩 Report This Listing</h3>
    <p>All reports are reviewed by admins within 24 hours. Your report is anonymous.</p>
    <?php if ($isLoggedIn): ?>
      <form method="POST" action="submit-report.php">
        <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
        <input type="hidden" name="listing_id"  value="<?= $listingId ?>"/>
        <select name="reason" required>
          <option value="">Select reason…</option>
          <option value="scam">Possible scam / fraud</option>
          <option value="fake_listing">Fake or misleading listing</option>
          <option value="inappropriate">Inappropriate content</option>
          <option value="suspicious_owner">Suspicious owner behaviour</option>
          <option value="other">Other</option>
        </select>
        <textarea name="details" placeholder="Optional: describe what seemed suspicious… (max 500 chars)" maxlength="500"></textarea>
        <div class="modal-actions">
          <button type="button" class="modal-cancel" onclick="closeModal()">Cancel</button>
          <button type="submit" class="modal-submit">Submit Report</button>
        </div>
      </form>
    <?php else: ?>
      <p style="text-align:center"><a href="../frontend/login.html" style="color:var(--teal);font-weight:600">Sign in to report this listing</a></p>
      <button type="button" class="modal-cancel" style="width:100%" onclick="closeModal()">Close</button>
    <?php endif; ?>
  </div>
</div>

<div class="page-wrap">

  <!-- ── LEFT / MAIN COLUMN ── -->
  <div>

    <!-- Breadcrumb -->
    <div class="crumb">
      <a href="../frontend/index.html">Home</a> ›
      <a href="listings.php">Rooms</a> ›
      <a href="listings.php?city=<?= e($listing['city']) ?>"><?= e($listing['city']) ?></a> ›
      <?= e($listing['area']) ?>
    </div>

    <!-- Photo Gallery -->
    <div class="listing-hero" style="background:linear-gradient(135deg,<?= $pal[0] ?>,<?= $pal[1] ?>)" id="gallery">
      <?php if (!empty($photos)): ?>
        <?php
          // Filter to only photos that actually exist on disk
          $validPhotos = [];
          foreach ($photos as $photo) {
              if (file_exists($photo)) {
                  $validPhotos[] = $photo;
              }
          }
        ?>
        <?php if (!empty($validPhotos)): ?>
          <!-- Show first photo by default -->
          <img id="gallery-img" src="<?= e($validPhotos[0]) ?>" alt="<?= e($listing['title']) ?>"/>

          <?php if (count($validPhotos) > 1): ?>
            <!-- Navigation arrows (only shown when multiple photos) -->
            <button class="gallery-btn prev" onclick="galleryPrev()">◀</button>
            <button class="gallery-btn next" onclick="galleryNext()">▶</button>
            <div class="gallery-counter" id="gallery-counter">1 / <?= count($validPhotos) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <div class="hero-placeholder">🏠</div>
        <?php endif; ?>
      <?php else: ?>
        <div class="hero-placeholder">🏠</div>
      <?php endif; ?>
    </div>

    <!-- Title -->
    <div class="status-pills">
      <span class="pill pill-green">● Available</span>
      <?php if ($listing['owner_verified']): ?><span class="pill pill-teal">✓ Owner verified</span><?php endif; ?>
      <span class="pill pill-navy">🏠 <?= e(ucfirst($listing['room_type'])) ?> room</span>
      <?php if ($listing['bills_included']): ?><span class="pill pill-teal">💡 Bills included</span><?php endif; ?>
      <?php if ($reportCount > 0): ?><span class="pill pill-red">⚠ <?= $reportCount ?> report<?= $reportCount!==1?'s':'' ?></span><?php endif; ?>
    </div>
    <h1 class="listing-h1"><?= e($listing['title']) ?></h1>
    <p class="listing-loc" style="margin-bottom:22px">
      📍 <?= e($listing['city']) ?>, <?= e($listing['area']) ?>
    </p>

    <!-- Cost bar -->
    <div class="cost-bar">
      <div class="cost-cell">
        <div class="cost-label">Monthly Rent</div>
        <div class="cost-val teal">DKK <?= number_format($rent) ?></div>
        <div class="cost-sub">per month</div>
      </div>
      <div class="cost-cell">
        <div class="cost-label">Deposit</div>
        <div class="cost-val">DKK <?= number_format($depositTotal) ?></div>
        <div class="cost-sub"><?= $depositMonths ?> month<?= $depositMonths!==1?'s':'' ?> · paid once</div>
      </div>
      <div class="cost-cell">
        <div class="cost-label">Total to Move In</div>
        <div class="cost-val gold">DKK <?= number_format($moveInTotal) ?></div>
        <div class="cost-sub">deposit + 1st month</div>
      </div>
    </div>

    <!-- Property details -->
    <div class="card">
      <div class="card-head">Property Details</div>
      <div class="details-grid">
        <div class="detail-item"><div class="di-icon">🏠</div><div><div class="di-label">Room type</div><div class="di-val"><?= e(ucfirst($listing['room_type'])) ?> room</div></div></div>
        <?php if ($listing['room_size_m2']): ?>
          <div class="detail-item"><div class="di-icon">📐</div><div><div class="di-label">Room size</div><div class="di-val"><?= (int)$listing['room_size_m2'] ?> m²</div></div></div>
        <?php endif; ?>
        <div class="detail-item"><div class="di-icon">👥</div><div><div class="di-label">Total occupants</div><div class="di-val"><?= (int)$listing['total_flatmates'] ?> people</div></div></div>
        <div class="detail-item"><div class="di-icon">🚿</div><div><div class="di-label">Bathroom</div><div class="di-val"><?= e(ucfirst($listing['bathroom_type'])) ?></div></div></div>
        <div class="detail-item"><div class="di-icon">📅</div><div><div class="di-label">Available from</div><div class="di-val"><?= $listing['available_from'] ? date('d M Y',strtotime($listing['available_from'])) : 'Now' ?></div></div></div>
        <div class="detail-item"><div class="di-icon">📋</div><div><div class="di-label">Min. lease</div><div class="di-val"><?= (int)$listing['min_lease_months'] ?> month<?= (int)$listing['min_lease_months']!==1?'s':'' ?></div></div></div>
        <div class="detail-item"><div class="di-icon">🐾</div><div><div class="di-label">Pets</div><div class="di-val"><?= $listing['pets_allowed']?'Welcome':'Not allowed' ?></div></div></div>
        <div class="detail-item"><div class="di-icon">👁</div><div><div class="di-label">Views</div><div class="di-val"><?= (int)$listing['view_count']+1 ?> views</div></div></div>
      </div>
    </div>

    <!-- Amenities -->
    <div class="card">
      <div class="card-head">Amenities</div>
      <div class="amenities">
        <?php
          $ams = [
            [$listing['furnished'],       '🪑','Furnished'],
            [$listing['wifi'],            '📡','WiFi'],
            [$listing['bills_included'],  '💡','Bills incl.'],
            [$listing['washing_machine'], '🫧','Washing machine'],
            [$listing['parking'],         '🅿️','Parking'],
            [$listing['balcony'],         '🌿','Balcony'],
          ];
          foreach ($ams as [$on,$icon,$label]):
        ?>
          <div class="am-item <?= $on?'on':'off' ?>"><?= $icon ?> <?= $label ?></div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Description -->
    <div class="card">
      <div class="card-head">About This Room</div>
      <div class="desc-text desc-clamp" id="desc-text"><?= e($listing['description']) ?></div>
      <?php if (strlen($listing['description']) > 400): ?>
        <button class="read-more" id="read-more" onclick="toggleDesc()">Read more ↓</button>
      <?php endif; ?>
    </div>

    <!-- Safety warning -->
    <div class="safety-warn">
      <div class="sw-title">🛡️ Safety checklist — read before contacting</div>
      <div class="sw-list">
        <div class="sw-item">✓ <span>Always view the property in person before paying anything.</span></div>
        <div class="sw-item">✓ <span>Never pay via gift cards, crypto, or wire transfer.</span></div>
        <div class="sw-item">✓ <span>Get a written rental contract before handing over any deposit.</span></div>
        <div class="sw-item">✓ <span>Keep all communication on BoligMatch.</span></div>
        <div class="sw-item">✓ <span>If something feels wrong, trust your instincts and report it.</span></div>
      </div>
    </div>

    <!-- Report button -->
    <div style="margin-bottom:20px">
      <button class="report-btn" onclick="document.getElementById('report-modal').classList.add('open')">
        🚩 Report this listing
        <?php if ($reportCount>0): ?><span>(<?= $reportCount ?> open)</span><?php endif; ?>
      </button>
    </div>

    <!-- Similar rooms -->
    <?php if (!empty($similar)): ?>
      <div class="card">
        <div class="card-head">Similar Rooms in <?= e($listing['city']) ?></div>
        <?php foreach ($similar as $s):
          $sp = $palettes[$s['id'] % count($palettes)];
        ?>
          <a href="listing-detail.php?id=<?= $s['id'] ?>" class="sim-card">
            <div class="sim-img" style="background:linear-gradient(135deg,<?= $sp[0] ?>,<?= $sp[1] ?>)">🏠</div>
            <div>
              <div class="sim-title"><?= e($s['title']) ?></div>
              <div class="sim-meta">📍 <?= e($s['city']) ?> · <?= e($s['area']) ?> · <?= e(ucfirst($s['room_type'])) ?></div>
              <div class="sim-price">DKK <?= number_format($s['rent_monthly']) ?>/mo · <?= $s['deposit_months'] ?> mo. deposit</div>
            </div>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  </div><!-- /main col -->

  <!-- ── STICKY SIDEBAR ── -->
  <div class="sticky-col">

    <!-- Price + contact card -->
    <div class="price-card">
      <div class="pc-top">
        <div class="pc-price">DKK <?= number_format($rent) ?> <small>/month</small></div>
        <div class="pc-deposit">Deposit: <strong>DKK <?= number_format($depositTotal) ?></strong> (<?= $depositMonths ?> mo.)</div>
        <div class="pc-avail">
          <?= $listing['available_from'] ? 'From '.date('d M Y',strtotime($listing['available_from'])) : 'Available now' ?>
        </div>
      </div>

      <div class="pc-body">

        <?php if (!$isLoggedIn): ?>
          <!-- Not logged in -->
          <div class="login-prompt">
            <p>Sign in to contact this room owner and arrange a viewing.</p>
            <a href="../frontend/login.html" class="lp-btn primary">Sign In to Message →</a>
            <a href="../frontend/register.html" class="lp-btn secondary">Create Free Account</a>
          </div>

        <?php elseif ($userRole === 'owner' && $_SESSION['user_id'] == $listing['owner_id']): ?>
          <!-- This owner's own listing -->
          <p style="font-size:.83rem;color:var(--text-muted);text-align:center;margin-bottom:12px">This is your listing.</p>
          <a href="edit-listing.php?id=<?= $listingId ?>" style="display:block;text-align:center;padding:12px;background:var(--navy);color:white;border-radius:9px;text-decoration:none;font-weight:700;font-size:.9rem">Edit Listing →</a>

        <?php elseif ($userRole !== 'student'): ?>
          <!-- Owner looking at someone else's listing -->
          <p style="font-size:.82rem;color:var(--text-muted);text-align:center">Only students can send enquiries.</p>

        <?php elseif ($alreadyMessaged): ?>
          <!-- Already contacted -->
          <div class="already-msg">
            ✅ You've already messaged this owner.<br/>
            <a href="inbox.php?listing=<?= $listingId ?>&user=<?= $listing['owner_id'] ?>">View conversation →</a>
          </div>

        <?php else: ?>
          <!-- First contact form -->
          <div class="cf-label" style="margin-bottom:7px">Quick templates:</div>
          <div class="cf-templates">
            <button class="cf-tpl" onclick="setMsg(1)">👋 Express interest + ask to view</button>
            <button class="cf-tpl" onclick="setMsg(2)">📅 Ask about availability</button>
          </div>
          <form method="POST" action="send-message.php">
            <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
            <input type="hidden" name="listing_id"  value="<?= $listingId ?>"/>
            <input type="hidden" name="receiver_id" value="<?= $listing['owner_id'] ?>"/>
            <label class="cf-label" for="msg-body">Your message:</label>
            <textarea name="body" id="msg-body" class="cf-textarea" rows="5"
              placeholder="Hi <?= e($listing['owner_first']) ?>, I'm interested in your room in <?= e($listing['area']) ?>…"
              maxlength="2000" oninput="updateSendBtn()" required></textarea>
            <div style="text-align:right;font-size:.7rem;color:var(--text-muted);margin-top:3px">
              <span id="char-count">0</span>/2000
            </div>
            <button type="submit" class="cf-send" id="send-btn" disabled>Send Message →</button>
          </form>
          <div class="cf-hint">🔒 Messages stay on-platform and are monitored for safety.</div>
        <?php endif; ?>

      </div>
    </div>

    <!-- Owner card -->
    <div class="owner-card">
      <div class="oc-row">
        <div class="oc-avatar"><?= strtoupper(substr($listing['owner_first'],0,1)) ?></div>
        <div>
          <div class="oc-name">
            <?= e($listing['owner_first'].' '.$listing['owner_last']) ?>
            <?php if ($listing['owner_verified']): ?>
              <span style="font-size:.64rem;background:rgba(22,163,74,.1);color:var(--green);padding:1px 7px;border-radius:100px;font-weight:600;margin-left:4px">✓</span>
            <?php endif; ?>
          </div>
          <div class="oc-role">Room owner · Member <?= e($ownerAge) ?></div>
        </div>
      </div>
      <div class="oc-stats">
        <div class="ocs"><div class="ocs-val"><?= $listing['owner_verified']?'✓':'✗' ?></div><div class="ocs-label">Verified</div></div>
        <div class="ocs"><div class="ocs-val"><?= e($ownerAge) ?></div><div class="ocs-label">Member</div></div>
        <div class="ocs"><div class="ocs-val"><?= $recentlyActive?'🟢':'⚪' ?></div><div class="ocs-label"><?= $recentlyActive?'Active':'Away' ?></div></div>
      </div>
    </div>

    <!-- Save button (students only) -->
    <?php if ($isLoggedIn && $userRole === 'student'): ?>
      <div class="save-row">
        <form method="POST" action="save-listing.php" style="flex:1">
          <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
          <input type="hidden" name="listing_id"  value="<?= $listingId ?>"/>
          <button type="submit" class="save-btn <?= $isSaved?'saved':'' ?>">
            <?= $isSaved ? '❤️ Saved' : '🤍 Save Listing' ?>
          </button>
        </form>
      </div>
    <?php endif; ?>

  </div><!-- /sticky col -->

</div><!-- /page-wrap -->

<script>
  // ── Photo Gallery Navigation ──────────────────────────────
  var galleryPhotos = <?php
    // Pass the valid photo paths to JavaScript as a JSON array
    $validForJs = [];
    if (!empty($photos)) {
        foreach ($photos as $p) {
            if (file_exists($p)) {
                $validForJs[] = $p;
            }
        }
    }
    echo json_encode($validForJs);
  ?>;
  var galleryIndex = 0;

  function galleryNext() {
    if (galleryPhotos.length < 2) return;
    galleryIndex = (galleryIndex + 1) % galleryPhotos.length;
    updateGallery();
  }

  function galleryPrev() {
    if (galleryPhotos.length < 2) return;
    galleryIndex = (galleryIndex - 1 + galleryPhotos.length) % galleryPhotos.length;
    updateGallery();
  }

  function updateGallery() {
    var img = document.getElementById('gallery-img');
    var counter = document.getElementById('gallery-counter');
    if (img) img.src = galleryPhotos[galleryIndex];
    if (counter) counter.textContent = (galleryIndex + 1) + ' / ' + galleryPhotos.length;
  }

  // Keyboard navigation: left/right arrow keys
  document.addEventListener('keydown', function(e) {
    if (e.key === 'ArrowLeft') galleryPrev();
    if (e.key === 'ArrowRight') galleryNext();
  });

  // Description expand / collapse
  let expanded = false;
  function toggleDesc() {
    expanded = !expanded;
    document.getElementById('desc-text').classList.toggle('desc-clamp', !expanded);
    document.getElementById('read-more').textContent = expanded ? 'Show less ↑' : 'Read more ↓';
  }

  // Message templates
  const MSGS = {
    1: 'Hi <?= e($listing['owner_first']) ?>, I am an international student interested in your room in <?= e($listing['area']) ?>. Could we arrange a viewing at a convenient time for you? I am flexible on dates. Thank you!',
    2: 'Hello, I found your listing on BoligMatch. Is the room still available from <?= $listing['available_from'] ? date('d M Y',strtotime($listing['available_from'])) : 'now' ?>? I would love to hear more details.',
  };
  function setMsg(n) {
    const ta = document.getElementById('msg-body');
    if (ta) { ta.value = MSGS[n]; updateSendBtn(); ta.focus(); }
  }

  // Character counter + enable send button
  function updateSendBtn() {
    const ta  = document.getElementById('msg-body');
    const btn = document.getElementById('send-btn');
    const cc  = document.getElementById('char-count');
    if (!ta) return;
    const len = ta.value.length;
    if (cc)  cc.textContent = len;
    if (btn) btn.disabled   = len < 10 || len > 2000;
  }

  // Report modal
  function closeModal() {
    document.getElementById('report-modal').classList.remove('open');
  }
  document.addEventListener('keydown', e => { if (e.key==='Escape') closeModal(); });
</script>
</body>
</html>
