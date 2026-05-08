<?php


session_start();
require_once 'db.php';

// ── Already logged in? ───────────────────────────────────────
if (isset($_SESSION['user_id'])) {
    $dest = $_SESSION['user_role'] === 'owner' ? 'home-owner.php' : 'home-student.php';
    redirect($dest);
}

// ── Only accept POST ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('../frontend/register.html');
}


// ================================================================
//  STEP 1 — CSRF TOKEN CHECK
//
//  The register.html form includes a hidden input:
//    <input type="hidden" name="csrf_token" value="...">
//
//  The token is stored in the session when register.html loads
//  (register.html needs to be renamed to register.php, or the
//  token can be fetched via a small PHP include).
//
//  For this prototype we accept missing tokens gracefully
//  so the static register.html still works out of the box.
// ================================================================

if (!empty($_SESSION['csrf_token']) && !empty($_POST['csrf_token'])) {
    if (!hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        redirect('../frontend/register.html?error=invalid_request');
    }
}


// ================================================================
//  STEP 2 — COLLECT AND SANITISE ALL FORM INPUTS
// ================================================================

$firstName  = trim($_POST['first_name']  ?? '');
$lastName   = trim($_POST['last_name']   ?? '');
$rawEmail   = trim($_POST['email']       ?? '');
$phone      = trim($_POST['phone']       ?? '');
$role       = in_array($_POST['role'] ?? '', ['student','owner']) ? $_POST['role'] : 'student';
$password   = $_POST['password']         ?? '';
$confirm    = $_POST['confirm_password'] ?? '';
$agreedTOS  = isset($_POST['terms']);

// Role-specific fields
$university = trim($_POST['university'] ?? ''); // students only
$city       = trim($_POST['address']    ?? ''); // owners only — matches name="address" in the HTML form


// ================================================================
//  STEP 3 — SERVER-SIDE VALIDATION
//
//  Important: The browser already validates these fields with HTML5
//  attributes (required, minlength, etc.) — but we ALWAYS re-validate
//  on the server. A user can bypass browser validation trivially.
// ================================================================

$errors = [];

// Name validation
if (strlen($firstName) < 2 || strlen($firstName) > 80) {
    $errors[] = 'First name must be between 2 and 80 characters.';
}
if (strlen($lastName) < 2 || strlen($lastName) > 80) {
    $errors[] = 'Last name must be between 2 and 80 characters.';
}

// Email validation
$email = sanitiseEmail($rawEmail);
if (!$email) {
    $errors[] = 'Please enter a valid email address.';
} elseif (isDisposableEmail($email)) {
    $errors[] = 'Temporary or disposable email addresses are not allowed.';
}

// Password validation
if (strlen($password) < 8) {
    $errors[] = 'Password must be at least 8 characters long.';
}
if ($password !== $confirm) {
    $errors[] = 'Passwords do not match. Please check and try again.';
}

// Phone validation (optional field)
if ($phone && !preg_match('/^\+?[\d\s\-\(\)]{6,20}$/', $phone)) {
    $errors[] = 'Please enter a valid phone number.';
}

// Terms of service
if (!$agreedTOS) {
    $errors[] = 'You must agree to the Terms of Service to register.';
}

// If there are validation errors, redirect back with the errors stored in session
if (!empty($errors)) {
    $_SESSION['register_errors']  = $errors;
    $_SESSION['register_old_data'] = [
        'first_name' => $firstName,
        'last_name'  => $lastName,
        'email'      => $rawEmail,
        'phone'      => $phone,
        'role'       => $role,
        'university' => $university,
    ];
    redirect('../frontend/register.html?error=validation');
}


// ================================================================
//  STEP 4 — CHECK FOR DUPLICATE EMAIL
//
//  We use a prepared statement to query the database safely.
//  The ? placeholder is replaced by PDO with the actual email,
//  preventing SQL injection.
// ================================================================

$checkStmt = $pdo->prepare("
    SELECT id FROM users WHERE email = ? LIMIT 1
");
$checkStmt->execute([$email]);

if ($checkStmt->fetch()) {
    $_SESSION['register_errors'] = [
        'An account with this email already exists. '
        . '<a href="../frontend/login.html" style="color:var(--teal);font-weight:600">Sign in instead →</a>'
    ];
    redirect('../frontend/register.html?error=duplicate_email');
}


// ================================================================
//  STEP 5 — HASH THE PASSWORD
//
//  We NEVER store plain text passwords.
//  password_hash() uses bcrypt with an automatic random salt.
//  Cost 12 means it takes ~250ms to hash — slow enough to make
//  brute-force attacks impractical.
// ================================================================

$passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);


