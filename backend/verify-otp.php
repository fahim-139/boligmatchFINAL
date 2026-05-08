<?php
// ================================================================
//  verify-otp.php — Email Verification via OTP sent to Gmail
//
//  After registration, the OTP is sent to the user's email.
//  This page has a form where they enter that code to verify.
// ================================================================

session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) {
    redirect('../frontend/login.html');
}

$userId   = (int)$_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? '';
$userRole = $_SESSION['user_role'] ?? 'student';

// Check if already verified
$stmt = $pdo->prepare("SELECT email, email_verified FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if ($user && $user['email_verified']) {
    $dest = $userRole === 'owner' ? 'home-owner.php' : 'home-student.php';
    redirect($dest, 'success', '✅ Your email is already verified!');
}

$userEmail = $user['email'] ?? '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken(32);
}
$csrf = $_SESSION['csrf_token'];

$mailSent = $_SESSION['mail_sent'] ?? true;
unset($_SESSION['mail_sent']);

// Handle POST — verify the OTP
$error   = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        $error = 'Invalid request. Please try again.';
    }

    $submittedOtp = trim($_POST['otp'] ?? '');

    if (!$error && strlen($submittedOtp) !== 6) {
        $error = 'Please enter the full 6-digit code.';
    }

    if (!$error) {
        $checkStmt = $pdo->prepare("SELECT id, verify_token FROM users WHERE id = ? LIMIT 1");
        $checkStmt->execute([$userId]);
        $dbUser = $checkStmt->fetch();

        if (!$dbUser || !$dbUser['verify_token']) {
            $error = 'Verification code has expired. Click "Resend Code" below.';
        } elseif (!hash_equals($dbUser['verify_token'], $submittedOtp)) {
            $error = 'Incorrect code. Please check your email and try again.';
        } else {
            $pdo->prepare("UPDATE users SET email_verified = 1, verify_token = NULL WHERE id = ?")
                ->execute([$userId]);
            $_SESSION['verified'] = true;
            $success = true;
        }
    }
}

// Handle resend
if (isset($_GET['resend'])) {
    require_once 'mailer.php';

    $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE users SET verify_token = ? WHERE id = ?")->execute([$otp, $userId]);

    $subject = 'Your new verification code — BoligMatch';
    $body    = "Hi {$userName},\n\n"
             . "Here is your new verification code:\n\n"
             . "    Verification Code: {$otp}\n\n"
             . "Enter this code on the verification page within 10 minutes.\n\n"
             . "— BoligMatch";

    $sent = sendBoligMail($userEmail, $subject, $body);
    $_SESSION['mail_sent'] = $sent;
    redirect('verify-otp.php');
}

