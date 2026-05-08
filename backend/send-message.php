<?php
// ================================================================
//  send-message.php — Send a Message Between Users
//
//  Handles both:
//    A) First contact: student messages an owner about a listing
//    B) Reply: either party continues an existing conversation
//
//  Supports both AJAX (returns JSON) and regular form POST (redirects).
//  The difference is detected by the X-Requested-With header.
//
//  Security:
//    ✓ Session auth (must be logged in)
//    ✓ CSRF token validation
//    ✓ Cannot message yourself
//    ✓ Cannot message a banned user
//    ✓ Banned users cannot send
//    ✓ Rate limiting: 5 messages per 10 minutes per user
//    ✓ Scam keyword detection → flagged=1 in DB (silent)
//    ✓ Message length limits (10–2000 chars)
//    ✓ Ownership verification: receiver must be the listing owner
//    ✓ First contact blocked on expired listings
//    ✓ All input stripped of HTML tags before storage
// ================================================================

session_start();
require_once 'db.php';


// ── Response helpers ─────────────────────────────────────────
function jsonResponse(bool $ok, array $data = [], int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(array_merge(['success' => $ok], $data));
    exit;
}

function isAjax(): bool
{
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function fail(string $message, int $code = 400): never
{
    if (isAjax()) {
        jsonResponse(false, ['error' => $message], $code);
    }
    // For regular form POST, store error and redirect back
    $_SESSION['flash_type'] = 'error';
    $_SESSION['flash_msg']  = $message;
    header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'listings.php'));
    exit;
}


// ── Auth ─────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    fail('You must be signed in to send messages.', 401);
}

$senderId = (int)$_SESSION['user_id'];

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: inbox.php');
    exit;
}


// ================================================================
//  STEP 1 — CSRF CHECK
// ================================================================

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken(32);
}

$submittedToken = $_POST['csrf_token']
               ?? $_SERVER['HTTP_X_CSRF_TOKEN']
               ?? '';

if (!hash_equals($_SESSION['csrf_token'], $submittedToken)) {
    fail('Invalid request. Please refresh and try again.');
}


// ================================================================
//  STEP 2 — COLLECT AND VALIDATE INPUTS
// ================================================================

$listingId  = (int)($_POST['listing_id']  ?? 0);
$receiverId = (int)($_POST['receiver_id'] ?? 0);
$body       = trim($_POST['body']          ?? '');

if (!$listingId)              fail('No listing specified.');
if (!$receiverId)             fail('No recipient specified.');
if ($senderId === $receiverId) fail('You cannot send a message to yourself.');
if (strlen($body) < 10)      fail('Message is too short. Minimum 10 characters.');
if (strlen($body) > 2000)    fail('Message is too long. Maximum 2,000 characters.');


// ================================================================
//  STEP 3 — VERIFY SENDER IS NOT BANNED
// ================================================================

$senderStmt = $pdo->prepare("SELECT id, role, email_verified FROM users WHERE id = ? LIMIT 1");
$senderStmt->execute([$senderId]);
$sender = $senderStmt->fetch();

if (!$sender)                     fail('Your account was not found.', 403);
if ($sender['role'] === 'banned') fail('Your account has been suspended.');


// ================================================================
//  STEP 4 — VERIFY RECEIVER EXISTS AND IS NOT BANNED
// ================================================================

$receiverStmt = $pdo->prepare("SELECT id, first_name, role FROM users WHERE id = ? LIMIT 1");
$receiverStmt->execute([$receiverId]);
$receiver = $receiverStmt->fetch();

if (!$receiver)                     fail('Recipient not found.', 404);
if ($receiver['role'] === 'banned') fail('This account is no longer active.');


// ================================================================
//  STEP 5 — VERIFY THE LISTING EXISTS
//  Also confirm the receiver is actually the listing owner.
//  This prevents a forged receiver_id in the POST body from
//  letting a user redirect messages to a random person.
// ================================================================