// ================================================================
//  STEP 6 — GENERATE EMAIL VERIFICATION TOKEN
//
//  A 64-character random token is stored in the database and
//  emailed to the user. They click the link to verify ownership
//  of their email address.
//
//  Until verified: users can browse and save rooms, but cannot
//  send messages to owners.
// ================================================================

$verifyToken = generateToken(32); // produces a 64-char hex string


// ================================================================
//  STEP 7 — INSERT THE NEW USER INTO THE DATABASE
// ================================================================

try {
    $insertStmt = $pdo->prepare("
        INSERT INTO users (
            first_name,
            last_name,
            email,
            phone,
            password_hash,
            role,
            university,
            address_city,
            email_verified,
            verify_token,
            created_at
        ) VALUES (
            :first_name,
            :last_name,
            :email,
            :phone,
            :password_hash,
            :role,
            :university,
            :address_city,
            0,
            :verify_token,
            NOW()
        )
    ");

    $insertStmt->execute([
        ':first_name'   => $firstName,
        ':last_name'    => $lastName,
        ':email'        => $email,
        ':phone'        => $phone    ?: null,
        ':password_hash'=> $passwordHash,
        ':role'         => $role,
        ':university'   => $role === 'student' ? ($university ?: null) : null,
        ':address_city' => $role === 'owner'   ? ($city       ?: null) : null,
        ':verify_token' => $verifyToken,
    ]);

    $newUserId = (int)$pdo->lastInsertId();

} catch (PDOException $e) {
    // Log the real error privately
    error_log('Registration DB error: ' . $e->getMessage());

    // Show a safe message to the user
    $_SESSION['register_errors'] = [
        'Registration failed due to a server error. Please try again.'
    ];
    redirect('../frontend/register.html?error=server');
}


// ================================================================
//  STEP 8 — SEND VERIFICATION OTP VIA EMAIL
//
//  Generate a 6-digit OTP and email it to the user using PHPMailer.
//  The user enters the code on verify-otp.php to verify their email.
// ================================================================

require_once 'mailer.php';

$otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

// Store OTP in the database
$pdo->prepare("UPDATE users SET verify_token = ? WHERE id = ?")
    ->execute([$otp, $newUserId]);

// Send the OTP via email
$subject = 'Verify your email — BoligMatch';
$body  = "Hi {$firstName},\n\n";
$body .= "Welcome to BoligMatch! Please use the code below to verify your email address:\n\n";
$body .= "    Verification Code: {$otp}\n\n";
$body .= "Enter this code on the verification page within 10 minutes.\n\n";
$body .= "If you did not register on BoligMatch, please ignore this email.\n\n";
$body .= "Stay safe — always view a room in person before paying any deposit.\n\n";
$body .= "— The BoligMatch Team";

$mailSent = sendBoligMail($email, $subject, $body);

if (!$mailSent) {
    error_log("Verification email failed for user_id={$newUserId} email={$email}");
}


// ================================================================
//  STEP 9 — LOG THE USER IN IMMEDIATELY
// ================================================================

session_regenerate_id(true);

$_SESSION['user_id']    = $newUserId;
$_SESSION['user_role']  = $role;
$_SESSION['user_name']  = $firstName;
$_SESSION['verified']   = false;
$_SESSION['otp_email']  = $email;
$_SESSION['mail_sent']  = $mailSent;

// Generate a fresh CSRF token for the new session
$_SESSION['csrf_token'] = generateToken(32);

// Clear any leftover registration error data
unset($_SESSION['register_errors'], $_SESSION['register_old_data']);


// ================================================================
//  STEP 10 — REDIRECT OWNERS TO DOCUMENT UPLOAD, STUDENTS TO OTP
// ================================================================

if ($role === 'owner') {
    // Owners must also upload ownership document
    redirect(
        'upload-document.php',
        'success',
        "🎉 Welcome to BoligMatch, {$firstName}! Please upload your property ownership document to complete registration."
    );
} else {
    // Students go directly to email verification
    redirect(
        'verify-otp.php',
        'success',
        "🎉 Welcome to BoligMatch, {$firstName}! Please check your email for a verification code."
    );
}
?>
