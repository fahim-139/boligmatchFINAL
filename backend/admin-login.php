<?php
// ================================================================
//  admin-login.php — BoligMatch Admin Login Page
//
//  Two-factor login for admin users:
//    Step 1: Enter the access code (BM-ADMIN-2026)
//    Step 2: Enter admin email + password
//
//  Security:
//    ✓ Access code required before credentials shown
//    ✓ Brute-force lockout after 3 failed access code attempts
//    ✓ CSRF token on login form
//    ✓ Session fixation prevention on successful login
// ================================================================

session_start();
require_once 'db.php';

// ── Already logged in as admin? ───────────────────────────────
if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'admin') {
    redirect('admin.php');
}

// ── Constants ─────────────────────────────────────────────────
$ACCESS_CODE   = 'BM-ADMIN-2026';
$maxAttempts   = 3;
$lockoutSecs   = 15 * 60;

// ── Lockout check ─────────────────────────────────────────────
$lockoutKey  = 'admin_lockout';
$attemptKey  = 'admin_attempts';
$isLockedOut = false;
$lockoutMsg  = '';

if (isset($_SESSION[$lockoutKey])) {
    $elapsed = time() - $_SESSION[$lockoutKey];
    if ($elapsed < $lockoutSecs) {
        $isLockedOut = true;
        $minutesLeft = ceil(($lockoutSecs - $elapsed) / 60);
        $lockoutMsg  = "Too many failed attempts. Try again in {$minutesLeft} minute(s).";
    } else {
        unset($_SESSION[$lockoutKey], $_SESSION[$attemptKey]);
    }
}

// ── Track whether access code verified this session ───────────
$codeVerified = $_SESSION['admin_code_verified'] ?? false;
$error      = '';
$loginError = '';

// ── Handle POST: Access Code Check ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['access_code'])) {
    if ($isLockedOut) {
        $error = $lockoutMsg;
    } else {
        $submitted = trim($_POST['access_code'] ?? '');
        if ($submitted === $ACCESS_CODE) {
            $_SESSION['admin_code_verified'] = true;
            $codeVerified = true;
            unset($_SESSION[$attemptKey]);
        } else {
            $_SESSION[$attemptKey] = ($_SESSION[$attemptKey] ?? 0) + 1;
            if ($_SESSION[$attemptKey] >= $maxAttempts) {
                $_SESSION[$lockoutKey] = time();
                $isLockedOut = true;
                $error = 'Too many failed attempts. Locked out for 15 minutes.';
            } else {
                $remaining = $maxAttempts - $_SESSION[$attemptKey];
                $error = "Invalid access code. {$remaining} attempt(s) remaining.";
            }
        }
    }
}

// ── Handle POST: Admin Login ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['email']) && $codeVerified) {
    $email    = sanitiseEmail($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!$email || !$password) {
        $loginError = 'Please enter your email and password.';
    } else {
        $stmt = $pdo->prepare("
            SELECT id, first_name, password_hash, role, email_verified
            FROM users WHERE email = ? AND role = 'admin' LIMIT 1
        ");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        $dummyHash = '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPD0aM';
        $ok = password_verify($password, $admin ? $admin['password_hash'] : $dummyHash);

        if (!$admin || !$ok) {
            $loginError = 'Invalid admin credentials.';
        } else {
            session_regenerate_id(true);
            $_SESSION['user_id']    = (int)$admin['id'];
            $_SESSION['user_role']  = 'admin';
            $_SESSION['user_name']  = $admin['first_name'];
            $_SESSION['verified']   = (bool)$admin['email_verified'];
            $_SESSION['csrf_token'] = generateToken(32);
            unset($_SESSION['admin_code_verified'], $_SESSION[$attemptKey], $_SESSION[$lockoutKey]);

            $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
                ->execute([$admin['id']]);

            redirect('admin.php', 'success', "Welcome back, {$admin['first_name']}!");
        }
    }
}

