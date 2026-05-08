<?php
// ================================================================
//  logout.php — BoligMatch Secure Logout
//
//  Destroys the session, clears cookies, redirects to homepage.
//
//  Usage: <a href="logout.php">Sign Out</a>
//
//  Security:
//    - Three-step session destruction
//    - Remember-me cookie cleared
//    - Remember-me DB token cleared
//    - CSRF token validated if provided
// ================================================================

session_start();
require_once 'db.php';

// ── Capture user info before destroying session ───────────────
$userId   = $_SESSION['user_id']   ?? null;
$userName = $_SESSION['user_name'] ?? 'User';


// ── CSRF VALIDATION (optional for prototype) ──────────────────
$csrfToken = $_SESSION['csrf_token'] ?? null;
$submitted = $_GET['token'] ?? $_POST['csrf_token'] ?? null;

if ($submitted !== null && $csrfToken !== null) {
    if (!hash_equals($csrfToken, $submitted)) {
        error_log('[LOGOUT] CSRF mismatch — user_id: ' . ($userId ?? '?'));
        redirect('../frontend/index.html');
    }
}


// ── Clean up remember-me token in the database ────────────────
if ($userId) {
    try {
        $pdo->prepare("
            UPDATE users
            SET    reset_token   = NULL,
                   reset_expires = NULL
            WHERE  id = ?
        ")->execute([$userId]);
    } catch (PDOException $e) {
        error_log('[LOGOUT] DB cleanup error: ' . $e->getMessage());
    }
}


// ── Log the logout event ──────────────────────────────────────
error_log(sprintf(
    '[LOGOUT] User signed out — user_id: %s | IP: %s | time: %s',
    $userId ?? '?',
    $_SERVER['REMOTE_ADDR'] ?? '?',
    date('Y-m-d H:i:s')
));


// ── Step 1: Clear all session variables ───────────────────────
$_SESSION = [];


// ── Step 2: Delete the PHPSESSID cookie from browser ──────────
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 86400,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}


// ── Step 3: Destroy the server-side session file ──────────────
session_destroy();


// ── Clear remember-me cookie from browser ─────────────────────
if (isset($_COOKIE['bm_remember'])) {
    setcookie('bm_remember', '', time() - 86400, '/', '', false, true);
}


// ── Start a fresh anonymous session for the flash message ─────
session_start();
session_regenerate_id(true);

$_SESSION['csrf_token'] = generateToken(32);
$_SESSION['flash_type'] = 'success';
$_SESSION['flash_msg']  = "You have been signed out. Stay safe!";


// ── Redirect to homepage ──────────────────────────────────────
header('Location: ../frontend/index.html');
exit;
?>
