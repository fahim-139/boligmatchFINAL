<?php
// ================================================================
//  create-listing.php — Create a New Room Listing
//
//  Handles both GET (show the form) and POST (save to database).
//  Owners only. Students and guests are redirected away.
//
//  Security:
//    ✓ Session auth — owners only
//    ✓ CSRF token on the form
//    ✓ Full server-side validation
//    ✓ Photo upload: MIME type check (not just extension)
//    ✓ Photo upload: file size limit (5MB per photo)
//    ✓ Safe filenames (no user input in filenames)
//    ✓ Listing limit per owner (2 unverified / 10 verified)
//    ✓ "Too good to be true" price flagging
// ================================================================

session_start();
require_once 'db.php';

// ── Auth: owners only ────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    redirect('../frontend/login.html');
}
if ($_SESSION['user_role'] !== 'owner') {
    redirect('home-student.php?error=owners_only');
}

$userId = (int)$_SESSION['user_id'];

// Fetch owner
$ownerStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$ownerStmt->execute([$userId]);
$owner = $ownerStmt->fetch();

if (!$owner || $owner['role'] === 'banned') {
    session_destroy();
    redirect('../frontend/login.html?error=banned');
}

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken(32);
}
$csrf = $_SESSION['csrf_token'];

// Check listing limit (unverified: 2, verified: 10)
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM listings WHERE owner_id = ?");
$countStmt->execute([$userId]);
$existingCount = (int)$countStmt->fetchColumn();
$limit         = $owner['email_verified'] ? 10 : 2;
$atLimit       = $existingCount >= $limit;

// Average rents per city — used to flag suspiciously cheap listings
$avgRents = [
    'Copenhagen' => 4500,
    'Aarhus'     => 3500,
    'Aalborg'    => 3000,
    'Odense'     => 3200,
];

// ── Form state ────────────────────────────────────────────────
$errors     = [];
$success    = false;
$newId      = null;
$old        = [];  // repopulate form on error

