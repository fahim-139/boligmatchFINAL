<?php
// ================================================================
//  edit-listing.php — Edit or Delete a Listing
//
//  Handles GET (show pre-filled form) and POST (update/delete).
//  Ownership is verified on every query — an owner can only
//  ever edit their OWN listings, even if they guess another ID.
//
//  Actions (via hidden 'action' POST field):
//    update        — save changes to the listing
//    toggle_status — flip active ↔ expired (mark available/unavailable)
//    delete        — permanently remove the listing
//
//  Security:
//    ✓ Session auth — owners only
//    ✓ Ownership check: WHERE id=? AND owner_id=?
//    ✓ CSRF token on every POST action
//    ✓ Server-side validation (same as create-listing.php)
//    ✓ Photo MIME type + size validation on new uploads
// ================================================================

session_start();
require_once 'db.php';

// ── Auth: owners only ────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    redirect('../frontend/login.html');
}
if ($_SESSION['user_role'] !== 'owner') {
    redirect('home-student.php');
}

$userId = (int)$_SESSION['user_id'];

// ── Get listing ID from URL ──────────────────────────────────
$listingId = (int)($_GET['id'] ?? $_POST['listing_id'] ?? 0);
if (!$listingId) {
    redirect('home-owner.php?error=no_id');
}

// ── Fetch listing — MUST belong to this owner ────────────────
$fetchStmt = $pdo->prepare("
    SELECT l.*, u.email_verified AS owner_verified, u.first_name
    FROM   listings l
    JOIN   users    u ON u.id = l.owner_id
    WHERE  l.id = ? AND l.owner_id = ?
    LIMIT  1
");
$fetchStmt->execute([$listingId, $userId]);
$listing = $fetchStmt->fetch();

if (!$listing) {
    // Either doesn't exist or belongs to someone else — same response
    redirect('home-owner.php?error=not_found');
}

// ── CSRF ─────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken(32);
}
$csrf = $_SESSION['csrf_token'];

// Average rents for price-flagging
$avgRents = ['Copenhagen'=>4500,'Aarhus'=>3500,'Aalborg'=>3000,'Odense'=>3200];

// Existing photos from JSON column
$existingPhotos = json_decode($listing['photos'] ?? '[]', true) ?: [];

$errors  = [];
$success = false;

