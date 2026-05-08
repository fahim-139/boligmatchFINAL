<?php
// ============================================================
//  admin.php — BoligMatch Admin Control Panel
//
//  Access: role='admin' only (via admin-login.html)
//
//  Tabs:
//    overview   — platform stats + activity feed
//    reports    — review / resolve user reports
//    pending    — approve / reject price-flagged listings
//    users      — search, ban, verify, delete users
//    messages   — flagged messages (scam keyword detections)
//    listings   — all listings (view / edit)
// ============================================================

session_start();
require_once 'db.php';

// ── Admin-only gate ──────────────────────────────────────────
if (!isset($_SESSION['user_id'])) { redirect('admin-login.php'); }
$checkStmt = $pdo->prepare("SELECT id, first_name, last_name FROM users WHERE id=? AND role='admin' LIMIT 1");
$checkStmt->execute([$_SESSION['user_id']]);
$admin = $checkStmt->fetch();
if (!$admin) {
    error_log("[ADMIN] Unauthorized access attempt by user_id:".$_SESSION['user_id']." IP:".($_SERVER['REMOTE_ADDR']??'?'));
    redirect('../frontend/login.html');
}

$adminId = (int)$admin['id'];

// ── CSRF ─────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = generateToken(32); }
$csrf = $_SESSION['csrf_token'];

// ── Tab ───────────────────────────────────────────────────────
$tab = in_array($_GET['tab']??'', ['overview','reports','pending','users','messages','listings','ownership'])
     ? ($_GET['tab']??'overview') : 'overview';

// ── POST actions ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (empty($_POST['csrf_token'])||!hash_equals($csrf,$_POST['csrf_token'])) {
        redirect("admin.php?tab={$tab}&error=csrf");
    }
    $action = $_POST['action'] ?? '';

    match($action) {
        'approve_listing' => (function() use ($pdo) {
            $id=(int)($_POST['listing_id']??0);
            $pdo->prepare("UPDATE listings SET status='active' WHERE id=?")->execute([$id]);
            redirect('admin.php?tab=pending&done=approved');
        })(),
        'reject_listing' => (function() use ($pdo) {
            $id=(int)($_POST['listing_id']??0);
            $pdo->prepare("UPDATE listings SET status='expired' WHERE id=?")->execute([$id]);
            redirect('admin.php?tab=pending&done=rejected');
        })(),
        'resolve_report' => (function() use ($pdo) {
            $rid=(int)($_POST['report_id']??0);
            $out=$_POST['outcome']??'dismiss';
            $pdo->prepare("UPDATE reports SET status='closed',resolved_at=NOW() WHERE id=?")->execute([$rid]);
            if ($out==='remove_listing'||$out==='ban_user') {
                $lid=(int)($_POST['listing_id']??0);
                $pdo->prepare("UPDATE listings SET status='expired' WHERE id=?")->execute([$lid]);
            }
            if ($out==='ban_user') {
                $uid=(int)($_POST['owner_id']??0);
                $pdo->prepare("UPDATE users SET role='banned' WHERE id=? AND role!='admin'")->execute([$uid]);
            }
            redirect('admin.php?tab=reports&done=resolved');
        })(),
        'ban_user' => (function() use ($pdo) {
            $uid=(int)($_POST['user_id']??0);
            $pdo->prepare("UPDATE users SET role='banned' WHERE id=? AND role!='admin'")->execute([$uid]);
            redirect('admin.php?tab=users&done=banned');
        })(),
        'unban_user' => (function() use ($pdo) {
            $uid=(int)($_POST['user_id']??0);
            $role=in_array($_POST['restore_role']??'',['student','owner'])?$_POST['restore_role']:'student';
            $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$role,$uid]);
            redirect('admin.php?tab=users&done=unbanned');
        })(),
        'verify_user' => (function() use ($pdo) {
            $uid=(int)($_POST['user_id']??0);
            $pdo->prepare("UPDATE users SET email_verified=1,verify_token=NULL WHERE id=?")->execute([$uid]);
            redirect('admin.php?tab=users&done=verified');
        })(),
        'delete_user' => (function() use ($pdo,$adminId) {
            $uid=(int)($_POST['user_id']??0);
            if ($uid===$adminId) redirect('admin.php?tab=users&error=self_delete');
            $pdo->prepare("DELETE FROM users WHERE id=? AND role!='admin'")->execute([$uid]);
            redirect('admin.php?tab=users&done=deleted');
        })(),
        'dismiss_message' => (function() use ($pdo) {
            $mid=(int)($_POST['message_id']??0);
            $pdo->prepare("UPDATE messages SET flagged=0 WHERE id=?")->execute([$mid]);
            redirect('admin.php?tab=messages&done=dismissed');
        })(),
        'delete_message' => (function() use ($pdo) {
            $mid=(int)($_POST['message_id']??0);
            $pdo->prepare("DELETE FROM messages WHERE id=?")->execute([$mid]);
            redirect('admin.php?tab=messages&done=deleted');
        })(),
        'approve_ownership' => (function() use ($pdo) {
            $uid = (int)($_POST['user_id'] ?? 0);
            // Mark ownership as verified
            $pdo->prepare("UPDATE users SET ownership_verified=1, ownership_rejected=0 WHERE id=? AND role='owner'")
                ->execute([$uid]);
            // Auto-activate any pending listings for this owner
            $pdo->prepare("UPDATE listings SET status='active' WHERE owner_id=? AND status='pending'")
                ->execute([$uid]);
            redirect('admin.php?tab=ownership&done=ownership_approved');
        })(),
        'reject_ownership' => (function() use ($pdo) {
            $uid = (int)($_POST['user_id'] ?? 0);
            // Get file path to delete the rejected document
            $st = $pdo->prepare("SELECT ownership_document FROM users WHERE id=?");
            $st->execute([$uid]);
            $doc = $st->fetchColumn();
            if ($doc) {
                $filePath = __DIR__ . '/../uploads/documents/' . $doc;
                if (file_exists($filePath)) { @unlink($filePath); }
            }
            // Clear document + mark as rejected
            $pdo->prepare("UPDATE users SET ownership_document=NULL, ownership_verified=0, ownership_rejected=1 WHERE id=? AND role='owner'")
                ->execute([$uid]);
            redirect('admin.php?tab=ownership&done=ownership_rejected');
        })(),
        default => null,
    };
}

