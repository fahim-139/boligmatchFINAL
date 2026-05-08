<?php
// ================================================================
//  upload-document.php — Owner Ownership Document Upload
//
//  Only owners see this page.
//  They upload a PDF (property deed, rental contract, etc.)
//  File is stored in /uploads/documents/ (blocked from public access)
//  Admin reviews it before activating listings.
// ================================================================

session_start();
require_once 'db.php';

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    redirect('../frontend/login.html');
}

// Only owners
if (($_SESSION['user_role'] ?? '') !== 'owner') {
    redirect('home-student.php');
}

$userId = (int)$_SESSION['user_id'];

// Get current ownership status
$stmt = $pdo->prepare("
    SELECT first_name, email, email_verified,
           ownership_document, ownership_verified, ownership_rejected, ownership_uploaded_at
    FROM users
    WHERE id = ?
");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    redirect('../frontend/login.html');
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken(32);
}
$csrf = $_SESSION['csrf_token'];

$error   = '';
$success = false;

// Handle POST — file upload
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // CSRF check
    if (empty($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    }

    // File validation
    if (!$error) {
        if (!isset($_FILES['document']) || $_FILES['document']['error'] === UPLOAD_ERR_NO_FILE) {
            $error = 'Please select a PDF file to upload.';
        } elseif ($_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Upload failed. Please try a smaller file.';
        }
    }

    if (!$error) {
        $file = $_FILES['document'];

        // Size check: max 5 MB
        if ($file['size'] > 5 * 1024 * 1024) {
            $error = 'File too large. Maximum size is 5 MB.';
        }

        // MIME type check: PDF only
        if (!$error) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if ($mime !== 'application/pdf') {
                $error = 'Only PDF files are allowed.';
            }
        }

        // File extension check (double defence)
        if (!$error) {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                $error = 'Only PDF files are allowed.';
            }
        }
    }

    // Move file to uploads/documents/
    if (!$error) {
        $uploadDir = __DIR__ . '/../uploads/documents/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Create .htaccess to block public access
        $htaccessPath = $uploadDir . '.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Deny from all\n");
        }

        // Random filename (prevents filename guessing attacks)
        $newFilename = 'doc_' . $userId . '_' . bin2hex(random_bytes(12)) . '.pdf';
        $destPath    = $uploadDir . $newFilename;

        if (move_uploaded_file($file['tmp_name'], $destPath)) {
            // Save filename in database
            $pdo->prepare("
                UPDATE users
                SET    ownership_document = ?,
                       ownership_verified = 0,
                       ownership_rejected = 0,
                       ownership_uploaded_at = NOW()
                WHERE  id = ?
            ")->execute([$newFilename, $userId]);

            $success = true;

            // Refresh user data
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
        } else {
            $error = 'Could not save the file. Please try again.';
        }
    }
}