// ================================================================
//  POST HANDLER
// ================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        redirect("edit-listing.php?id={$listingId}&error=csrf");
    }

    $action = $_POST['action'] ?? 'update';


    // ── DELETE ───────────────────────────────────────────────
    if ($action === 'delete') {
        // The WHERE owner_id check ensures no one can delete another owner's listing
        $pdo->prepare("DELETE FROM listings WHERE id = ? AND owner_id = ?")->execute([$listingId, $userId]);
        redirect('home-owner.php', 'success', '✅ Listing deleted successfully.');
    }


    // ── TOGGLE STATUS (available ↔ unavailable) ──────────────
    if ($action === 'toggle_status') {
        $newStatus = $listing['status'] === 'active' ? 'expired' : 'active';
        $pdo->prepare("UPDATE listings SET status = ? WHERE id = ? AND owner_id = ?")
            ->execute([$newStatus, $listingId, $userId]);
        redirect("edit-listing.php?id={$listingId}&done=toggled");
    }


    // ── UPDATE ───────────────────────────────────────────────
    if ($action === 'update') {

        // Collect inputs
        $title          = trim($_POST['title']          ?? '');
        $description    = trim($_POST['description']    ?? '');
        $city           = trim($_POST['city']            ?? '');
        $area           = trim($_POST['area']            ?? '');
        $addressFull    = trim($_POST['address_full']    ?? '');
        $roomType       = in_array($_POST['room_type'] ?? '', ['single','double']) ? $_POST['room_type'] : 'single';
        $roomSizeM2     = (int)($_POST['room_size_m2']     ?? 0);
        $totalFlatmates = (int)($_POST['total_flatmates']  ?? 1);
        $bathroomType   = in_array($_POST['bathroom_type'] ?? '', ['private','shared']) ? $_POST['bathroom_type'] : 'shared';
        $rentMonthly    = (float)($_POST['rent_monthly']   ?? 0);
        $depositMonths  = (int)($_POST['deposit_months']   ?? 1);
        $availableFrom  = trim($_POST['available_from']    ?? '');
        $minLease       = (int)($_POST['min_lease_months'] ?? 3);
        $billsIncluded  = isset($_POST['bills_included'])   ? 1 : 0;
        $furnished      = isset($_POST['furnished'])         ? 1 : 0;
        $wifi           = isset($_POST['wifi'])              ? 1 : 0;
        $washingMachine = isset($_POST['washing_machine'])   ? 1 : 0;
        $parking        = isset($_POST['parking'])           ? 1 : 0;
        $petsAllowed    = isset($_POST['pets_allowed'])      ? 1 : 0;
        $balcony        = isset($_POST['balcony'])           ? 1 : 0;

        // Validate
        $allowedCities = ['Copenhagen','Aarhus','Aalborg','Odense'];
        if (strlen($title) < 10 || strlen($title) > 200) $errors[] = 'Title must be 10–200 characters.';
        if (strlen($description) < 50)  $errors[] = 'Description must be at least 50 characters.';
        if (strlen($description) > 3000) $errors[] = 'Description cannot exceed 3,000 characters.';
        if (!in_array($city, $allowedCities)) $errors[] = 'Please select a valid city.';
        if (strlen($area) < 2 || strlen($area) > 100) $errors[] = 'Area / neighbourhood is required.';
        if ($rentMonthly < 1000 || $rentMonthly > 30000) $errors[] = 'Monthly rent must be DKK 1,000–30,000.';
        if (!in_array($depositMonths, [1,2,3])) $errors[] = 'Deposit must be 1, 2, or 3 months.';
        if ($minLease < 1 || $minLease > 24) $errors[] = 'Minimum lease must be 1–24 months.';

        // Handle new photo uploads
        $newPhotos = [];
        if (!empty($_FILES['new_photos']['name'][0])) {
            $uploadDir    = __DIR__ . '/uploads/listings/';
            $allowedMimes = ['image/jpeg','image/png','image/webp'];
            $maxFileSize  = 5 * 1024 * 1024;
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            $files = $_FILES['new_photos'];
            for ($i = 0; $i < count($files['name']); $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($files['size'][$i] > $maxFileSize) { $errors[] = "Photo ".($i+1)." exceeds 5 MB."; continue; }
                $finfo    = finfo_open(FILEINFO_MIME_TYPE);
                $mime     = finfo_file($finfo, $files['tmp_name'][$i]);
                finfo_close($finfo);
                if (!in_array($mime, $allowedMimes, true)) { $errors[] = "Photo ".($i+1)." must be JPG, PNG, or WebP."; continue; }
                $ext      = match($mime){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp',default=>'jpg'};
                $safeName = 'listing_' . $userId . '_' . time() . '_' . $i . '.' . $ext;
                if (move_uploaded_file($files['tmp_name'][$i], $uploadDir . $safeName)) {
                    $newPhotos[] = 'uploads/listings/' . $safeName;
                }
            }
        }

        // Handle removed existing photos
        $keepPhotos  = $_POST['keep_photos'] ?? [];
        $finalPhotos = array_merge(
            array_filter($existingPhotos, fn($p) => in_array($p, $keepPhotos)),
            $newPhotos
        );
        if (count($finalPhotos) > 8) $errors[] = 'Maximum 8 photos allowed in total.';

        // Save to database
        if (empty($errors)) {
            $avg       = $avgRents[$city] ?? 4000;
            $newStatus = ($rentMonthly < $avg * 0.5) ? 'pending' : 'active';

            try {
                $pdo->prepare("
                    UPDATE listings SET
                        title=:t, description=:d, city=:c, area=:a,
                        address_full=:af, room_type=:rt, room_size_m2=:rs,
                        total_flatmates=:tf, bathroom_type=:bt,
                        rent_monthly=:rm, deposit_months=:dm,
                        bills_included=:bi, furnished=:fu, wifi=:wi,
                        washing_machine=:wm, parking=:pk, pets_allowed=:pa,
                        balcony=:ba, photos=:ph,
                        available_from=:av, min_lease_months=:ml,
                        status=:st, updated_at=NOW()
                    WHERE id=:id AND owner_id=:oid
                ")->execute([
                    ':t'=>$title,    ':d'=>$description, ':c'=>$city,
                    ':a'=>$area,     ':af'=>$addressFull?:null,
                    ':rt'=>$roomType,':rs'=>$roomSizeM2?:null,
                    ':tf'=>$totalFlatmates, ':bt'=>$bathroomType,
                    ':rm'=>$rentMonthly,    ':dm'=>$depositMonths,
                    ':bi'=>$billsIncluded,  ':fu'=>$furnished,    ':wi'=>$wifi,
                    ':wm'=>$washingMachine, ':pk'=>$parking,      ':pa'=>$petsAllowed,
                    ':ba'=>$balcony, ':ph'=>$finalPhotos ? json_encode($finalPhotos) : null,
                    ':av'=>$availableFrom?:null, ':ml'=>$minLease,
                    ':st'=>$newStatus, ':id'=>$listingId, ':oid'=>$userId,
                ]);

                // Reload listing with fresh data
                $fetchStmt->execute([$listingId, $userId]);
                $listing = $fetchStmt->fetch();
                $existingPhotos = json_decode($listing['photos'] ?? '[]', true) ?: [];
                $success = true;

                // Refresh CSRF
                $_SESSION['csrf_token'] = generateToken(32);
                $csrf = $_SESSION['csrf_token'];

            } catch (PDOException $e) {
                error_log('edit-listing DB error: ' . $e->getMessage());
                $errors[] = 'Failed to save changes. Please try again.';
            }
        }
    }
}