// ── Stats (always needed for sidebar badges) ─────────────────
function qint(PDO $pdo, string $sql, array $p=[]): int {
    $s=$pdo->prepare($sql); $s->execute($p); return (int)$s->fetchColumn();
}
$s = [
    'users'    => qint($pdo,"SELECT COUNT(*) FROM users WHERE role NOT IN('admin','banned')"),
    'students' => qint($pdo,"SELECT COUNT(*) FROM users WHERE role='student'"),
    'owners'   => qint($pdo,"SELECT COUNT(*) FROM users WHERE role='owner'"),
    'banned'   => qint($pdo,"SELECT COUNT(*) FROM users WHERE role='banned'"),
    'listings' => qint($pdo,"SELECT COUNT(*) FROM listings"),
    'active'   => qint($pdo,"SELECT COUNT(*) FROM listings WHERE status='active'"),
    'pending'  => qint($pdo,"SELECT COUNT(*) FROM listings WHERE status='pending'"),
    'messages' => qint($pdo,"SELECT COUNT(*) FROM messages"),
    'flagged'  => qint($pdo,"SELECT COUNT(*) FROM messages WHERE flagged=1"),
    'reports'  => qint($pdo,"SELECT COUNT(*) FROM reports WHERE status='open'"),
    'new_week' => qint($pdo,"SELECT COUNT(*) FROM users WHERE created_at>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND role!='admin'"),
    'ownership_pending' => qint($pdo,"SELECT COUNT(*) FROM users WHERE role='owner' AND ownership_document IS NOT NULL AND ownership_verified=0"),
];