$listingStmt = $pdo->prepare("
    SELECT l.id, l.title, l.status, l.owner_id
    FROM   listings l
    WHERE  l.id = ?
    LIMIT  1
");
$listingStmt->execute([$listingId]);
$listing = $listingStmt->fetch();

if (!$listing) fail('Listing not found.', 404);

// Students message owners: receiver must be the listing owner
$senderIsStudent = $sender['role'] === 'student';
$senderIsOwner   = $sender['role'] === 'owner';

if ($senderIsStudent && $listing['owner_id'] !== $receiverId) {
    fail('Recipient mismatch. Please refresh the page and try again.');
}

// Owners can only reply to threads on their own listings
if ($senderIsOwner && $listing['owner_id'] !== $senderId) {
    fail('You can only reply to messages about your own listings.');
}

// Check whether a thread already exists between these two users for this listing
$existsStmt = $pdo->prepare("
    SELECT COUNT(*) FROM messages
    WHERE  listing_id   = ?
      AND  (
          (sender_id = ? AND receiver_id = ?)
       OR (sender_id = ? AND receiver_id = ?)
      )
");
$existsStmt->execute([$listingId, $senderId, $receiverId, $receiverId, $senderId]);
$threadExists = (bool)$existsStmt->fetchColumn();

// Block first contact on expired listings, but allow replies within existing threads
if (!$threadExists && $listing['status'] === 'expired') {
    fail('This listing is no longer active. You cannot initiate new contact.');
}


// ================================================================
//  STEP 6 — RATE LIMITING
//  Max 5 messages per user per 10-minute window (session-based).
//  In production: use a dedicated DB table for cross-session limits.
// ================================================================

$rateKey    = 'msg_rate_' . $senderId;
$rateWindow = 10 * 60; // 10 minutes

if (!isset($_SESSION[$rateKey])) {
    $_SESSION[$rateKey] = ['count' => 0, 'start' => time()];
}

// Reset window if it has expired
if (time() - $_SESSION[$rateKey]['start'] > $rateWindow) {
    $_SESSION[$rateKey] = ['count' => 0, 'start' => time()];
}

if ($_SESSION[$rateKey]['count'] >= 5) {
    $wait = ceil(($rateWindow - (time() - $_SESSION[$rateKey]['start'])) / 60);
    fail("Too many messages sent. Please wait {$wait} minute(s) before sending again.");
}

$_SESSION[$rateKey]['count']++;


// ================================================================
//  STEP 7 — SCAM KEYWORD DETECTION
//
//  If the message body contains a known scam phrase, the message
//  is stored with flagged=1 so the admin can review it.
//
//  Crucially: the SENDER IS NOT TOLD their message was flagged.
//  If we told them, a scammer would just rephrase their message.
//  The admin sees all flagged messages in the admin panel.
// ================================================================

$flagged = containsScamKeywords($body) ? 1 : 0;

if ($flagged) {
    error_log(sprintf(
        '[MSG FLAGGED] sender_id=%d listing_id=%d IP=%s preview="%s"',
        $senderId,
        $listingId,
        $_SERVER['REMOTE_ADDR'] ?? '?',
        substr($body, 0, 80)
    ));
}


// ================================================================
//  STEP 8 — SANITISE THE MESSAGE BODY
//
//  strip_tags() removes any HTML that was submitted.
//  We store plain text only. When displaying, we use nl2br()
//  to convert line breaks and htmlspecialchars() to escape output.
// ================================================================

$cleanBody = strip_tags($body);


// ================================================================
//  STEP 9 — INSERT THE MESSAGE INTO THE DATABASE
// ================================================================

try {
    $insertStmt = $pdo->prepare("
        INSERT INTO messages
            (sender_id, receiver_id, listing_id, body, is_read, flagged, created_at)
        VALUES
            (:sender_id, :receiver_id, :listing_id, :body, 0, :flagged, NOW())
    ");

    $insertStmt->execute([
        ':sender_id'   => $senderId,
        ':receiver_id' => $receiverId,
        ':listing_id'  => $listingId,
        ':body'        => $cleanBody,
        ':flagged'     => $flagged,
    ]);

    $newMessageId = (int)$pdo->lastInsertId();

} catch (PDOException $e) {
    error_log('send-message DB error: ' . $e->getMessage());
    fail('Failed to send message. Please try again.', 500);
}


// ================================================================
//  STEP 10 — MESSAGE NOTIFICATION (disabled in prototype)
//
//  In production: send an email notification to the receiver
//  using PHPMailer or an SMTP service to let them know they
//  have a new message.
//
//  For the prototype: the message is stored in the database and
//  visible in the receiver's inbox. No email notification is sent
//  because the prototype uses fictional test data without real
//  email addresses.
// ================================================================


// ================================================================
//  STEP 11 — RESPOND
// ================================================================

if (isAjax()) {
    // AJAX response — used by inbox.php and listing-detail.php
    jsonResponse(true, [
        'message_id'  => $newMessageId,
        'body'        => htmlspecialchars($cleanBody),
        'created_at'  => date('Y-m-d H:i:s'),
        'sender_name' => htmlspecialchars($fullName),
    ]);
}

// Regular form POST — redirect back to the conversation
redirect(
    "inbox.php?listing={$listingId}&user={$receiverId}",
    'success',
    '✅ Message sent!'
);
?>