function sel(mixed $a, mixed $b): string { return $a == $b ? 'selected' : ''; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Edit Listing — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{--navy:#0d1b2a;--teal:#1a7f6e;--teal-light:#22a08a;--teal-pale:rgba(26,127,110,.08);--cream:#f7f3ee;--warm-white:#fdfaf6;--text:#0d1b2a;--text-muted:#6b7280;--border:#e5e0d8;--red:#dc2626;--red-pale:rgba(220,38,38,.07);--green:#16a34a;--gold:#c9a84c;}
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    html{scroll-behavior:smooth;}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--text);}
    nav{background:rgba(253,250,246,.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);padding:0 48px;height:62px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;}
    .logo{font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:900;color:var(--navy);text-decoration:none;letter-spacing:-.5px;}
    .logo span{color:var(--teal);}
    .nav-r{display:flex;align-items:center;gap:10px;}
    .nav-link{font-size:.86rem;color:var(--text-muted);text-decoration:none;padding:7px 12px;border-radius:7px;transition:all .2s;}
    .nav-link:hover{color:var(--navy);background:var(--cream);}
    .nav-btn{padding:8px 17px;border-radius:7px;text-decoration:none;font-size:.86rem;font-weight:600;transition:background .2s;}
    .nav-btn.ghost{border:1.5px solid var(--border);color:var(--text-muted);} .nav-btn.ghost:hover{border-color:var(--teal);color:var(--teal);}
    .nav-btn.red{border:1.5px solid rgba(220,38,38,.3);color:var(--red);} .nav-btn.red:hover{background:var(--red);color:white;}

    .page-wrap{max-width:860px;margin:0 auto;padding:32px 40px 64px;}
    .page-eyebrow{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:var(--teal);margin-bottom:6px;display:flex;align-items:center;gap:7px;}
    .page-eyebrow a{color:var(--text-muted);text-decoration:none;} .page-eyebrow a:hover{color:var(--teal);}
    .page-h1{font-family:'Playfair Display',serif;font-size:2rem;font-weight:700;color:var(--navy);margin-bottom:6px;}

    /* Status bar */
    .status-bar{display:flex;align-items:center;gap:10px;padding:12px 18px;background:white;border:1.5px solid var(--border);border-radius:10px;margin-bottom:22px;flex-wrap:wrap;}
    .sp{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;border-radius:100px;font-size:.68rem;font-weight:700;}
    .sp.active {background:rgba(22,163,74,.1);color:var(--green);}
    .sp.pending{background:rgba(201,168,76,.1);color:#7c5a00;}
    .sp.expired{background:var(--red-pale);color:var(--red);}
    .sb-meta{font-size:.78rem;color:var(--text-muted);}
    .sb-actions{margin-left:auto;display:flex;gap:8px;}
    .sb-btn{padding:7px 14px;border-radius:7px;border:1.5px solid var(--border);background:white;font-family:'DM Sans',sans-serif;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .2s;text-decoration:none;color:var(--text-muted);}
    .sb-btn:hover{border-color:var(--teal);color:var(--teal);}
    .sb-btn.view{background:var(--teal);color:white;border-color:var(--teal);} .sb-btn.view:hover{background:var(--teal-light);}

    .alert{padding:12px 16px;border-radius:9px;margin-bottom:18px;display:flex;align-items:flex-start;gap:10px;font-size:.84rem;line-height:1.55;}
    .alert-ok  {background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.2);color:var(--green);}
    .alert-err {background:var(--red-pale);border:1px solid rgba(220,38,38,.2);color:var(--red);}
    .alert ul{margin:6px 0 0 16px;display:flex;flex-direction:column;gap:4px;}

    .section-card{background:white;border:1.5px solid var(--border);border-radius:12px;margin-bottom:18px;overflow:hidden;transition:box-shadow .2s;}
    .section-card:hover{box-shadow:0 4px 16px rgba(13,27,42,.06);}
    .sc-header{display:flex;align-items:center;gap:13px;padding:14px 20px;border-bottom:1px solid var(--border);}
    .sc-num{width:28px;height:28px;border-radius:7px;background:var(--navy);color:white;font-family:'Playfair Display',serif;font-size:.82rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .sc-title{font-size:.91rem;font-weight:700;color:var(--navy);}
    .sc-desc{font-size:.72rem;color:var(--text-muted);margin-top:1px;}
    .sc-body{padding:20px;}

    .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;}
    .form-row.thirds{grid-template-columns:1fr 1fr 1fr;}
    .form-group{margin-bottom:0;}
    .fl{display:block;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:6px;}
    .fi,.fs,.fta{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.88rem;color:var(--text);background:var(--warm-white);outline:none;transition:border-color .2s;}
    .fi:focus,.fs:focus,.fta:focus{border-color:var(--teal);background:white;}
    .fta{resize:vertical;min-height:100px;line-height:1.6;}
    .fi::placeholder,.fta::placeholder{color:#c0bab2;}
    .fhint{font-size:.7rem;color:var(--text-muted);margin-top:4px;}
    .dkk-wrap{position:relative;}
    .dkk-pre{position:absolute;left:0;top:0;bottom:0;display:flex;align-items:center;padding:0 11px;font-size:.8rem;font-weight:600;color:var(--text-muted);border-right:1.5px solid var(--border);pointer-events:none;background:var(--cream);border-radius:6px 0 0 6px;}
    .dkk-wrap .fi{padding-left:56px;}

    .cost-preview{background:var(--navy);border-radius:8px;padding:12px 16px;margin-top:12px;display:grid;grid-template-columns:repeat(3,1fr);gap:10px;}
    .cp-item{text-align:center;}
    .cp-lbl{font-size:.6rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:rgba(255,255,255,.35);margin-bottom:3px;}
    .cp-val{font-size:.96rem;font-weight:700;color:white;}
    .cp-val.t{color:#22a08a;} .cp-val.g{color:#c9a84c;}

    .am-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:9px;}
    .am-toggle{position:relative;}
    .am-toggle input{position:absolute;opacity:0;width:0;height:0;}
    .am-toggle label{display:flex;flex-direction:column;align-items:center;gap:5px;padding:11px 8px;border-radius:9px;border:1.5px solid var(--border);background:white;cursor:pointer;transition:all .18s;text-align:center;}
    .am-toggle label:hover{border-color:var(--teal);background:var(--teal-pale);}
    .am-toggle input:checked+label{border-color:var(--teal);background:var(--teal-pale);}
    .am-icon{font-size:1.15rem;} .am-name{font-size:.7rem;font-weight:600;color:var(--navy);}
    .am-toggle input:checked+label .am-name{color:var(--teal);}

    /* Existing photo grid */
    .ep-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px;}
    .ep-item{aspect-ratio:4/3;border-radius:8px;overflow:hidden;position:relative;border:1.5px solid var(--border);}
    .ep-item img{width:100%;height:100%;object-fit:cover;}
    .ep-overlay{position:absolute;inset:0;background:rgba(13,27,42,0);transition:background .2s;display:flex;align-items:center;justify-content:center;}
    .ep-item:hover .ep-overlay{background:rgba(13,27,42,.5);}
    .ep-rm{opacity:0;background:var(--red);color:white;border:none;border-radius:7px;padding:6px 12px;font-family:'DM Sans',sans-serif;font-size:.74rem;font-weight:600;cursor:pointer;transition:opacity .2s;}
    .ep-item:hover .ep-rm{opacity:1;}
    .main-badge{position:absolute;top:5px;left:5px;background:var(--teal);color:white;font-size:.6rem;padding:2px 7px;border-radius:100px;font-weight:600;}

    .drop-zone{border:2px dashed var(--border);border-radius:10px;padding:24px 18px;text-align:center;background:var(--warm-white);cursor:pointer;transition:all .2s;position:relative;}
    .drop-zone:hover,.drop-zone.over{border-color:var(--teal);background:var(--teal-pale);}
    .drop-zone input{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
    .dz-title{font-size:.86rem;font-weight:600;color:var(--navy);margin-bottom:3px;}
    .dz-sub{font-size:.74rem;color:var(--text-muted);}
    .new-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:12px;}
    .np-item{aspect-ratio:4/3;border-radius:8px;overflow:hidden;position:relative;border:1.5px solid rgba(26,127,110,.3);}
    .np-item img{width:100%;height:100%;object-fit:cover;}
    .np-rm{position:absolute;top:4px;right:4px;width:20px;height:20px;border-radius:50%;background:rgba(13,27,42,.7);color:white;border:none;font-size:.6rem;cursor:pointer;display:flex;align-items:center;justify-content:center;}
    .np-rm:hover{background:var(--red);}
    .new-badge{position:absolute;bottom:4px;left:4px;background:var(--teal);color:white;font-size:.58rem;padding:1px 6px;border-radius:100px;font-weight:600;}

    .submit-row{display:flex;align-items:center;justify-content:space-between;padding-top:18px;border-top:1px solid var(--border);margin-top:4px;gap:14px;}
    .submit-note{font-size:.75rem;color:var(--text-muted);max-width:280px;line-height:1.5;}
    .submit-btn{padding:12px 28px;background:var(--teal);color:white;border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:.92rem;font-weight:700;cursor:pointer;transition:background .2s;}
    .submit-btn:hover{background:var(--teal-light);}

    /* Danger zone */
    .danger-zone{background:var(--red-pale);border:1.5px solid rgba(220,38,38,.2);border-radius:12px;padding:20px 22px;margin-top:20px;}
    .dz-t{font-size:.9rem;font-weight:700;color:var(--red);margin-bottom:6px;}
    .dz-d{font-size:.82rem;color:var(--text-muted);line-height:1.6;margin-bottom:14px;}
    .dz-btns{display:flex;gap:10px;flex-wrap:wrap;}
    .dz-btn{padding:9px 18px;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:600;cursor:pointer;transition:all .2s;border:1.5px solid var(--border);background:white;color:var(--text-muted);}
    .dz-btn:hover{border-color:var(--teal);color:var(--teal);}
    .dz-btn.del{border:none;background:var(--red);color:white;} .dz-btn.del:hover{background:#b91c1c;}

    /* Delete confirm modal */
    .modal-bg{display:none;position:fixed;inset:0;z-index:500;background:rgba(13,27,42,.65);align-items:center;justify-content:center;}
    .modal-bg.open{display:flex;}
    .modal{background:white;border-radius:14px;padding:32px;max-width:400px;width:90%;animation:pop .3s ease;}
    @keyframes pop{from{opacity:0;transform:scale(.92);}to{opacity:1;transform:scale(1);}}
    .modal h3{font-family:'Playfair Display',serif;font-size:1.15rem;color:var(--navy);margin-bottom:8px;}
    .modal p{font-size:.83rem;color:var(--text-muted);line-height:1.6;margin-bottom:10px;}
    .modal-warn{background:var(--red-pale);border:1px solid rgba(220,38,38,.2);border-radius:8px;padding:10px 13px;font-size:.79rem;color:var(--red);margin-bottom:18px;line-height:1.5;}
    .modal-acts{display:flex;gap:9px;}
    .modal-cancel{flex:1;padding:11px;border:1.5px solid var(--border);border-radius:8px;background:white;font-family:'DM Sans',sans-serif;font-size:.84rem;cursor:pointer;color:var(--text-muted);}
    .modal-del{flex:2;padding:11px;border:none;border-radius:8px;background:var(--red);color:white;font-family:'DM Sans',sans-serif;font-size:.84rem;font-weight:700;cursor:pointer;}

    /* Toast */
    .toast{display:none;position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--navy);color:white;padding:11px 22px;border-radius:100px;font-size:.84rem;font-weight:600;z-index:300;box-shadow:0 8px 28px rgba(0,0,0,.2);white-space:nowrap;}
    .toast.show{display:block;animation:slideUp .3s ease;}
    @keyframes slideUp{from{opacity:0;transform:translateX(-50%) translateY(14px);}to{opacity:1;transform:translateX(-50%) translateY(0);}}

    @keyframes fadeUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
    .section-card{animation:fadeUp .38s ease both;}
    .section-card:nth-child(1){animation-delay:.04s;}.section-card:nth-child(2){animation-delay:.08s;}
    .section-card:nth-child(3){animation-delay:.12s;}.section-card:nth-child(4){animation-delay:.16s;}
  </style>
