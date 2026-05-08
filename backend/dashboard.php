<?php
// ============================================================
//  dashboard.php — BoligMatch User Dashboard
//  Works for both students and owners — tabs change per role.
//
//  Tabs:
//    Overview   — stats + recent activity
//    Listings   — owner: manage listings | student: saved rooms
//    Inbox      — threaded messages
//    Profile    — edit account details + change password
// ============================================================

session_start();
require_once 'db.php';

// ── Auth ─────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    redirect('../frontend/login.html');
}

$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'];

// ── Fetch current user ────────────────────────────────────────
$userStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$userId]);
$user = $userStmt->fetch();

if (!$user || $user['role'] === 'banned') {
    session_destroy();
    redirect('../frontend/login.html?error=banned');
}

expireOldListings($pdo);

// ── CSRF ─────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken(32);
}
$csrf = $_SESSION['csrf_token'];

// ── Active tab ───────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'overview';
$allowedTabs = ['overview','listings','inbox','profile'];
if (!in_array($tab, $allowedTabs)) $tab = 'overview';

// ── Handle POST actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_POST['csrf_token']) || !hash_equals($csrf, $_POST['csrf_token'])) {
        redirect("dashboard.php?tab={$tab}&error=csrf");
    }

    $action = $_POST['action'] ?? '';

    // Update profile
    if ($action === 'update_profile') {
        $firstName  = trim(htmlspecialchars($_POST['first_name'] ?? ''));
        $lastName   = trim(htmlspecialchars($_POST['last_name']  ?? ''));
        $phone      = trim(htmlspecialchars($_POST['phone']      ?? ''));
        $university = trim(htmlspecialchars($_POST['university'] ?? ''));

        if (strlen($firstName) < 2 || strlen($lastName) < 2) {
            redirect('dashboard.php?tab=profile&error=name');
        }

        $pdo->prepare("
            UPDATE users SET first_name=?, last_name=?, phone=?, university=?
            WHERE id=?
        ")->execute([$firstName, $lastName, $phone ?: null, $university ?: null, $userId]);

        $_SESSION['user_name'] = $firstName;
        redirect('dashboard.php?tab=profile&done=profile_saved');
    }

    // Change password
    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!password_verify($current, $user['password_hash'])) {
            redirect('dashboard.php?tab=profile&error=wrong_password');
        }
        if (strlen($new) < 8) {
            redirect('dashboard.php?tab=profile&error=password_short');
        }
        if ($new !== $confirm) {
            redirect('dashboard.php?tab=profile&error=password_mismatch');
        }

        $hash = password_hash($new, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$hash, $userId]);
        redirect('dashboard.php?tab=profile&done=password_changed');
    }

    // Unsave a listing (students)
    if ($action === 'unsave') {
        $lid = (int)($_POST['listing_id'] ?? 0);
        $pdo->prepare("DELETE FROM saved_listings WHERE student_id=? AND listing_id=?")->execute([$userId, $lid]);
        redirect('dashboard.php?tab=listings&done=unsaved');
    }

    // Delete a listing (owners)
    if ($action === 'delete_listing') {
        $lid = (int)($_POST['listing_id'] ?? 0);
        $pdo->prepare("DELETE FROM listings WHERE id=? AND owner_id=?")->execute([$lid, $userId]);
        redirect('dashboard.php?tab=listings&done=deleted');
    }

    // ──────────────────────────────────────────────────────────
    // Delete user account (self-service, permanent)
    // ──────────────────────────────────────────────────────────
    if ($action === 'delete_account') {

        // Require password confirmation
        $pw = $_POST['confirm_password'] ?? '';
        if (!$pw) {
            redirect('dashboard.php?tab=profile&error=pw_required');
        }

        // Verify the password matches
        $check = $pdo->prepare("SELECT password_hash, role FROM users WHERE id = ? LIMIT 1");
        $check->execute([$userId]);
        $me = $check->fetch();

        if (!$me || !password_verify($pw, $me['password_hash'])) {
            redirect('dashboard.php?tab=profile&error=wrong_password');
        }

        // Safety: admins cannot delete themselves from here
        if ($me['role'] === 'admin') {
            redirect('dashboard.php?tab=profile&error=admin_cannot_delete');
        }

        // Delete related rows first (in case foreign keys aren't set to CASCADE)
        $pdo->prepare("DELETE FROM saved_listings WHERE student_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM messages WHERE sender_id = ? OR receiver_id = ?")->execute([$userId, $userId]);
        $pdo->prepare("DELETE FROM reports WHERE reporter_id = ?")->execute([$userId]);
        $pdo->prepare("DELETE FROM listings WHERE owner_id = ?")->execute([$userId]);

        // Finally delete the user
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);

        // Destroy the session and redirect to homepage
        $_SESSION = [];
        session_destroy();
        setcookie(session_name(), '', time() - 3600, '/');

        header('Location: ../frontend/index.html?deleted=1');
        exit;
    }

    // Toggle listing status
    if ($action === 'toggle_status') {
        $lid = (int)($_POST['listing_id'] ?? 0);
        $cur = $pdo->prepare("SELECT status FROM listings WHERE id=? AND owner_id=? LIMIT 1");
        $cur->execute([$lid, $userId]);
        $curStatus = $cur->fetchColumn();
        $newStatus = $curStatus === 'active' ? 'expired' : 'active';
        $pdo->prepare("UPDATE listings SET status=? WHERE id=? AND owner_id=?")->execute([$newStatus, $lid, $userId]);
        redirect('dashboard.php?tab=listings');
    }
}

// ── Fetch data per tab ────────────────────────────────────────
$unreadCount = getUnreadCount($pdo, $userId);