// ================================================================
//  POST HANDLER
// ================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';
    }

    // Listing limit
    if ($atLimit) {
        $errors[] = $owner['email_verified']
            ? "You have reached the listing limit ({$limit}). Contact support to increase it."
            : "Unverified accounts can post up to {$limit} listings. Verify your email to post more.";
    }

    if (empty($errors)) {

        // ── Collect all inputs ────────────────────────────────
        $old = $_POST; // save for form repopulation if validation fails

        $title         = trim($_POST['title']         ?? '');
        $description   = trim($_POST['description']   ?? '');
        $city          = trim($_POST['city']           ?? '');
        $area          = trim($_POST['area']           ?? '');
        $addressFull   = trim($_POST['address_full']   ?? '');
        $roomType      = in_array($_POST['room_type'] ?? '', ['single','double']) ? $_POST['room_type'] : 'single';
        $roomSizeM2    = (int)($_POST['room_size_m2']    ?? 0);
        $totalFlatmates= (int)($_POST['total_flatmates'] ?? 1);
        $bathroomType  = in_array($_POST['bathroom_type'] ?? '', ['private','shared']) ? $_POST['bathroom_type'] : 'shared';
        $rentMonthly   = (float)($_POST['rent_monthly']  ?? 0);
        $depositMonths = (int)($_POST['deposit_months']  ?? 1);
        $availableFrom = trim($_POST['available_from']   ?? '');
        $minLease      = (int)($_POST['min_lease_months']?? 3);

        // Boolean amenity checkboxes
        $billsIncluded  = isset($_POST['bills_included'])   ? 1 : 0;
        $furnished      = isset($_POST['furnished'])         ? 1 : 0;
        $wifi           = isset($_POST['wifi'])              ? 1 : 0;
        $washingMachine = isset($_POST['washing_machine'])   ? 1 : 0;
        $parking        = isset($_POST['parking'])           ? 1 : 0;
        $petsAllowed    = isset($_POST['pets_allowed'])      ? 1 : 0;
        $balcony        = isset($_POST['balcony'])           ? 1 : 0;

        // ── Validate ──────────────────────────────────────────
        $allowedCities = ['Copenhagen','Aarhus','Aalborg','Odense'];

        if (strlen($title) < 10 || strlen($title) > 200)
            $errors[] = 'Title must be between 10 and 200 characters.';
        if (strlen($description) < 50)
            $errors[] = 'Description must be at least 50 characters. Be detailed to build trust with students.';
        if (strlen($description) > 3000)
            $errors[] = 'Description cannot exceed 3,000 characters.';
        if (!in_array($city, $allowedCities))
            $errors[] = 'Please select a valid city.';
        if (strlen($area) < 2 || strlen($area) > 100)
            $errors[] = 'Neighbourhood / area is required.';
        if ($rentMonthly < 1000 || $rentMonthly > 30000)
            $errors[] = 'Monthly rent must be between DKK 1,000 and DKK 30,000.';
        if (!in_array($depositMonths, [1,2,3]))
            $errors[] = 'Deposit must be 1, 2, or 3 months.';
        if ($totalFlatmates < 1 || $totalFlatmates > 10)
            $errors[] = 'Total occupants must be between 1 and 10.';
        if ($minLease < 1 || $minLease > 24)
            $errors[] = 'Minimum lease must be between 1 and 24 months.';
        if ($availableFrom && !DateTime::createFromFormat('Y-m-d', $availableFrom))
            $errors[] = 'Please enter a valid available-from date.';
    }

    // ── Photo upload ──────────────────────────────────────────
    $uploadedPhotos = [];

    if (empty($errors) && !empty($_FILES['photos']['name'][0])) {

        $uploadDir    = __DIR__ . '/uploads/listings/';
        $allowedMimes = ['image/jpeg','image/png','image/webp'];
        $maxFileSize  = 5 * 1024 * 1024; // 5 MB
        $maxPhotos    = 8;

        // Create upload directory if it doesn't exist
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $files = $_FILES['photos'];
        $count = count($files['name']);

        if ($count > $maxPhotos) {
            $errors[] = "You can upload a maximum of {$maxPhotos} photos.";
        }

        if (empty($errors)) {
            for ($i = 0; $i < $count; $i++) {

                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;

                // Check file size
                if ($files['size'][$i] > $maxFileSize) {
                    $errors[] = "Photo " . ($i + 1) . " exceeds the 5 MB size limit.";
                    continue;
                }

                // Check actual MIME type using the file's binary content
                // (not just the file extension — extensions can be faked)
                $finfo    = finfo_open(FILEINFO_MIME_TYPE);
                $mimeType = finfo_file($finfo, $files['tmp_name'][$i]);
                finfo_close($finfo);

                if (!in_array($mimeType, $allowedMimes, true)) {
                    $errors[] = "Photo " . ($i + 1) . " must be a JPG, PNG, or WebP image.";
                    continue;
                }

                // Build a safe filename — never use the user's filename
                $ext      = match($mimeType) {
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    default      => 'jpg',
                };
                $safeName = 'listing_' . $userId . '_' . time() . '_' . $i . '.' . $ext;
                $destPath = $uploadDir . $safeName;

                if (move_uploaded_file($files['tmp_name'][$i], $destPath)) {
                    $uploadedPhotos[] = 'uploads/listings/' . $safeName;
                } else {
                    $errors[] = "Failed to save photo " . ($i + 1) . ". Check folder permissions.";
                }
            }
        }
    }

    // ── Insert into database ──────────────────────────────────
    if (empty($errors)) {

        // Determine listing status based on multiple factors:
        //  1. If owner ownership is NOT verified → pending (admin must approve)
        //  2. If rent is suspiciously low → pending
        //  3. Otherwise → active
        $avg    = $avgRents[$city] ?? 4000;

        if (empty($owner['ownership_verified'])) {
            $status = 'pending';
            $statusReason = 'ownership_not_verified';
        } elseif ($rentMonthly < $avg * 0.5) {
            $status = 'pending';
            $statusReason = 'low_rent';
        } else {
            $status = 'active';
            $statusReason = '';
        }

        try {
            $insertStmt = $pdo->prepare("
                INSERT INTO listings (
                    owner_id,      title,         description,
                    city,          area,          address_full,
                    room_type,     room_size_m2,  total_flatmates,
                    bathroom_type, rent_monthly,  deposit_months,
                    bills_included, furnished,    wifi,
                    washing_machine, parking,     pets_allowed,
                    balcony,       photos,        available_from,
                    min_lease_months, status,     view_count,
                    created_at
                ) VALUES (
                    :owner_id,     :title,        :description,
                    :city,         :area,         :address_full,
                    :room_type,    :room_size_m2, :total_flatmates,
                    :bathroom_type,:rent_monthly, :deposit_months,
                    :bills_incl,   :furnished,    :wifi,
                    :washing,      :parking,      :pets,
                    :balcony,      :photos,       :available_from,
                    :min_lease,    :status,       0,
                    NOW()
                )
            ");

            $insertStmt->execute([
                ':owner_id'      => $userId,
                ':title'         => $title,
                ':description'   => $description,
                ':city'          => $city,
                ':area'          => $area,
                ':address_full'  => $addressFull ?: null,
                ':room_type'     => $roomType,
                ':room_size_m2'  => $roomSizeM2  ?: null,
                ':total_flatmates'=> $totalFlatmates,
                ':bathroom_type' => $bathroomType,
                ':rent_monthly'  => $rentMonthly,
                ':deposit_months'=> $depositMonths,
                ':bills_incl'    => $billsIncluded,
                ':furnished'     => $furnished,
                ':wifi'          => $wifi,
                ':washing'       => $washingMachine,
                ':parking'       => $parking,
                ':pets'          => $petsAllowed,
                ':balcony'       => $balcony,
                ':photos'        => $uploadedPhotos ? json_encode($uploadedPhotos) : null,
                ':available_from'=> $availableFrom  ?: null,
                ':min_lease'     => $minLease,
                ':status'        => $status,
            ]);

            $newId   = (int)$pdo->lastInsertId();
            $success = true;

            // Refresh CSRF token after success
            $_SESSION['csrf_token'] = generateToken(32);
            $csrf = $_SESSION['csrf_token'];

            $successMsg = $status === 'pending'
                ? "Your listing has been submitted but is under review because the rent seems very low for {$city}. It will go live after admin approval."
                : "🎉 Your room has been listed! Students can now find and contact you.";

        } catch (PDOException $e) {
            error_log('create-listing DB error: ' . $e->getMessage());
            $errors[] = 'Failed to save your listing. Please try again.';
        }
    }
}