</head>
<body>

<!-- Delete confirm modal -->
<div class="modal-bg" id="del-modal">
  <div class="modal">
    <h3>🗑️ Delete this listing?</h3>
    <p>You are about to permanently delete <strong>"<?= e($listing['title']) ?>"</strong>.</p>
    <div class="modal-warn">
      ⚠️ All student messages and saved references to this room will also be removed. This cannot be undone.
    </div>
    <div class="modal-acts">
      <button class="modal-cancel" onclick="document.getElementById('del-modal').classList.remove('open')">Keep Listing</button>
      <form method="POST" action="edit-listing.php?id=<?= $listingId ?>" style="flex:2">
        <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
        <input type="hidden" name="listing_id"  value="<?= $listingId ?>"/>
        <input type="hidden" name="action"       value="delete"/>
        <button type="submit" class="modal-del" style="width:100%">Yes, Delete Permanently</button>
      </form>
    </div>
  </div>
</div>

<!-- Toast (success) -->
<?php if ($success || isset($_GET['done'])): ?>
  <div class="toast show" id="toast">
    <?= $success ? '✅ Changes saved!' : '✅ Listing updated!' ?>
  </div>
  <script>setTimeout(()=>document.getElementById('toast')?.classList.remove('show'),4000);</script>
<?php endif; ?>