// Compute status
$hasDoc     = !empty($user['ownership_document']);
$isVerified = (bool)$user['ownership_verified'];
$isRejected = (bool)$user['ownership_rejected'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Upload Ownership Document — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{--navy:#0d1b2a;--teal:#1a7f6e;--teal-light:#22a08a;--cream:#f7f3ee;--text:#0d1b2a;--text-muted:#6b7280;--border:#e5e0d8;--red:#dc2626;--green:#16a34a;--gold:#c9a84c;}
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);min-height:100vh;padding:32px 24px;}
    .container{max-width:720px;margin:0 auto;}
    .logo{font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:900;color:var(--navy);text-decoration:none;display:block;margin-bottom:24px;text-align:center;}
    .logo span{color:var(--teal);}
    .card{background:white;border-radius:16px;border:1.5px solid var(--border);padding:40px;margin-bottom:20px;}
    .breadcrumb{font-size:.82rem;color:var(--text-muted);margin-bottom:18px;}
    .breadcrumb a{color:var(--teal);text-decoration:none;}
    h1{font-family:'Playfair Display',serif;font-size:1.8rem;color:var(--navy);margin-bottom:8px;}
    .subtitle{font-size:.9rem;color:var(--text-muted);margin-bottom:24px;line-height:1.6;}
    .status-badge{display:inline-block;padding:5px 13px;border-radius:100px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;margin-bottom:18px;}
    .status-pending{background:rgba(201,168,76,.15);color:#8a6f1c;}
    .status-verified{background:rgba(22,163,74,.12);color:var(--green);}
    .status-rejected{background:rgba(220,38,38,.1);color:var(--red);}
    .info-box{background:rgba(26,127,110,.06);border:1px solid rgba(26,127,110,.2);border-radius:10px;padding:16px 20px;margin-bottom:20px;font-size:.85rem;color:var(--text);line-height:1.6;}
    .info-box strong{color:var(--teal);}
    .info-box ul{margin:8px 0 0 18px;}
    .info-box li{margin-bottom:4px;}
    .warn-box{background:rgba(220,38,38,.06);border:1px solid rgba(220,38,38,.2);border-radius:10px;padding:16px 20px;margin-bottom:20px;font-size:.85rem;color:var(--red);line-height:1.6;}
    .success-box{background:rgba(22,163,74,.06);border:1px solid rgba(22,163,74,.2);border-radius:10px;padding:16px 20px;margin-bottom:20px;font-size:.85rem;color:var(--green);line-height:1.6;}
    .drop-zone{border:2px dashed var(--border);border-radius:12px;padding:40px 20px;text-align:center;transition:all .2s;cursor:pointer;background:#fafafa;}
    .drop-zone:hover{border-color:var(--teal);background:rgba(26,127,110,.03);}
    .drop-zone .icon{font-size:2.4rem;margin-bottom:10px;}
    .drop-zone .main-text{font-size:.92rem;font-weight:600;color:var(--navy);margin-bottom:4px;}
    .drop-zone .sub-text{font-size:.78rem;color:var(--text-muted);}
    #file-input{display:none;}
    .file-preview{display:none;padding:12px 16px;background:rgba(26,127,110,.06);border:1px solid rgba(26,127,110,.2);border-radius:8px;margin-top:14px;font-size:.85rem;color:var(--teal);}
    .file-preview.show{display:block;}
    .btn{width:100%;padding:13px;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;margin-top:18px;}
    .btn-primary{background:var(--teal);color:white;}
    .btn-primary:hover{background:var(--teal-light);}
    .btn-primary:disabled{opacity:.6;cursor:not-allowed;}
    .btn-secondary{background:white;border:1.5px solid var(--border);color:var(--text);}
    .btn-secondary:hover{border-color:var(--teal);color:var(--teal);}
    .link-row{text-align:center;margin-top:16px;}
    .link-row a{font-size:.82rem;color:var(--text-muted);text-decoration:none;}
    .link-row a:hover{color:var(--teal);}
  </style>
</head>
<body>
  <div class="container">
    <a href="home-owner.php" class="logo">Bolig<span>Match</span></a>

    <div class="card">
      <div class="breadcrumb"><a href="home-owner.php">Dashboard</a> › Property Ownership Verification</div>

      <h1>Verify Property Ownership</h1>
      <p class="subtitle">
        Hi <?= e($user['first_name']) ?>, to protect students from rental scams, we require owners to verify
        property ownership before listings go live. Please upload a PDF document as proof.
      </p>

      <?php if ($isVerified): ?>
        <div class="status-badge status-verified">✓ Verified</div>
        <div class="success-box">
          <strong>✅ Your ownership has been verified.</strong><br/>
          You can now post listings and they will be immediately visible to students.
        </div>
        <a href="home-owner.php" class="btn btn-primary" style="display:block;text-decoration:none;text-align:center;">
          Go to Dashboard →
        </a>

      <?php elseif ($isRejected): ?>
        <div class="status-badge status-rejected">✗ Rejected</div>
        <div class="warn-box">
          <strong>Your previous document was rejected by an admin.</strong><br/>
          This usually means the document was unclear, not a valid ownership proof, or in the wrong name.
          Please upload a new, clearer document showing proof of ownership or a rental contract.
        </div>

      <?php elseif ($hasDoc && !$isVerified): ?>
        <div class="status-badge status-pending">⏳ Pending Review</div>
        <div class="info-box">
          <strong>Your document has been submitted.</strong><br/>
          Our admin team will review it within 1–2 business days. You will be notified by email
          once the review is complete.<br/><br/>
          <strong>Uploaded:</strong> <?= e(date('d M Y, H:i', strtotime($user['ownership_uploaded_at']))) ?><br/>
          In the meantime, you can create listings — but they will only become visible to students
          after your ownership is verified.
        </div>
        <a href="home-owner.php" class="btn btn-primary" style="display:block;text-decoration:none;text-align:center;">
          Continue to Dashboard →
        </a>
      <?php endif; ?>

      <?php if (!$isVerified): ?>
        <?php if ($success): ?>
          <div class="success-box">
            <strong>✅ Document uploaded successfully!</strong><br/>
            Our admin team will review it within 1–2 business days.
          </div>
          <a href="home-owner.php" class="btn btn-primary" style="display:block;text-decoration:none;text-align:center;">
            Continue to Dashboard →
          </a>
        <?php else: ?>

          <div class="info-box">
            <strong>Acceptable documents:</strong>
            <ul>
              <li>Property deed (skøde)</li>
              <li>Tingbogsattest (land registry extract)</li>
              <li>Existing rental contract showing you as landlord</li>
              <li>Property tax statement with your name</li>
            </ul>
            <strong style="display:block;margin-top:10px;">Requirements:</strong>
            PDF format only • Maximum 5 MB • Document must clearly show your name and the property address
          </div>

          <?php if ($error): ?>
            <div class="warn-box">⚠️ <?= e($error) ?></div>
          <?php endif; ?>

          <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"/>

            <label for="file-input" class="drop-zone" id="drop-zone">
              <div class="icon">📄</div>
              <div class="main-text">Click to select a PDF file</div>
              <div class="sub-text">or drag and drop here</div>
            </label>
            <input type="file" id="file-input" name="document" accept="application/pdf,.pdf" required/>

            <div class="file-preview" id="file-preview"></div>

            <button type="submit" class="btn btn-primary" id="submit-btn" disabled>
              Upload Document
            </button>
          </form>

          <div class="link-row">
            <a href="home-owner.php">Skip for now — upload later</a>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div style="text-align:center;font-size:.76rem;color:var(--text-muted);margin-top:12px;">
      🔒 Your document is stored securely and only visible to BoligMatch administrators.
    </div>
  </div>

  <script>
    const fileInput  = document.getElementById('file-input');
    const dropZone   = document.getElementById('drop-zone');
    const preview    = document.getElementById('file-preview');
    const submitBtn  = document.getElementById('submit-btn');

    if (fileInput) {
      fileInput.addEventListener('change', function() {
        if (fileInput.files.length > 0) {
          const f = fileInput.files[0];
          const size = (f.size / 1024 / 1024).toFixed(2);
          preview.textContent = '📄 ' + f.name + ' (' + size + ' MB)';
          preview.classList.add('show');
          submitBtn.disabled = false;
        }
      });
    }

    // Drag-and-drop
    if (dropZone) {
      ['dragenter','dragover','dragleave','drop'].forEach(function(ev) {
        dropZone.addEventListener(ev, function(e) {
          e.preventDefault(); e.stopPropagation();
        });
      });
      dropZone.addEventListener('drop', function(e) {
        const files = e.dataTransfer.files;
        if (files.length > 0 && fileInput) {
          fileInput.files = files;
          fileInput.dispatchEvent(new Event('change'));
        }
      });
    }
  </script>
</body>
</html>
