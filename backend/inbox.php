<?php
// ================================================================
//  inbox.php — BoligMatch Messaging Inbox
//
//  Two-pane layout:
//    LEFT  — thread list (all conversations)
//    RIGHT — active conversation (chat bubble view)
//
//  Features:
//    - Thread list with unread counts + last-message preview
//    - Chat bubble view with read receipts
//    - AJAX send (no page reload) via send-message.php
//    - Real-time new message polling via poll.php (every 8s)
//    - Auto-marks messages as read when thread is opened
//    - Scam keyword warning shown before sending
//    - Safety banner on every conversation
//    - Pause polling when tab is hidden (saves resources)
// ================================================================

session_start();
require_once 'db.php';

// ── Auth ─────────────────────────────────────────────────────
if (!isset($_SESSION['user_id'])) {
    redirect('../frontend/login.html');
}
$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'];
$userName = $_SESSION['user_name'];

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken(32);
}
$csrf = $_SESSION['csrf_token'];

// Active thread from URL params
$activeListing = (int)($_GET['listing'] ?? 0);
$activeUser    = (int)($_GET['user']    ?? 0);

// Flash message
$flash     = $_SESSION['flash_msg']  ?? '';
$flashType = $_SESSION['flash_type'] ?? '';
unset($_SESSION['flash_msg'], $_SESSION['flash_type']);