// Overview stats
$stats = [];
if ($userRole === 'owner') {
    $lsStmt = $pdo->prepare("SELECT COUNT(*) FROM listings WHERE owner_id=?"); $lsStmt->execute([$userId]);
    $stats['listings']    = (int)$lsStmt->fetchColumn();
    $vsStmt = $pdo->prepare("SELECT COALESCE(SUM(view_count),0) FROM listings WHERE owner_id=?"); $vsStmt->execute([$userId]);
    $stats['views']       = (int)$vsStmt->fetchColumn();
    $acStmt = $pdo->prepare("SELECT COUNT(*) FROM listings WHERE owner_id=? AND status='active'"); $acStmt->execute([$userId]);
    $stats['active']      = (int)$acStmt->fetchColumn();
} else {
    $svStmt = $pdo->prepare("SELECT COUNT(*) FROM saved_listings WHERE student_id=?"); $svStmt->execute([$userId]);
    $stats['saved']       = (int)$svStmt->fetchColumn();
    $cnStmt = $pdo->prepare("SELECT COUNT(DISTINCT listing_id) FROM messages WHERE sender_id=? OR receiver_id=?"); $cnStmt->execute([$userId,$userId]);
    $stats['convos']      = (int)$cnStmt->fetchColumn();
}
$stats['unread'] = $unreadCount;

