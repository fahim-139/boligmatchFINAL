<?php
// ================================================================
//  reset-password.php — Password Reset Form + Handler
//
//  Two modes:
//    GET  → show the reset form (user clicked the email link)
//    POST → validate token + update password
//
//  Security:
//    ✓ Token validated with timing-safe hash_equals()
//    ✓ Token expires after 1 hour
//    ✓ Token cleared after successful reset (single-use)
//    ✓ Password hashed with bcrypt cost 12
//    ✓ Minimum 8 characters required
// ================================================================

session_start();
require_once 'db.php';


// ================================================================
//  POST — Handle the password reset form submission
// ================================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $token       = trim($_POST['token']            ?? '');
    $emailRaw    = trim($_POST['email']             ?? '');
    $password    = $_POST['password']               ?? '';
    $confirmPw   = $_POST['confirm_password']       ?? '';

    $email = sanitiseEmail($emailRaw);
    $error = '';

    // Validate inputs
    if (!$token || !$email) {
        $error = 'Invalid reset link. Please request a new one.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirmPw) {
        $error = 'Passwords do not match.';
    }

    if (!$error) {
        // Look up user by email
        $stmt = $pdo->prepare("
            SELECT id, first_name, reset_token, reset_expires
            FROM   users
            WHERE  email = ?
            LIMIT  1
        ");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !$user['reset_token']) {
            $error = 'Invalid or expired reset link. Please request a new one.';
        } elseif (!hash_equals($user['reset_token'], $token)) {
            $error = 'Invalid reset token.';
        } elseif (strtotime($user['reset_expires']) < time()) {
            $error = 'This reset link has expired. Please request a new one.';
        }
    }

    if (!$error) {
        // All checks passed — update the password
        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        $pdo->prepare("
            UPDATE users
            SET    password_hash  = ?,
                   reset_token    = NULL,
                   reset_expires  = NULL
            WHERE  id = ?
        ")->execute([$hash, $user['id']]);

        // Show success page
        showPage('success', "Password updated! You can now sign in with your new password.", $user['first_name']);
    } else {
        // Show error — re-display form with error message
        showPage('form', $error, '', $token, $emailRaw);
    }
    exit;
}


// ================================================================
//  GET — Show the reset form (user clicked the link in their email)
//
//  TEMPORARY DEBUG MODE: Add ?debug=1 to URL to see why it fails
// ================================================================

$token = trim($_GET['token'] ?? '');
$email = trim($_GET['email'] ?? '');
$debug = isset($_GET['debug']);

// Helper: show debug info if ?debug=1, otherwise silent
function debugLog(string $label, $value): void {
    if (isset($_GET['debug'])) {
        echo "<div style='background:#fef3c7;padding:8px 14px;margin:4px 0;border-left:3px solid #c9a84c;font-family:monospace;font-size:13px;'>";
        echo "<strong>{$label}:</strong> ";
        echo is_array($value) || is_object($value) ? '<pre>' . htmlspecialchars(print_r($value, true)) . '</pre>' : htmlspecialchars((string)$value);
        echo "</div>";
    }
}

if ($debug) {
    echo "<div style='font-family:monospace;padding:20px;background:#0d1b2a;color:#fff;'>";
    echo "<h2>🔍 RESET PASSWORD DEBUG MODE</h2>";
    echo "</div>";
    debugLog("URL token (length)", strlen($token));
    debugLog("URL token", $token);
    debugLog("URL email (raw)", $email);
    debugLog("URL email (sanitised)", sanitiseEmail($email));
}

if (!$token || !$email || strlen($token) < 60) {
    if ($debug) debugLog("CHECK FAILED", "Token or email missing or too short");
    showPage('error', 'Invalid reset link. Please request a new one from the login page.');
    exit;
}

// Verify the token is valid before showing the form
$cleanEmail = sanitiseEmail($email);

