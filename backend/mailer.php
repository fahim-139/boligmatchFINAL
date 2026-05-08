<?php


require_once __DIR__ . '../PHPMailer/PHPMailer.php';
require_once __DIR__ . '../PHPMailer/SMTP.php';
require_once __DIR__ . '../PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;


/**
 * Send an email using Gmail SMTP via PHPMailer.
 *
 * @param  string $to       Recipient email address
 * @param  string $subject  Email subject line
 * @param  string $body     Plain text email body
 * @return bool             True if sent, false if failed
 */
function sendBoligMail(string $to, string $subject, string $body): bool
{
    $mail = new PHPMailer(true);

    try {
        // ── SMTP settings (Gmail) ─────────────────────────
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;

    
        $mail->Username   = 'fmd4965@gmail.com';      
        $mail->Password   = 'nwgi xknu ytrs hlqu';   

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // ── Sender and recipient ──────────────────────────
        $mail->setFrom('fmd4965@gmail.com', 'BoligMatch');
        $mail->addAddress($to);

        // ── Email content ─────────────────────────────────
        $mail->Subject = $subject;
        $mail->Body    = $body;

        // ── Send ──────────────────────────────────────────
        $mail->send();
        return true;

    } catch (Exception $e) {
        // Log error but don't crash the page
        error_log('[MAILER] Failed to send to ' . $to . ' — ' . $mail->ErrorInfo);
        return false;
    }
}
?>
