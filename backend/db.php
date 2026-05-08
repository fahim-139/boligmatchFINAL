<?php
// ================================================================
//  db.php — BoligMatch Database Connection
//
//  Every other PHP file starts with:
//    require_once 'db.php';
//
//  This gives them:
//    $pdo          → the live database connection
//    Helper functions used throughout the project
//
//  SETUP (one time only):
//    1. Start Apache + MySQL in XAMPP Control Panel
//    2. Open http://localhost/phpmyadmin
//    3. Create a database named: boligmatch
//    4. Import boligmatch.sql to create all tables + sample data
//    5. Done — every PHP file will connect automatically
// ================================================================


// ── Database credentials ─────────────────────────────────────
// These are the default XAMPP settings.
// Only change them if you customised your MySQL installation.
define('DB_HOST',    'localhost');
define('DB_NAME',    'boligmatch');
define('DB_USER',    'root');   // default XAMPP username
define('DB_PASS',    '');       // default XAMPP has no password
define('DB_CHARSET', 'utf8mb4');


// ── Connect to MySQL using PDO ────────────────────────────────
// PDO (PHP Data Objects) is used because:
//   - It supports prepared statements (prevents SQL injection)
//   - It throws exceptions on errors (easier to debug)
//   - It always returns associative arrays (easier to use)
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST
            . ';dbname='  . DB_NAME
            . ';charset=' . DB_CHARSET,
        DB_USER,
        DB_PASS,
        [
            // Throw a PHP exception whenever a query fails
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,

            // Return rows as ['column_name' => 'value'] arrays
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,

            // Use real prepared statements (not simulated ones)
            // This is what actually prevents SQL injection
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );

} catch (PDOException $e) {
    // Log the real error privately — never show DB errors to users
    error_log('BoligMatch DB connection failed: ' . $e->getMessage());

    // Show a safe, generic error to the browser
    http_response_code(500);
    die('Database connection failed. Please make sure XAMPP is running and the boligmatch database exists.');
}


// ================================================================
//  HELPER FUNCTIONS
//  These are available in every PHP file that does require_once 'db.php'
// ================================================================


/**
 * Generate a secure random token for email verification,
 * password reset links, and CSRF protection.
 *
 * Returns a 64-character lowercase hex string by default.
 * Example: "a3f8c2d1..." (64 chars when $bytes = 32)
 */
function generateToken(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}


/**
 * Sanitise and validate an email address.
 *
 * Returns the lowercased email if valid, or false if invalid.
 * Used by register.php and login.php before touching the database.
 */
function sanitiseEmail(string $email): string|false
{
    $clean = filter_var(trim($email), FILTER_SANITIZE_EMAIL);

    if (!filter_var($clean, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    return strtolower($clean);
}


/**
 * Check a message body for known rental scam keywords.
 * Used by send-message.php to auto-flag suspicious messages.
 *
 * Returns true if a scam keyword is found.
 * The message is still delivered — it is just marked flagged=1
 * in the database so the admin can review it.
 */
function containsScamKeywords(string $text): bool
{
    $keywords = [
        'western union',
        'moneygram',
        'wire transfer',
        'gift card',
        'google play card',
        'itunes card',
        'bitcoin',
        'cryptocurrency',
        'crypto payment',
        'cashapp',
        'cash app',
        'zelle',
        'venmo',
        'paypal friends',
        'paypal family',
        'urgent payment',
        'send money now',
        'send deposit first',
        'pay before viewing',
        'will ship the keys',
        'send the keys by mail',
        'i am currently abroad',
        'i am out of country',
        'click here to pay',
    ];

    $lower = strtolower($text);

    foreach ($keywords as $keyword) {
        if (str_contains($lower, $keyword)) {
            return true;
        }
    }

    return false;
}


/**
 * Check if an email address uses a known disposable/temporary domain.
 * Used by register.php to block throwaway email accounts.
 *
 * Returns true if the domain is on the disposable list.
 */
function isDisposableEmail(string $email): bool
{
    $disposableDomains = [
        'mailinator.com',
        'guerrillamail.com',
        'tempmail.com',
        'throwaway.email',
        'sharklasers.com',
        'yopmail.com',
        'trashmail.com',
        'fakeinbox.com',
        '10minutemail.com',
        'mailnull.com',
        'dispostable.com',
        'getairmail.com',
        'trashmail.net',
        'discard.email',
        'spamgourmet.com',
    ];

    // Extract the part after the @ symbol
    $domain = strtolower(substr(strrchr($email, '@'), 1));

    return in_array($domain, $disposableDomains, true);
}


/**
 * Format a DKK amount for display.
 * Example: 4800.00 → "DKK 4,800"
 */
function formatDKK(float $amount): string
{
    return 'DKK ' . number_format($amount, 0, '.', ',');
}


/**
 * Redirect the user to a URL.
 * Optionally stores a flash message to show on the next page.
 *
 * Flash message types: 'success', 'error', 'warning'
 *
 * Usage:
 *   redirect('listings.php');
 *   redirect('login.html', 'error', 'Session expired. Please sign in again.');
 */
function redirect(string $url, string $type = '', string $message = ''): never
{
    if ($type && $message) {
        $_SESSION['flash_type'] = $type;
        $_SESSION['flash_msg']  = $message;
    }

    header('Location: ' . $url);
    exit;
}


/**
 * Render and clear any queued flash message.
 *
 * Call this once near the top of a page's <body>.
 * Outputs a styled <div> if a message exists, or nothing if not.
 *
 * Usage in a PHP page:
 *   <?php renderFlash(); ?>
 */
function renderFlash(): void
{
    if (empty($_SESSION['flash_msg'])) {
        return;
    }

    $type = match($_SESSION['flash_type'] ?? '') {
        'success' => 'flash-success',
        'error'   => 'flash-error',
        default   => 'flash-warning',
    };

    $message = htmlspecialchars($_SESSION['flash_msg']);

    echo "<div class=\"flash {$type}\">{$message}</div>";

    // Clear so it doesn't show again on next page load
    unset($_SESSION['flash_type'], $_SESSION['flash_msg']);
}


/**
 * HTML-escape a value for safe output in a template.
 * Shorthand for htmlspecialchars() used in every template.
 *
 * Usage:
 *   echo e($user['first_name']);
 */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''));
}


/**
 * Get the total number of unread messages for a user.
 * Used to show the notification badge count in the nav.
 */
function getUnreadCount(PDO $pdo, int $userId): int
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM   messages
        WHERE  receiver_id = ? AND is_read = 0
    ");
    $stmt->execute([$userId]);
    return (int)$stmt->fetchColumn();
}


/**
 * Expire listings older than 90 days.
 * Called once per session from dashboard.php.
 *
 * In production this would be a cron job — for the prototype
 * we run it lazily when someone loads their dashboard.
 */
function expireOldListings(PDO $pdo): void
{
    $pdo->exec("
        UPDATE listings
        SET    status = 'expired'
        WHERE  status = 'active'
          AND  created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)
    ");
}
?>
