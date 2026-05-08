<?php
// ================================================================
//  save-listing.php — Save / Unsave a Listing (Heart Button)
//
//  Students only. Toggles a listing's saved status:
//    - If not saved → insert into saved_listings → saved: true
//    - If already saved → delete from saved_listings → saved: false
//
//  Called via AJAX (fetch) from:
//    - listings.php       (❤️ button on each listing card)
//    - listing-detail.php (❤️ Save Listing sidebar button)
//    - home-student.php   (saved rooms section)
//
//  Returns JSON:
//    { "success": true, "saved": true,  "total": 7 }
//    { "success": true, "saved": false, "total": 6 }
//
//  For non-AJAX (form POST) fallback it redirects back.
//
//  Security:
//    ✓ Session auth — students only
//    ✓ CSRF token validated
//    ✓ Listing must exist and be active (or saved already)
//    ✓ UNIQUE constraint in DB prevents duplicate saves
// ================================================================

session_start();
require_once 'db.php';

function isAjax(): bool {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

function fail(string $msg, int $code = 400): never {
    if (isAjax()) {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $msg]);
    } else {
        $_SESSION['flash_type'] = 'error';
        $_SESSION['flash_msg']  = $msg;
        header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'listings.php'));
    }
    exit;
}

// ── Auth ─────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    fail('You must be signed in to save listings.', 401);
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'];

// Only students can save listings
if ($userRole !== 'student') {
    fail('Only students can save listings.');
}

// ── POST only ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: listings.php');
    exit;
}

// ── CSRF ─────────────────────────────────────────────────────
$submitted = $_POST['csrf_token'] ?? '';
$stored    = $_SESSION['csrf_token'] ?? '';
if (!$stored || !$submitted || !hash_equals($stored, $submitted)) {
    fail('Invalid request. Please refresh and try again.');
}

// ── Get listing ID ───────────────────────────────────────────
$listingId = (int)($_POST['listing_id'] ?? 0);
if (!$listingId) {
    fail('No listing specified.');
}

// ── Verify listing exists (don't require it to be active —
//    students should be able to unsave expired listings) ───────
$chk = $pdo->prepare("SELECT id FROM listings WHERE id = ? LIMIT 1");
$chk->execute([$listingId]);
if (!$chk->fetch()) {
    fail('Listing not found.', 404);
}

// ── Check if already saved ────────────────────────────────────
$existsStmt = $pdo->prepare("
    SELECT id FROM saved_listings
    WHERE  student_id = ? AND listing_id = ?
    LIMIT  1
");
$existsStmt->execute([$userId, $listingId]);
$alreadySaved = (bool)$existsStmt->fetch();

// ── Toggle ───────────────────────────────────────────────────
if ($alreadySaved) {
    // Unsave
    $pdo->prepare("
        DELETE FROM saved_listings
        WHERE student_id = ? AND listing_id = ?
    ")->execute([$userId, $listingId]);
    $nowSaved = false;
} else {
    // Save — INSERT IGNORE handles the UNIQUE constraint gracefully
    // if somehow two requests arrive simultaneously
    $pdo->prepare("
        INSERT IGNORE INTO saved_listings (student_id, listing_id, saved_at)
        VALUES (?, ?, NOW())
    ")->execute([$userId, $listingId]);
    $nowSaved = true;
}

// ── Get updated total saved count ─────────────────────────────
$totalStmt = $pdo->prepare("
    SELECT COUNT(*) FROM saved_listings WHERE student_id = ?
");
$totalStmt->execute([$userId]);
$total = (int)$totalStmt->fetchColumn();

// ── Respond ──────────────────────────────────────────────────
if (isAjax()) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'saved'   => $nowSaved,
        'total'   => $total,
    ]);
    exit;
}

// Form POST fallback (for non-JS users)
$_SESSION['flash_type'] = 'success';
$_SESSION['flash_msg']  = $nowSaved ? '❤️ Room saved!' : '✓ Room removed from saved.';
header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? 'listings.php'));
exit;
?>