// Listings tab data
$listings = [];
if ($tab === 'listings' || $tab === 'overview') {
    if ($userRole === 'owner') {
        $lStmt = $pdo->prepare("SELECT * FROM listings WHERE owner_id=? ORDER BY created_at DESC");
        $lStmt->execute([$userId]);
        $listings = $lStmt->fetchAll();
    } else {
        $lStmt = $pdo->prepare("
            SELECT l.*, s.saved_at FROM saved_listings s
            JOIN listings l ON l.id=s.listing_id
            WHERE s.student_id=? ORDER BY s.saved_at DESC
        ");
        $lStmt->execute([$userId]);
        $listings = $lStmt->fetchAll();
    }
}

// Inbox tab — conversation threads
// Uses ? placeholders because PDO does not allow reusing
// the same named parameter with EMULATE_PREPARES=false
$threads = [];
if ($tab === 'inbox' || $tab === 'overview') {
    $tStmt = $pdo->prepare("
        SELECT m.listing_id,
               l.title AS listing_title,
               CASE WHEN m.sender_id=? THEN m.receiver_id ELSE m.sender_id END AS other_id,
               CASE WHEN m.sender_id=? THEN r.first_name ELSE s.first_name END AS other_first,
               CASE WHEN m.sender_id=? THEN r.last_name  ELSE s.last_name  END AS other_last,
               MAX(m.created_at)                                                 AS last_at,
               SUM(CASE WHEN m.receiver_id=? AND m.is_read=0 THEN 1 ELSE 0 END) AS unread
        FROM   messages m
        JOIN   listings l ON l.id=m.listing_id
        JOIN   users    s ON s.id=m.sender_id
        JOIN   users    r ON r.id=m.receiver_id
        WHERE  m.sender_id=? OR m.receiver_id=?
        GROUP  BY m.listing_id, CASE WHEN m.sender_id=? THEN m.receiver_id ELSE m.sender_id END
        ORDER  BY last_at DESC
        LIMIT  20
    ");
    // Pass $userId once for each ? placeholder (7 total)
    $tStmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId]);
    $threads = $tStmt->fetchAll();

    // Get last message preview for each thread
    foreach ($threads as &$thread) {
        $pvStmt = $pdo->prepare("
            SELECT SUBSTRING(body, 1, 80) FROM messages
            WHERE listing_id = ?
              AND ((sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?))
            ORDER BY created_at DESC LIMIT 1
        ");
        $pvStmt->execute([$thread['listing_id'], $userId, $thread['other_id'], $thread['other_id'], $userId]);
        $thread['preview'] = $pvStmt->fetchColumn() ?: '';
    }
    unset($thread);
}

// Flash message
$flash     = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// Helper: get cover photo path (or null) for a listing row
function findCoverPhoto(array $listing): ?string {
    $photos = json_decode($listing['photos'] ?? '[]', true) ?: [];
    foreach ($photos as $p) {
        if (file_exists(__DIR__ . '/' . $p)) {
            return $p;
        }
    }
    return null;
}

// URL error/done messages
$urlDone  = $_GET['done']  ?? '';
$urlError = $_GET['error'] ?? '';
$doneMessages = [
    'profile_saved'    => '✅ Profile updated successfully.',
    'password_changed' => '✅ Password changed successfully.',
    'unsaved'          => '✅ Room removed from saved list.',
    'deleted'          => '✅ Listing deleted.',
];
$errorMessages = [
    'wrong_password'         => '⚠️ Current password is incorrect.',
    'password_short'         => '⚠️ New password must be at least 8 characters.',
    'password_mismatch'      => '⚠️ New passwords do not match.',
    'name'                   => '⚠️ First and last name must be at least 2 characters.',
    'pw_required'            => '⚠️ Please enter your password to confirm account deletion.',
    'admin_cannot_delete'    => '⚠️ Admin accounts cannot be deleted from here.',
];

function timeAgo(string $dt): string {
    $d = time()-strtotime($dt);
    if ($d<60) return 'Just now';
    if ($d<3600) return floor($d/60).'m ago';
    if ($d<86400) return floor($d/3600).'h ago';
    return date('d M', strtotime($dt));
}
$palettes=[['#afc8da','#c8d8e8'],['#d4a8c8','#e8c8e0'],['#a8c8b8','#c8e0d4'],['#d4c8a8','#e8e0c8'],['#a8b8d4','#c8d4e8'],['#c8a8a8','#e0c8c8']];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{
      --navy:#0d1b2a;--teal:#1a7f6e;--teal-light:#22a08a;
      --teal-pale:rgba(26,127,110,.08);--cream:#f7f3ee;--warm-white:#fdfaf6;
      --gold:#c9a84c;--text:#0d1b2a;--text-muted:#6b7280;--border:#e5e0d8;
      --red:#dc2626;--red-pale:rgba(220,38,38,.07);--green:#16a34a;
      --sidebar:240px;
    }
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    html{scroll-behavior:smooth;}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--text);display:flex;min-height:100vh;}

    /* ── SIDEBAR ──────────────────────────────── */
    .sidebar{
      width:var(--sidebar);flex-shrink:0;
      background:var(--navy);
      display:flex;flex-direction:column;
      position:fixed;inset:0 auto 0 0;
      overflow-y:auto;z-index:50;
    }
    .sb-brand{padding:24px 20px 18px;border-bottom:1px solid rgba(255,255,255,.07);}
    .sb-logo{font-family:'Playfair Display',serif;font-size:1.4rem;font-weight:900;color:white;text-decoration:none;display:block;margin-bottom:14px;}
    .sb-logo span{color:var(--teal-light);}
    .sb-user{display:flex;align-items:center;gap:10px;}
    .sb-avatar{width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,var(--teal),#0a2a40);color:white;font-family:'Playfair Display',serif;font-size:.9rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .sb-uname{font-size:.83rem;font-weight:600;color:rgba(255,255,255,.85);}
    .sb-urole{font-size:.68rem;color:rgba(255,255,255,.38);text-transform:capitalize;}

    .sb-nav{flex:1;padding:12px 0;}
    .sb-section{padding:12px 18px 5px;font-size:.6rem;font-weight:700;letter-spacing:2.5px;text-transform:uppercase;color:rgba(255,255,255,.25);}
    .sb-link{
      display:flex;align-items:center;gap:10px;
      padding:9px 14px;margin:2px 8px;border-radius:7px;
      text-decoration:none;color:rgba(255,255,255,.5);
      font-size:.83rem;font-weight:500;transition:all .18s;position:relative;
    }
    .sb-link:hover{background:rgba(255,255,255,.06);color:rgba(255,255,255,.85);}
    .sb-link.active{background:rgba(26,127,110,.18);color:var(--teal-light);}
    .sb-link.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:55%;background:var(--teal-light);border-radius:0 2px 2px 0;}
    .sb-icon{font-size:.95rem;width:18px;text-align:center;flex-shrink:0;}
    .sb-badge{margin-left:auto;background:var(--red);color:white;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:100px;min-width:18px;text-align:center;}
    .sb-footer{padding:14px 16px;border-top:1px solid rgba(255,255,255,.07);}
    .sb-logout{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.4);font-size:.8rem;text-decoration:none;transition:color .2s;}
    .sb-logout:hover{color:var(--red);}

    /* ── MAIN ─────────────────────────────────── */
    .main{margin-left:var(--sidebar);flex:1;display:flex;flex-direction:column;}

    /* Topbar */
    .topbar{
      background:white;border-bottom:1px solid var(--border);
      height:58px;padding:0 32px;
      display:flex;align-items:center;justify-content:space-between;
      position:sticky;top:0;z-index:40;
    }
    .topbar-title{font-family:'Playfair Display',serif;font-size:1.05rem;font-weight:700;color:var(--navy);}
    .topbar-right{display:flex;align-items:center;gap:10px;}
    .topbar-btn{padding:8px 17px;border-radius:7px;text-decoration:none;font-size:.83rem;font-weight:600;transition:background .2s;}
    .topbar-btn.solid{background:var(--teal);color:white;}
    .topbar-btn.solid:hover{background:var(--teal-light);}
    .topbar-btn.ghost{background:var(--cream);color:var(--text-muted);border:1px solid var(--border);}
    .topbar-btn.ghost:hover{border-color:var(--teal);color:var(--teal);}

    /* Content area */
    .content{padding:28px 32px 60px;flex:1;}

    /* ── FLASH / ALERTS ───────────────────────── */
    .alert{padding:11px 16px;border-radius:9px;margin-bottom:22px;display:flex;align-items:center;gap:10px;font-size:.85rem;}
    .alert-ok  {background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.2);color:var(--green);}
    .alert-err {background:var(--red-pale);border:1px solid rgba(220,38,38,.2);color:var(--red);}
    .alert-warn{background:rgba(201,168,76,.1);border:1px solid rgba(201,168,76,.25);color:#7c5a00;}

    /* ── STAT CARDS ───────────────────────────── */
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:26px;}
    .stat-card{background:white;border:1.5px solid var(--border);border-radius:11px;padding:18px 20px;transition:border-color .2s,box-shadow .2s;}
    .stat-card:hover{border-color:var(--teal);box-shadow:0 4px 16px rgba(13,27,42,.06);}
    .sc-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);margin-bottom:8px;}
    .sc-val{font-family:'Playfair Display',serif;font-size:2rem;font-weight:700;color:var(--navy);line-height:1;}
    .sc-val.teal{color:var(--teal);}
    .sc-val.zero{color:var(--border);}
    .sc-sub{font-size:.73rem;color:var(--text-muted);margin-top:5px;}

    /* ── PANEL ────────────────────────────────── */
    .panel{background:white;border:1.5px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:20px;}
    .panel-header{padding:14px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
    .panel-title{font-size:.82rem;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:7px;}
    .panel-title::before{content:'';width:3px;height:13px;border-radius:2px;background:var(--teal-light);display:block;}
    .panel-link{font-size:.78rem;color:var(--teal);text-decoration:none;font-weight:600;}
    .panel-link:hover{text-decoration:underline;}

    /* ── LISTING ROWS (owner) ─────────────────── */
    .listing-row{display:flex;align-items:center;gap:14px;padding:14px 20px;border-bottom:1px solid rgba(229,224,216,.5);transition:background .15s;}
    .listing-row:last-child{border-bottom:none;}
    .listing-row:hover{background:var(--cream);}
    .lr-img{width:52px;height:52px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.4rem;overflow:hidden;}
    .lr-img img{width:100%;height:100%;object-fit:cover;}
    .lr-info{flex:1;min-width:0;}
    .lr-title{font-size:.88rem;font-weight:600;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-bottom:3px;}
    .lr-meta{font-size:.73rem;color:var(--text-muted);display:flex;gap:10px;flex-wrap:wrap;}
    .lr-price{font-size:.88rem;font-weight:700;color:var(--teal);flex-shrink:0;}
    .lr-status{font-size:.65rem;font-weight:700;padding:2px 8px;border-radius:100px;}
    .lr-status.active {background:rgba(22,163,74,.1);color:var(--green);}
    .lr-status.pending{background:rgba(201,168,76,.1);color:#7c5a00;}
    .lr-status.expired{background:var(--red-pale);color:var(--red);}
    .lr-actions{display:flex;gap:6px;flex-shrink:0;}
    .lr-btn{padding:5px 12px;border-radius:6px;font-family:'DM Sans',sans-serif;font-size:.74rem;font-weight:600;cursor:pointer;transition:all .18s;text-decoration:none;border:1.5px solid var(--border);background:white;color:var(--text-muted);}
    .lr-btn:hover{border-color:var(--teal);color:var(--teal);}
    .lr-btn.danger:hover{border-color:var(--red);color:var(--red);}

    /* ── THREAD ROWS (inbox) ──────────────────── */
    .thread-row{display:flex;align-items:flex-start;gap:12px;padding:14px 20px;border-bottom:1px solid rgba(229,224,216,.5);text-decoration:none;color:inherit;transition:background .15s;}
    .thread-row:last-child{border-bottom:none;}
    .thread-row:hover{background:var(--cream);}
    .thread-row.unread{border-left:3px solid var(--teal);}
    .tr-avatar{width:38px;height:38px;border-radius:9px;flex-shrink:0;background:linear-gradient(135deg,var(--teal),#0a2a40);color:white;font-family:'Playfair Display',serif;font-size:.9rem;font-weight:700;display:flex;align-items:center;justify-content:center;}
    .tr-body{flex:1;min-width:0;}
    .tr-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:2px;}
    .tr-name{font-size:.85rem;font-weight:700;color:var(--navy);}
    .tr-time{font-size:.68rem;color:var(--text-muted);flex-shrink:0;}
    .tr-listing{font-size:.72rem;color:var(--teal);margin-bottom:3px;}
    .tr-preview{font-size:.78rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .tr-dot{width:8px;height:8px;border-radius:50%;background:var(--teal);flex-shrink:0;margin-top:5px;}

    /* ── PROFILE FORM ─────────────────────────── */
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px;}
    .form-group{display:flex;flex-direction:column;gap:6px;}
    .form-group.full{grid-column:1/-1;}
    .form-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);}
    .form-input{padding:11px 13px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--text);background:var(--warm-white);outline:none;transition:border-color .2s;}
    .form-input:focus{border-color:var(--teal);background:white;}
    .form-input:read-only{background:var(--cream);color:var(--text-muted);cursor:not-allowed;}
    .form-hint{font-size:.71rem;color:var(--text-muted);}
    .form-submit{padding:12px 28px;background:var(--teal);color:white;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:700;cursor:pointer;transition:background .2s;}
    .form-submit:hover{background:var(--teal-light);}

    /* Divider */
    .divider{border:none;border-top:1px solid var(--border);margin:24px 0;}

    /* Danger zone */
    .danger-zone{background:var(--red-pale);border:1.5px solid rgba(220,38,38,.2);border-radius:10px;padding:20px 22px;}
    .dz-title{font-size:.88rem;font-weight:700;color:var(--red);margin-bottom:6px;}
    .dz-text{font-size:.8rem;color:var(--text-muted);line-height:1.6;margin-bottom:14px;}

    /* Empty states */
    .empty-state{text-align:center;padding:52px 24px;}
    .es-icon{font-size:2.5rem;margin-bottom:14px;opacity:.3;}
    .es-title{font-size:.95rem;font-weight:700;color:var(--navy);margin-bottom:6px;}
    .es-sub{font-size:.8rem;color:var(--text-muted);line-height:1.6;margin-bottom:18px;}
    .es-btn{display:inline-block;padding:10px 22px;background:var(--teal);color:white;border-radius:7px;text-decoration:none;font-weight:600;font-size:.84rem;}

    /* Saved rooms grid (students) */
    .saved-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;padding:18px 20px;}
    .saved-card{border-radius:10px;border:1.5px solid var(--border);overflow:hidden;text-decoration:none;color:inherit;transition:all .2s;display:flex;flex-direction:column;}
    .saved-card:hover{border-color:var(--teal);transform:translateY(-2px);box-shadow:0 6px 20px rgba(13,27,42,.08);}
    .sc-img{height:100px;display:flex;align-items:center;justify-content:center;font-size:2rem;opacity:.35;overflow:hidden;}
    .sc-img img{width:100%;height:100%;object-fit:cover;opacity:1;}
    .sc-body{padding:10px 12px;flex:1;}
    .sc-city{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--teal);margin-bottom:3px;}
    .sc-title{font-size:.82rem;font-weight:600;color:var(--navy);margin-bottom:6px;line-height:1.3;}
    .sc-footer{display:flex;justify-content:space-between;align-items:center;padding-top:8px;border-top:1px solid var(--border);}
    .sc-price{font-size:.85rem;font-weight:700;color:var(--navy);}
    .sc-dep{font-size:.63rem;background:var(--teal-pale);color:var(--teal);padding:2px 7px;border-radius:100px;font-weight:600;}
    .sc-remove{margin:8px 12px 10px;width:calc(100% - 24px);padding:7px;border:1.5px solid var(--border);border-radius:6px;background:white;font-family:'DM Sans',sans-serif;font-size:.75rem;cursor:pointer;color:var(--text-muted);transition:all .18s;}
    .sc-remove:hover{border-color:var(--red);color:var(--red);}

    @keyframes fadeUp{from{opacity:0;transform:translateY(12px);}to{opacity:1;transform:translateY(0);}}
    .panel{animation:fadeUp .35s ease both;}
    .panel:nth-child(1){animation-delay:.04s;}
    .panel:nth-child(2){animation-delay:.08s;}
    .panel:nth-child(3){animation-delay:.12s;}
  </style>
