<?php
// ================================================================
//  forgot-password.php — Send Password Reset Link via Email
//
//  Security:
//    ✓ Does NOT reveal whether the email exists (prevents enumeration)
//    ✓ Token expires in 1 hour
//    ✓ Rate limiting: max 3 requests per hour per session
// ================================================================

session_start();
require_once 'db.php';
require_once 'mailer.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$rawEmail = trim($_POST['email'] ?? '');
$email    = sanitiseEmail($rawEmail);

if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// Rate limiting
$rateKey = 'reset_rate';
if (!isset($_SESSION[$rateKey])) {
    $_SESSION[$rateKey] = ['count' => 0, 'start' => time()];
}
if (time() - $_SESSION[$rateKey]['start'] > 3600) {
    $_SESSION[$rateKey] = ['count' => 0, 'start' => time()];
}
if ($_SESSION[$rateKey]['count'] >= 3) {
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please wait before trying again.']);
    exit;
}
$_SESSION[$rateKey]['count']++;

$stmt = $pdo->prepare("SELECT id, first_name, role FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

$successMsg = 'If an account with that email exists, a reset link has been sent. Check your inbox.';

if (!$user || $user['role'] === 'banned' || $user['role'] === 'admin') {
    echo json_encode(['success' => true, 'message' => $successMsg]);
    exit;
}

$resetToken = generateToken(32);

$pdo->prepare("
    UPDATE users
    SET    reset_token   = ?,
           reset_expires = DATE_ADD(NOW(), INTERVAL 1 HOUR)
    WHERE  id = ?
")->execute([$resetToken, $user['id']]);

$resetUrl = 'http://localhost/boligmatch/backend/reset-password.php'
          . '?token=' . $resetToken
          . '&email=' . urlencode($email);

$subject = 'Reset your password — BoligMatch';
$body  = "Hi {$user['first_name']},\n\n";
$body .= "You requested a password reset for your BoligMatch account.\n\n";
$body .= "Click the link below to set a new password:\n\n";
$body .= $resetUrl . "\n\n";
$body .= "This link expires in 1 hour.\n\n";
$body .= "If you did not request this, please ignore this email.\n\n";
$body .= "— BoligMatch";

sendBoligMail($email, $subject, $body);

echo json_encode(['success' => true, 'message' => $successMsg]);
?>
