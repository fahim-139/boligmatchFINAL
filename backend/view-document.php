<?php
// ================================================================
//  view-document.php — Secure Document Viewer
//
//  Only admins can view ownership documents.
//  The uploads/documents/ folder is blocked by .htaccess.
//  This file is the ONLY way to access those files.
//
//  Usage: view-document.php?user_id=5
// ================================================================

session_start();
require_once 'db.php';

// Only admins allowed
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'admin') {
    http_response_code(403);
    die('Access denied. Admin only.');
}

$userId = (int)($_GET['user_id'] ?? 0);
if ($userId < 1) {
    http_response_code(400);
    die('Invalid request.');
}

// Get the filename from the database
$stmt = $pdo->prepare("SELECT ownership_document FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$userId]);
$row = $stmt->fetch();

if (!$row || empty($row['ownership_document'])) {
    http_response_code(404);
    die('Document not found.');
}

$filename = $row['ownership_document'];

// Security: filename must match our expected pattern
if (!preg_match('/^doc_\d+_[a-f0-9]+\.pdf$/', $filename)) {
    http_response_code(400);
    die('Invalid filename.');
}

$filePath = __DIR__ . '/../uploads/documents/' . $filename;

if (!file_exists($filePath)) {
    http_response_code(404);
    die('File not found on disk.');
}

// Serve the PDF inline in the browser
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="ownership-document-' . $userId . '.pdf"');
header('Content-Length: ' . filesize($filePath));
header('X-Content-Type-Options: nosniff');
readfile($filePath);
exit;
?>