// ── Per-tab data ──────────────────────────────────────────────
$rows=[];
if ($tab==='reports') {
    $f=$_GET['filter']??'open';
    $w=in_array($f,['open','reviewed','closed'])?["r.status=?"]:[];
    $p=in_array($f,['open','reviewed','closed'])?[$f]:[];
    $where=$w?"WHERE ".implode(' AND ',$w):'';
    $st=$pdo->prepare("SELECT r.*,l.title AS lt,l.id AS lid,l.status AS ls,l.owner_id,u1.first_name AS rf,u1.email AS re,u2.first_name AS of,u2.last_name AS ol FROM reports r JOIN listings l ON l.id=r.listing_id JOIN users u1 ON u1.id=r.reporter_id JOIN users u2 ON u2.id=l.owner_id {$where} ORDER BY r.created_at DESC LIMIT 100");
    $st->execute($p); $rows=$st->fetchAll();
}
if ($tab==='pending') {
    $st=$pdo->prepare("SELECT l.*,u.first_name,u.last_name,u.email,u.email_verified FROM listings l JOIN users u ON u.id=l.owner_id WHERE l.status='pending' ORDER BY l.created_at DESC LIMIT 50");
    $st->execute(); $rows=$st->fetchAll();
}
if ($tab==='users') {
    $q=htmlspecialchars(trim($_GET['q']??''));
    $r=$_GET['role']??'all';
    $where="WHERE u.role!='admin'"; $p=[];
    if ($q){ $where.=" AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ?)"; $like="%{$q}%"; $p=[$like,$like,$like]; }
    if (in_array($r,['student','owner','banned'])){ $where.=" AND u.role=?"; $p[]=$r; }
    $st=$pdo->prepare("SELECT u.*,COUNT(DISTINCT l.id) lc,COUNT(DISTINCT m.id) mc FROM users u LEFT JOIN listings l ON l.owner_id=u.id LEFT JOIN messages m ON m.sender_id=u.id {$where} GROUP BY u.id ORDER BY u.created_at DESC LIMIT 200");
    $st->execute($p); $rows=$st->fetchAll();
}
if ($tab==='messages') {
    $st=$pdo->prepare("SELECT m.*,s.first_name sf,s.last_name sl,s.email se,r.first_name rf,l.title lt FROM messages m JOIN users s ON s.id=m.sender_id JOIN users r ON r.id=m.receiver_id JOIN listings l ON l.id=m.listing_id WHERE m.flagged=1 ORDER BY m.created_at DESC LIMIT 100");
    $st->execute(); $rows=$st->fetchAll();
}
if ($tab==='listings') {
    $lf=$_GET['status']??'all';
    $w=in_array($lf,['active','pending','expired'])?['l.status=?']:[];
    $p=in_array($lf,['active','pending','expired'])?[$lf]:[];
    $where=$w?"WHERE ".implode(' AND ',$w):'';
    $st=$pdo->prepare("SELECT l.*,u.first_name,u.last_name FROM listings l JOIN users u ON u.id=l.owner_id {$where} ORDER BY l.created_at DESC LIMIT 200");
    $st->execute($p); $rows=$st->fetchAll();
}
if ($tab==='ownership') {
    // Show owners who uploaded documents but aren't verified yet
    $st=$pdo->prepare("
        SELECT id, first_name, last_name, email, phone, created_at,
               ownership_document, ownership_uploaded_at, ownership_verified, ownership_rejected,
               (SELECT COUNT(*) FROM listings WHERE owner_id=users.id) AS listing_count
        FROM users
        WHERE role='owner' AND ownership_document IS NOT NULL AND ownership_verified=0
        ORDER BY ownership_uploaded_at ASC
    ");
    $st->execute(); $rows=$st->fetchAll();
}

// ── Activity feed (overview) ──────────────────────────────────
$activity=[];
if ($tab==='overview') {
    $st=$pdo->query("(SELECT 'user' t,id,CONCAT(first_name,' ',last_name) sub,created_at FROM users WHERE role!='admin')
    UNION ALL (SELECT 'listing',id,title,created_at FROM listings)
    UNION ALL (SELECT 'report',id,reason,created_at FROM reports)
    ORDER BY created_at DESC LIMIT 18");
    $activity=$st->fetchAll();
}

function pill(string $s):string{
    $m=['active'=>['green','● Active'],'pending'=>['gold','⏳ Pending'],'expired'=>['red','✕ Expired'],
        'open'=>['red','🔴 Open'],'closed'=>['green','✓ Closed'],'student'=>['teal','🎓 Student'],
        'owner'=>['navy','🏠 Owner'],'banned'=>['red','🚫 Banned']];
    [$c,$l]=$m[$s]??['muted',ucfirst($s)];
    return "<span class=\"pill pill-{$c}\">{$l}</span>";
}
function timeAgo(string $dt):string{
    $d=time()-strtotime($dt);
    if($d<60)return'Just now';if($d<3600)return floor($d/60).'m ago';
    if($d<86400)return floor($d/3600).'h ago';return date('d M Y',strtotime($dt));
}
$done=$_GET['done']??''; $err=$_GET['error']??'';
$doneMsg=['approved'=>'✅ Listing approved.','rejected'=>'✅ Listing rejected.','resolved'=>'✅ Report resolved.','banned'=>'🚫 User banned.','unbanned'=>'✅ User restored.','verified'=>'✅ Email verified.','deleted'=>'✅ Deleted.','dismissed'=>'✅ Flag dismissed.','ownership_approved'=>'✅ Ownership approved — all pending listings activated.','ownership_rejected'=>'✅ Ownership document rejected.'];
$errMsg=['csrf'=>'⚠️ Invalid request.','self_delete'=>'⚠️ Cannot delete your own account.'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Panel — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{--bg:#0a1628;--sb:#060f1c;--card:#0f1f35;--bdr:#1a2e48;--teal:#1a7f6e;--tl:#22a08a;--tg:rgba(26,127,110,.15);--red:#ef4444;--rp:rgba(239,68,68,.12);--green:#22c55e;--gp:rgba(34,197,94,.12);--gold:#c9a84c;--gop:rgba(201,168,76,.12);--blue:#3b82f6;--bp:rgba(59,130,246,.12);--text:#e2e8f0;--muted:#64748b;--dim:#334155;}
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;display:flex;}
    /* SIDEBAR */
    .sb{width:220px;flex-shrink:0;background:var(--sb);border-right:1px solid var(--bdr);display:flex;flex-direction:column;position:fixed;inset:0 auto 0 0;overflow-y:auto;z-index:50;}
    .sb-brand{padding:20px 18px 16px;border-bottom:1px solid var(--bdr);}
    .sb-logo{font-family:'Playfair Display',serif;font-size:1.3rem;font-weight:900;color:white;text-decoration:none;display:block;margin-bottom:8px;}
    .sb-logo span{color:var(--tl);}
    .sb-badge{display:inline-flex;align-items:center;gap:5px;background:var(--rp);border:1px solid rgba(239,68,68,.3);color:var(--red);font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;padding:3px 10px;border-radius:100px;}
    .sb-badge::before{content:'';width:5px;height:5px;border-radius:50%;background:var(--red);animation:blink 1.5s infinite;}
    @keyframes blink{0%,100%{opacity:1;}50%{opacity:.2;}}
    .sb-user{padding:12px 16px;border-bottom:1px solid var(--bdr);display:flex;align-items:center;gap:9px;}
    .sb-av{width:32px;height:32px;border-radius:7px;background:linear-gradient(135deg,var(--teal),#0a2a40);color:white;font-family:'Playfair Display',serif;font-size:.85rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .sb-un{font-size:.8rem;font-weight:600;color:var(--text);}
    .sb-ur{font-size:.65rem;color:var(--red);font-weight:700;text-transform:uppercase;letter-spacing:.8px;}
    .sb-nav{flex:1;padding:8px 0;}
    .sb-sec{padding:9px 16px 4px;font-size:.58rem;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:var(--dim);}
    .sb-a{display:flex;align-items:center;gap:9px;padding:8px 14px;margin:2px 7px;border-radius:7px;text-decoration:none;color:var(--muted);font-size:.81rem;font-weight:500;transition:all .18s;position:relative;}
    .sb-a:hover{background:rgba(255,255,255,.04);color:var(--text);}
    .sb-a.active{background:var(--tg);color:var(--tl);}
    .sb-a.active::before{content:'';position:absolute;left:0;top:50%;transform:translateY(-50%);width:3px;height:55%;background:var(--tl);border-radius:0 2px 2px 0;}
    .sb-cnt{margin-left:auto;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:100px;min-width:17px;text-align:center;}
    .sb-cnt.r{background:var(--red);color:white;}
    .sb-cnt.g{background:var(--gold);color:#3d2800;}
    .sb-cnt.b{background:var(--blue);color:white;}
    .sb-foot{padding:12px 16px;border-top:1px solid var(--bdr);}
    .sb-out{display:flex;align-items:center;gap:7px;color:var(--muted);font-size:.79rem;text-decoration:none;transition:color .2s;}
    .sb-out:hover{color:var(--red);}
    /* MAIN */
    .main{margin-left:220px;flex:1;display:flex;flex-direction:column;}
    .topbar{height:54px;background:var(--sb);border-bottom:1px solid var(--bdr);display:flex;align-items:center;justify-content:space-between;padding:0 28px;position:sticky;top:0;z-index:40;}
    .tb-title{font-family:'Playfair Display',serif;font-size:1rem;font-weight:700;color:var(--text);}
    .tb-right{display:flex;align-items:center;gap:10px;}
    .tb-time{font-size:.75rem;color:var(--muted);}
    .tb-site{padding:6px 13px;border-radius:6px;background:rgba(255,255,255,.05);border:1px solid var(--bdr);color:var(--muted);text-decoration:none;font-size:.76rem;transition:all .18s;}
    .tb-site:hover{border-color:var(--teal);color:var(--tl);}
    .content{padding:24px 28px 56px;flex:1;}
    /* FLASH */
    .flash{padding:10px 14px;border-radius:8px;margin-bottom:20px;display:flex;align-items:center;gap:9px;font-size:.82rem;animation:up .3s ease;}
    @keyframes up{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
    .flash-ok{background:var(--gp);border:1px solid rgba(34,197,94,.25);color:var(--green);}
    .flash-err{background:var(--rp);border:1px solid rgba(239,68,68,.25);color:var(--red);}
    /* STATS */
    .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:22px;}
    .sc{background:var(--card);border:1px solid var(--bdr);border-radius:10px;padding:16px 18px;transition:border-color .2s,transform .2s;}
    .sc:hover{border-color:var(--teal);transform:translateY(-2px);}
    .sc-ico{font-size:1.1rem;margin-bottom:8px;}
    .sc-val{font-family:'Playfair Display',serif;font-size:1.8rem;font-weight:700;color:white;line-height:1;}
    .sc-lbl{font-size:.7rem;color:var(--muted);margin-top:4px;}
    /* PANEL */
    .panel{background:var(--card);border:1px solid var(--bdr);border-radius:10px;margin-bottom:18px;overflow:hidden;}
    .ph{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;border-bottom:1px solid var(--bdr);}
    .ph-t{font-size:.8rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:7px;}
    .ph-t::before{content:'';width:3px;height:12px;border-radius:2px;background:var(--tl);display:block;}
    /* FILTER TABS */
    .ftabs{display:flex;gap:3px;padding:12px 18px 0;border-bottom:1px solid var(--bdr);}
    .ftab{padding:6px 13px;border-radius:6px 6px 0 0;font-size:.76rem;font-weight:600;text-decoration:none;color:var(--muted);border:1px solid transparent;border-bottom:none;margin-bottom:-1px;transition:all .18s;}
    .ftab:hover{color:var(--text);}
    .ftab.active{background:var(--card);border-color:var(--bdr);color:var(--tl);}
    /* TABLE */
    table{width:100%;border-collapse:collapse;}
    th{text-align:left;padding:9px 16px;font-size:.64rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);background:rgba(0,0,0,.15);border-bottom:1px solid var(--bdr);white-space:nowrap;}
    td{padding:12px 16px;border-bottom:1px solid rgba(26,46,72,.6);font-size:.81rem;color:var(--text);vertical-align:middle;}
    tr:last-child td{border-bottom:none;}
    tbody tr:hover td{background:rgba(255,255,255,.015);}
    .td-p{font-weight:600;color:white;}
    .td-s{font-size:.71rem;color:var(--muted);margin-top:2px;}
    /* PILLS */
    .pill{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:100px;font-size:.67rem;font-weight:700;}
    .pill-green{background:var(--gp);color:var(--green);}
    .pill-red  {background:var(--rp);color:var(--red);}
    .pill-gold {background:var(--gop);color:var(--gold);}
    .pill-blue {background:var(--bp);color:var(--blue);}
    .pill-teal {background:var(--tg);color:var(--tl);}
    .pill-navy {background:rgba(255,255,255,.06);color:var(--muted);}
    .pill-muted{background:rgba(255,255,255,.04);color:var(--muted);}
    /* ACTIONS */
    .acts{display:flex;gap:5px;flex-wrap:wrap;}
    .btn{padding:4px 10px;border-radius:6px;font-family:'DM Sans',sans-serif;font-size:.72rem;font-weight:600;cursor:pointer;transition:all .18s;border:1px solid transparent;display:inline-flex;align-items:center;gap:4px;text-decoration:none;white-space:nowrap;}
    .btn-ok  {background:var(--gp);color:var(--green);border-color:rgba(34,197,94,.2);}  .btn-ok:hover{background:var(--green);color:white;border-color:var(--green);}
    .btn-err {background:var(--rp);color:var(--red);border-color:rgba(239,68,68,.2);}   .btn-err:hover{background:var(--red);color:white;}
    .btn-dim {background:rgba(255,255,255,.04);color:var(--muted);border-color:var(--bdr);}  .btn-dim:hover{border-color:var(--muted);color:var(--text);}
    .btn-teal{background:var(--tg);color:var(--tl);border-color:rgba(26,127,110,.25);}  .btn-teal:hover{background:var(--teal);color:white;}
    .btn-green{background:rgba(22,163,74,.12);color:#4ade80;border-color:rgba(22,163,74,.3);}  .btn-green:hover{background:#16a34a;color:white;}
    .btn-red  {background:rgba(239,68,68,.12);color:#fca5a5;border-color:rgba(239,68,68,.3);}  .btn-red:hover{background:#dc2626;color:white;}
    /* REPORT CARD */
    .rcard{background:rgba(0,0,0,.2);border:1px solid var(--bdr);border-radius:8px;padding:14px;margin-bottom:1px;}
    .rc-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;gap:10px;}
    .rc-meta{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:10px;}
    .rm{font-size:.73rem;color:var(--muted);}
    .rm strong{color:var(--text);display:block;margin-bottom:1px;}
    .rc-body{font-size:.79rem;color:var(--muted);background:rgba(0,0,0,.15);padding:9px 11px;border-radius:6px;line-height:1.6;margin-bottom:10px;}
    .rc-foot{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding-top:10px;border-top:1px solid var(--bdr);}
    .out-sel{padding:5px 9px;border-radius:6px;background:rgba(0,0,0,.3);border:1px solid var(--bdr);color:var(--text);font-family:'DM Sans',sans-serif;font-size:.76rem;outline:none;}
    /* SEARCH BAR */
    .sbar{display:flex;gap:8px;padding:12px 18px;border-bottom:1px solid var(--bdr);}
    .si{flex:1;padding:8px 11px;border-radius:7px;background:rgba(0,0,0,.3);border:1px solid var(--bdr);color:var(--text);font-family:'DM Sans',sans-serif;font-size:.8rem;outline:none;transition:border-color .2s;}
    .si:focus{border-color:var(--teal);}
    .si::placeholder{color:var(--dim);}
    .ss{padding:8px 11px;border-radius:7px;background:rgba(0,0,0,.3);border:1px solid var(--bdr);color:var(--text);font-family:'DM Sans',sans-serif;font-size:.79rem;outline:none;}
    /* MSG CARD */
    .mcard{padding:14px 18px;border-bottom:1px solid rgba(26,46,72,.5);}
    .mcard:last-child{border-bottom:none;}
    .mc-head{display:flex;justify-content:space-between;margin-bottom:7px;}
    .mc-from{font-size:.81rem;font-weight:600;color:var(--text);}
    .mc-body{background:rgba(239,68,68,.06);border:1px solid rgba(239,68,68,.15);border-radius:6px;padding:9px 12px;font-size:.79rem;color:var(--muted);line-height:1.55;margin-bottom:9px;}
    .mc-foot{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:7px;}
    /* OVERVIEW GRID */
    .ov{display:grid;grid-template-columns:1fr 300px;gap:18px;}
    .act-item{display:flex;align-items:flex-start;gap:10px;padding:9px 16px;border-bottom:1px solid rgba(26,46,72,.5);}
    .act-item:last-child{border-bottom:none;}
    .act-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;margin-top:4px;}
    .act-dot.u{background:var(--tl);}
    .act-dot.l{background:var(--gold);}
    .act-dot.r{background:var(--red);}
    .act-text{font-size:.78rem;color:var(--text);line-height:1.45;}
    .act-time{font-size:.66rem;color:var(--muted);margin-top:2px;}
    .qact{padding:14px;display:flex;flex-direction:column;gap:7px;}
    .qa{display:flex;align-items:center;justify-content:space-between;padding:9px 12px;border-radius:7px;border:1px solid var(--bdr);background:rgba(0,0,0,.15);text-decoration:none;font-size:.8rem;color:var(--text);transition:border-color .18s;}
    .qa:hover{border-color:var(--teal);}
    /* EMPTY */
    .empty{padding:48px 24px;text-align:center;}
    .ei{font-size:2.2rem;margin-bottom:12px;opacity:.3;}
    .et{font-size:.9rem;font-weight:600;color:var(--text);margin-bottom:5px;}
    .es{font-size:.77rem;color:var(--muted);line-height:1.6;}
  </style>
</head>
<body>

<!-- SIDEBAR -->
<aside class="sb">
  <div class="sb-brand">
    <a href="../frontend/index.html" class="sb-logo">Bolig<span>Match</span></a>
    <span class="sb-badge">Admin Panel</span>
  </div>
  <div class="sb-user">
    <div class="sb-av"><?= strtoupper(substr($admin['first_name'],0,1)) ?></div>
    <div><div class="sb-un"><?= e($admin['first_name'].' '.$admin['last_name']) ?></div><div class="sb-ur">Administrator</div></div>
  </div>
  <nav class="sb-nav">
    <div class="sb-sec">Dashboard</div>
    <a href="?tab=overview"  class="sb-a <?= $tab==='overview'?'active':'' ?>">📊 Overview</a>

    <div class="sb-sec">Moderation</div>
    <a href="?tab=reports"   class="sb-a <?= $tab==='reports'?'active':'' ?>">🚩 Reports <?php if($s['reports']>0): ?><span class="sb-cnt r"><?= $s['reports'] ?></span><?php endif; ?></a>
    <a href="?tab=pending"   class="sb-a <?= $tab==='pending'?'active':'' ?>">⏳ Pending <?php if($s['pending']>0): ?><span class="sb-cnt g"><?= $s['pending'] ?></span><?php endif; ?></a>
    <a href="?tab=ownership" class="sb-a <?= $tab==='ownership'?'active':'' ?>">📄 Ownership <?php if($s['ownership_pending']>0): ?><span class="sb-cnt g"><?= $s['ownership_pending'] ?></span><?php endif; ?></a>
    <a href="?tab=messages"  class="sb-a <?= $tab==='messages'?'active':'' ?>">💬 Flagged <?php if($s['flagged']>0): ?><span class="sb-cnt r"><?= $s['flagged'] ?></span><?php endif; ?></a>

    <div class="sb-sec">Users & Listings</div>
    <a href="?tab=users"     class="sb-a <?= $tab==='users'?'active':'' ?>">👥 Users <?php if($s['banned']>0): ?><span class="sb-cnt b"><?= $s['banned'] ?> banned</span><?php endif; ?></a>
    <a href="?tab=listings"  class="sb-a <?= $tab==='listings'?'active':'' ?>">🏠 All Listings</a>

    <div class="sb-sec">Site</div>
    <a href="dashboard.php"  class="sb-a">← Back to Dashboard</a>
  </nav>
  <div class="sb-foot"><a href="logout.php?token=<?= $csrf ?>" class="sb-out">🚪 Sign Out</a></div>
</aside>

<!-- MAIN -->
<div class="main">
  <div class="topbar">
    <?php $ttitles=['overview'=>'Overview','reports'=>'Reports Queue','pending'=>'Pending Listings','users'=>'User Management','messages'=>'Flagged Messages','listings'=>'All Listings','ownership'=>'Ownership Verification']; ?>
    <div class="tb-title"><?= $ttitles[$tab]??'Admin Panel' ?></div>
    <div class="tb-right">
      <span class="tb-time" id="clk"></span>
      <a href="../frontend/index.html" target="_blank" class="tb-site">View Site ↗</a>
    </div>
  </div>

  <div class="content">

    <!-- Flash -->
    <?php if ($done&&isset($doneMsg[$done])): ?><div class="flash flash-ok"><?= $doneMsg[$done] ?></div><?php endif; ?>
    <?php if ($err &&isset($errMsg[$err])):  ?><div class="flash flash-err"><?= $errMsg[$err]  ?></div><?php endif; ?>


    <!-- ══════ OVERVIEW ══════ -->
    <?php if ($tab==='overview'): ?>
      <div class="stats">
        <div class="sc"><div class="sc-ico">👥</div><div class="sc-val"><?= $s['users'] ?></div><div class="sc-lbl">Total Users (+<?= $s['new_week'] ?> this week)</div></div>
        <div class="sc"><div class="sc-ico">🏠</div><div class="sc-val"><?= $s['active'] ?></div><div class="sc-lbl">Active Listings</div></div>
        <div class="sc"><div class="sc-ico">🚩</div><div class="sc-val" style="color:<?= $s['reports']>0?'var(--red)':'white' ?>"><?= $s['reports'] ?></div><div class="sc-lbl">Open Reports</div></div>
        <div class="sc"><div class="sc-ico">⏳</div><div class="sc-val" style="color:<?= $s['pending']>0?'var(--gold)':'white' ?>"><?= $s['pending'] ?></div><div class="sc-lbl">Pending Listings</div></div>
        <div class="sc"><div class="sc-ico">🎓</div><div class="sc-val"><?= $s['students'] ?></div><div class="sc-lbl">Students</div></div>
        <div class="sc"><div class="sc-ico">🔑</div><div class="sc-val"><?= $s['owners'] ?></div><div class="sc-lbl">Owners</div></div>
        <div class="sc"><div class="sc-ico">🚫</div><div class="sc-val" style="color:<?= $s['banned']>0?'var(--red)':'white' ?>"><?= $s['banned'] ?></div><div class="sc-lbl">Banned Accounts</div></div>
        <div class="sc"><div class="sc-ico">⚠️</div><div class="sc-val" style="color:<?= $s['flagged']>0?'var(--red)':'white' ?>"><?= $s['flagged'] ?></div><div class="sc-lbl">Flagged Messages</div></div>
      </div>

      <div class="ov">
        <div class="panel">
          <div class="ph"><div class="ph-t">Recent Activity</div></div>
          <?php if (empty($activity)): ?>
            <div class="empty"><div class="ei">📋</div><div class="et">No activity yet</div></div>
          <?php else: ?>
            <?php foreach ($activity as $a):
              $dc=['user'=>'u','listing'=>'l','report'=>'r'][$a['t']]??'u';
              $label=match($a['t']){
                'user'    =>"New user: <strong>".e($a['sub'])."</strong>",
                'listing' =>"New listing: <strong>".e($a['sub'])."</strong>",
                'report'  =>"Report filed — <strong>".e($a['sub'])."</strong>",
                default   =>e($a['sub']),
              };
            ?>
              <div class="act-item">
                <div class="act-dot <?= $dc ?>"></div>
                <div><div class="act-text"><?= $label ?></div><div class="act-time"><?= timeAgo($a['created_at']) ?></div></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <div>
          <div class="panel" style="margin-bottom:14px">
            <div class="ph"><div class="ph-t">Quick Actions</div></div>
            <div class="qact">
              <a href="?tab=reports"  class="qa">🚩 Review reports <?php if($s['reports']>0): ?><span class="pill pill-red"><?= $s['reports'] ?></span><?php endif; ?></a>
              <a href="?tab=pending"  class="qa">⏳ Approve listings <?php if($s['pending']>0): ?><span class="pill pill-gold"><?= $s['pending'] ?></span><?php endif; ?></a>
              <a href="?tab=messages" class="qa">💬 Flagged messages <?php if($s['flagged']>0): ?><span class="pill pill-red"><?= $s['flagged'] ?></span><?php endif; ?></a>
              <a href="?tab=users"    class="qa">👥 Manage users <span class="pill pill-teal"><?= $s['users'] ?></span></a>
            </div>
          </div>
          <div class="panel" style="border-color:rgba(239,68,68,.2)">
            <div class="ph" style="border-color:rgba(239,68,68,.15)"><div class="ph-t" style="color:var(--red)">⚠️ Platform Health</div></div>
            <div style="padding:12px 16px;display:flex;flex-direction:column;gap:7px">
              <?php foreach ([
                [$s['reports']===0,'No open reports',$s['reports'].' open'],
                [$s['pending']===0,'No pending listings',$s['pending'].' pending'],
                [$s['flagged']===0,'No flagged messages',$s['flagged'].' flagged'],
                [$s['banned']===0,'No banned users',$s['banned'].' banned'],
              ] as [$ok,$good,$bad]): ?>
                <div style="font-size:.78rem;color:<?= $ok?'var(--green)':'var(--red)' ?>;display:flex;gap:7px">
                  <span><?= $ok?'✓':'⚠' ?></span><span><?= $ok?$good:$bad ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>


    <!-- ══════ REPORTS ══════ -->
    <?php elseif ($tab==='reports'): ?>
      <?php $f=$_GET['filter']??'open'; ?>
      <div class="panel">
        <div class="ftabs">
          <?php foreach(['open'=>'🔴 Open','reviewed'=>'👁 Reviewed','closed'=>'✓ Closed',''=>'All'] as $fv=>$fl): ?>
            <a href="?tab=reports&filter=<?= $fv ?>" class="ftab <?= ($f===$fv)?'active':'' ?>"><?= $fl ?></a>
          <?php endforeach; ?>
        </div>
        <?php if (empty($rows)): ?>
          <div class="empty"><div class="ei">🛡️</div><div class="et">No <?= $f ?> reports</div><div class="es">Reports submitted by users appear here.</div></div>
        <?php else: ?>
          <div style="padding:14px 16px;display:flex;flex-direction:column;gap:10px">
            <?php foreach ($rows as $r): ?>
              <div class="rcard">
                <div class="rc-head">
                  <div>
                    <div style="font-size:.84rem;font-weight:700;color:white;margin-bottom:3px"><?= pill($r['status']) ?> &nbsp;<?= e($r['lt']) ?></div>
                    <div style="font-size:.71rem;color:var(--muted)">Listing #<?= $r['lid'] ?> · <?= pill($r['ls']) ?></div>
                  </div>
                  <div style="font-size:.68rem;color:var(--muted);flex-shrink:0"><?= timeAgo($r['created_at']) ?></div>
                </div>
                <div class="rc-meta">
                  <div class="rm"><strong>Reporter</strong><?= e($r['rf']) ?><br/><span style="font-size:.68rem"><?= e($r['re']) ?></span></div>
                  <div class="rm"><strong>Owner</strong><?= e($r['of'].' '.$r['ol']) ?></div>
                  <div class="rm"><strong>Reason</strong><?= pill(str_replace('_',' ',$r['reason'])) ?></div>
                </div>
                <?php if ($r['details']): ?><div class="rc-body">"<?= e($r['details']) ?>"</div><?php endif; ?>
                <?php if ($r['status']==='open'): ?>
                  <div class="rc-foot">
                    <span style="font-size:.7rem;color:var(--muted);font-weight:600">Outcome:</span>
                    <form method="POST" action="admin.php?tab=reports" style="display:flex;gap:7px;align-items:center;flex-wrap:wrap">
                      <input type="hidden" name="csrf_token" value="<?= $csrf ?>"/>
                      <input type="hidden" name="action"     value="resolve_report"/>
                      <input type="hidden" name="report_id"  value="<?= $r['id'] ?>"/>
                      <input type="hidden" name="listing_id" value="<?= $r['lid'] ?>"/>
                      <input type="hidden" name="owner_id"   value="<?= $r['owner_id'] ?>"/>
                      <select name="outcome" class="out-sel">
                        <option value="dismiss">Dismiss — no action</option>
                        <option value="remove_listing">Remove listing</option>
                        <option value="ban_user">Remove listing + Ban owner</option>
                      </select>
                      <button type="submit" class="btn btn-ok">✓ Resolve</button>
                      <a href="listing-detail.php?id=<?= $r['lid'] ?>" target="_blank" class="btn btn-dim">👁 View</a>
                    </form>
                  </div>
                <?php else: ?>
                  <div style="padding-top:8px;border-top:1px solid var(--bdr);font-size:.72rem;color:var(--muted)">Resolved — no further action needed.</div>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>


    <!-- ══════ PENDING ══════ -->
    <?php elseif ($tab==='pending'): ?>
      <div class="panel">
        <div class="ph"><div class="ph-t">Price-Flagged Listings — Awaiting Approval</div></div>
        <?php if (empty($rows)): ?>
          <div class="empty"><div class="ei">✅</div><div class="et">No pending listings</div><div class="es">Listings with rent far below city average are held here for review.</div></div>
        <?php else: ?>
          <table>
            <thead><tr><th>Listing</th><th>Owner</th><th>Rent / Deposit</th><th>City</th><th>Submitted</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><div class="td-p"><?= e($r['title']) ?></div><div class="td-s"><?= e($r['room_type']) ?> room</div></td>
                  <td><div class="td-p"><?= e($r['first_name'].' '.$r['last_name']) ?></div><div class="td-s"><?= e($r['email']) ?> <?= $r['email_verified']?'✓':'' ?></div></td>
                  <td><div style="color:var(--gold);font-weight:700;font-size:.83rem">DKK <?= number_format($r['rent_monthly']) ?>/mo</div><div class="td-s"><?= $r['deposit_months'] ?> mo. deposit</div></td>
                  <td><?= e($r['city']) ?><div class="td-s"><?= e($r['area']) ?></div></td>
                  <td style="font-size:.73rem"><?= date('d M Y',strtotime($r['created_at'])) ?></td>
                  <td>
                    <div class="acts">
                      <form method="POST" action="admin.php?tab=pending"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"/><input type="hidden" name="action" value="approve_listing"/><input type="hidden" name="listing_id" value="<?= $r['id'] ?>"/><button type="submit" class="btn btn-ok">✓ Approve</button></form>
                      <form method="POST" action="admin.php?tab=pending"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"/><input type="hidden" name="action" value="reject_listing"/><input type="hidden" name="listing_id" value="<?= $r['id'] ?>"/><button type="submit" class="btn btn-err">✕ Reject</button></form>
                      <a href="listing-detail.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-dim">👁</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>


    <!-- ══════ USERS ══════ -->
    <?php elseif ($tab==='users'): ?>
      <?php $q=e($_GET['q']??''); $rf=$_GET['role']??'all'; ?>
      <div class="panel">
        <div class="sbar">
          <form method="GET" action="admin.php" style="display:contents">
            <input type="hidden" name="tab" value="users"/>
            <input type="text" name="q" class="si" placeholder="Search by name or email…" value="<?= $q ?>"/>
            <select name="role" class="ss" onchange="this.form.submit()">
              <option value="all"     <?= $rf==='all'?    'selected':'' ?>>All roles</option>
              <option value="student" <?= $rf==='student'?'selected':'' ?>>Students</option>
              <option value="owner"   <?= $rf==='owner'?  'selected':'' ?>>Owners</option>
              <option value="banned"  <?= $rf==='banned'? 'selected':'' ?>>Banned</option>
            </select>
            <button type="submit" class="btn btn-dim">Search</button>
          </form>
        </div>
        <?php if (empty($rows)): ?>
          <div class="empty"><div class="ei">👥</div><div class="et">No users found</div></div>
        <?php else: ?>
          <table>
            <thead><tr><th>#</th><th>User</th><th>Role</th><th>Verified</th><th>Listings</th><th>Joined</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $u): ?>
                <tr>
                  <td style="color:var(--muted);font-size:.72rem"><?= $u['id'] ?></td>
                  <td><div class="td-p"><?= e($u['first_name'].' '.$u['last_name']) ?></div><div class="td-s"><?= e($u['email']) ?></div></td>
                  <td><?= pill($u['role']) ?></td>
                  <td><?= $u['email_verified']?'<span class="pill pill-green">✓ Yes</span>':'<span class="pill pill-red">✗ No</span>' ?></td>
                  <td style="text-align:center"><?= (int)$u['lc'] ?></td>
                  <td style="font-size:.72rem"><?= date('d M Y',strtotime($u['created_at'])) ?></td>
                  <td>
                    <div class="acts">
                      <?php if ($u['role']!=='banned'): ?>
                        <form method="POST" action="admin.php?tab=users"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"/><input type="hidden" name="action" value="ban_user"/><input type="hidden" name="user_id" value="<?= $u['id'] ?>"/><button type="submit" class="btn btn-err" onclick="return confirm('Ban <?= e($u['first_name']) ?>?')">🚫 Ban</button></form>
                      <?php else: ?>
                        <form method="POST" action="admin.php?tab=users" style="display:flex;gap:5px;align-items:center"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"/><input type="hidden" name="action" value="unban_user"/><input type="hidden" name="user_id" value="<?= $u['id'] ?>"/><select name="restore_role" class="ss" style="font-size:.7rem;padding:4px 7px"><option value="student">Student</option><option value="owner">Owner</option></select><button type="submit" class="btn btn-ok">↩ Unban</button></form>
                      <?php endif; ?>
                      <?php if (!$u['email_verified']): ?>
                        <form method="POST" action="admin.php?tab=users"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"/><input type="hidden" name="action" value="verify_user"/><input type="hidden" name="user_id" value="<?= $u['id'] ?>"/><button type="submit" class="btn btn-teal">✓ Verify</button></form>
                      <?php endif; ?>
                      <form method="POST" action="admin.php?tab=users"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"/><input type="hidden" name="action" value="delete_user"/><input type="hidden" name="user_id" value="<?= $u['id'] ?>"/><button type="submit" class="btn btn-err" onclick="return confirm('Permanently delete <?= e($u['first_name']) ?>?')">🗑️</button></form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>


    <!-- ══════ FLAGGED MESSAGES ══════ -->
    <?php elseif ($tab==='messages'): ?>
      <div class="panel">
        <div class="ph"><div class="ph-t">Auto-Flagged Messages (Scam Keywords)</div></div>
        <?php if (empty($rows)): ?>
          <div class="empty"><div class="ei">💬</div><div class="et">No flagged messages</div><div class="es">Messages containing scam keywords (Western Union, gift cards, etc.) are flagged and appear here.</div></div>
        <?php else: ?>
          <?php foreach ($rows as $m): ?>
            <div class="mcard">
              <div class="mc-head">
                <div class="mc-from"><span class="pill pill-red" style="margin-right:6px">⚠ Flagged</span><?= e($m['sf'].' '.$m['sl']) ?> → <?= e($m['rf']) ?></div>
                <div style="font-size:.68rem;color:var(--muted)"><?= timeAgo($m['created_at']) ?></div>
              </div>
              <div class="mc-body"><?= e($m['body']) ?></div>
              <div class="mc-foot">
                <div style="font-size:.7rem;color:var(--tl)">Re: <?= e($m['lt']) ?></div>
                <div class="acts">
                  <form method="POST" action="admin.php?tab=messages"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"/><input type="hidden" name="action" value="dismiss_message"/><input type="hidden" name="message_id" value="<?= $m['id'] ?>"/><button type="submit" class="btn btn-dim">✓ Dismiss</button></form>
                  <form method="POST" action="admin.php?tab=messages"><input type="hidden" name="csrf_token" value="<?= $csrf ?>"/><input type="hidden" name="action" value="delete_message"/><input type="hidden" name="message_id" value="<?= $m['id'] ?>"/><button type="submit" class="btn btn-err" onclick="return confirm('Delete this message?')">🗑️ Delete</button></form>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>


    <!-- ══════ ALL LISTINGS ══════ -->
    <?php elseif ($tab==='listings'): ?>
      <?php $lf=$_GET['status']??'all'; ?>
      <div class="panel">
        <div class="ftabs">
          <?php foreach(['all'=>'All','active'=>'Active','pending'=>'Pending','expired'=>'Expired'] as $fv=>$fl): ?>
            <a href="?tab=listings&status=<?= $fv ?>" class="ftab <?= ($lf===$fv)?'active':'' ?>"><?= $fl ?></a>
          <?php endforeach; ?>
        </div>
        <?php if (empty($rows)): ?>
          <div class="empty"><div class="ei">🏠</div><div class="et">No listings</div></div>
        <?php else: ?>
          <table>
            <thead><tr><th>Title</th><th>Owner</th><th>City</th><th>Rent</th><th>Views</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr>
                  <td><div class="td-p"><?= e($r['title']) ?></div><div class="td-s"><?= e($r['room_type']) ?></div></td>
                  <td><?= e($r['first_name'].' '.$r['last_name']) ?></td>
                  <td><?= e($r['city']) ?><div class="td-s"><?= e($r['area']) ?></div></td>
                  <td style="color:var(--tl);font-weight:700;font-size:.83rem">DKK <?= number_format($r['rent_monthly']) ?></td>
                  <td style="text-align:center"><?= (int)$r['view_count'] ?></td>
                  <td><?= pill($r['status']) ?></td>
                  <td style="font-size:.72rem"><?= date('d M Y',strtotime($r['created_at'])) ?></td>
                  <td>
                    <div class="acts">
                      <a href="listing-detail.php?id=<?= $r['id'] ?>" target="_blank" class="btn btn-dim">👁 View</a>
                      <a href="edit-listing.php?id=<?= $r['id'] ?>"   class="btn btn-teal">✏️ Edit</a>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <?php elseif ($tab==='ownership'): ?>
      <!-- ══════ OWNERSHIP VERIFICATION ══════ -->
      <div class="panel">
        <?php if (empty($rows)): ?>
          <div class="empty">
            <div class="ei">📄</div>
            <div class="et">No documents awaiting review</div>
            <div class="es">Owners who upload ownership documents will appear here for verification.</div>
          </div>
        <?php else: ?>
          <div style="padding:14px 18px;background:var(--tg);border-bottom:1px solid var(--bdr);color:var(--tl);font-size:.83rem;">
            📋 <strong><?= count($rows) ?></strong> owner<?= count($rows)!==1?'s':'' ?> awaiting ownership verification.
            Review their documents carefully before approving — approval will instantly activate all their pending listings.
          </div>
          <table>
            <thead>
              <tr>
                <th>Owner</th>
                <th>Contact</th>
                <th>Listings</th>
                <th>Uploaded</th>
                <th>Document</th>
                <th style="width:280px;">Decision</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $o): ?>
                <tr>
                  <td>
                    <div class="td-p"><?= e($o['first_name'].' '.$o['last_name']) ?></div>
                    <div class="td-s">Joined <?= date('d M Y', strtotime($o['created_at'])) ?></div>
                  </td>
                  <td>
                    <div class="td-p"><?= e($o['email']) ?></div>
                    <?php if ($o['phone']): ?>
                      <div class="td-s">📞 <?= e($o['phone']) ?></div>
                    <?php endif; ?>
                  </td>
                  <td style="text-align:center;">
                    <div class="td-p" style="font-size:1rem;"><?= (int)$o['listing_count'] ?></div>
                    <?php if ($o['listing_count']>0): ?>
                      <div class="td-s">pending</div>
                    <?php endif; ?>
                  </td>
                  <td style="font-size:.75rem;">
                    <?= date('d M Y', strtotime($o['ownership_uploaded_at'])) ?>
                    <div class="td-s"><?= date('H:i', strtotime($o['ownership_uploaded_at'])) ?></div>
                  </td>
                  <td>
                    <a href="view-document.php?user_id=<?= $o['id'] ?>" target="_blank" class="btn btn-teal">
                      📄 View PDF
                    </a>
                  </td>
                  <td>
                    <div class="acts" style="flex-wrap:wrap;">
                      <!-- Approve -->
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Approve this owner? All their pending listings will become active immediately.');">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"/>
                        <input type="hidden" name="action" value="approve_ownership"/>
                        <input type="hidden" name="user_id" value="<?= (int)$o['id'] ?>"/>
                        <button type="submit" class="btn btn-green">✓ Approve</button>
                      </form>

                      <!-- Reject -->
                      <form method="POST" style="display:inline;" onsubmit="return confirm('Reject this document? The owner will be asked to re-upload. The current file will be deleted.');">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>"/>
                        <input type="hidden" name="action" value="reject_ownership"/>
                        <input type="hidden" name="user_id" value="<?= (int)$o['id'] ?>"/>
                        <button type="submit" class="btn btn-red">✗ Reject</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    <?php endif; ?>

  </div>
</div>

<script>
  function updateClock(){const e=document.getElementById('clk');if(e)e.textContent=new Date().toLocaleTimeString('en-DK',{hour:'2-digit',minute:'2-digit',second:'2-digit'});}
  updateClock(); setInterval(updateClock,1000);
  document.querySelectorAll('.flash').forEach(e=>setTimeout(()=>{e.style.transition='opacity .5s';e.style.opacity='0';setTimeout(()=>e.remove(),500);},5000));
</script>
</body>
</html>
