<?php
// ============================================================
//  get-listings.php — BoligMatch JSON API
//
//  Called by index.html via fetch() to load real listings
//  from MySQL without making index.html a PHP file.
//
//  Test it: open http://localhost/boligmatch/get-listings.php
//  You will see raw JSON — that means it is working.
//
//  URL parameters (all optional):
//    ?limit=6           how many to return    (default 6, max 12)
//    ?city=Copenhagen   filter by city
//    ?deposit=1         max deposit months
//    ?max_rent=5000     max monthly rent in DKK
// ============================================================

require_once 'db.php';

// Only respond to GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Read + sanitise parameters ───────────────────────────────
$limit = min(12, max(1, (int)($_GET['limit'] ?? 6)));

$cityFilter    = trim($_GET['city'] ?? '');
$allowedCities = ['Copenhagen','Aarhus','Aalborg','Odense'];
if (!in_array($cityFilter, $allowedCities, true)) {
    $cityFilter = '';
}

$depositFilter = (int)($_GET['deposit'] ?? 0);
if ($depositFilter < 1 || $depositFilter > 3) $depositFilter = 0;

$maxRent = (int)($_GET['max_rent'] ?? 0);

// ── Build SQL dynamically based on filters ───────────────────
$conditions = ["l.status = 'active'"];
$params     = [];

if ($cityFilter)        { $conditions[] = 'l.city = ?';            $params[] = $cityFilter;    }
if ($depositFilter > 0) { $conditions[] = 'l.deposit_months <= ?'; $params[] = $depositFilter; }
if ($maxRent > 0)       { $conditions[] = 'l.rent_monthly <= ?';   $params[] = $maxRent;       }

$where = 'WHERE ' . implode(' AND ', $conditions);

// ── Total count (for "View all X rooms" link) ────────────────
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM listings l {$where}");
$countStmt->execute($params);
$totalCount = (int)$countStmt->fetchColumn();

// ── Fetch rows ───────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT  l.id, l.title, l.city, l.area,
            l.rent_monthly, l.deposit_months,
            l.room_type, l.room_size_m2,
            l.furnished, l.wifi, l.bills_included,
            l.washing_machine, l.balcony, l.pets_allowed,
            l.available_from, l.view_count, l.created_at,
            l.photos,
            u.email_verified AS owner_verified
    FROM    listings l
    JOIN    users    u ON u.id = l.owner_id
    {$where}
    ORDER   BY l.created_at DESC
    LIMIT   {$limit}
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Format each row for the frontend ─────────────────────────
$listings = array_map(function($r) {
    $rent       = (float)$r['rent_monthly'];
    $deposit    = (int)$r['deposit_months'];
    $isNew      = (time() - strtotime($r['created_at'])) < 86400 * 3;

    // Find first valid photo (if any)
    // Photos saved at backend/uploads/listings/abc.jpg
    // DB stores relative path 'uploads/listings/abc.jpg'
    // index.html lives in /frontend/, so URL needs '../backend/' prefix
    $coverPhoto = null;
    $photosArr  = json_decode($r['photos'] ?? '[]', true) ?: [];
    foreach ($photosArr as $photoPath) {
        if (file_exists(__DIR__ . '/' . $photoPath)) {
            $coverPhoto = '../backend/' . $photoPath;
            break;
        }
    }

    $tags = [];
    if ($r['furnished'])       $tags[] = '🪑 Furnished';
    if ($r['wifi'])            $tags[] = '📡 WiFi';
    if ($r['bills_included'])  $tags[] = '💡 Bills incl.';
    if ($r['washing_machine']) $tags[] = '🫧 Washing';
    if ($r['balcony'])         $tags[] = '🌿 Balcony';

    return [
        'id'             => (int)$r['id'],
        'title'          => $r['title'],
        'city'           => $r['city'],
        'area'           => $r['area'],
        'cover_photo'    => $coverPhoto,
        'room_type'      => $r['room_type'],
        'room_size_m2'   => $r['room_size_m2'] ? (int)$r['room_size_m2'] : null,
        'rent_monthly'   => $rent,
        'rent_formatted' => 'DKK ' . number_format($rent, 0, '.', ','),
        'deposit_months' => $deposit,
        'deposit_dkk'    => $rent * $deposit,
        'move_in_total'  => $rent + ($rent * $deposit),
        'owner_verified' => (bool)$r['owner_verified'],
        'is_new'         => $isNew,
        'view_count'     => (int)$r['view_count'],
        'available_from' => $r['available_from'] ? date('d M Y', strtotime($r['available_from'])) : 'Now',
        'tags'           => array_slice($tags, 0, 3),
        'detail_url'     => 'listing-detail.php?id=' . (int)$r['id'],
    ];
}, $rows);

// ── Output JSON ──────────────────────────────────────────────
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

echo json_encode([
    'count'    => $totalCount,
    'shown'    => count($listings),
    'listings' => $listings,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
?>
