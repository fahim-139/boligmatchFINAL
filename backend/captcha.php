<?php
// ================================================================
//  captcha.php — Generate Math CAPTCHA
//
//  Called by frontend/login.html via JavaScript fetch().
//  Returns a JSON object with the question to display.
//  The correct answer is stored in the session (never sent to browser).
//
//  Example response: { "question": "What is 7 + 3?" }
//
//  Security:
//    ✓ Answer stored server-side only (session)
//    ✓ New question generated every time this is called
//    ✓ Answer cleared after successful login (single-use)
// ================================================================

session_start();

header('Content-Type: application/json');

// Generate two random numbers
$num1 = rand(1, 10);
$num2 = rand(1, 10);

// Store the correct answer in the session
$_SESSION['captcha_answer'] = $num1 + $num2;

// Return only the question (NOT the answer)
echo json_encode([
    'question' => "What is {$num1} + {$num2}?"
]);
?>
