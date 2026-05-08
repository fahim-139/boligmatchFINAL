<?php
// ============================================================
//  home-owner.php — BoligMatch Owner Home Page
//  Shown immediately after an owner logs in.
//
//  Shows:
//    - Welcome header with stats
//    - Quick action buttons (create listing, view inbox)
//    - Their active listings with edit/delete actions
//    - Recent messages from students
//    - Any open reports against their listings
// ============================================================

session_start();
require_once 'db.php';

// ── Auth: owners only ────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    redirect('../frontend/login.html');
}
if ($_SESSION['user_role'] !== 'owner') {
    redirect('home-student.php');
}

$userId   = (int)$_SESSION['user_id'];
$userName = $_SESSION['user_name'];

// ── Fetch owner record ───────────────────────────────────────
$ownerStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$ownerStmt->execute([$userId]);
$owner = $ownerStmt->fetch();

if (!$owner || $owner['role'] === 'banned') {
    session_destroy();
    redirect('../frontend/login.html?error=banned');
}

expireOldListings($pdo);

// ── CSRF ─────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken(32);
}
$csrf = $_SESSION['csrf_token'];

// ── Stats ────────────────────────────────────────────────────
$listingsStmt = $pdo->prepare("
    SELECT * FROM listings WHERE owner_id = ? ORDER BY created_at DESC
");
$listingsStmt->execute([$userId]);
$listings = $listingsStmt->fetchAll();

$activeCount  = count(array_filter($listings, fn($l) => $l['status'] === 'active'));
$totalViews   = array_sum(array_column($listings, 'view_count'));
$unreadCount  = getUnreadCount($pdo, $userId);

// ── Recent messages (latest 5 threads) ──────────────────────
$msgsStmt = $pdo->prepare("
    SELECT m.id, m.body, m.is_read, m.created_at,
           m.listing_id,
           l.title AS listing_title,
           u.first_name, u.last_name, u.id AS sender_id
    FROM   messages m
    JOIN   users    u ON u.id = m.sender_id
    JOIN   listings l ON l.id = m.listing_id
    WHERE  m.receiver_id = ?
    ORDER  BY m.created_at DESC
    LIMIT  5
");
$msgsStmt->execute([$userId]);
$recentMessages = $msgsStmt->fetchAll();

// ── Open reports against their listings ──────────────────────
$reportsStmt = $pdo->prepare("
    SELECT r.id, r.reason, r.created_at, l.title AS listing_title, l.id AS listing_id
    FROM   reports  r
    JOIN   listings l ON l.id = r.listing_id
    WHERE  l.owner_id = ? AND r.status = 'open'
    ORDER  BY r.created_at DESC
");
$reportsStmt->execute([$userId]);
$openReports = $reportsStmt->fetchAll();

// ── Flash from previous action ───────────────────────────────
$flash     = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// ── Helpers ──────────────────────────────────────────────────
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
  <title>My BoligMatch — <?= e($userName) ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root {
      --navy:#0d1b2a; --teal:#1a7f6e; --teal-light:#22a08a;
      --teal-pale:rgba(26,127,110,.08); --cream:#f7f3ee;
      --warm-white:#fdfaf6; --gold:#c9a84c; --gold-pale:rgba(201,168,76,.1);
      --text:#0d1b2a; --text-muted:#6b7280; --border:#e5e0d8;
      --red:#dc2626; --red-pale:rgba(220,38,38,.07); --green:#16a34a;
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

    /* HERO WELCOME */
    .hero{background:var(--navy);padding:40px 40px 48px;position:relative;overflow:hidden;}
    .hero::after{content:'';position:absolute;right:-60px;top:-60px;width:300px;height:300px;border-radius:50%;background:rgba(26,127,110,.08);pointer-events:none;}
    .hero-inner{max-width:1160px;margin:0 auto;position:relative;z-index:1;}
    .hero-top{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:20px;}
    .hero-greeting{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--teal-light);margin-bottom:8px;}
    .hero-name{font-family:'Playfair Display',serif;font-size:2.1rem;font-weight:700;color:white;line-height:1.2;margin-bottom:6px;}
    .hero-sub{font-size:.9rem;color:rgba(255,255,255,.5);line-height:1.6;}
    .hero-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;}
    .ha-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:.88rem;font-weight:700;text-decoration:none;transition:all .2s;cursor:pointer;border:none;}
    .ha-btn.primary{background:var(--teal);color:white;}
    .ha-btn.primary:hover{background:var(--teal-light);}
    .ha-btn.outline{background:transparent;color:rgba(255,255,255,.8);border:1.5px solid rgba(255,255,255,.2);}
    .ha-btn.outline:hover{background:rgba(255,255,255,.08);color:white;}

    /* STAT CARDS */
    .stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:28px;}
    .stat-card{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);border-radius:10px;padding:16px 20px;transition:border-color .2s;}
    .stat-card:hover{border-color:rgba(26,127,110,.4);}
    .sc-val{font-family:'Playfair Display',serif;font-size:1.9rem;font-weight:700;color:white;line-height:1;}
    .sc-label{font-size:.74rem;color:rgba(255,255,255,.45);margin-top:5px;}
    .sc-icon{font-size:1.2rem;margin-bottom:8px;}
    .sc-zero{color:rgba(255,255,255,.25);}

    /* VERIFY BANNER */
    .verify-banner{background:rgba(201,168,76,.12);border-bottom:1px solid rgba(201,168,76,.2);padding:12px 40px;display:flex;align-items:center;gap:12px;}
    .vb-text{font-size:.82rem;color:#7c5a00;line-height:1.5;}
    .vb-text a{color:#7c5a00;font-weight:700;}
    .vb-text a:hover{text-decoration:underline;}

    /* FLASH */
    .flash{padding:12px 40px;font-size:.83rem;display:flex;align-items:center;gap:10px;}
    .flash-success{background:rgba(22,163,74,.08);border-bottom:1px solid rgba(22,163,74,.15);color:var(--green);}
    .flash-error  {background:var(--red-pale);border-bottom:1px solid rgba(220,38,38,.15);color:var(--red);}

    /* MAIN LAYOUT */
    .main{max-width:1160px;margin:0 auto;padding:32px 40px 60px;display:grid;grid-template-columns:1fr 340px;gap:28px;align-items:start;}

    /* SECTION */
    .section{margin-bottom:24px;}
    .section-title{font-family:'Playfair Display',serif;font-size:1.15rem;font-weight:700;color:var(--navy);margin-bottom:16px;display:flex;align-items:center;gap:10px;}
    .section-title span{font-family:'DM Sans',sans-serif;font-size:.7rem;font-weight:600;background:var(--cream);border:1px solid var(--border);color:var(--text-muted);padding:2px 9px;border-radius:100px;}

    /* LISTING CARD */
    .listing-card{background:white;border:1.5px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:12px;transition:box-shadow .2s;}
    .listing-card:hover{box-shadow:0 4px 20px rgba(13,27,42,.07);}
    .lc-body{display:flex;align-items:center;gap:16px;padding:16px 18px;}
    .lc-img{width:64px;height:64px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.6rem;background:linear-gradient(135deg,#afc8da,#c8d8e8);overflow:hidden;}
    .lc-img img{width:100%;height:100%;object-fit:cover;}
    .lc-info{flex:1;min-width:0;}
    .lc-title{font-size:.92rem;font-weight:700;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:4px;}
    .lc-meta{font-size:.74rem;color:var(--text-muted);display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
    .lc-price{font-size:.88rem;font-weight:700;color:var(--teal);flex-shrink:0;}
    .lc-status{display:inline-flex;align-items:center;gap:4px;font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:100px;}
    .lc-status.active {background:rgba(22,163,74,.1);color:var(--green);}
    .lc-status.pending{background:var(--gold-pale);color:#7c5a00;}
    .lc-status.expired{background:var(--red-pale);color:var(--red);}
    .lc-actions{display:flex;gap:7px;padding:0 18px 14px;padding-left:98px;}
    .lc-btn{padding:6px 13px;border-radius:6px;font-family:'DM Sans',sans-serif;font-size:.76rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:5px;}
    .lc-btn.edit  {background:var(--teal-pale);color:var(--teal);border:1px solid rgba(26,127,110,.2);}
    .lc-btn.edit:hover{background:var(--teal);color:white;}
    .lc-btn.view  {background:var(--cream);color:var(--text-muted);border:1px solid var(--border);}
    .lc-btn.view:hover{border-color:var(--teal);color:var(--teal);}
    .lc-btn.msg   {background:var(--navy);color:white;border:1px solid var(--navy);}
    .lc-btn.msg:hover{background:var(--slate);}

    /* Empty listing state */
    .empty-listings{text-align:center;padding:48px 24px;background:white;border-radius:12px;border:2px dashed var(--border);}
    .el-icon{font-size:2.5rem;margin-bottom:14px;opacity:.3;}
    .el-title{font-size:1rem;font-weight:700;color:var(--navy);margin-bottom:6px;}
    .el-sub{font-size:.82rem;color:var(--text-muted);line-height:1.6;margin-bottom:20px;}
    .el-btn{display:inline-flex;align-items:center;gap:8px;padding:12px 24px;background:var(--teal);color:white;border-radius:8px;text-decoration:none;font-weight:700;font-size:.88rem;transition:background .2s;}
    .el-btn:hover{background:var(--teal-light);}

    /* MESSAGE ROW */
    .msg-row{display:flex;align-items:flex-start;gap:12px;padding:13px 18px;border-radius:10px;border:1.5px solid var(--border);background:white;margin-bottom:8px;text-decoration:none;color:inherit;transition:all .18s;}
    .msg-row:hover{border-color:var(--teal);background:var(--teal-pale);}
    .msg-row.unread{border-left:3px solid var(--teal);}
    .mr-avatar{width:38px;height:38px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,var(--teal),#0a2a40);display:flex;align-items:center;justify-content:center;color:white;font-family:'Playfair Display',serif;font-weight:700;font-size:.95rem;}
    .mr-body{flex:1;min-width:0;}
    .mr-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:3px;}
    .mr-name{font-size:.86rem;font-weight:700;color:var(--navy);}
    .mr-time{font-size:.68rem;color:var(--text-muted);flex-shrink:0;}
    .mr-listing{font-size:.73rem;color:var(--teal);margin-bottom:3px;}
    .mr-preview{font-size:.78rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .mr-unread-dot{width:8px;height:8px;border-radius:50%;background:var(--teal);flex-shrink:0;margin-top:5px;}

    /* REPORT ROW */
    .report-row{display:flex;align-items:flex-start;gap:12px;padding:13px 16px;border-radius:9px;background:var(--red-pale);border:1px solid rgba(220,38,38,.2);margin-bottom:8px;}
    .rr-icon{font-size:1.2rem;flex-shrink:0;}
    .rr-title{font-size:.84rem;font-weight:700;color:var(--red);margin-bottom:2px;}
    .rr-sub  {font-size:.75rem;color:var(--text-muted);line-height:1.4;}

    /* RIGHT SIDEBAR */
    .right-sidebar{position:sticky;top:78px;display:flex;flex-direction:column;gap:16px;}

    /* Quick tips card */
    .tips-card{background:var(--navy);border-radius:12px;padding:20px 22px;}
    .tc-title{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:rgba(255,255,255,.35);margin-bottom:14px;}
    .tc-item{display:flex;align-items:flex-start;gap:10px;padding:9px 0;border-bottom:1px solid rgba(255,255,255,.06);font-size:.8rem;color:rgba(255,255,255,.55);line-height:1.45;}
    .tc-item:last-child{border-bottom:none;padding-bottom:0;}
    .tc-item strong{color:white;display:block;margin-bottom:1px;font-size:.82rem;}

    /* Profile card */
    .profile-card{background:white;border:1.5px solid var(--border);border-radius:12px;padding:18px 20px;}
    .pc-top{display:flex;align-items:center;gap:12px;margin-bottom:14px;}
    .pc-avatar{width:44px;height:44px;border-radius:10px;background:linear-gradient(135deg,var(--teal),#0a2a40);color:white;font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .pc-name{font-size:.92rem;font-weight:700;color:var(--navy);}
    .pc-role{font-size:.72rem;color:var(--text-muted);margin-top:2px;}
    .pc-links{display:flex;flex-direction:column;gap:6px;}
    .pc-link{display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:7px;border:1px solid var(--border);text-decoration:none;font-size:.8rem;color:var(--text-muted);transition:all .15s;}
    .pc-link:hover{border-color:var(--teal);color:var(--teal);background:var(--teal-pale);}
    .pc-link-badge{background:var(--red);color:white;font-size:.6rem;font-weight:700;padding:1px 6px;border-radius:100px;}

    @keyframes fadeUp{from{opacity:0;transform:translateY(14px);}to{opacity:1;transform:translateY(0);}}
    .listing-card,.msg-row,.section{animation:fadeUp .4s ease both;}
    .listing-card:nth-child(1){animation-delay:.05s;}
    .listing-card:nth-child(2){animation-delay:.10s;}
    .listing-card:nth-child(3){animation-delay:.15s;}
  </style>
</head>
<body>

<!-- NAV -->
<nav>
  <a href="home-owner.php" class="logo">Bolig<span>Match</span></a>
  <div class="nav-right">
    <span class="nav-greet">👋 <?= e($userName) ?></span>
    <a href="inbox.php" class="nav-btn ghost nav-badge">
      💬 Inbox
      <?php if ($unreadCount > 0): ?><span class="badge-dot"></span><?php endif; ?>
    </a>
    <a href="create-listing.php" class="nav-btn solid">+ List a Room</a>
    <a href="logout.php?token=<?= $csrf ?>" class="nav-btn ghost">Sign Out</a>
  </div>
</nav>

<?php if (!$owner['email_verified']): ?>
<!-- VERIFY BANNER -->
<div class="verify-banner">
  <span>⚠️</span>
  <div class="vb-text">
    Your email address hasn't been verified yet.
    <strong>Verify your email to unlock full messaging.</strong>
    <a href="verify-otp.php?resend=1">Resend verification email →</a>
  </div>
</div>
<?php endif; ?>

<?php
// Check ownership verification status
$isOwnershipVerified = !empty($owner['ownership_verified']);
$hasDocument         = !empty($owner['ownership_document']);
$wasRejected         = !empty($owner['ownership_rejected']);
?>

<?php if (!$isOwnershipVerified && !$hasDocument && !$wasRejected): ?>
<!-- OWNERSHIP UPLOAD BANNER — no document yet -->
<div class="verify-banner" style="background:rgba(201,168,76,.15);border-color:#c9a84c;color:#7c5a00;">
  <span>📄</span>
  <div class="vb-text">
    <strong>Upload your property ownership document.</strong>
    Your listings will not be visible to students until an admin verifies your ownership.
    <a href="upload-document.php" style="color:#7c5a00;font-weight:700;">Upload now →</a>
  </div>
</div>
<?php elseif (!$isOwnershipVerified && $hasDocument): ?>
<!-- OWNERSHIP PENDING BANNER -->
<div class="verify-banner" style="background:rgba(26,127,110,.1);border-color:#1a7f6e;color:#0d5e50;">
  <span>⏳</span>
  <div class="vb-text">
    <strong>Your ownership document is under admin review.</strong>
    This usually takes 1–2 business days. Your listings will become visible to students once approved.
  </div>
</div>
<?php elseif (!$isOwnershipVerified && $wasRejected): ?>
<!-- OWNERSHIP REJECTED BANNER -->
<div class="verify-banner" style="background:rgba(220,38,38,.1);border-color:#dc2626;color:#a01020;">
  <span>✗</span>
  <div class="vb-text">
    <strong>Your ownership document was rejected.</strong>
    Please upload a clearer document or a valid ownership proof.
    <a href="upload-document.php" style="color:#a01020;font-weight:700;">Upload new document →</a>
  </div>
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
    <div class="hero-top">
      <div>
        <div class="hero-greeting">Owner Dashboard</div>
        <div class="hero-name">Welcome back,<br/><?= e($owner['first_name'].' '.$owner['last_name']) ?></div>
        <div class="hero-sub">Manage your listings, reply to students, and keep your rooms up to date.</div>
        <div class="hero-actions">
          <a href="create-listing.php" class="ha-btn primary">🏠 List a New Room</a>
          <a href="inbox.php"          class="ha-btn outline">
            💬 Inbox <?php if ($unreadCount > 0): ?>(<?= $unreadCount ?> new)<?php endif; ?>
          </a>
        </div>
      </div>
    </div>

    <!-- Stats -->
    <div class="stats-row">
      <div class="stat-card">
        <div class="sc-icon">🏠</div>
        <div class="sc-val <?= $activeCount===0?'sc-zero':'' ?>"><?= $activeCount ?: '0' ?></div>
        <div class="sc-label">Active listings</div>
      </div>
      <div class="stat-card">
        <div class="sc-icon">👁️</div>
        <div class="sc-val <?= $totalViews===0?'sc-zero':'' ?>"><?= number_format($totalViews) ?></div>
        <div class="sc-label">Total views</div>
      </div>
      <div class="stat-card">
        <div class="sc-icon">💬</div>
        <div class="sc-val <?= $unreadCount===0?'sc-zero':'' ?>"><?= $unreadCount ?: '0' ?></div>
        <div class="sc-label">Unread messages</div>
      </div>
      <div class="stat-card">
        <div class="sc-icon">🛡️</div>
        <div class="sc-val <?= count($openReports)===0?'':'' ?>" style="color:<?= count($openReports)>0?'#ef4444':'white' ?>">
          <?= count($openReports) ?>
        </div>
        <div class="sc-label">Open reports</div>
      </div>
    </div>
  </div>
</div>

<!-- MAIN CONTENT -->
<div class="main">

  <!-- LEFT COLUMN -->
  <div>

    <!-- My Listings -->
    <div class="section">
      <div class="section-title">
        🏠 My Listings <span><?= count($listings) ?></span>
      </div>

      <?php if (empty($listings)): ?>
        <div class="empty-listings">
          <div class="el-icon">🏠</div>
          <div class="el-title">No listings yet</div>
          <div class="el-sub">Post your first room in under 5 minutes. Add photos, set your deposit clearly, and students will start messaging you.</div>
          <a href="create-listing.php" class="el-btn">🏠 Create Your First Listing</a>
        </div>

      <?php else: ?>
        <?php foreach ($listings as $l):
          // Find a usable cover photo
          $cover = null;
          $photos = json_decode($l['photos'] ?? '[]', true) ?: [];
          foreach ($photos as $p) {
            if (file_exists(__DIR__ . '/' . $p)) { $cover = $p; break; }
          }
        ?>
          <div class="listing-card">
            <div class="lc-body">
              <div class="lc-img">
                <?php if ($cover): ?>
                  <img src="<?= e($cover) ?>" alt="<?= e($l['title']) ?>" loading="lazy"/>
                <?php else: ?>
                  🏠
                <?php endif; ?>
              </div>
              <div class="lc-info">
                <div class="lc-title"><?= e($l['title']) ?></div>
                <div class="lc-meta">
                  <span>📍 <?= e($l['city']) ?> · <?= e($l['area']) ?></span>
                  <span>👁 <?= (int)$l['view_count'] ?> views</span>
                  <span>📅 <?= date('d M Y', strtotime($l['created_at'])) ?></span>
                  <span class="lc-status <?= $l['status'] ?>"><?= ucfirst($l['status']) ?></span>
                </div>
              </div>
              <div class="lc-price">DKK <?= number_format($l['rent_monthly']) ?><br/><small style="font-size:.7rem;font-weight:400;color:var(--text-muted)">/month</small></div>
            </div>
            <div class="lc-actions">
              <a href="edit-listing.php?id=<?= $l['id'] ?>"        class="lc-btn edit">✏️ Edit</a>
              <a href="listing-detail.php?id=<?= $l['id'] ?>"       class="lc-btn view">👁 View</a>
              <a href="inbox.php?listing=<?= $l['id'] ?>"           class="lc-btn msg">💬 Messages</a>
            </div>
          </div>
        <?php endforeach; ?>

        <div style="margin-top:8px">
          <a href="create-listing.php" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;background:white;color:var(--teal);border:1.5px solid rgba(26,127,110,.3);border-radius:8px;text-decoration:none;font-size:.84rem;font-weight:600;transition:all .2s" onmouseover="this.style.background='var(--teal)';this.style.color='white'" onmouseout="this.style.background='white';this.style.color='var(--teal)'">
            + Add Another Listing
          </a>
        </div>
      <?php endif; ?>
    </div>

    <!-- Recent Messages -->
    <div class="section">
      <div class="section-title">
        💬 Recent Messages
        <?php if ($unreadCount > 0): ?><span><?= $unreadCount ?> unread</span><?php endif; ?>
      </div>

      <?php if (empty($recentMessages)): ?>
        <div style="background:white;border:1.5px solid var(--border);border-radius:10px;padding:32px;text-align:center;">
          <div style="font-size:1.8rem;margin-bottom:10px;opacity:.3">💬</div>
          <div style="font-size:.88rem;font-weight:600;color:var(--text-muted)">No messages yet</div>
          <div style="font-size:.78rem;color:#c0bab2;margin-top:4px">When students contact you about your listings, messages will appear here.</div>
        </div>
      <?php else: ?>
        <?php foreach ($recentMessages as $msg): ?>
          <a href="inbox.php?listing=<?= $msg['listing_id'] ?>&user=<?= $msg['sender_id'] ?>"
             class="msg-row <?= !$msg['is_read']?'unread':'' ?>">
            <div class="mr-avatar"><?= strtoupper(substr($msg['first_name'],0,1)) ?></div>
            <div class="mr-body">
              <div class="mr-top">
                <span class="mr-name"><?= e($msg['first_name'].' '.$msg['last_name']) ?></span>
                <span class="mr-time"><?= timeAgo($msg['created_at']) ?></span>
              </div>
              <div class="mr-listing">Re: <?= e($msg['listing_title']) ?></div>
              <div class="mr-preview"><?= e(substr($msg['body'],0,90)) ?><?= strlen($msg['body'])>90?'…':'' ?></div>
            </div>
            <?php if (!$msg['is_read']): ?><div class="mr-unread-dot"></div><?php endif; ?>
          </a>
        <?php endforeach; ?>
        <a href="inbox.php" style="display:block;text-align:center;padding:10px;font-size:.81rem;color:var(--teal);font-weight:600;text-decoration:none;border:1px solid rgba(26,127,110,.2);border-radius:8px;background:var(--teal-pale);transition:all .2s" onmouseover="this.style.background='var(--teal)';this.style.color='white'" onmouseout="this.style.background='var(--teal-pale)';this.style.color='var(--teal)'">
          View All Messages →
        </a>
      <?php endif; ?>
    </div>

    <!-- Open Reports -->
    <?php if (!empty($openReports)): ?>
    <div class="section">
      <div class="section-title" style="color:var(--red)">⚠️ Open Reports <span><?= count($openReports) ?></span></div>
      <?php foreach ($openReports as $r): ?>
        <div class="report-row">
          <div class="rr-icon">🚩</div>
          <div>
            <div class="rr-title">Report on "<?= e($r['listing_title']) ?>"</div>
            <div class="rr-sub">Reason: <?= e(str_replace('_',' ',ucfirst($r['reason']))) ?> · Filed <?= timeAgo($r['created_at']) ?> · Under admin review.</div>
          </div>
        </div>
      <?php endforeach; ?>
      <div style="font-size:.78rem;color:var(--text-muted);margin-top:6px;line-height:1.5">Reports are reviewed by admins. If a listing is found to violate platform rules it may be removed. Ensure all your listings are accurate.</div>
    </div>
    <?php endif; ?>

  </div><!-- /left column -->

  <!-- RIGHT SIDEBAR -->
  <aside class="right-sidebar">

    <!-- Profile quick view -->
    <div class="profile-card">
      <div class="pc-top">
        <div class="pc-avatar"><?= strtoupper(substr($owner['first_name'],0,1)) ?></div>
        <div>
          <div class="pc-name"><?= e($owner['first_name'].' '.$owner['last_name']) ?></div>
          <div class="pc-role">
            Room Owner
            <?php if ($owner['email_verified']): ?>
              · <span style="color:var(--green);font-weight:600">✓ Verified</span>
            <?php else: ?>
              · <span style="color:var(--red);font-weight:600">✗ Unverified</span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="pc-links">
        <a href="create-listing.php"     class="pc-link">🏠 Create new listing <span>→</span></a>
        <a href="inbox.php"              class="pc-link">
          💬 My inbox
          <?php if ($unreadCount > 0): ?><span class="pc-link-badge"><?= $unreadCount ?></span><?php endif; ?>
        </a>
        <a href="dashboard.php?tab=profile" class="pc-link">⚙️ Account settings <span>→</span></a>
        <a href="logout.php?token=<?= $csrf ?>" class="pc-link" style="color:var(--text-muted)">🚪 Sign out <span>→</span></a>
      </div>
    </div>

    <!-- Tips -->
    <div class="tips-card">
      <div class="tc-title">🏆 Owner Tips</div>
      <div class="tc-item"><strong>Add photos</strong>Listings with 3+ photos get 5× more student enquiries.</div>
      <div class="tc-item"><strong>Set a low deposit</strong>1-month deposit listings attract significantly more interest than 3-month.</div>
      <div class="tc-item"><strong>Reply quickly</strong>Students are often searching urgently before semester starts.</div>
      <div class="tc-item"><strong>Keep it current</strong>Mark your listing as unavailable once rented. Listings auto-expire after 90 days.</div>
      <div class="tc-item"><strong>Write a contract</strong>Danish Rent Act requires a written lease — protect yourself and your tenant.</div>
    </div>

  </aside>

</div><!-- /main -->

</body>
</html>