<nav>
  <a href="../frontend/index.html" class="logo">Bolig<span>Match</span></a>
  <div class="nav-r">
    <a href="home-owner.php" class="nav-link">← Dashboard</a>
    <a href="listing-detail.php?id=<?= $listingId ?>" target="_blank" class="nav-btn ghost">👁 View Live</a>
  </div>
</nav>

<div class="page-wrap">
  <div class="page-eyebrow">
    <a href="home-owner.php">Dashboard</a> › <a href="home-owner.php?tab=listings">My Listings</a> › Edit
  </div>
  <h1 class="page-h1">Edit listing</h1>

  <!-- Status bar -->
  <div class="status-bar">
    <span class="sp <?= e($listing['status']) ?>"><?= ucfirst(e($listing['status'])) ?></span>
    <span class="sb-meta">Created <?= date('d M Y', strtotime($listing['created_at'])) ?></span>
    <span class="sb-meta">·</span>
    <span class="sb-meta">👁 <?= (int)$listing['view_count'] ?> views</span>
    <div class="sb-actions">
      <a href="listing-detail.php?id=<?= $listingId ?>" target="_blank" class="sb-btn view">👁 View</a>
      <form method="POST" action="edit-listing.php?id=<?= $listingId ?>" style="display:inline">
        <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
        <input type="hidden" name="listing_id"  value="<?= $listingId ?>"/>
        <input type="hidden" name="action"       value="toggle_status"/>
        <button type="submit" class="sb-btn">
          <?= $listing['status']==='active' ? '⏸ Mark Unavailable' : '▶ Mark Available' ?>
        </button>
      </form>
    </div>
  </div>

  <!-- Alerts -->
  <?php if (!empty($errors)): ?>
    <div class="alert alert-err">
      <span>⚠️</span>
      <div><strong>Please fix:</strong><ul><?php foreach ($errors as $e2): ?><li><?= e($e2) ?></li><?php endforeach; ?></ul></div>
    </div>
  <?php endif; ?>

  <form method="POST" action="edit-listing.php?id=<?= $listingId ?>" enctype="multipart/form-data" id="edit-form">
    <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
    <input type="hidden" name="listing_id"  value="<?= $listingId ?>"/>
    <input type="hidden" name="action"       value="update"/>

    <!-- ── Section 1: Room Details ── -->
    <div class="section-card">
      <div class="sc-header"><div class="sc-num">1</div><div><div class="sc-title">Room Details</div><div class="sc-desc">Basic information</div></div></div>
      <div class="sc-body">
        <div class="form-group" style="margin-bottom:14px">
          <label class="fl">Title *</label>
          <input type="text" name="title" class="fi" maxlength="200" value="<?= e($listing['title']) ?>" required/>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="fl">City *</label>
            <select name="city" class="fs" id="f-city" onchange="updateCostPreview()" required>
              <?php foreach (['Copenhagen','Aarhus','Aalborg','Odense'] as $c): ?>
                <option value="<?= $c ?>" <?= sel($listing['city'],$c) ?>><?= $c ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label class="fl">Neighbourhood / Area *</label>
            <input type="text" name="area" class="fi" value="<?= e($listing['area']) ?>" required/>
          </div>
        </div>
        <div class="form-group" style="margin-bottom:14px">
          <label class="fl">Full Address <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.7rem;color:var(--text-muted)">(hidden from public)</span></label>
          <input type="text" name="address_full" class="fi" value="<?= e($listing['address_full']) ?>"/>
        </div>
        <div class="form-row thirds">
          <div class="form-group">
            <label class="fl">Room Type *</label>
            <select name="room_type" class="fs" required>
              <option value="single" <?= sel($listing['room_type'],'single') ?>>Single room</option>
              <option value="double" <?= sel($listing['room_type'],'double') ?>>Double room</option>
            </select>
          </div>
          <div class="form-group">
            <label class="fl">Size (m²)</label>
            <input type="number" name="room_size_m2" class="fi" min="5" max="100" value="<?= e($listing['room_size_m2']) ?>"/>
          </div>
          <div class="form-group">
            <label class="fl">Total Occupants *</label>
            <select name="total_flatmates" class="fs" required>
              <?php for ($n=1;$n<=8;$n++): ?>
                <option value="<?= $n ?>" <?= sel($listing['total_flatmates'],$n) ?>><?= $n ?> <?= $n===1?'(you)':'people' ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label class="fl">Bathroom *</label>
            <select name="bathroom_type" class="fs" required>
              <option value="shared"  <?= sel($listing['bathroom_type'],'shared')  ?>>Shared</option>
              <option value="private" <?= sel($listing['bathroom_type'],'private') ?>>Private (en-suite)</option>
            </select>
          </div>
          <div class="form-group">
            <label class="fl">Pets Allowed?</label>
            <select name="pets_allowed" class="fs">
              <option value="0" <?= !$listing['pets_allowed']?'selected':'' ?>>No pets</option>
              <option value="1" <?= $listing['pets_allowed'] ?'selected':'' ?>>Pets welcome</option>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label class="fl">Description *</label>
          <textarea name="description" class="fta" maxlength="3000" required><?= e($listing['description']) ?></textarea>
        </div>
      </div>
    </div>

    <!-- ── Section 2: Costs ── -->
    <div class="section-card">
      <div class="sc-header"><div class="sc-num">2</div><div><div class="sc-title">Costs & Availability</div><div class="sc-desc">Changes shown immediately to students</div></div></div>
      <div class="sc-body">
        <div class="form-row">
          <div class="form-group">
            <label class="fl">Monthly Rent *</label>
            <div class="dkk-wrap">
              <span class="dkk-pre">DKK</span>
              <input type="number" name="rent_monthly" class="fi" id="f-rent"
                     min="1000" max="30000" step="50"
                     value="<?= e($listing['rent_monthly']) ?>"
                     oninput="updateCostPreview()" required/>
            </div>
          </div>
          <div class="form-group">
            <label class="fl">Deposit *</label>
            <select name="deposit_months" class="fs" id="f-dep" onchange="updateCostPreview()" required>
              <option value="1" <?= sel($listing['deposit_months'],1) ?>>1 month</option>
              <option value="2" <?= sel($listing['deposit_months'],2) ?>>2 months</option>
              <option value="3" <?= sel($listing['deposit_months'],3) ?>>3 months (max)</option>
            </select>
          </div>
        </div>
        <div class="cost-preview">
          <div class="cp-item"><div class="cp-lbl">Monthly Rent</div><div class="cp-val t" id="cp-rent">DKK <?= number_format($listing['rent_monthly']) ?></div></div>
          <div class="cp-item"><div class="cp-lbl">Deposit (once)</div><div class="cp-val" id="cp-dep">DKK <?= number_format($listing['rent_monthly']*$listing['deposit_months']) ?></div></div>
          <div class="cp-item"><div class="cp-lbl">Move-in Total</div><div class="cp-val g" id="cp-total">DKK <?= number_format($listing['rent_monthly']*($listing['deposit_months']+1)) ?></div></div>
        </div>
        <div class="form-row" style="margin-top:14px">
          <div class="form-group">
            <label class="fl">Available From *</label>
            <input type="date" name="available_from" class="fi" value="<?= e($listing['available_from']) ?>" required/>
          </div>
          <div class="form-group">
            <label class="fl">Minimum Lease *</label>
            <select name="min_lease_months" class="fs" required>
              <?php foreach ([1=>'1 month',2=>'2 months',3=>'3 months',6=>'6 months',12=>'12 months'] as $v=>$l): ?>
                <option value="<?= $v ?>" <?= sel($listing['min_lease_months'],$v) ?>><?= $l ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Section 3: Amenities ── -->
    <div class="section-card">
      <div class="sc-header"><div class="sc-num">3</div><div><div class="sc-title">Amenities</div><div class="sc-desc">Toggle what is included</div></div></div>
      <div class="sc-body">
        <div class="am-grid">
          <?php
            $ams=[['bills_included','💡','Bills Included'],['furnished','🪑','Furnished'],['wifi','📡','WiFi'],['washing_machine','🫧','Washing Machine'],['parking','🅿️','Parking'],['balcony','🌿','Balcony']];
            foreach ($ams as [$name,$icon,$label]):
          ?>
            <div class="am-toggle">
              <input type="checkbox" name="<?= $name ?>" id="am-<?= $name ?>" value="1" <?= $listing[$name]?'checked':'' ?>/>
              <label for="am-<?= $name ?>"><span class="am-icon"><?= $icon ?></span><span class="am-name"><?= $label ?></span></label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- ── Section 4: Photos ── -->
    <div class="section-card">
      <div class="sc-header"><div class="sc-num">4</div><div><div class="sc-title">Photos</div><div class="sc-desc">Remove or add photos</div></div></div>
      <div class="sc-body">
        <?php if (!empty($existingPhotos)): ?>
          <p style="font-size:.73rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:9px">Current Photos</p>
          <div class="ep-grid">
            <?php foreach ($existingPhotos as $idx => $photo): ?>
              <div class="ep-item" id="ep-<?= $idx ?>">
                <img src="<?= e($photo) ?>" alt="Photo <?= $idx+1 ?>"/>
                <?php if ($idx===0): ?><div class="main-badge">★ Main</div><?php endif; ?>
                <div class="ep-overlay">
                  <button type="button" class="ep-rm" onclick="removeExisting(<?= $idx ?>,'<?= e($photo) ?>')">🗑️ Remove</button>
                </div>
                <input type="hidden" name="keep_photos[]" id="keep-<?= $idx ?>" value="<?= e($photo) ?>"/>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <p style="font-size:.73rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:9px;margin-top:<?= !empty($existingPhotos)?'18px':'0' ?>">Add More Photos</p>
        <div class="drop-zone" id="drop-zone">
          <input type="file" name="new_photos[]" id="photo-input" accept="image/jpeg,image/png,image/webp" multiple onchange="handleNew(this)"/>
          <div class="dz-title">Drag & drop or click to add</div>
          <div class="dz-sub">JPG, PNG, WebP · Max 5 MB each · Up to 8 total</div>
        </div>
        <div class="new-grid" id="new-grid"></div>
      </div>
    </div>

    <!-- ── Submit ── -->
    <div class="section-card">
      <div class="sc-header"><div class="sc-num">5</div><div><div class="sc-title">Save Changes</div></div></div>
      <div class="sc-body">
        <div class="submit-row">
          <div class="submit-note">Changes are applied immediately. Students will see the updated listing.</div>
          <button type="submit" class="submit-btn">💾 Save Changes</button>
        </div>
      </div>
    </div>

  </form>

  <!-- Danger Zone -->
  <div class="danger-zone">
    <div class="dz-t">⚠️ Danger Zone</div>
    <div class="dz-d">Mark your room as unavailable if it's temporarily off the market, or permanently delete this listing.</div>
    <div class="dz-btns">
      <form method="POST" action="edit-listing.php?id=<?= $listingId ?>">
        <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
        <input type="hidden" name="listing_id"  value="<?= $listingId ?>"/>
        <input type="hidden" name="action"       value="toggle_status"/>
        <button type="submit" class="dz-btn">
          <?= $listing['status']==='active' ? '⏸ Mark as Unavailable' : '▶ Mark as Available' ?>
        </button>
      </form>
      <button type="button" class="dz-btn del"
              onclick="document.getElementById('del-modal').classList.add('open')">
        🗑️ Delete This Listing
      </button>
    </div>
  </div>

