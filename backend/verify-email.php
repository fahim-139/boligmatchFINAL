<?php
// ================================================================
//  verify-email.php — Email Verification Handler
//
//  Called when a user clicks the link in their registration email:
//  http://localhost/boligmatch/verify-email.php?token=XXX&email=YYY
//
//  Also handles:  ?resend=1  → resend the verification email
//
//  Security:
//    ✓ Token compared with hash_equals() (timing-safe)
//    ✓ Email + token must both match (prevents guessing)
//    ✓ Token is single-use (cleared from DB after verification)
//    ✓ Already-verified accounts handled gracefully
// ================================================================

session_start();
require_once 'db.php';


// ================================================================
//  CASE A — RESEND VERIFICATION EMAIL
//  URL: verify-email.php?resend=1
//  Called from the dashboard banner when email not yet verified.
// ================================================================

if (isset($_GET['resend'])) {

    if (!isset($_SESSION['user_id'])) {
        redirect('../frontend/login.html');
    }

    $userId = (int)$_SESSION['user_id'];

    // Fetch current user
    $stmt = $pdo->prepare("SELECT id, first_name, email, email_verified FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        redirect('home-student.php');
    }

    if ($user['email_verified']) {
        redirect('dashboard.php?tab=overview');
    }

    // Generate a fresh 6-digit OTP and update the database
    $otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $pdo->prepare("UPDATE users SET verify_token = ? WHERE id = ?")->execute([$otp, $userId]);

    // Store in session for display on verify-otp.php
    $_SESSION['otp_code']  = $otp;
    $_SESSION['otp_email'] = $user['email'];

    // Redirect to the OTP verification page
    redirect('verify-otp.php');
}


// ================================================================
//  CASE B — VERIFY THE TOKEN FROM THE EMAIL LINK
//  URL: verify-email.php?token=XXX&email=YYY
// ================================================================

$rawToken = trim($_GET['token'] ?? '');
$rawEmail = trim($_GET['email'] ?? '');

// Basic input checks
if (!$rawToken || !$rawEmail || strlen($rawToken) < 60) {
    showPage('error', 'Invalid verification link. Please request a new one from your dashboard.');
}

$email = sanitiseEmail($rawEmail);
if (!$email) {
    showPage('error', 'Invalid email in verification link.');
}

// Look up the user by email
$stmt = $pdo->prepare("
    SELECT id, first_name, email_verified, verify_token, role
    FROM   users
    WHERE  email = ?
    LIMIT  1
");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    showPage('error', 'No account found for this email address.');
}

// Already verified?
if ($user['email_verified']) {
    showPage('already', 'Your email is already verified. You can sign in normally.', $user['first_name']);
}

// Timing-safe token comparison
// hash_equals() prevents timing attacks that could guess the token character by character
if (!$user['verify_token'] || !hash_equals($user['verify_token'], $rawToken)) {
    showPage('error', 'This verification link is invalid or has already been used. Please request a new one from your dashboard.');
}

// ── All checks passed — mark the email as verified ────────────
$pdo->prepare("
    UPDATE users
    SET    email_verified = 1,
           verify_token   = NULL
    WHERE  id = ?
")->execute([$user['id']]);

// ── Auto-login if not already logged in ──────────────────────
if (!isset($_SESSION['user_id'])) {
    session_regenerate_id(true);
    $_SESSION['user_id']    = (int)$user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_name']  = $user['first_name'];
    $_SESSION['verified']   = true;
    $_SESSION['csrf_token'] = generateToken(32);
} else {
    // Already logged in — just update the verified flag
    $_SESSION['verified'] = true;
}

// Redirect to dashboard with success message
$dest = $user['role'] === 'owner' ? 'home-owner.php' : 'home-student.php';
redirect(
    $dest,
    'success',
    "✅ Email verified! Welcome to BoligMatch, {$user['first_name']}!"
);


// ================================================================
//  showPage() — render a result page for errors / already verified
//  (success redirects immediately so never reaches this)
// ================================================================

function showPage(string $type, string $message, string $name = ''): never
{
    $config = [
        'error'   => ['#dc2626', '⚠️',  'Verification Problem', 'Go to Dashboard', 'dashboard.php'],
        'already' => ['#1a7f6e', '✓',   'Already Verified',     'Go to Dashboard', 'dashboard.php'],
    ];

    [$colour, $icon, $title, $btnText, $btnHref] = $config[$type] ?? $config['error'];
    http_response_code($type === 'error' ? 400 : 200);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($title) ?> — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@400;600&display=swap" rel="stylesheet"/>
  <style>
    *{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'DM Sans',sans-serif;background:#f7f3ee;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px;}
    .card{background:white;border-radius:16px;border:1.5px solid #e5e0d8;padding:52px 44px;max-width:420px;width:100%;text-align:center;box-shadow:0 8px 32px rgba(13,27,42,.08);}
    .icon{font-size:2.8rem;margin-bottom:16px;}
    h1{font-family:'Playfair Display',serif;font-size:1.5rem;color:#0d1b2a;margin-bottom:10px;}
    p{color:#6b7280;font-size:.9rem;line-height:1.7;margin-bottom:24px;}
    .btn{display:inline-block;padding:12px 26px;background:<?= $colour ?>;color:white;border-radius:8px;text-decoration:none;font-weight:600;font-size:.9rem;transition:opacity .2s;}
    .btn:hover{opacity:.85;}
    .logo{font-family:'Playfair Display',serif;font-size:1.1rem;font-weight:900;color:#0d1b2a;text-decoration:none;display:block;margin-bottom:24px;}
    .logo span{color:#1a7f6e;}
  </style>
</head>
<body>
  <div class="card">
    <a href="../frontend/index.html" class="logo">Bolig<span>Match</span></a>
    <div class="icon"><?= $icon ?></div>
    <h1><?= htmlspecialchars($title) ?></h1>
    <p><?= htmlspecialchars($message) ?></p>
    <a href="<?= $btnHref ?>" class="btn"><?= $btnText ?></a>
  </div>
</body>
</html>
<?php
    exit;
}
?>