function sel(mixed $a, mixed $b): string { return $a == $b ? 'selected' : ''; }
function chk(mixed $v, mixed $field = null): string {
    if ($field !== null) return isset($_POST[$field]) ? 'checked' : '';
    return $v ? 'checked' : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>List a Room — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{--navy:#0d1b2a;--teal:#1a7f6e;--teal-light:#22a08a;--teal-pale:rgba(26,127,110,.08);--cream:#f7f3ee;--warm-white:#fdfaf6;--gold:#c9a84c;--text:#0d1b2a;--text-muted:#6b7280;--border:#e5e0d8;--red:#dc2626;--red-pale:rgba(220,38,38,.07);--green:#16a34a;}
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    html{scroll-behavior:smooth;}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--text);}
    nav{background:rgba(253,250,246,.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);padding:0 48px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
    .logo{font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:900;color:var(--navy);text-decoration:none;letter-spacing:-.5px;}
    .logo span{color:var(--teal);}
    .nav-r{display:flex;align-items:center;gap:10px;}
    .nav-link{font-size:.86rem;color:var(--text-muted);text-decoration:none;padding:7px 12px;border-radius:7px;transition:all .2s;}
    .nav-link:hover{color:var(--navy);background:var(--cream);}
    .nav-btn{padding:8px 18px;border-radius:7px;text-decoration:none;font-size:.86rem;font-weight:600;transition:background .2s;}
    .nav-btn.solid{background:var(--navy);color:white;} .nav-btn.solid:hover{background:var(--teal);}
    .nav-btn.ghost{border:1.5px solid var(--border);color:var(--text-muted);} .nav-btn.ghost:hover{border-color:var(--teal);color:var(--teal);}

    .page-wrap{max-width:860px;margin:0 auto;padding:36px 40px 64px;}
    .page-eyebrow{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--teal);margin-bottom:6px;display:flex;align-items:center;gap:7px;}
    .page-eyebrow a{color:var(--text-muted);text-decoration:none;} .page-eyebrow a:hover{color:var(--teal);}
    .page-h1{font-family:'Playfair Display',serif;font-size:2rem;font-weight:700;color:var(--navy);margin-bottom:6px;}
    .page-sub{font-size:.88rem;color:var(--text-muted);margin-bottom:28px;line-height:1.6;}

    .alert{padding:12px 16px;border-radius:9px;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;font-size:.85rem;line-height:1.55;}
    .alert-err {background:var(--red-pale);border:1px solid rgba(220,38,38,.2);color:var(--red);}
    .alert-ok  {background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.2);color:var(--green);}
    .alert-warn{background:rgba(201,168,76,.1);border:1px solid rgba(201,168,76,.25);color:#7c5a00;}
    .alert ul{margin:6px 0 0 16px;display:flex;flex-direction:column;gap:4px;}

    .section-card{background:white;border:1.5px solid var(--border);border-radius:13px;margin-bottom:20px;overflow:hidden;transition:box-shadow .2s;}
    .section-card:hover{box-shadow:0 4px 18px rgba(13,27,42,.06);}
    .sc-header{display:flex;align-items:center;gap:13px;padding:16px 22px;border-bottom:1px solid var(--border);}
    .sc-num{width:30px;height:30px;border-radius:7px;background:var(--navy);color:white;font-family:'Playfair Display',serif;font-size:.85rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .sc-title{font-size:.93rem;font-weight:700;color:var(--navy);}
    .sc-desc{font-size:.74rem;color:var(--text-muted);margin-top:1px;}
    .sc-body{padding:22px;}

    .form-row{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:16px;}
    .form-row.thirds{grid-template-columns:1fr 1fr 1fr;}
    .form-group{margin-bottom:0;}
    .form-group.full{grid-column:1/-1;}
    .fl{display:block;font-size:.71rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:7px;}
    .fl .req{color:var(--red);}
    .fi,.fs,.fta{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.88rem;color:var(--text);background:var(--warm-white);outline:none;transition:border-color .2s;}
    .fi:focus,.fs:focus,.fta:focus{border-color:var(--teal);background:white;}
    .fi::placeholder,.fta::placeholder{color:#c0bab2;}
    .fta{resize:vertical;min-height:110px;line-height:1.6;}
    .fhint{font-size:.7rem;color:var(--text-muted);margin-top:4px;line-height:1.4;}
    .char-count{font-size:.7rem;color:var(--text-muted);text-align:right;margin-top:3px;}

    /* DKK prefix input */
    .dkk-wrap{position:relative;}
    .dkk-pre{position:absolute;left:0;top:0;bottom:0;display:flex;align-items:center;padding:0 11px;font-size:.8rem;font-weight:600;color:var(--text-muted);border-right:1.5px solid var(--border);pointer-events:none;background:var(--cream);border-radius:6px 0 0 6px;}
    .dkk-wrap .fi{padding-left:56px;}

    /* Cost preview */
    .cost-preview{background:var(--navy);border-radius:9px;padding:14px 18px;margin-top:14px;display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
    .cp-item{text-align:center;}
    .cp-lbl{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:rgba(255,255,255,.38);margin-bottom:4px;}
    .cp-val{font-size:1rem;font-weight:700;color:white;}
    .cp-val.t{color:#22a08a;} .cp-val.g{color:#c9a84c;}

    /* Amenity toggles */
    .am-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;}
    .am-toggle{position:relative;}
    .am-toggle input{position:absolute;opacity:0;width:0;height:0;}
    .am-toggle label{display:flex;flex-direction:column;align-items:center;gap:5px;padding:12px 8px;border-radius:9px;border:1.5px solid var(--border);background:white;cursor:pointer;transition:all .18s;text-align:center;}
    .am-toggle label:hover{border-color:var(--teal);background:var(--teal-pale);}
    .am-toggle input:checked+label{border-color:var(--teal);background:var(--teal-pale);}
    .am-icon{font-size:1.2rem;}
    .am-name{font-size:.72rem;font-weight:600;color:var(--navy);}
    .am-toggle input:checked+label .am-name{color:var(--teal);}

    /* Photo drop zone */
    .drop-zone{border:2px dashed var(--border);border-radius:11px;padding:32px 20px;text-align:center;background:var(--warm-white);cursor:pointer;transition:all .2s;position:relative;}
    .drop-zone:hover,.drop-zone.over{border-color:var(--teal);background:var(--teal-pale);}
    .drop-zone input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
    .dz-icon{font-size:2rem;margin-bottom:8px;opacity:.4;}
    .dz-title{font-size:.9rem;font-weight:600;color:var(--navy);margin-bottom:4px;}
    .dz-sub{font-size:.76rem;color:var(--text-muted);}
    .photo-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:14px;}
    .photo-thumb{aspect-ratio:4/3;border-radius:7px;overflow:hidden;border:1.5px solid var(--border);background:var(--cream);position:relative;}
    .photo-thumb img{width:100%;height:100%;object-fit:cover;}
    .photo-rm{position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:50%;background:rgba(13,27,42,.7);color:white;border:none;font-size:.6rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:background .18s;}
    .photo-rm:hover{background:var(--red);}
    .photo-counter{font-size:.74rem;margin-top:7px;}
    .photo-counter.ok{color:var(--green);} .photo-counter.bad{color:var(--red);}

    /* Submit row */
    .submit-row{display:flex;align-items:center;justify-content:space-between;padding-top:20px;border-top:1px solid var(--border);margin-top:6px;gap:16px;}
    .submit-note{font-size:.76rem;color:var(--text-muted);max-width:280px;line-height:1.5;}
    .submit-btn{padding:13px 32px;background:var(--teal);color:white;border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;transition:background .2s;}
    .submit-btn:hover{background:var(--teal-light);}
    .submit-btn:disabled{opacity:.45;cursor:not-allowed;}

    /* Success overlay */
    .success-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(13,27,42,.8);align-items:center;justify-content:center;}
    .success-overlay.show{display:flex;}
    .success-box{background:white;border-radius:18px;padding:48px 44px;max-width:460px;width:90%;text-align:center;animation:popIn .4s ease;}
    @keyframes popIn{from{opacity:0;transform:scale(.88);}to{opacity:1;transform:scale(1);}}
    .sb-icon{font-size:3rem;margin-bottom:14px;}
    .sb-title{font-family:'Playfair Display',serif;font-size:1.6rem;color:var(--navy);margin-bottom:8px;}
    .sb-msg{font-size:.88rem;color:var(--text-muted);line-height:1.7;margin-bottom:26px;}
    .sb-btns{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;}
    .sb-btn-p{padding:12px 24px;background:var(--teal);color:white;border-radius:8px;text-decoration:none;font-weight:700;font-size:.9rem;}
    .sb-btn-s{padding:12px 24px;background:var(--cream);color:var(--navy);border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;border:1.5px solid var(--border);}

    @keyframes fadeUp{from{opacity:0;transform:translateY(13px);}to{opacity:1;transform:translateY(0);}}
    .section-card{animation:fadeUp .4s ease both;}
    .section-card:nth-child(1){animation-delay:.04s;}.section-card:nth-child(2){animation-delay:.08s;}
    .section-card:nth-child(3){animation-delay:.12s;}.section-card:nth-child(4){animation-delay:.16s;}
    .section-card:nth-child(5){animation-delay:.20s;}
  </style>
</head>
<body>

<!-- Success overlay -->
<?php if ($success): ?>
<div class="success-overlay show">
  <div class="success-box">
    <div class="sb-icon">🎉</div>
    <div class="sb-title">Room Listed!</div>
    <div class="sb-msg"><?= e($successMsg ?? '') ?></div>
    <div class="sb-btns">
      <a href="listing-detail.php?id=<?= $newId ?>" class="sb-btn-p">View My Listing →</a>
      <a href="home-owner.php" class="sb-btn-s">Back to Dashboard</a>
    </div>
  </div>
</div>
<?php endif; ?>

<nav>
  <a href="../frontend/index.html" class="logo">Bolig<span>Match</span></a>
  <div class="nav-r">
    <a href="home-owner.php" class="nav-link">← My Dashboard</a>
    <a href="logout.php?token=<?= $csrf ?>" class="nav-btn ghost">Sign Out</a>
  </div>
</nav>

<div class="page-wrap">
  <div class="page-eyebrow">
    <a href="home-owner.php">Dashboard</a> › List a New Room
  </div>
  <h1 class="page-h1">List your room</h1>
  <p class="page-sub">Complete all required fields. Detailed, honest listings receive significantly more student enquiries.</p>

  <?php if ($atLimit): ?>
    <div class="alert alert-err">
      <span>⚠️</span>
      <div>
        You have reached your listing limit (<?= $limit ?>).
        <?= $owner['email_verified'] ? 'Contact support to increase your limit.' : 'Verify your email to post more listings.' ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="alert alert-err">
      <span>⚠️</span>
      <div>
        <strong>Please fix the following:</strong>
        <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$atLimit): ?>
  <form method="POST" action="create-listing.php" enctype="multipart/form-data" id="create-form">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>

    <!-- ── Section 1: Room Details ── -->
    <div class="section-card">
      <div class="sc-header">
        <div class="sc-num">1</div>
        <div><div class="sc-title">Room Details</div><div class="sc-desc">Basic information about the room</div></div>
      </div>
      <div class="sc-body">

        <div class="form-group" style="margin-bottom:16px">
          <label class="fl">Title <span class="req">*</span></label>
          <input type="text" name="title" class="fi" id="f-title" maxlength="200"
                 placeholder="e.g. Bright Single Room in Shared Flat, Nørrebro"
                 value="<?= e($old['title'] ?? '') ?>" oninput="updateChars('f-title','tc',200)" required/>
          <div class="char-count"><span id="tc"><?= strlen($old['title'] ?? '') ?></span>/200</div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="fl">City <span class="req">*</span></label>
            <select name="city" class="fs" id="f-city" onchange="updateCostPreview()" required>
              <option value="">Select city…</option>
              <?php foreach (['Copenhagen','Aarhus','Aalborg','Odense'] as $c): ?>
                <option value="<?= $c ?>" <?= sel($old['city'] ?? '', $c) ?>><?= $c ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="fl">Neighbourhood / Area <span class="req">*</span></label>
            <input type="text" name="area" class="fi" placeholder="e.g. Nørrebro, Vesterbro, Frederiksbjerg"
                   value="<?= e($old['area'] ?? '') ?>" required/>
          </div>
        </div>

        <div class="form-group" style="margin-bottom:16px">
          <label class="fl">Full Address <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.7rem;color:var(--text-muted)">(optional — hidden from listing, shared only after contact)</span></label>
          <input type="text" name="address_full" class="fi" placeholder="e.g. Nørrebrogade 45, 3rd floor"
                 value="<?= e($old['address_full'] ?? '') ?>"/>
          <div class="fhint">🔒 Students won't see this until you decide to share it privately.</div>
        </div>

        <div class="form-row thirds">
          <div class="form-group">
            <label class="fl">Room Type <span class="req">*</span></label>
            <select name="room_type" class="fs" required>
              <option value="single" <?= sel($old['room_type'] ?? 'single','single') ?>>Single room</option>
              <option value="double" <?= sel($old['room_type'] ?? '','double') ?>>Double room</option>
            </select>
          </div>
          <div class="form-group">
            <label class="fl">Room Size (m²)</label>
            <input type="number" name="room_size_m2" class="fi" min="5" max="100"
                   placeholder="e.g. 16" value="<?= e($old['room_size_m2'] ?? '') ?>"/>
          </div>
          <div class="form-group">
            <label class="fl">Total Occupants <span class="req">*</span></label>
            <select name="total_flatmates" class="fs" required>
              <?php for ($n=1;$n<=8;$n++): ?>
                <option value="<?= $n ?>" <?= sel($old['total_flatmates'] ?? 2, $n) ?>>
                  <?= $n ?> <?= $n===1?'(just you)':'people total' ?>
                </option>
              <?php endfor; ?>
            </select>
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label class="fl">Bathroom <span class="req">*</span></label>
            <select name="bathroom_type" class="fs" required>
              <option value="shared"  <?= sel($old['bathroom_type'] ?? 'shared','shared') ?>>Shared bathroom</option>
              <option value="private" <?= sel($old['bathroom_type'] ?? '','private') ?>>Private (en-suite)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="fl">Pets Allowed?</label>
            <select name="pets_allowed" class="fs">
              <option value="0" <?= isset($old['pets_allowed']) && !$old['pets_allowed']?'selected':'' ?>>No pets</option>
              <option value="1" <?= isset($old['pets_allowed']) && $old['pets_allowed']?'selected':'' ?>>Pets welcome</option>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label class="fl">Description <span class="req">*</span></label>
          <textarea name="description" class="fta" id="f-desc" maxlength="3000"
            placeholder="Describe the room, flat, neighbourhood, flatmates, house rules…"
            oninput="updateChars('f-desc','dc',3000)" required><?= e($old['description'] ?? '') ?></textarea>
          <div style="display:flex;justify-content:space-between;margin-top:4px">
            <div class="fhint">Minimum 50 characters. More detail = more enquiries.</div>
            <div class="char-count"><span id="dc"><?= strlen($old['description'] ?? '') ?></span>/3000</div>
          </div>
        </div>

      </div>
    </div>

    <!-- ── Section 2: Costs & Availability ── -->
    <div class="section-card">
      <div class="sc-header">
        <div class="sc-num">2</div>
        <div><div class="sc-title">Upfront Costs & Availability</div><div class="sc-desc">Be transparent — students filter by deposit amount</div></div>
      </div>
      <div class="sc-body">
        <div class="form-row">
          <div class="form-group">
            <label class="fl">Monthly Rent (DKK) <span class="req">*</span></label>
            <div class="dkk-wrap">
              <span class="dkk-pre">DKK</span>
              <input type="number" name="rent_monthly" class="fi" id="f-rent"
                     min="1000" max="30000" step="50" placeholder="4500"
                     value="<?= e($old['rent_monthly'] ?? '') ?>"
                     oninput="updateCostPreview()" required/>
            </div>
          </div>
          <div class="form-group">
            <label class="fl">Deposit <span class="req">*</span></label>
            <select name="deposit_months" class="fs" id="f-dep" onchange="updateCostPreview()" required>
              <option value="1" <?= sel($old['deposit_months'] ?? 1,1) ?>>1 month's rent (lowest)</option>
              <option value="2" <?= sel($old['deposit_months'] ?? '',2) ?>>2 months' rent</option>
              <option value="3" <?= sel($old['deposit_months'] ?? '',3) ?>>3 months' rent (legal max)</option>
            </select>
            <div class="fhint">Lower deposits attract more students. Danish legal max is 3 months.</div>
          </div>
        </div>

        <!-- Live cost preview -->
        <div class="cost-preview">
          <div class="cp-item"><div class="cp-lbl">Monthly Rent</div><div class="cp-val t" id="cp-rent">—</div></div>
          <div class="cp-item"><div class="cp-lbl">Deposit (once)</div><div class="cp-val" id="cp-dep">—</div></div>
          <div class="cp-item"><div class="cp-lbl">Total to Move In</div><div class="cp-val g" id="cp-total">—</div></div>
        </div>

        <div class="form-row" style="margin-top:16px">
          <div class="form-group">
            <label class="fl">Available From <span class="req">*</span></label>
            <input type="date" name="available_from" class="fi"
                   min="<?= date('Y-m-d') ?>"
                   value="<?= e($old['available_from'] ?? date('Y-m-d')) ?>" required/>
          </div>
          <div class="form-group">
            <label class="fl">Minimum Lease <span class="req">*</span></label>
            <select name="min_lease_months" class="fs" required>
              <?php foreach ([1=>'1 month',2=>'2 months',3=>'3 months',6=>'6 months',12=>'12 months'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= sel($old['min_lease_months'] ?? 3,$v) ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Section 3: Amenities ── -->
    <div class="section-card">
      <div class="sc-header">
        <div class="sc-num">3</div>
        <div><div class="sc-title">Amenities</div><div class="sc-desc">Select everything that is included</div></div>
      </div>
      <div class="sc-body">
        <div class="am-grid">
          <?php
            $ams = [
              ['bills_included',  '💡','Bills Included'],
              ['furnished',       '🪑','Furnished'],
              ['wifi',            '📡','WiFi'],
              ['washing_machine', '🫧','Washing Machine'],
              ['parking',         '🅿️','Parking'],
              ['balcony',         '🌿','Balcony'],
            ];
            foreach ($ams as [$name,$icon,$label]):
              $checked = !empty($old) ? (isset($old[$name]) ? 'checked' : '') : ($name==='wifi'||$name==='furnished'?'checked':'');
          ?>
            <div class="am-toggle">
              <input type="checkbox" name="<?= $name ?>" id="am-<?= $name ?>" value="1" <?= $checked ?>/>
              <label for="am-<?= $name ?>">
                <span class="am-icon"><?= $icon ?></span>
                <span class="am-name"><?= $label ?></span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ── Section 4: Photos ── -->
    <div class="section-card">
      <div class="sc-header">
        <div class="sc-num">4</div>
        <div><div class="sc-title">Photos</div><div class="sc-desc">Listings with 3+ photos get 5× more enquiries</div></div>
      </div>
      <div class="sc-body">
        <div class="drop-zone" id="drop-zone">
          <input type="file" name="photos[]" id="photo-input" accept="image/jpeg,image/png,image/webp" multiple
                 onchange="handlePhotos(this)"/>
          <div class="dz-icon">📸</div>
          <div class="dz-title">Drag & drop photos here, or click to choose</div>
          <div class="dz-sub">JPG, PNG, WebP · Max 5 MB each · Up to 8 photos</div>
        </div>
        <div class="photo-grid" id="photo-grid"></div>
        <div class="photo-counter" id="photo-counter"></div>
      </div>
    </div>

    <!-- ── Section 5: Submit ── -->
    <div class="section-card">
      <div class="sc-header">
        <div class="sc-num">5</div>
        <div><div class="sc-title">Publish</div><div class="sc-desc">Review and go live</div></div>
      </div>
      <div class="sc-body">
        <div class="alert alert-warn" style="margin-bottom:18px">
          <span>🛡️</span>
          <div style="font-size:.82rem">
            By publishing this listing you confirm all information is accurate. Misleading listings will be removed.
            Students are entitled to a written contract under Danish Rent Act law.
          </div>
        </div>
        <div class="submit-row">
          <div class="submit-note">Your listing will be live immediately (unless rent seems unusually low for the city, in which case it is reviewed first).</div>
          <button type="submit" class="submit-btn" id="submit-btn">🚀 Publish Listing</button>
        </div>
      </div>
    </div>

  </form>
  <?php endif; ?>

</div><!-- /page-wrap -->

<script>
  // ── Character counters ─────────────────────────────────────
  function updateChars(inputId, countId, max) {
    const len = document.getElementById(inputId)?.value.length || 0;
    const el  = document.getElementById(countId);
    if (el) { el.textContent = len; el.style.color = (inputId==='f-desc' && len<50) ? 'var(--red)' : 'var(--text-muted)'; }
  }

  // ── Live cost preview ──────────────────────────────────────
  function updateCostPreview() {
    const rent = parseFloat(document.getElementById('f-rent')?.value) || 0;
    const dep  = parseInt(document.getElementById('f-dep')?.value)   || 1;
    const fmt  = n => n ? 'DKK ' + n.toLocaleString() : '—';
    document.getElementById('cp-rent').textContent  = fmt(rent);
    document.getElementById('cp-dep').textContent   = fmt(rent * dep);
    document.getElementById('cp-total').textContent = fmt(rent + rent * dep);
  }

  // ── Photo handling ─────────────────────────────────────────
  let selectedFiles = [];

  function handlePhotos(input) {
    selectedFiles = Array.from(input.files).slice(0, 8);
    renderPhotos();
  }

  function renderPhotos() {
    const grid = document.getElementById('photo-grid');
    const ctr  = document.getElementById('photo-counter');
    grid.innerHTML = '';
    selectedFiles.forEach((file, i) => {
      const reader = new FileReader();
      reader.onload = e => {
        const div = document.createElement('div');
        div.className = 'photo-thumb';
        div.innerHTML = `<img src="${e.target.result}" alt="Photo ${i+1}"/><button type="button" class="photo-rm" onclick="removePhoto(${i})">✕</button>`;
        grid.appendChild(div);
      };
      reader.readAsDataURL(file);
    });
    const n = selectedFiles.length;
    ctr.textContent  = n === 0 ? '' : n < 3 ? `⚠️ ${n} photo(s) — at least 3 recommended` : `✅ ${n} photo(s) selected`;
    ctr.className    = 'photo-counter ' + (n>=3?'ok':n>0?'bad':'');
  }

  function removePhoto(idx) {
    selectedFiles.splice(idx, 1);
    const dt = new DataTransfer();
    selectedFiles.forEach(f => dt.items.add(f));
    document.getElementById('photo-input').files = dt.files;
    renderPhotos();
  }

  // Drag & drop
  const dz = document.getElementById('drop-zone');
  if (dz) {
    dz.addEventListener('dragover',  e => { e.preventDefault(); dz.classList.add('over'); });
    dz.addEventListener('dragleave', ()  => dz.classList.remove('over'));
    dz.addEventListener('drop', e => {
      e.preventDefault(); dz.classList.remove('over');
      selectedFiles = Array.from(e.dataTransfer.files).filter(f=>f.type.startsWith('image/')).slice(0,8);
      const dt = new DataTransfer(); selectedFiles.forEach(f=>dt.items.add(f));
      document.getElementById('photo-input').files = dt.files;
      renderPhotos();
    });
  }

  // Prevent double-submit
  document.getElementById('create-form')?.addEventListener('submit', () => {
    const btn = document.getElementById('submit-btn');
    btn.disabled = true; btn.textContent = 'Publishing…';
  });

  // Init
  updateCostPreview();
</script>
</body>
</html>
