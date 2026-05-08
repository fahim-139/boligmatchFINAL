<?php
// ================================================================
//  poll.php — New Message Polling Endpoint
//
//  inbox.php calls this every 8 seconds via JavaScript to check
//  for new messages without doing a full page reload.
//
//  How it works:
//    1. inbox.php sends: ?listing_id=5&user_id=3&since=42
//    2. poll.php fetches all messages with id > 42 in that thread
//    3. Returns them as JSON
//    4. inbox.php appends any new messages to the chat view
//
//  Returns JSON:
//    { "messages": [ { id, sender_id, sender_first, body, created_at }, ... ] }
//    { "messages": [] }   ← if nothing new
//
//  Security:
//    ✓ Session auth
//    ✓ User must be part of the thread (sender or receiver)
//      — prevents reading other people's messages by guessing IDs
//    ✓ Only returns new messages (since > last known ID)
// ================================================================

session_start();
require_once 'db.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// ── Auth ─────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['messages' => []]);
    exit;
}
$userId = (int)$_SESSION['user_id'];

// ── Only GET ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['messages' => []]);
    exit;
}

// ── Inputs ────────────────────────────────────────────────────
$listingId   = (int)($_GET['listing_id'] ?? 0);
$otherUserId = (int)($_GET['user_id']    ?? 0);
$sinceId     = (int)($_GET['since']      ?? 0); // last known message ID

if (!$listingId || !$otherUserId) {
    echo json_encode(['messages' => []]);
    exit;
}

// ── Fetch new messages ────────────────────────────────────────
// The WHERE clause ensures the current user is part of this thread.
// Without this check, anyone could poll any thread by guessing IDs.
$stmt = $pdo->prepare("
    SELECT
        m.id,
        m.sender_id,
        m.body,
        m.flagged,
        m.created_at,
        u.first_name AS sender_first
    FROM   messages m
    JOIN   users    u ON u.id = m.sender_id
    WHERE  m.listing_id = ?
      AND  m.id         > ?
      AND  (
              (m.sender_id   = ? AND m.receiver_id = ?)
           OR (m.sender_id   = ? AND m.receiver_id = ?)
           )
    ORDER  BY m.created_at ASC
    LIMIT  50
");
$stmt->execute([
    $listingId,
    $sinceId,
    $userId,      $otherUserId,  // messages I sent
    $otherUserId, $userId,       // messages they sent
]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format output — only the fields inbox.php needs for rendering
$output = array_map(function($m) {
    return [
        'id'           => (int)$m['id'],
        'sender_id'    => (int)$m['sender_id'],
        'sender_first' => $m['sender_first'],
        'body'         => $m['body'],
        'flagged'      => (bool)$m['flagged'],
        'created_at'   => $m['created_at'],
    ];
}, $messages);

echo json_encode(['messages' => $output]);
?>