</div><!-- /page-wrap -->

<script>
  function updateCostPreview() {
    const rent = parseFloat(document.getElementById('f-rent')?.value) || 0;
    const dep  = parseInt(document.getElementById('f-dep')?.value)   || 1;
    const fmt  = n => n ? 'DKK ' + n.toLocaleString() : '—';
    document.getElementById('cp-rent').textContent  = fmt(rent);
    document.getElementById('cp-dep').textContent   = fmt(rent * dep);
    document.getElementById('cp-total').textContent = fmt(rent + rent * dep);
  }

  function removeExisting(idx, path) {
    const item  = document.getElementById(`ep-${idx}`);
    const input = document.getElementById(`keep-${idx}`);
    if (item)  { item.style.opacity = '.3'; item.style.pointerEvents = 'none'; }
    if (input) input.disabled = true;
  }

  let newFiles = [];
  function handleNew(input) {
    newFiles = Array.from(input.files).slice(0, 8);
    renderNew();
  }
  function renderNew() {
    const grid = document.getElementById('new-grid');
    grid.innerHTML = '';
    newFiles.forEach((f, i) => {
      const r = new FileReader();
      r.onload = e => {
        const d = document.createElement('div');
        d.className = 'np-item';
        d.innerHTML = `<img src="${e.target.result}"/><button type="button" class="np-rm" onclick="removeNew(${i})">✕</button><div class="new-badge">NEW</div>`;
        grid.appendChild(d);
      };
      r.readAsDataURL(f);
    });
  }
  function removeNew(idx) {
    newFiles.splice(idx, 1);
    const dt = new DataTransfer(); newFiles.forEach(f=>dt.items.add(f));
    document.getElementById('photo-input').files = dt.files;
    renderNew();
  }

  const dz = document.getElementById('drop-zone');
  if (dz) {
    dz.addEventListener('dragover', e => { e.preventDefault(); dz.classList.add('over'); });
    dz.addEventListener('dragleave', () => dz.classList.remove('over'));
    dz.addEventListener('drop', e => {
      e.preventDefault(); dz.classList.remove('over');
      newFiles = Array.from(e.dataTransfer.files).filter(f=>f.type.startsWith('image/')).slice(0,8);
      const dt = new DataTransfer(); newFiles.forEach(f=>dt.items.add(f));
      document.getElementById('photo-input').files = dt.files;
      renderNew();
    });
  }

  document.addEventListener('keydown', e => { if(e.key==='Escape') document.getElementById('del-modal')?.classList.remove('open'); });
</script>
</body>
</html>