$stmt = $pdo->prepare("
    SELECT id, email, reset_token, reset_expires
    FROM   users
    WHERE  email = ?
    LIMIT  1
");
$stmt->execute([$cleanEmail]);
$user = $stmt->fetch();

if ($debug) {
    debugLog("DB query email used", $cleanEmail);
    debugLog("DB user found?", $user ? 'YES' : 'NO');
    if ($user) {
        debugLog("DB id", $user['id']);
        debugLog("DB email stored", $user['email']);
        debugLog("DB reset_token (length)", strlen($user['reset_token'] ?? ''));
        debugLog("DB reset_token", $user['reset_token'] ?? 'NULL');
        debugLog("Tokens match (hash_equals)?", $user['reset_token'] && hash_equals($user['reset_token'], $token) ? 'YES' : 'NO');
        debugLog("DB reset_expires", $user['reset_expires'] ?? 'NULL');
        if ($user['reset_expires']) {
            $secsLeft = strtotime($user['reset_expires']) - time();
            debugLog("Seconds left", $secsLeft . ' (' . round($secsLeft/60) . ' min)');
            debugLog("Expired?", $secsLeft < 0 ? 'YES' : 'NO');
        }
    }
}

if (!$user) {
    if ($debug) debugLog("CHECK FAILED", "No user with email " . $cleanEmail);
    showPage('error', 'Invalid or already-used reset link.');
    exit;
}

if (!$user['reset_token']) {
    if ($debug) debugLog("CHECK FAILED", "User has no reset_token in database (NULL)");
    showPage('error', 'Invalid or already-used reset link.');
    exit;
}

if (!hash_equals($user['reset_token'], $token)) {
    if ($debug) debugLog("CHECK FAILED", "Tokens do not match");
    showPage('error', 'Invalid or already-used reset link.');
    exit;
}

if (strtotime($user['reset_expires']) < time()) {
    if ($debug) debugLog("CHECK FAILED", "Token expired");
    showPage('error', 'This reset link has expired. Please request a new one.');
    exit;
}

if ($debug) debugLog("✅ ALL CHECKS PASSED", "Reset form would now appear");

// Token is valid — show the password reset form
showPage('form', '', '', $token, $email);


// ================================================================
//  RENDER FUNCTION — outputs the full HTML page
// ================================================================

function showPage(string $type, string $message = '', string $name = '', string $token = '', string $email = ''): void
{
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Reset Password — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{--navy:#0d1b2a;--teal:#1a7f6e;--teal-light:#22a08a;--cream:#f7f3ee;--text:#0d1b2a;--text-muted:#6b7280;--border:#e5e0d8;--red:#dc2626;--green:#16a34a;}
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
    .card{background:white;border-radius:16px;border:1.5px solid var(--border);padding:48px 40px;max-width:440px;width:100%;text-align:center;}
    .logo{font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:900;color:var(--navy);text-decoration:none;display:block;margin-bottom:24px;}
    .logo span{color:var(--teal);}
    .icon{font-size:2.5rem;margin-bottom:16px;}
    h2{font-family:'Playfair Display',serif;font-size:1.4rem;color:var(--navy);margin-bottom:10px;}
    p{font-size:.88rem;color:var(--text-muted);line-height:1.6;margin-bottom:20px;}
    .error-box{background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.15);color:var(--red);padding:10px 14px;border-radius:8px;font-size:.82rem;margin-bottom:16px;text-align:left;}
    .success-box{background:rgba(22,163,74,.07);border:1px solid rgba(22,163,74,.15);color:var(--green);padding:10px 14px;border-radius:8px;font-size:.82rem;margin-bottom:16px;}
    label{display:block;font-size:.82rem;font-weight:600;color:var(--navy);margin-bottom:6px;text-align:left;}
    input[type="password"]{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none;margin-bottom:14px;}
    input:focus{border-color:var(--teal);}
    .btn{width:100%;padding:13px;background:var(--teal);color:white;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.92rem;font-weight:700;cursor:pointer;margin-top:4px;}
    .btn:hover{background:var(--teal-light);}
    .link{display:inline-block;margin-top:16px;font-size:.84rem;color:var(--teal);text-decoration:none;font-weight:600;}
    .link:hover{text-decoration:underline;}
  </style>
</head>
<body>
  <div class="card">
    <a href="../frontend/index.html" class="logo">Bolig<span>Match</span></a>

    <?php if ($type === 'success'): ?>
      <div class="icon">✅</div>
      <h2>Password Reset!</h2>
      <div class="success-box"><?= htmlspecialchars($message) ?></div>
      <a href="../frontend/login.html" class="btn" style="display:block;text-decoration:none;text-align:center;">Sign In →</a>

    <?php elseif ($type === 'error'): ?>
      <div class="icon">⚠️</div>
      <h2>Reset Failed</h2>
      <p><?= htmlspecialchars($message) ?></p>
      <a href="../frontend/login.html" class="link">← Back to login</a>

    <?php elseif ($type === 'form'): ?>
      <h2>Set a new password</h2>
      <p>Enter your new password below. Must be at least 8 characters.</p>

      <?php if ($message): ?>
        <div class="error-box"><?= htmlspecialchars($message) ?></div>
      <?php endif; ?>

      <form method="POST" action="reset-password.php">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>" />
        <input type="hidden" name="email" value="<?= htmlspecialchars($email) ?>" />

        <label for="password">New Password</label>
        <input type="password" id="password" name="password" placeholder="At least 8 characters" required />

        <label for="confirm_password">Confirm Password</label>
        <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat your password" required />

        <button type="submit" class="btn">Reset Password →</button>
      </form>
      <a href="../frontend/login.html" class="link">← Back to login</a>
    <?php endif; ?>
  </div>
</body>
</html>
<?php
    exit;
}
?>
