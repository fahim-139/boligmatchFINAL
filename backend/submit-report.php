<?php
// ================================================================
//  submit-report.php — Submit a Scam / Abuse Report
//
//  Called via form POST from the Report modal on listing-detail.php
//
//  On success:
//    - Inserts a row into the reports table (status = 'open')
//    - If listing has 3+ open reports, auto-sets status = 'pending'
//      so it's hidden until admin reviews it
//    - Redirects back to the listing with a success flash
//
//  Security:
//    ✓ Session auth required (must be logged in to report)
//    ✓ CSRF token validated
//    ✓ Valid reason required (from allowed list)
//    ✓ Rate limiting: max 3 reports per hour per user (session-based)
//    ✓ Cannot report your own listing
//    ✓ Duplicate check: same user can only report same listing once
// ================================================================

session_start();
require_once 'db.php';

// ── Auth ─────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    redirect('../frontend/login.html?next=listings.php');
}

$userId = (int)$_SESSION['user_id'];

// ── POST only ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('listings.php');
}

// ── CSRF ─────────────────────────────────────────────────────
$submitted = $_POST['csrf_token'] ?? '';
$stored    = $_SESSION['csrf_token'] ?? '';
if (!$stored || !$submitted || !hash_equals($stored, $submitted)) {
    redirect('listings.php?error=invalid_request');
}

// ── Inputs ────────────────────────────────────────────────────
$listingId = (int)($_POST['listing_id'] ?? 0);
$reason    = trim($_POST['reason']       ?? '');
$details   = trim(strip_tags($_POST['details'] ?? ''));

$allowedReasons = ['scam', 'fake_listing', 'inappropriate', 'suspicious_owner', 'other'];

// ── Validate ─────────────────────────────────────────────────
if (!$listingId) {
    redirect('listings.php?error=missing_listing');
}

if (!in_array($reason, $allowedReasons, true)) {
    redirect("listing-detail.php?id={$listingId}&error=invalid_reason");
}

if (strlen($details) > 500) {
    $details = substr($details, 0, 500);
}

// ── Check listing exists and get owner ID ────────────────────
$lStmt = $pdo->prepare("SELECT id, owner_id, status FROM listings WHERE id = ? LIMIT 1");
$lStmt->execute([$listingId]);
$listing = $lStmt->fetch();

if (!$listing) {
    redirect('listings.php?error=listing_not_found');
}

// Cannot report your own listing
if ($listing['owner_id'] === $userId) {
    redirect("listing-detail.php?id={$listingId}&error=own_listing");
}

// ── Rate limiting: max 3 reports per hour per user ────────────
$rateKey = 'report_rate_' . $userId;
$rateWin = 3600; // 1 hour

if (!isset($_SESSION[$rateKey])) {
    $_SESSION[$rateKey] = ['count' => 0, 'start' => time()];
}
if (time() - $_SESSION[$rateKey]['start'] > $rateWin) {
    $_SESSION[$rateKey] = ['count' => 0, 'start' => time()];
}

if ($_SESSION[$rateKey]['count'] >= 3) {
    redirect("listing-detail.php?id={$listingId}&error=report_limit");
}

// ── Duplicate check: same user + same listing ─────────────────
$dupStmt = $pdo->prepare("
    SELECT id FROM reports
    WHERE reporter_id = ? AND listing_id = ?
    LIMIT 1
");
$dupStmt->execute([$userId, $listingId]);
if ($dupStmt->fetch()) {
    // Already reported — silently redirect with a thank-you message
    // Don't tell them it's a duplicate (to avoid gaming the system)
    redirect("listing-detail.php?id={$listingId}", 'success', '🛡️ Report received. Our team will review it.');
}

// ── Insert the report ─────────────────────────────────────────
try {
    $pdo->prepare("
        INSERT INTO reports (reporter_id, listing_id, reason, details, status, created_at)
        VALUES (?, ?, ?, ?, 'open', NOW())
    ")->execute([$userId, $listingId, $reason, $details ?: null]);

    $_SESSION[$rateKey]['count']++;

} catch (PDOException $e) {
    error_log('submit-report DB error: ' . $e->getMessage());
    redirect("listing-detail.php?id={$listingId}&error=server");
}

// ── Auto-flag listing if it has 3+ open reports ───────────────
// This hides it from students until an admin reviews it
$openReports = $pdo->prepare("
    SELECT COUNT(*) FROM reports WHERE listing_id = ? AND status = 'open'
");
$openReports->execute([$listingId]);
$openCount = (int)$openReports->fetchColumn();

if ($openCount >= 3 && $listing['status'] === 'active') {
    $pdo->prepare("
        UPDATE listings SET status = 'pending' WHERE id = ? AND status = 'active'
    ")->execute([$listingId]);

    error_log(sprintf(
        '[REPORT] Listing #%d auto-flagged after %d reports — IP: %s',
        $listingId,
        $openCount,
        $_SERVER['REMOTE_ADDR'] ?? '?'
    ));
}

// ── Log the report ────────────────────────────────────────────
error_log(sprintf(
    '[REPORT] New report — listing_id: %d | reporter: %d | reason: %s | IP: %s',
    $listingId,
    $userId,
    $reason,
    $_SERVER['REMOTE_ADDR'] ?? '?'
));

// ── Redirect with success message ────────────────────────────
redirect(
    "listing-detail.php?id={$listingId}",
    'success',
    '🛡️ Report submitted. Thank you — our team will review it within 24 hours.'
);
?>