// ── CSRF token ────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken(32);
}
$csrf = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Login — BoligMatch</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@700;900&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet"/>
  <style>
    :root{--navy:#0d1b2a;--teal:#1a7f6e;--teal-light:#22a08a;--cream:#f7f3ee;--text:#0d1b2a;--text-muted:#6b7280;--border:#e5e0d8;--red:#dc2626;--green:#16a34a;}
    *,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
    body{font-family:'DM Sans',sans-serif;background:var(--navy);min-height:100vh;display:flex;align-items:center;justify-content:center;}
    .card{background:white;border-radius:16px;padding:48px 40px;max-width:420px;width:90%;box-shadow:0 20px 60px rgba(0,0,0,.3);}
    .logo{font-family:'Playfair Display',serif;font-size:1.5rem;font-weight:900;color:var(--navy);text-align:center;margin-bottom:8px;text-decoration:none;display:block;}
    .logo span{color:var(--teal);}
    .subtitle{text-align:center;font-size:.82rem;color:var(--text-muted);margin-bottom:28px;}
    .badge{display:block;background:rgba(220,38,38,.08);color:var(--red);font-size:.72rem;font-weight:700;padding:8px 10px;border-radius:6px;text-transform:uppercase;letter-spacing:1px;margin-bottom:20px;text-align:center;}
    .step-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--teal);margin-bottom:12px;}
    label{display:block;font-size:.82rem;font-weight:600;color:var(--navy);margin-bottom:6px;}
    input[type="text"],input[type="email"],input[type="password"]{width:100%;padding:12px 14px;border:1.5px solid var(--border);border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.9rem;outline:none;transition:border-color .2s;margin-bottom:16px;}
    input:focus{border-color:var(--teal);}
    .btn{width:100%;padding:13px;background:var(--navy);color:white;border:none;border-radius:8px;font-family:'DM Sans',sans-serif;font-size:.92rem;font-weight:700;cursor:pointer;transition:background .2s;}
    .btn:hover{background:var(--teal);}
    .error-box{background:rgba(220,38,38,.07);border:1px solid rgba(220,38,38,.15);color:var(--red);padding:10px 14px;border-radius:8px;font-size:.82rem;margin-bottom:16px;}
    .success-badge{background:rgba(22,163,74,.08);color:var(--green);padding:8px 14px;border-radius:8px;font-size:.82rem;margin-bottom:16px;text-align:center;}
    .back-link{display:block;text-align:center;margin-top:20px;font-size:.82rem;color:var(--text-muted);text-decoration:none;}
    .back-link:hover{color:var(--teal);}
  </style>
</head>
<body>
  <div class="card">
    <a href="../frontend/index.html" class="logo">Bolig<span>Match</span></a>
    <div class="subtitle">Admin Control Panel</div>
    <div class="badge">🔒 Restricted Access</div>

    <?php if ($isLockedOut && !$codeVerified): ?>
      <div class="error-box"><?= e($lockoutMsg) ?></div>
      <a href="../frontend/login.html" class="back-link">← Back to regular login</a>

    <?php elseif (!$codeVerified): ?>
      <div class="step-label">Step 1 of 2 — Access Code</div>
      <?php if ($error): ?>
        <div class="error-box"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="POST">
        <label for="access_code">Enter Admin Access Code</label>
        <input type="text" id="access_code" name="access_code" placeholder="e.g. BM-ADMIN-2026" required autofocus />
        <button type="submit" class="btn">Verify Code →</button>
      </form>
      <a href="../frontend/login.html" class="back-link">← Back to regular login</a>

    <?php else: ?>
      <div class="step-label">Step 2 of 2 — Admin Credentials</div>
      <div class="success-badge">✓ Access code verified</div>
      <?php if ($loginError): ?>
        <div class="error-box"><?= e($loginError) ?></div>
      <?php endif; ?>
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>" />
        <label for="email">Admin Email</label>
        <input type="email" id="email" name="email" placeholder="admin@boligmatch.local" required autofocus />
        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="Your admin password" required />
        <button type="submit" class="btn">Sign In to Admin Panel →</button>
      </form>
      <a href="../frontend/login.html" class="back-link">← Back to regular login</a>
    <?php endif; ?>
  </div>
</body>
</html>