// Mask email for display
$maskedEmail = '';
if ($userEmail) {
    $parts = explode('@', $userEmail);
    if (count($parts) === 2) {
        $show = min(3, strlen($parts[0]));
        $maskedEmail = substr($parts[0], 0, $show) . str_repeat('*', max(0, strlen($parts[0]) - $show)) . '@' . $parts[1];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Verify Your Email — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{--navy:#0d1b2a;--teal:#1a7f6e;--teal-light:#22a08a;--cream:#f7f3ee;--text:#0d1b2a;--text-muted:#6b7280;--border:#e5e0d8;--red:#dc2626;--green:#16a34a;}
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
    .card{background:white;border-radius:16px;border:1.5px solid var(--border);padding:48px 40px;max-width:480px;width:100%;text-align:center;}
    .logo{font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:900;color:var(--navy);text-decoration:none;display:block;margin-bottom:24px;}
    .logo span{color:var(--teal);}
    .icon{font-size:2.5rem;margin-bottom:12px;}
    h2{font-family:'Playfair Display',serif;font-size:1.4rem;color:var(--navy);margin-bottom:8px;}
    p{font-size:.88rem;color:var(--text-muted);line-height:1.6;margin-bottom:20px;}
    .info-box{background:rgba(26,127,110,.06);border:1px solid rgba(26,127,110,.25);border-radius:10px;padding:16px 18px;margin-bottom:22px;font-size:.82rem;color:var(--teal);line-height:1.55;text-align:left;}
    .warn-box{background:rgba(201,168,76,.1);border:1px solid rgba(201,168,76,.25);border-radius:8px;padding:12px 16px;margin-bottom:16px;font-size:.78rem;color:#7c5a00;line-height:1.5;text-align:left;}
    .otp-input{width:100%;padding:14px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:1.3rem;font-weight:700;text-align:center;letter-spacing:8px;outline:none;margin-bottom:14px;}
    .otp-input:focus{border-color:var(--teal);}
    .otp-input::placeholder{letter-spacing:2px;font-size:.9rem;font-weight:400;color:#ccc;}
    .btn{width:100%;padding:13px;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.92rem;font-weight:700;cursor:pointer;}
    .btn-primary{background:var(--teal);color:white;}
    .btn-primary:hover{background:var(--teal-light);}
    .error-box{background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.15);color:var(--red);padding:10px 14px;border-radius:8px;font-size:.82rem;margin-bottom:16px;}
    .success-box{background:rgba(22,163,74,.07);border:1px solid rgba(22,163,74,.15);color:var(--green);padding:14px;border-radius:8px;font-size:.88rem;margin-bottom:20px;}
    .link{font-size:.82rem;color:var(--teal);text-decoration:none;font-weight:600;display:inline-block;margin-top:16px;}
    .link:hover{text-decoration:underline;}
    .skip-link{font-size:.78rem;color:var(--text-muted);text-decoration:none;display:inline-block;margin-top:12px;}
    .skip-link:hover{color:var(--teal);}
  </style>
</head>
<body>
  <div class="card">
    <a href="../frontend/index.html" class="logo">Bolig<span>Match</span></a>

    <?php if ($success): ?>
      <div class="icon">✅</div>
      <h2>Email Verified!</h2>
      <div class="success-box">Your email has been verified successfully.</div>
      <?php
        if ($userRole === 'owner') {
            $docCheck = $pdo->prepare("SELECT ownership_document FROM users WHERE id = ?");
            $docCheck->execute([$userId]);
            $docRow = $docCheck->fetch();
            $dest = (!$docRow || !$docRow['ownership_document']) ? 'upload-document.php' : 'home-owner.php';
        } else {
            $dest = 'home-student.php';
        }
      ?>
      <a href="<?= $dest ?>" class="btn btn-primary" style="display:block;text-decoration:none;text-align:center;padding:14px;">
        Continue →
      </a>

    <?php else: ?>
      <div class="icon">📧</div>
      <h2>Check your email</h2>
      <p>
        We sent a 6-digit verification code to <strong><?= e($maskedEmail) ?></strong>.
        Enter it below to activate your account.
      </p>

      <?php if (!$mailSent): ?>
        <div class="warn-box">
          ⚠️ We could not send the email. Please click "Resend Code" below.
        </div>
      <?php else: ?>
        <div class="info-box">
          📬 <strong>Check your inbox</strong> (and spam folder). The code will arrive within a minute.
        </div>
      <?php endif; ?>

      <?php if ($error): ?>
        <div class="error-box"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>" />
        <input type="text" name="otp" class="otp-input" placeholder="000000" maxlength="6"
               pattern="[0-9]{6}" inputmode="numeric" autocomplete="one-time-code" required autofocus />
        <button type="submit" class="btn btn-primary">Verify Email →</button>
      </form>

      <a href="verify-otp.php?resend=1" class="link">🔄 Resend Code</a>
      <br/>
      <?php $skipDest = $userRole === 'owner' ? 'home-owner.php' : 'home-student.php'; ?>
      <a href="<?= $skipDest ?>" class="skip-link">Skip for now — verify later from dashboard</a>
    <?php endif; ?>
  </div>
</body>
</html>