</head>
<body>

<!-- ── SIDEBAR ── -->
<aside class="sidebar">
  <div class="sb-brand">
    <a href="../frontend/index.html" class="sb-logo">Bolig<span>Match</span></a>
    <div class="sb-user">
      <div class="sb-avatar"><?= strtoupper(substr($user['first_name'],0,1)) ?></div>
      <div>
        <div class="sb-uname"><?= e($user['first_name'].' '.$user['last_name']) ?></div>
        <div class="sb-urole"><?= e($userRole) ?><?= $user['email_verified'] ? ' · ✓ verified' : '' ?></div>
      </div>
    </div>
  </div>

  <nav class="sb-nav">
    <div class="sb-section">Navigation</div>
    <a href="dashboard.php?tab=overview" class="sb-link <?= $tab==='overview'?'active':'' ?>">
      <span class="sb-icon">📊</span> Overview
    </a>
    <a href="dashboard.php?tab=listings" class="sb-link <?= $tab==='listings'?'active':'' ?>">
      <span class="sb-icon"><?= $userRole==='owner'?'🏠':'❤️' ?></span>
      <?= $userRole==='owner' ? 'My Listings' : 'Saved Rooms' ?>
    </a>
    <a href="inbox.php" class="sb-link">
      <span class="sb-icon">💬</span> Inbox
      <?php if ($unreadCount>0): ?><span class="sb-badge"><?= $unreadCount ?></span><?php endif; ?>
    </a>
    <a href="dashboard.php?tab=profile" class="sb-link <?= $tab==='profile'?'active':'' ?>">
      <span class="sb-icon">⚙️</span> Profile & Settings
    </a>

    <div class="sb-section">Quick Links</div>
    <?php if ($userRole === 'owner'): ?>
      <a href="create-listing.php" class="sb-link"><span class="sb-icon">➕</span> Add Listing</a>
    <?php else: ?>
      <a href="listings.php" class="sb-link"><span class="sb-icon">🔍</span> Browse Rooms</a>
    <?php endif; ?>
    <a href="../frontend/how-it-works.html" class="sb-link"><span class="sb-icon">📖</span> How It Works</a>
    <a href="../frontend/safety-tips.html"  class="sb-link"><span class="sb-icon">🛡️</span> Safety Tips</a>
  </nav>

  <div class="sb-footer">
    <a href="logout.php?token=<?= $csrf ?>" class="sb-logout">🚪 Sign Out</a>
  </div>
