<?php
// ================================================================
//  mark-read.php — Mark Messages as Read
//
//  Called via AJAX from inbox.php and poll.php whenever
//  a user opens a conversation thread.
//
//  Supports two modes:
//    Mode A — mark an entire thread as read
//      POST: listing_id + user_id (the other user in the thread)
//
//    Mode B — mark a single message as read
//      POST: message_id
//
//  Returns JSON:
//    { "success": true, "total_unread": 5 }
//
//  Security:
//    ✓ Session auth required
//    ✓ CSRF token validated
//    ✓ User can only mark messages sent TO them (receiver_id = own ID)
// ================================================================

session_start();
require_once 'db.php';

header('Content-Type: application/json');

function fail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

// ── Auth ─────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    fail('Unauthorised', 401);
}
$userId = (int)$_SESSION['user_id'];

// ── CSRF ─────────────────────────────────────────────────────
$submitted = $_POST['csrf_token'] ?? '';
$stored    = $_SESSION['csrf_token'] ?? '';
if (!$stored || !$submitted || !hash_equals($stored, $submitted)) {
    fail('Invalid CSRF token');
}

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail('POST required');
}

// ── Mode B: single message ────────────────────────────────────
if (!empty($_POST['message_id'])) {
    $msgId = (int)$_POST['message_id'];

    // Only mark messages that were sent TO the current user
    $pdo->prepare("
        UPDATE messages
        SET    is_read = 1
        WHERE  id = ? AND receiver_id = ? AND is_read = 0
    ")->execute([$msgId, $userId]);

    $unread = getUnreadCount($pdo, $userId);
    echo json_encode(['success' => true, 'total_unread' => $unread]);
    exit;
}

// ── Mode A: entire thread ─────────────────────────────────────
$listingId = (int)($_POST['listing_id'] ?? 0);
$senderId  = (int)($_POST['user_id']    ?? 0); // the other user (sender of those messages)

if (!$listingId || !$senderId) {
    fail('Missing listing_id or user_id');
}

// Mark all unread messages in this thread that were sent TO the current user
$pdo->prepare("
    UPDATE messages
    SET    is_read = 1
    WHERE  listing_id  = ?
      AND  sender_id   = ?
      AND  receiver_id = ?
      AND  is_read     = 0
")->execute([$listingId, $senderId, $userId]);

// Return updated unread count (used to refresh nav badge)
$unread = getUnreadCount($pdo, $userId);
echo json_encode(['success' => true, 'total_unread' => $unread]);
?>