// ================================================================
//  FETCH ALL CONVERSATION THREADS
//  Groups messages by unique (listing_id + other_user) pair
//  Uses ? placeholders because PDO does not allow reusing
//  the same named parameter with EMULATE_PREPARES=false
// ================================================================
$threadsStmt = $pdo->prepare("
    SELECT
        m.listing_id,
        l.title                                             AS listing_title,
        l.city,
        CASE WHEN m.sender_id=? THEN m.receiver_id
             ELSE m.sender_id END                          AS other_id,
        CASE WHEN m.sender_id=? THEN ru.first_name
             ELSE su.first_name END                        AS other_first,
        CASE WHEN m.sender_id=? THEN ru.last_name
             ELSE su.last_name END                         AS other_last,
        CASE WHEN m.sender_id=? THEN ru.role
             ELSE su.role END                              AS other_role,
        MAX(m.created_at)                                  AS last_at,
        SUM(CASE WHEN m.receiver_id=? AND m.is_read=0 THEN 1 ELSE 0 END) AS unread
    FROM   messages m
    JOIN   listings l  ON l.id  = m.listing_id
    JOIN   users    su ON su.id = m.sender_id
    JOIN   users    ru ON ru.id = m.receiver_id
    WHERE  m.sender_id=? OR m.receiver_id=?
    GROUP  BY m.listing_id,
              CASE WHEN m.sender_id=? THEN m.receiver_id ELSE m.sender_id END
    ORDER  BY last_at DESC
    LIMIT  60
");
// Pass $userId once for each ? placeholder (8 total)
$threadsStmt->execute([$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId]);
$threads     = $threadsStmt->fetchAll();
$totalUnread = (int)array_sum(array_column($threads, 'unread'));

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

// ================================================================
//  FETCH ACTIVE CONVERSATION
// ================================================================
$messages      = [];
$listingInfo   = null;
$otherUser     = null;

if ($activeListing && $activeUser) {
    // Load message history
    $msgStmt = $pdo->prepare("
        SELECT m.id, m.sender_id, m.body, m.is_read, m.flagged, m.created_at,
               u.first_name AS sender_first
        FROM   messages m
        JOIN   users    u ON u.id = m.sender_id
        WHERE  m.listing_id = ?
          AND  ((m.sender_id=? AND m.receiver_id=?)
             OR (m.sender_id=? AND m.receiver_id=?))
        ORDER  BY m.created_at ASC
        LIMIT  200
    ");
    $msgStmt->execute([$activeListing, $userId, $activeUser, $activeUser, $userId]);
    $messages = $msgStmt->fetchAll();

    // Listing header info
    $lStmt = $pdo->prepare("SELECT id, title, city, rent_monthly, status FROM listings WHERE id=? LIMIT 1");
    $lStmt->execute([$activeListing]);
    $listingInfo = $lStmt->fetch();

    // Other user info
    $uStmt = $pdo->prepare("SELECT id, first_name, last_name, role, email_verified, last_login FROM users WHERE id=? LIMIT 1");
    $uStmt->execute([$activeUser]);
    $otherUser = $uStmt->fetch();

    // Mark incoming messages as read
    $pdo->prepare("
        UPDATE messages SET is_read=1
        WHERE  listing_id=? AND sender_id=? AND receiver_id=? AND is_read=0
    ")->execute([$activeListing, $activeUser, $userId]);
}

$lastMsgId = !empty($messages) ? (int)end($messages)['id'] : 0;

function ago(string $dt): string {
    $d = time() - strtotime($dt);
    if ($d < 60)    return 'Just now';
    if ($d < 3600)  return floor($d/60).'m ago';
    if ($d < 86400) return floor($d/3600).'h ago';
    return date('d M', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Inbox<?= $totalUnread>0?" ({$totalUnread})":'' ?> — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{--navy:#0d1b2a;--teal:#1a7f6e;--tl:#22a08a;--tp:rgba(26,127,110,.08);--cream:#f7f3ee;--ww:#fdfaf6;--gold:#c9a84c;--gp:rgba(201,168,76,.1);--text:#0d1b2a;--muted:#6b7280;--border:#e5e0d8;--red:#dc2626;--rp:rgba(220,38,38,.07);--green:#16a34a;--SB:310px;}
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    html,body{height:100%;}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);color:var(--text);display:flex;flex-direction:column;height:100vh;overflow:hidden;}
    /* NAV */
    nav{background:rgba(253,250,246,.95);backdrop-filter:blur(12px);border-bottom:1px solid var(--border);height:60px;display:flex;align-items:center;justify-content:space-between;padding:0 28px;flex-shrink:0;}
    .logo{font-family:'Playfair Display',serif;font-size:1.4rem;font-weight:900;color:var(--navy);text-decoration:none;}
    .logo span{color:var(--teal);}
    .nav-r{display:flex;align-items:center;gap:9px;}
    .nl{font-size:.84rem;color:var(--muted);text-decoration:none;padding:6px 11px;border-radius:7px;transition:all .2s;}
    .nl:hover{color:var(--navy);background:var(--cream);}
    .nb{padding:8px 16px;border-radius:7px;text-decoration:none;font-size:.84rem;font-weight:600;}
    .nb.s{background:var(--teal);color:white;} .nb.s:hover{background:var(--tl);}
    .nb.g{border:1.5px solid var(--border);color:var(--muted);} .nb.g:hover{border-color:var(--teal);color:var(--teal);}
    /* FLASH */
    .flash{padding:9px 20px;font-size:.82rem;display:flex;align-items:center;gap:8px;flex-shrink:0;}
    .flash.ok {background:rgba(22,163,74,.07);border-bottom:1px solid rgba(22,163,74,.15);color:var(--green);}
    .flash.err{background:var(--rp);border-bottom:1px solid rgba(220,38,38,.15);color:var(--red);}
    /* SHELL */
    .shell{display:flex;flex:1;overflow:hidden;}
    /* SIDEBAR */
    .sidebar{width:var(--SB);flex-shrink:0;background:white;border-right:1px solid var(--border);display:flex;flex-direction:column;overflow:hidden;}
    .sb-head{padding:13px 17px;border-bottom:1px solid var(--border);flex-shrink:0;}
    .sb-title{font-family:'Playfair Display',serif;font-size:1.05rem;font-weight:700;color:var(--navy);display:flex;align-items:center;gap:8px;}
    .ubadge{background:var(--red);color:white;font-size:.6rem;font-weight:700;padding:2px 6px;border-radius:100px;}
    .sb-count{font-size:.73rem;color:var(--muted);margin-top:2px;}
    .sb-search{padding:8px 13px;border-bottom:1px solid var(--border);flex-shrink:0;}
    .sb-search input{width:100%;padding:8px 11px;border-radius:7px;border:1.5px solid var(--border);background:var(--cream);font-family:'DM Sans',sans-serif;font-size:.83rem;color:var(--text);outline:none;transition:border-color .2s;}
    .sb-search input:focus{border-color:var(--teal);}
    .sb-search input::placeholder{color:#c0bab2;}
    .thread-list{flex:1;overflow-y:auto;}
    /* Thread item */
    .ti{display:flex;align-items:flex-start;gap:10px;padding:12px 15px;border-bottom:1px solid rgba(229,224,216,.6);cursor:pointer;transition:background .15s;text-decoration:none;color:inherit;position:relative;}
    .ti:hover{background:var(--cream);}
    .ti.active{background:var(--tp);border-left:3px solid var(--teal);padding-left:12px;}
    .ti.unread .ti-name{font-weight:700;}
    .ti.unread .ti-prev{color:var(--text);font-weight:500;}
    .ti-av{width:38px;height:38px;border-radius:9px;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:white;font-family:'Playfair Display',serif;font-size:.9rem;font-weight:700;}
    .ti-av.s{background:linear-gradient(135deg,var(--teal),#0a2a40);}
    .ti-av.o{background:linear-gradient(135deg,var(--gold),#8b5e1a);}
    .ti-body{flex:1;min-width:0;}
    .ti-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:2px;}
    .ti-name{font-size:.84rem;color:var(--navy);}
    .ti-time{font-size:.65rem;color:var(--muted);flex-shrink:0;}
    .ti-listing{font-size:.7rem;color:var(--teal);margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .ti-prev{font-size:.75rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
    .ti-dot{position:absolute;top:14px;right:11px;width:8px;height:8px;border-radius:50%;background:var(--teal);}
    /* Sidebar empty */
    .sb-empty{padding:48px 20px;text-align:center;}
    .sb-empty .ei{font-size:2rem;margin-bottom:10px;opacity:.3;}
    .sb-empty .et{font-size:.87rem;font-weight:600;color:var(--muted);margin-bottom:4px;}
    .sb-empty .es{font-size:.76rem;color:#c0bab2;line-height:1.5;}
    .sb-empty a{display:inline-block;margin-top:13px;padding:9px 18px;background:var(--teal);color:white;border-radius:7px;text-decoration:none;font-size:.82rem;font-weight:600;}
    /* CONV PANE */
    .conv{flex:1;display:flex;flex-direction:column;overflow:hidden;background:var(--ww);}
    /* Conv header */
    .ch{padding:11px 20px;background:white;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:11px;flex-shrink:0;}
    .ch-dot{width:8px;height:8px;border-radius:50%;background:#d1d5db;flex-shrink:0;}
    .ch-dot.on{background:var(--green);}
    .ch-av{width:36px;height:36px;border-radius:8px;flex-shrink:0;display:flex;align-items:center;justify-content:center;color:white;font-family:'Playfair Display',serif;font-weight:700;font-size:.9rem;}
    .ch-av.s{background:linear-gradient(135deg,var(--teal),#0a2a40);}
    .ch-av.o{background:linear-gradient(135deg,var(--gold),#8b5e1a);}
    .ch-info{flex:1;}
    .ch-name{font-size:.89rem;font-weight:700;color:var(--navy);}
    .ch-meta{font-size:.7rem;color:var(--muted);margin-top:1px;}
    .ch-link{font-size:.73rem;padding:5px 11px;border-radius:6px;border:1px solid var(--border);color:var(--muted);text-decoration:none;transition:all .18s;white-space:nowrap;flex-shrink:0;}
    .ch-link:hover{border-color:var(--teal);color:var(--teal);}
    /* Safety */
    .safety{padding:8px 20px;background:var(--gp);border-bottom:1px solid rgba(201,168,76,.22);display:flex;align-items:center;gap:8px;flex-shrink:0;}
    .safety-txt{font-size:.73rem;color:#7c5a00;line-height:1.4;flex:1;}
    .safety-x{background:none;border:none;font-size:.72rem;color:#7c5a00;cursor:pointer;opacity:.6;flex-shrink:0;}
    .safety-x:hover{opacity:1;}
    /* Messages */
    .msgs{flex:1;overflow-y:auto;padding:16px 20px;display:flex;flex-direction:column;gap:9px;}
    .date-div{display:flex;align-items:center;gap:9px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:1px;color:var(--muted);margin:3px 0;}
    .date-div::before,.date-div::after{content:'';flex:1;height:1px;background:var(--border);}
    .mrow{display:flex;align-items:flex-end;gap:6px;}
    .mrow.me{flex-direction:row-reverse;}
    .mav{width:26px;height:26px;border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.68rem;font-weight:700;color:white;}
    .mav.t{background:linear-gradient(135deg,var(--teal),#0a2a40);}
    .mav.m{background:linear-gradient(135deg,var(--navy),#1b2d45);}
    .bwrap{max-width:72%;display:flex;flex-direction:column;gap:3px;}
    .mrow.me .bwrap{align-items:flex-end;}
    .bubble{padding:9px 13px;border-radius:12px;font-size:.85rem;line-height:1.6;word-break:break-word;}
    .bubble.t{background:white;color:var(--text);border:1px solid var(--border);border-bottom-left-radius:3px;}
    .bubble.m{background:var(--navy);color:white;border-bottom-right-radius:3px;}
    .bubble.flag{border-color:var(--red);background:var(--rp);color:var(--text);}
    .btime{font-size:.61rem;color:var(--muted);padding:0 2px;}
    .mrow.me .btime{text-align:right;}
    .flag-lbl{font-size:.6rem;background:var(--rp);color:var(--red);padding:1px 6px;border-radius:100px;font-weight:700;margin-left:4px;}
    /* Typing dots */
    .typing{display:flex;align-items:flex-end;gap:6px;display:none;}
    .tdots{display:flex;gap:3px;}
    .tdots span{width:6px;height:6px;border-radius:50%;background:var(--muted);animation:bounce 1.2s infinite;}
    .tdots span:nth-child(2){animation-delay:.2s;} .tdots span:nth-child(3){animation-delay:.4s;}
    @keyframes bounce{0%,60%,100%{transform:translateY(0);}30%{transform:translateY(-5px);}}
    /* Compose */
    .compose{padding:13px 20px;background:white;border-top:1px solid var(--border);flex-shrink:0;}
    .scam-alert{display:none;background:var(--rp);border:1px solid rgba(220,38,38,.2);border-radius:8px;padding:8px 11px;margin-bottom:8px;font-size:.76rem;color:var(--red);line-height:1.5;}
    .scam-alert.show{display:block;}
    .compose-row{display:flex;gap:8px;align-items:flex-end;}
    .cta{flex:1;padding:10px 12px;border-radius:9px;border:1.5px solid var(--border);background:var(--ww);font-family:'DM Sans',sans-serif;font-size:.86rem;color:var(--text);resize:none;outline:none;line-height:1.6;transition:border-color .2s;min-height:42px;max-height:120px;overflow-y:auto;}
    .cta:focus{border-color:var(--teal);background:white;}
    .cta::placeholder{color:#c0bab2;}
    .csend{padding:10px 17px;background:var(--teal);color:white;border:none;border-radius:9px;font-family:'DM Sans',sans-serif;font-size:.87rem;font-weight:700;cursor:pointer;transition:background .2s;flex-shrink:0;}
    .csend:hover{background:var(--tl);}
    .csend:disabled{opacity:.4;cursor:not-allowed;}
    .cf{display:flex;justify-content:space-between;margin-top:5px;font-size:.68rem;color:var(--muted);}
    .cstat.sending{color:var(--teal);} .cstat.ok{color:var(--green);} .cstat.err{color:var(--red);}
    /* Empty conv */
    .conv-empty{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:48px 28px;}
    .cet{font-family:'Playfair Display',serif;font-size:1.15rem;color:var(--navy);margin-bottom:7px;margin-top:14px;}
    .ces{font-size:.84rem;color:var(--muted);line-height:1.65;max-width:280px;}
    /* Scrollbars */
    .msgs::-webkit-scrollbar,.thread-list::-webkit-scrollbar{width:4px;}
    .msgs::-webkit-scrollbar-thumb,.thread-list::-webkit-scrollbar-thumb{background:var(--border);border-radius:2px;}
    @keyframes bubIn{from{opacity:0;transform:translateY(7px);}to{opacity:1;transform:translateY(0);}}
    .bub-new{animation:bubIn .22s ease;}
  </style>
</head>
<body>
<nav>
  <a href="../frontend/index.html" class="logo">Bolig<span>Match</span></a>
  <div class="nav-r">
    <a href="<?= $userRole==='owner'?'home-owner.php':'home-student.php' ?>" class="nl">← Dashboard</a>
    <?php if ($userRole==='owner'): ?>
      <a href="create-listing.php" class="nb s">+ Add Listing</a>
    <?php else: ?>
      <a href="listings.php" class="nb g">Browse Rooms</a>
    <?php endif; ?>
  </div>
</nav>

<?php if ($flash): ?>
  <div class="flash <?= $flashType==='success'?'ok':'err' ?>"><?= e($flash) ?></div>
<?php endif; ?>

<div class="shell">

  <!-- ── THREAD SIDEBAR ── -->
  <aside class="sidebar">
    <div class="sb-head">
      <div class="sb-title">
        Inbox
        <?php if ($totalUnread>0): ?><span class="ubadge"><?= $totalUnread ?></span><?php endif; ?>
      </div>
      <div class="sb-count"><?= count($threads) ?> conversation<?= count($threads)!==1?'s':'' ?></div>
    </div>
    <div class="sb-search">
      <input type="text" id="tsearch" placeholder="🔍 Search…" oninput="filterThreads(this.value)"/>
    </div>
    <div class="thread-list">
      <?php if (empty($threads)): ?>
        <div class="sb-empty">
          <div class="ei">💬</div>
          <div class="et">No conversations yet</div>
          <div class="es"><?= $userRole==='student'?'Browse rooms and message an owner to get started.':'Students will message you about your listings.' ?></div>
          <?php if ($userRole==='student'): ?><a href="listings.php">Browse Rooms →</a><?php endif; ?>
        </div>
      <?php else: ?>
        <?php foreach ($threads as $t):
          $isActive = ($t['listing_id']==$activeListing && $t['other_id']==$activeUser);
          $hasUnread= (int)$t['unread']>0;
          $av       = $t['other_role']==='owner'?'o':'s';
          $init     = strtoupper(substr($t['other_first'],0,1));
        ?>
          <a href="inbox.php?listing=<?= $t['listing_id'] ?>&user=<?= $t['other_id'] ?>"
             class="ti <?= $isActive?'active':'' ?> <?= $hasUnread?'unread':'' ?>"
             data-n="<?= e(strtolower($t['other_first'].' '.$t['other_last'])) ?>"
             data-l="<?= e(strtolower($t['listing_title'])) ?>">
            <div class="ti-av <?= $av ?>"><?= $init ?></div>
            <div class="ti-body">
              <div class="ti-top">
                <span class="ti-name"><?= e($t['other_first'].' '.$t['other_last']) ?></span>
                <span class="ti-time"><?= ago($t['last_at']) ?></span>
              </div>
              <div class="ti-listing">🏠 <?= e($t['listing_title']) ?></div>
              <div class="ti-prev"><?= e($t['preview']) ?><?= strlen((string)$t['preview'])>=80?'…':'' ?></div>
            </div>
            <?php if ($hasUnread): ?><div class="ti-dot"></div><?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </aside>

  <!-- ── CONVERSATION PANE ── -->
  <main class="conv">
    <?php if ($activeListing && $activeUser && $otherUser && $listingInfo): ?>
      <?php
        $init2  = strtoupper(substr($otherUser['first_name'],0,1));
        $av2    = $otherUser['role']==='owner'?'o':'s';
        $online = $otherUser['last_login'] && (time()-strtotime($otherUser['last_login']))<10800;
      ?>
      <!-- Header -->
      <div class="ch">
        <div class="ch-dot <?= $online?'on':'' ?>"></div>
        <div class="ch-av <?= $av2 ?>"><?= $init2 ?></div>
        <div class="ch-info">
          <div class="ch-name">
            <?= e($otherUser['first_name'].' '.$otherUser['last_name']) ?>
            <?php if ($otherUser['email_verified']): ?>
              <span style="font-size:.62rem;background:rgba(22,163,74,.1);color:var(--green);padding:1px 7px;border-radius:100px;font-weight:600;margin-left:3px">✓</span>
            <?php endif; ?>
          </div>
          <div class="ch-meta">
            <?= ucfirst(e($otherUser['role'])) ?> ·
            <?= $online?'<span style="color:var(--green)">Active recently</span>':('Last seen '.ago($otherUser['last_login']??'')) ?>
          </div>
        </div>
        <a href="listing-detail.php?id=<?= $activeListing ?>" target="_blank" class="ch-link">
          🏠 <?= e($listingInfo['city']) ?> · DKK <?= number_format($listingInfo['rent_monthly']) ?>/mo →
        </a>
      </div>

      <!-- Safety -->
      <div class="safety" id="safetybanner">
        <span>🛡️</span>
        <div class="safety-txt"><strong>Stay safe:</strong> Never pay before viewing in person. Keep all communication here.
          <a href="../frontend/safety-tips.html" style="color:#7c5a00;font-weight:600">Safety tips →</a></div>
        <button class="safety-x" onclick="document.getElementById('safetybanner').style.display='none'">✕</button>
      </div>

      <!-- Messages -->
      <div class="msgs" id="msgs">
        <?php if (empty($messages)): ?>
          <div style="text-align:center;padding:28px;color:var(--muted);font-size:.84rem">Start the conversation. 👋</div>
        <?php else:
          $prevDate='';
          foreach ($messages as $msg):
            $mine    = $msg['sender_id']==$userId;
            $msgDate = date('d M Y', strtotime($msg['created_at']));
            $msgTime = date('H:i',   strtotime($msg['created_at']));
        ?>
          <?php if ($msgDate!==$prevDate): $prevDate=$msgDate; ?>
            <div class="date-div"><?= $msgDate ?></div>
          <?php endif; ?>
          <div class="mrow <?= $mine?'me':'' ?>">
            <div class="mav <?= $mine?'m':'t' ?>"><?= strtoupper(substr($msg['sender_first'],0,1)) ?></div>
            <div class="bwrap" <?= $mine?'style="align-items:flex-end"':'' ?>>
              <div class="bubble <?= $mine?'m':'t' ?> <?= ($msg['flagged']&&!$mine)?'flag':'' ?>">
                <?= nl2br(e($msg['body'])) ?>
                <?php if ($msg['flagged']&&!$mine): ?><span class="flag-lbl">⚠ Flagged</span><?php endif; ?>
              </div>
              <div class="btime">
                <?= $msgTime ?><?php if ($mine): ?> · <?= $msg['is_read']?'✓✓ Read':'✓ Sent' ?><?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; endif; ?>
        <!-- Typing indicator placeholder -->
        <div class="typing" id="typing">
          <div class="mav t"><?= $init2 ?></div>
          <div class="bwrap"><div class="bubble t" style="padding:8px 13px"><div class="tdots"><span></span><span></span><span></span></div></div></div>
        </div>
      </div>

      <!-- Compose -->
      <div class="compose">
        <div class="scam-alert" id="scam-alert">
          ⚠️ <strong>Possible scam language detected.</strong>
          Legitimate landlords never request gift cards, crypto, or wire transfers. Always view before paying.
        </div>
        <div class="compose-row">
          <textarea class="cta" id="cta"
            placeholder="Type a message… (Enter to send, Shift+Enter for new line)"
            maxlength="2000" rows="1"
            oninput="onInput(this)" onkeydown="onKey(event)"></textarea>
          <button class="csend" id="csend" onclick="send()" disabled>Send ➤</button>
        </div>
        <div class="cf">
          <span class="cstat" id="cstat">Enter to send · Shift+Enter for new line</span>
          <span id="cc">0/2000</span>
        </div>
      </div>

      <input type="hidden" id="jLid"   value="<?= $activeListing ?>"/>
      <input type="hidden" id="jRid"   value="<?= $activeUser ?>"/>
      <input type="hidden" id="jCsrf"  value="<?= $csrf ?>"/>
      <input type="hidden" id="jLast"  value="<?= $lastMsgId ?>"/>
      <input type="hidden" id="jMyI"   value="<?= strtoupper(substr($userName,0,1)) ?>"/>
      <input type="hidden" id="jThI"   value="<?= $init2 ?>"/>

    <?php else: ?>
      <div class="conv-empty">
        <div style="font-size:2.6rem;opacity:.15">💬</div>
        <div class="cet">
          <?= empty($threads)?'Your inbox is empty':'Select a conversation' ?>
        </div>
        <div class="ces">
          <?php if (empty($threads)): ?>
            <?= $userRole==='student'?'Browse rooms and send a message to an owner to get started.':'When students enquire about your listings, conversations will appear here.' ?>
          <?php else: ?>
            Choose a conversation from the left panel to read messages and reply.
          <?php endif; ?>
        </div>
        <?php if ($userRole==='student' && empty($threads)): ?>
          <a href="listings.php" style="display:inline-block;margin-top:16px;padding:10px 22px;background:var(--teal);color:white;border-radius:8px;text-decoration:none;font-weight:600;font-size:.84rem">Browse Rooms →</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </main>

</div><!-- /shell -->

<script>
  const SCAM = ['western union','wire transfer','gift card','bitcoin','cryptocurrency','moneygram','cashapp','zelle','venmo','paypal friends','paypal family','send deposit','pay before view','click here to pay'];
  const uid  = <?= $userId ?>;

  // Auto-scroll to bottom
  const msgs = document.getElementById('msgs');
  if (msgs) msgs.scrollTop = msgs.scrollHeight;

  // Mark as read on page load
  const jLid = document.getElementById('jLid')?.value;
  const jRid = document.getElementById('jRid')?.value;
  if (jLid && jRid) {
    fetch('mark-read.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
      body:new URLSearchParams({csrf_token:document.getElementById('jCsrf').value,listing_id:jLid,user_id:jRid})
    }).catch(()=>{});
  }

  // Compose handlers
  function onInput(el){
    el.style.height='auto';
    el.style.height=Math.min(el.scrollHeight,120)+'px';
    const l=el.value.length;
    document.getElementById('cc').textContent=l+'/2000';
    document.getElementById('csend').disabled=l<10||l>2000;
    document.getElementById('scam-alert').classList.toggle('show',SCAM.some(w=>el.value.toLowerCase().includes(w)));
  }
  function onKey(e){ if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();send();} }

  async function send(){
    const ta=document.getElementById('cta'), btn=document.getElementById('csend'), st=document.getElementById('cstat');
    const body=ta?.value?.trim(); if(!body||body.length<10) return;
    btn.disabled=true; btn.textContent='…';
    st.textContent='Sending…'; st.className='cstat sending';
    try{
      const r=await fetch('send-message.php',{method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded','X-Requested-With':'XMLHttpRequest'},
        body:new URLSearchParams({csrf_token:document.getElementById('jCsrf').value,listing_id:jLid,receiver_id:jRid,body})
      });
      const d=await r.json();
      if(d.success){
        addBubble({body,time:new Date().toLocaleTimeString('en-DK',{hour:'2-digit',minute:'2-digit'}),mine:true});
        ta.value=''; ta.style.height='auto';
        document.getElementById('cc').textContent='0/2000';
        document.getElementById('scam-alert').classList.remove('show');
        st.textContent='✓ Sent'; st.className='cstat ok';
        if(d.message_id) document.getElementById('jLast').value=d.message_id;
        setTimeout(()=>{st.textContent='Enter to send · Shift+Enter for new line';st.className='cstat';},2000);
      }else{st.textContent='⚠ '+(d.error||'Failed');st.className='cstat err';}
    }catch(e){st.textContent='⚠ Network error';st.className='cstat err';}
    btn.disabled=false; btn.textContent='Send ➤';
  }

  function addBubble({body,time,mine,initial}){
    const msgs=document.getElementById('msgs');
    const typing=document.getElementById('typing');
    const init=mine?document.getElementById('jMyI').value:(initial||document.getElementById('jThI').value);
    const row=document.createElement('div');
    row.className='mrow bub-new '+(mine?'me':'');
    row.innerHTML=`<div class="mav ${mine?'m':'t'}">${init}</div>
      <div class="bwrap" ${mine?'style="align-items:flex-end"':''}>
        <div class="bubble ${mine?'m':'t'}">${esc(body).replace(/\n/g,'<br/>')}</div>
        <div class="btime">${time}${mine?' · ✓ Sent':''}</div>
      </div>`;
    msgs.insertBefore(row,typing);
    msgs.scrollTop=msgs.scrollHeight;
  }

  function esc(s){return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

  // Polling
  let paused=false;
  if(jLid&&jRid){
    setInterval(()=>{
      if(paused) return;
      const since=document.getElementById('jLast').value;
      fetch(`poll.php?listing_id=${jLid}&user_id=${jRid}&since=${since}`)
        .then(r=>r.json())
        .then(data=>{
          if(!data.messages?.length) return;
          data.messages.forEach(m=>{
            if(m.sender_id==uid) return;
            addBubble({body:m.body,time:new Date(m.created_at).toLocaleTimeString('en-DK',{hour:'2-digit',minute:'2-digit'}),mine:false,initial:m.sender_first?.charAt(0).toUpperCase()});
            document.getElementById('jLast').value=Math.max(parseInt(document.getElementById('jLast').value)||0,m.id);
          });
          fetch('mark-read.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},
            body:new URLSearchParams({csrf_token:document.getElementById('jCsrf').value,listing_id:jLid,user_id:jRid})
          }).catch(()=>{});
        }).catch(()=>{});
    },8000);
    document.addEventListener('visibilitychange',()=>{ paused=document.hidden; });
  }

  // Thread search
  function filterThreads(q){
    q=q.toLowerCase().trim();
    document.querySelectorAll('.ti').forEach(el=>{
      el.style.display=(!q||el.dataset.n?.includes(q)||el.dataset.l?.includes(q))?'':'none';
    });
  }
</script>
</body>
</html>