</aside>

<!-- ── MAIN ── -->
<div class="main">

  <!-- Topbar -->
  <div class="topbar">
    <?php $titles=['overview'=>'Overview','listings'=>$userRole==='owner'?'My Listings':'Saved Rooms','inbox'=>'Inbox','profile'=>'Profile & Settings']; ?>
    <div class="topbar-title"><?= $titles[$tab] ?? 'Dashboard' ?></div>
    <div class="topbar-right">
      <?php if ($userRole==='owner'): ?>
        <a href="create-listing.php" class="topbar-btn solid">+ Add Listing</a>
      <?php else: ?>
        <a href="listings.php" class="topbar-btn ghost">Browse Rooms</a>
      <?php endif; ?>
      <a href="inbox.php" class="topbar-btn ghost">
        💬<?php if ($unreadCount>0): ?> (<?= $unreadCount ?>)<?php endif; ?>
      </a>
    </div>
  </div>

  <div class="content">

    <!-- Flash / URL alerts -->
    <?php if ($flash): ?>
      <div class="alert alert-ok"><?= e($flash) ?></div>
    <?php endif; ?>
    <?php if ($urlDone && isset($doneMessages[$urlDone])): ?>
      <div class="alert alert-ok"><?= $doneMessages[$urlDone] ?></div>
    <?php endif; ?>
    <?php if ($urlError && isset($errorMessages[$urlError])): ?>
      <div class="alert alert-err"><?= $errorMessages[$urlError] ?></div>
    <?php endif; ?>

    <!-- Email not verified banner -->
    <?php if (!$user['email_verified']): ?>
      <div class="alert alert-warn">
        ⚠️ Your email isn't verified yet — you can't send messages until you do.
        <a href="verify-otp.php?resend=1" style="color:#7c5a00;font-weight:700;margin-left:8px">Resend verification email →</a>
      </div>
    <?php endif; ?>


    <!-- ═══════════ OVERVIEW ═══════════ -->
    <?php if ($tab === 'overview'): ?>

      <!-- Stat cards -->
      <div class="stats-grid">
        <?php if ($userRole === 'owner'): ?>
          <div class="stat-card">
            <div class="sc-label">Active Listings</div>
            <div class="sc-val <?= $stats['active']===0?'zero':'' ?>"><?= $stats['active'] ?></div>
            <div class="sc-sub"><?= $stats['listings'] ?> total</div>
          </div>
          <div class="stat-card">
            <div class="sc-label">Total Views</div>
            <div class="sc-val teal <?= $stats['views']===0?'zero':'' ?>"><?= number_format($stats['views']) ?></div>
            <div class="sc-sub">across all listings</div>
          </div>
        <?php else: ?>
          <div class="stat-card">
            <div class="sc-label">Saved Rooms</div>
            <div class="sc-val <?= $stats['saved']===0?'zero':'' ?>"><?= $stats['saved'] ?></div>
            <div class="sc-sub">rooms bookmarked</div>
          </div>
          <div class="stat-card">
            <div class="sc-label">Conversations</div>
            <div class="sc-val teal <?= $stats['convos']===0?'zero':'' ?>"><?= $stats['convos'] ?></div>
            <div class="sc-sub">with owners</div>
          </div>
        <?php endif; ?>
        <div class="stat-card">
          <div class="sc-label">Unread Messages</div>
          <div class="sc-val <?= $stats['unread']===0?'zero':'teal' ?>"><?= $stats['unread'] ?></div>
          <div class="sc-sub"><?= $stats['unread']>0?'replies waiting':'all caught up' ?></div>
        </div>
        <div class="stat-card">
          <div class="sc-label">Email Status</div>
          <div class="sc-val" style="font-size:1.4rem"><?= $user['email_verified']?'✅':'⚠️' ?></div>
          <div class="sc-sub"><?= $user['email_verified']?'Verified':'Not verified' ?></div>
        </div>
      </div>

      <!-- Recent listings / saved -->
      <?php if (!empty($listings)): ?>
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title"><?= $userRole==='owner'?'My Listings':'Saved Rooms' ?></div>
            <a href="dashboard.php?tab=listings" class="panel-link">View all →</a>
          </div>
          <?php foreach (array_slice($listings,0,4) as $l):
            $pal=$palettes[$l['id']%count($palettes)];
            $cover = findCoverPhoto($l);
          ?>
            <div class="listing-row">
              <div class="lr-img"<?= $cover ? '' : ' style="background:linear-gradient(135deg,'.$pal[0].','.$pal[1].')"' ?>>
                <?php if ($cover): ?>
                  <img src="<?= e($cover) ?>" alt="" loading="lazy"/>
                <?php else: ?>
                  🏠
                <?php endif; ?>
              </div>
              <div class="lr-info">
                <div class="lr-title"><?= e($l['title']) ?></div>
                <div class="lr-meta">
                  <span>📍 <?= e($l['city']) ?> · <?= e($l['area']) ?></span>
                  <?php if ($userRole==='owner'): ?>
                    <span>👁 <?= (int)$l['view_count'] ?> views</span>
                    <span class="lr-status <?= $l['status'] ?>"><?= ucfirst($l['status']) ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="lr-price">DKK <?= number_format($l['rent_monthly']) ?>/mo</div>
              <div class="lr-actions">
                <a href="listing-detail.php?id=<?= $l['id'] ?>" class="lr-btn">View</a>
                <?php if ($userRole==='owner'): ?>
                  <a href="edit-listing.php?id=<?= $l['id'] ?>" class="lr-btn">Edit</a>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Recent threads -->
      <?php if (!empty($threads)): ?>
        <div class="panel">
          <div class="panel-header">
            <div class="panel-title">Recent Messages</div>
            <a href="inbox.php" class="panel-link">View all →</a>
          </div>
          <?php foreach (array_slice($threads,0,5) as $t): ?>
            <a href="inbox.php?listing=<?= $t['listing_id'] ?>&user=<?= $t['other_id'] ?>"
               class="thread-row <?= $t['unread']>0?'unread':'' ?>">
              <div class="tr-avatar"><?= strtoupper(substr($t['other_first'],0,1)) ?></div>
              <div class="tr-body">
                <div class="tr-top">
                  <span class="tr-name"><?= e($t['other_first'].' '.$t['other_last']) ?></span>
                  <span class="tr-time"><?= timeAgo($t['last_at']) ?></span>
                </div>
                <div class="tr-listing">🏠 <?= e($t['listing_title']) ?></div>
                <div class="tr-preview"><?= e($t['preview']) ?>…</div>
              </div>
              <?php if ($t['unread']>0): ?><div class="tr-dot"></div><?php endif; ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (empty($listings) && empty($threads)): ?>
        <div class="panel">
          <div class="empty-state">
            <div class="es-icon"><?= $userRole==='owner'?'🏠':'🔍' ?></div>
            <div class="es-title">Welcome to BoligMatch, <?= e($user['first_name']) ?>!</div>
            <div class="es-sub">
              <?php if ($userRole==='owner'): ?>
                You haven't posted any listings yet. Post your first room in under 5 minutes.
              <?php else: ?>
                Start browsing rooms and save the ones you like. They'll appear here.
              <?php endif; ?>
            </div>
            <a href="<?= $userRole==='owner'?'create-listing.php':'listings.php' ?>" class="es-btn">
              <?= $userRole==='owner'?'🏠 Post Your First Room →':'🔍 Browse Rooms →' ?>
            </a>
          </div>
        </div>
      <?php endif; ?>


    <!-- ═══════════ LISTINGS / SAVED ═══════════ -->
    <?php elseif ($tab === 'listings'): ?>

      <?php if ($userRole === 'owner'): ?>
        <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
          <a href="create-listing.php" class="topbar-btn solid">+ Add New Listing</a>
        </div>

        <div class="panel">
          <?php if (empty($listings)): ?>
            <div class="empty-state">
              <div class="es-icon">🏠</div>
              <div class="es-title">No listings yet</div>
              <div class="es-sub">Post your first room. It's free and takes under 5 minutes.</div>
              <a href="create-listing.php" class="es-btn">Create Your First Listing</a>
            </div>
          <?php else: ?>
            <?php foreach ($listings as $l):
              $pal=$palettes[$l['id']%count($palettes)];
              $cover = findCoverPhoto($l);
            ?>
              <div class="listing-row">
                <div class="lr-img"<?= $cover ? '' : ' style="background:linear-gradient(135deg,'.$pal[0].','.$pal[1].')"' ?>>
                  <?php if ($cover): ?>
                    <img src="<?= e($cover) ?>" alt="" loading="lazy"/>
                  <?php else: ?>
                    🏠
                  <?php endif; ?>
                </div>
                <div class="lr-info">
                  <div class="lr-title"><?= e($l['title']) ?></div>
                  <div class="lr-meta">
                    <span>📍 <?= e($l['city']) ?> · <?= e($l['area']) ?></span>
                    <span>👁 <?= (int)$l['view_count'] ?> views</span>
                    <span>📅 <?= date('d M Y',strtotime($l['created_at'])) ?></span>
                    <span class="lr-status <?= $l['status'] ?>"><?= ucfirst($l['status']) ?></span>
                  </div>
                </div>
                <div class="lr-price">DKK <?= number_format($l['rent_monthly']) ?>/mo</div>
                <div class="lr-actions">
                  <a href="listing-detail.php?id=<?= $l['id'] ?>" class="lr-btn">👁 View</a>
                  <a href="edit-listing.php?id=<?= $l['id'] ?>"   class="lr-btn">✏️ Edit</a>
                  <form method="POST" action="dashboard.php?tab=listings" style="display:inline">
                    <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
                    <input type="hidden" name="action"       value="toggle_status"/>
                    <input type="hidden" name="listing_id"   value="<?= $l['id'] ?>"/>
                    <button type="submit" class="lr-btn"><?= $l['status']==='active'?'⏸ Pause':'▶ Activate' ?></button>
                  </form>
                  <form method="POST" action="dashboard.php?tab=listings" style="display:inline"
                        onsubmit="return confirm('Delete this listing permanently?')">
                    <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
                    <input type="hidden" name="action"       value="delete_listing"/>
                    <input type="hidden" name="listing_id"   value="<?= $l['id'] ?>"/>
                    <button type="submit" class="lr-btn danger">🗑️</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

      <?php else: /* Student saved rooms */ ?>
        <?php if (empty($listings)): ?>
          <div class="panel">
            <div class="empty-state">
              <div class="es-icon">❤️</div>
              <div class="es-title">No saved rooms yet</div>
              <div class="es-sub">Click the 🤍 on any room listing to save it here for later.</div>
              <a href="listings.php" class="es-btn">Browse Rooms →</a>
            </div>
          </div>
        <?php else: ?>
          <div class="panel">
            <div class="panel-header"><div class="panel-title">Saved Rooms (<?= count($listings) ?>)</div></div>
            <div class="saved-grid">
              <?php foreach ($listings as $l):
                $pal=$palettes[$l['id']%count($palettes)];
                $expired=$l['status']==='expired';
                $cover = findCoverPhoto($l);
              ?>
                <div class="saved-card" style="opacity:<?= $expired?.6:1 ?>">
                  <a href="listing-detail.php?id=<?= $l['id'] ?>" style="text-decoration:none;color:inherit;flex:1;display:flex;flex-direction:column">
                    <div class="sc-img"<?= $cover ? '' : ' style="background:linear-gradient(135deg,'.$pal[0].','.$pal[1].')"' ?>>
                      <?php if ($cover): ?>
                        <img src="<?= e($cover) ?>" alt="" loading="lazy"/>
                      <?php else: ?>
                        🏠
                      <?php endif; ?>
                    </div>
                    <div class="sc-body">
                      <div class="sc-city">📍 <?= e($l['city']) ?></div>
                      <div class="sc-title"><?= e($l['title']) ?></div>
                      <?php if ($expired): ?><div style="font-size:.65rem;color:var(--red);font-weight:600">No longer available</div><?php endif; ?>
                      <div class="sc-footer">
                        <div class="sc-price">DKK <?= number_format($l['rent_monthly']) ?></div>
                        <div class="sc-dep"><?= $l['deposit_months'] ?> mo.</div>
                      </div>
                    </div>
                  </a>
                  <form method="POST" action="dashboard.php?tab=listings">
                    <input type="hidden" name="csrf_token"  value="<?= $csrf ?>"/>
                    <input type="hidden" name="action"       value="unsave"/>
                    <input type="hidden" name="listing_id"   value="<?= $l['id'] ?>"/>
                    <button type="submit" class="sc-remove">✕ Remove</button>
                  </form>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      <?php endif; ?>


    <!-- ═══════════ PROFILE ═══════════ -->
    <?php elseif ($tab === 'profile'): ?>

      <div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start">

        <!-- Left: forms -->
        <div>
          <!-- Personal info -->
          <div class="panel">
            <div class="panel-header"><div class="panel-title">Personal Information</div></div>
            <div style="padding:22px">
              <form method="POST" action="dashboard.php?tab=profile">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
                <input type="hidden" name="action"      value="update_profile"/>
                <div class="form-grid">
                  <div class="form-group">
                    <label class="form-label">First Name *</label>
                    <input type="text" name="first_name" class="form-input" value="<?= e($user['first_name']) ?>" required/>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Last Name *</label>
                    <input type="text" name="last_name"  class="form-input" value="<?= e($user['last_name']) ?>"  required/>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" class="form-input" value="<?= e($user['email']) ?>" readonly/>
                    <span class="form-hint"><?= $user['email_verified']?'✅ Verified':'⚠️ Not verified — <a href="verify-otp.php?resend=1" style="color:var(--teal)">resend link</a>' ?></span>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Phone Number</label>
                    <input type="tel" name="phone" class="form-input" value="<?= e($user['phone']) ?>" placeholder="+45 00 00 00 00"/>
                  </div>
                  <?php if ($userRole==='student'): ?>
                    <div class="form-group full">
                      <label class="form-label">University</label>
                      <input type="text" name="university" class="form-input" value="<?= e($user['university']) ?>" placeholder="e.g. University of Copenhagen"/>
                    </div>
                  <?php endif; ?>
                </div>
                <button type="submit" class="form-submit">Save Changes</button>
              </form>
            </div>
          </div>

          <!-- Change password -->
          <div class="panel">
            <div class="panel-header"><div class="panel-title">Change Password</div></div>
            <div style="padding:22px">
              <form method="POST" action="dashboard.php?tab=profile">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
                <input type="hidden" name="action"      value="change_password"/>
                <div class="form-grid">
                  <div class="form-group full">
                    <label class="form-label">Current Password</label>
                    <input type="password" name="current_password" class="form-input" placeholder="••••••••" required/>
                  </div>
                  <div class="form-group">
                    <label class="form-label">New Password</label>
                    <input type="password" name="new_password" class="form-input" placeholder="Min. 8 characters" required/>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Confirm New Password</label>
                    <input type="password" name="confirm_password" class="form-input" placeholder="Repeat new password" required/>
                  </div>
                </div>
                <button type="submit" class="form-submit">Update Password</button>
              </form>
            </div>
          </div>
        </div>

        <!-- Right: account info -->
        <div>
          <div class="panel" style="margin-bottom:16px">
            <div class="panel-header"><div class="panel-title">Account Info</div></div>
            <div style="padding:16px 20px;display:flex;flex-direction:column;gap:10px">
              <?php foreach ([
                ['Role',       ucfirst($userRole)],
                ['Member since', date('d M Y',strtotime($user['created_at']))],
                ['Last login',  $user['last_login'] ? date('d M Y H:i',strtotime($user['last_login'])) : 'First session'],
                ['Email status', $user['email_verified']?'✅ Verified':'⚠️ Not verified'],
              ] as [$label,$val]): ?>
                <div style="display:flex;justify-content:space-between;align-items:center;font-size:.82rem;padding:7px 0;border-bottom:1px solid var(--border)">
                  <span style="color:var(--text-muted)"><?= $label ?></span>
                  <span style="font-weight:600;color:var(--navy)"><?= $val ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="danger-zone">
            <div class="dz-title">⚠️ Danger Zone</div>
            <div class="dz-text">Deleting your account is permanent and cannot be undone. All your data, messages, saved rooms, listings, and reports will be removed immediately.</div>
            <button type="button" onclick="openDeleteModal()"
               style="padding:9px 18px;background:var(--red);color:white;border:none;border-radius:7px;cursor:pointer;font-size:.82rem;font-weight:600;font-family:'DM Sans',sans-serif;">
               Delete My Account
            </button>
          </div>

          <!-- Account Deletion Confirmation Modal -->
          <div id="delete-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:100;align-items:center;justify-content:center;">
            <div style="background:white;border-radius:16px;padding:36px 32px;max-width:440px;width:90%;">
              <h3 style="font-family:'Playfair Display',serif;font-size:1.3rem;color:var(--navy);margin-bottom:10px;">⚠️ Delete account permanently?</h3>
              <p style="color:var(--text-muted);font-size:.86rem;margin-bottom:18px;line-height:1.6;">
                This will permanently delete your account (<strong><?= e($user['email']) ?></strong>) and all associated data. <strong>This action cannot be undone.</strong>
              </p>
              <form method="POST" action="dashboard.php?tab=profile">
                <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"/>
                <input type="hidden" name="action"     value="delete_account"/>
                <label style="display:block;font-size:.82rem;font-weight:600;color:var(--navy);margin-bottom:6px;">Enter your password to confirm:</label>
                <input type="password" name="confirm_password" required placeholder="Your password"
                       style="width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;margin-bottom:18px;outline:none;"/>
                <div style="display:flex;gap:10px;">
                  <button type="button" onclick="closeDeleteModal()"
                          style="flex:1;padding:11px;border:1.5px solid var(--border);background:white;color:var(--text-muted);border-radius:7px;font-family:'DM Sans',sans-serif;font-size:.86rem;font-weight:600;cursor:pointer;">
                    Cancel
                  </button>
                  <button type="submit"
                          style="flex:2;padding:11px;background:var(--red);color:white;border:none;border-radius:7px;font-family:'DM Sans',sans-serif;font-size:.86rem;font-weight:700;cursor:pointer;">
                    Yes, Delete My Account
                  </button>
                </div>
              </form>
            </div>
          </div>

          <script>
            function openDeleteModal() {
              document.getElementById('delete-modal').style.display = 'flex';
            }
            function closeDeleteModal() {
              document.getElementById('delete-modal').style.display = 'none';
            }
          </script>
        </div>

      </div>

    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

</body>
</html>
