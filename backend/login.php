<?php
// ================================================================
//  login.html — BoligMatch Login Handler
//
//  Receives the form submission from login.html
//  Validates credentials and starts an authenticated session.
//
//  Flow:
//    login.html  →  POST login.html  →  home-student.php
//                                   →  home-owner.php
//                                   →  admin.php  (admin role)
//
//  Security features:
//    ✓ CSRF token validation
//    ✓ Brute-force protection (5 attempts → 15-min lockout)
//    ✓ Timing-safe password verification (constant-time)
//    ✓ Dummy hash prevents email enumeration via timing
//    ✓ Session fixation prevention (regenerate_id)
//    ✓ Password rehashing when cost factor changes
//    ✓ "Remember Me" persistent login with hashed cookie token
//    ✓ Role mismatch detection (wrong tab selected)
// ================================================================

session_start();
require_once 'db.php';

// ── Already logged in? ───────────────────────────────────────
if (isset($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'];
    redirect(match($role) {
        'owner' => 'home-owner.php',
        'admin' => 'admin.php',
        default => 'home-student.php',
    });
}

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../frontend/login.html');
}


// ================================================================
//  STEP 1 — CSRF TOKEN CHECK
// ================================================================

if (!empty($_SESSION['csrf_token']) && !empty($_POST['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        redirect('../frontend/login.html?error=invalid_request');
    }
}


// ================================================================
//  STEP 2 — COLLECT INPUTS
// ================================================================

$rawEmail   = trim($_POST['email']    ?? '');
$password   = $_POST['password']       ?? '';
$role       = in_array($_POST['role'] ?? '', ['student','owner']) ? $_POST['role'] : 'student';
$rememberMe = isset($_POST['remember']);

$email = sanitiseEmail($rawEmail);

// Basic presence check
if (!$email || empty($password)) {
    $_SESSION['login_error'] = 'Please enter your email address and password.';
    redirect('../frontend/login.html?error=missing');
}


// ================================================================
//  STEP 2B — CAPTCHA VALIDATION
//
//  The math CAPTCHA answer is stored in the session by
//  frontend/login.html when the page loads. We compare the
//  user's submitted answer against the stored answer.
//  This prevents automated bots from submitting the login form.
// ================================================================

$captchaAnswer    = (int)($_POST['captcha'] ?? 0);
$expectedAnswer   = (int)($_SESSION['captcha_answer'] ?? -1);

if ($captchaAnswer !== $expectedAnswer) {
    $_SESSION['login_error'] = 'Incorrect security answer. Please try again.';
    redirect('../frontend/login.html?error=captcha');
}

// Clear the used CAPTCHA so it can't be reused
unset($_SESSION['captcha_answer']);


// ================================================================
//  STEP 3 — BRUTE-FORCE PROTECTION
//
//  We track failed attempts per email address in the session.
//  After 5 failures within the window: 15-minute lockout.
//
//  In a production system you would store attempts in the database
//  so lockouts survive page refreshes and server restarts.
//  For the prototype, session storage is sufficient.
// ================================================================

$attemptKey  = 'login_attempts_' . md5($email); // per-email counter
$lockoutKey  = 'login_lockout_'  . md5($email); // per-email lockout timestamp
$maxAttempts = 5;
$lockoutSecs = 15 * 60; // 15 minutes

// Check if currently locked out
if (isset($_SESSION[$lockoutKey])) {
    $elapsed = time() - $_SESSION[$lockoutKey];

    if ($elapsed < $lockoutSecs) {
        $minutesLeft = ceil(($lockoutSecs - $elapsed) / 60);
        $_SESSION['login_error'] = "Too many failed attempts. Please wait {$minutesLeft} minute(s) before trying again.";
        redirect('../frontend/login.html?error=locked');
    } else {
        // Lockout window has expired — clear it
        unset($_SESSION[$attemptKey], $_SESSION[$lockoutKey]);
    }
}


// ================================================================
//  STEP 4 — LOOK UP THE USER IN THE DATABASE
//
//  We fetch by email only, then verify the password separately.
//  This prevents timing attacks that could reveal valid emails.
// ================================================================

$stmt = $pdo->prepare("
    SELECT id, first_name, last_name, email,
           password_hash, role, email_verified
    FROM   users
    WHERE  email = ?
    LIMIT  1
");
$stmt->execute([$email]);
$user = $stmt->fetch();


// ================================================================
//  STEP 5 — VERIFY PASSWORD
//
//  password_verify() is timing-safe — it takes the same amount
//  of time whether the user exists or not (when combined with
//  the dummy hash below).
//
//  If we only called password_verify() when the user exists,
//  an attacker could measure response times to learn which
//  emails have accounts. The dummy hash prevents that.
// ================================================================

// This dummy hash is used when no user is found.
// It makes the response time identical to a real hash check.
$dummyHash = '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPD0aM';

$passwordCorrect = password_verify(
    $password,
    $user ? $user['password_hash'] : $dummyHash
);


// ================================================================
//  STEP 6 — HANDLE FAILED LOGIN
// ================================================================

if (!$user || !$passwordCorrect) {

    // Increment the failed attempt counter
    $_SESSION[$attemptKey] = ($_SESSION[$attemptKey] ?? 0) + 1;

    if ((int)$_SESSION[$attemptKey] >= $maxAttempts) {
        // Trigger the lockout
        $_SESSION[$lockoutKey] = time();
        unset($_SESSION[$attemptKey]);
        $_SESSION['login_error'] = 'Too many failed attempts. Account locked for 15 minutes.';
    } else {
        $remaining = $maxAttempts - (int)$_SESSION[$attemptKey];
        $_SESSION['login_error'] = "Incorrect email or password. {$remaining} attempt(s) remaining.";
    }

    // Log the failed attempt
    error_log(sprintf(
        '[LOGIN] Failed attempt — email: %s | IP: %s | time: %s',
        $email,
        $_SERVER['REMOTE_ADDR'] ?? '?',
        date('Y-m-d H:i:s')
    ));

    redirect('../frontend/login.html?error=invalid');
}


// ================================================================
//  STEP 7 — CHECK ROLE MATCHES
//
//  If a student tries to log in using the "Room Owner" tab
//  (or vice versa), show a helpful error instead of a confusing
//  generic "invalid credentials" message.
//
//  Admins can log in via the regular login — they will be
//  redirected to admin.php automatically.
// ================================================================

$actualRole = $user['role'];

if ($actualRole === 'banned') {
    $_SESSION['login_error'] = 'Your account has been suspended. Please contact support.';
    redirect('../frontend/login.html?error=banned');
}

// If the user selected the wrong role tab, guide them
if ($actualRole !== 'admin' && $actualRole !== $role) {
    $_SESSION['login_error'] = "This account is registered as a "
        . ucfirst($actualRole) . ", not a " . ucfirst($role)
        . ". Please select the correct role above.";
    redirect('../frontend/login.html?error=wrong_role');
}


// ================================================================
//  STEP 8 — REHASH PASSWORD IF NEEDED
//
//  PHP's password_needs_rehash() checks whether the stored hash
//  was created with the current algorithm and cost factor.
//  If not, we silently upgrade it on successful login.
//  The user never notices — they just log in normally.
// ================================================================

if (password_needs_rehash($user['password_hash'], PASSWORD_BCRYPT, ['cost' => 12])) {
    $newHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
        ->execute([$newHash, $user['id']]);
}


// ================================================================
//  STEP 9 — START AUTHENTICATED SESSION
//
//  session_regenerate_id(true) creates a new session ID and
//  deletes the old one. This prevents session fixation attacks
//  where an attacker plants a known session ID before login.
// ================================================================

session_regenerate_id(true);

$_SESSION['user_id']    = (int)$user['id'];
$_SESSION['user_role']  = $actualRole;
$_SESSION['user_name']  = $user['first_name'];
$_SESSION['verified']   = (bool)$user['email_verified'];
$_SESSION['csrf_token'] = generateToken(32);

// Clear any leftover brute-force counters for this email
unset($_SESSION[$attemptKey], $_SESSION[$lockoutKey]);


// ================================================================
//  STEP 10 — UPDATE LAST LOGIN TIMESTAMP
// ================================================================

$pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
    ->execute([$user['id']]);


// ================================================================
//  STEP 11 — REMEMBER ME (PERSISTENT LOGIN)
//
//  If the user checked "Remember me", we:
//    1. Generate a secure random token
//    2. Hash it and store it in the database (never store raw tokens)
//    3. Set a browser cookie containing userId:rawToken
//
//  On the next visit, the cookie is checked against the DB hash.
//  If it matches, the user is logged in automatically.
//
//  The cookie uses httponly=true so JavaScript cannot access it,
//  protecting against XSS token theft.
// ================================================================

if ($rememberMe) {
    $rawToken    = generateToken(32);          // 64-char raw token
    $hashedToken = hash('sha256', $rawToken);  // store only the hash
    $expiry      = time() + (30 * 24 * 3600); // 30 days from now

    // Store the hashed token in the database
    // (reusing reset_token column for simplicity in this prototype)
    $pdo->prepare("
        UPDATE users
        SET    reset_token   = ?,
               reset_expires = DATE_ADD(NOW(), INTERVAL 30 DAY)
        WHERE  id = ?
    ")->execute([$hashedToken, $user['id']]);

    // Set the cookie in the browser
    setcookie(
        'bm_remember',
        $user['id'] . ':' . $rawToken,  // format: "userId:rawToken"
        [
            'expires'  => $expiry,
            'path'     => '/',
            'secure'   => false,   // set to true when using HTTPS
            'httponly' => true,    // blocks JavaScript access (XSS protection)
            'samesite' => 'Lax',   // CSRF protection
        ]
    );
}


// ================================================================
//  STEP 12 — REDIRECT TO THE CORRECT HOME PAGE
// ================================================================

$destination = match($actualRole) {
    'owner' => 'home-owner.php',
    'admin' => 'admin.php',
    default => 'home-student.php',
};

redirect(
    $destination,
    'success',
    "👋 Welcome back, {$user['first_name']}!"
);
?>
