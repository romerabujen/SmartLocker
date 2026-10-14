<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/phpmailer/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/SMTP.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

function sendVerificationEmail(PDO $connection, int $userId, string $email, string $firstName): bool
{
    $token = bin2hex(random_bytes(32));
    $statement = $connection->prepare(
        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 24 HOUR))'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':token_hash' => hash('sha256', $token),
    ]);

    $baseUrl = defined('APP_BASE_URL') ? constant('APP_BASE_URL') : '';
    $mailFrom = defined('MAIL_FROM') ? constant('MAIL_FROM') : '';
    $verificationUrl = $baseUrl . '/pages/verify/verify.html?verify_token=' . urlencode($token);
    $subject = 'Verify your SmartLocker email address';
    $message = "Hello {$firstName},\n\nOpen the link below, then select Confirm email address to verify your SmartLocker account:\n{$verificationUrl}\n\nThis link expires in 24 hours.";
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = (string) constant('SMTP_HOST');
    $mailer->SMTPAuth = true;
    $mailer->Username = (string) constant('SMTP_USERNAME');
    $mailer->Password = (string) constant('SMTP_PASSWORD');
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Port = (int) constant('SMTP_PORT');
    $mailer->setFrom($mailFrom, 'SmartLocker');
    $mailer->addAddress($email);
    $mailer->Subject = $subject;
    $mailer->Body = $message;

    try {
        return $mailer->send();
    } catch (Exception $exception) {
        return false;
    }
}

function sendPasswordResetEmail(PDO $connection, int $userId, string $email, string $firstName): bool
{
    $token = bin2hex(random_bytes(32));
    $statement = $connection->prepare(
        'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
    );
    $statement->execute([':user_id' => $userId, ':token_hash' => hash('sha256', $token)]);

    $baseUrl = defined('APP_BASE_URL') ? constant('APP_BASE_URL') : '';
    $mailFrom = defined('MAIL_FROM') ? constant('MAIL_FROM') : '';
    $resetUrl = $baseUrl . '/pages/reset-password/reset-password.html?token=' . urlencode($token);
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = (string) constant('SMTP_HOST');
    $mailer->SMTPAuth = true;
    $mailer->Username = (string) constant('SMTP_USERNAME');
    $mailer->Password = (string) constant('SMTP_PASSWORD');
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Port = (int) constant('SMTP_PORT');
    $mailer->setFrom($mailFrom, 'SmartLocker');
    $mailer->addAddress($email);
    $mailer->Subject = 'Reset your SmartLocker password';
    $mailer->Body = "Hello {$firstName},\n\nReset your SmartLocker password using this link:\n{$resetUrl}\n\nThis link expires in 1 hour.";

    try {
        return $mailer->send();
    } catch (Exception $exception) {
        return false;
    }
}

function sendPasswordResetConfirmationEmail(PDO $connection, int $resetId, string $email, string $firstName): bool
{
    $token = bin2hex(random_bytes(32));
    $statement = $connection->prepare(
        'UPDATE password_reset_tokens
         SET confirmation_token_hash = :token_hash, confirmation_expires_at = DATE_ADD(NOW(), INTERVAL 1 HOUR)
         WHERE id = :id'
    );
    $statement->execute([':token_hash' => hash('sha256', $token), ':id' => $resetId]);

    $baseUrl = defined('APP_BASE_URL') ? constant('APP_BASE_URL') : '';
    $mailFrom = defined('MAIL_FROM') ? constant('MAIL_FROM') : '';
    $confirmationUrl = $baseUrl . '/pages/verify/verify.html?reset_token=' . urlencode($token);
    $mailer = new PHPMailer(true);
    $mailer->isSMTP();
    $mailer->Host = (string) constant('SMTP_HOST');
    $mailer->SMTPAuth = true;
    $mailer->Username = (string) constant('SMTP_USERNAME');
    $mailer->Password = (string) constant('SMTP_PASSWORD');
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mailer->Port = (int) constant('SMTP_PORT');
    $mailer->setFrom($mailFrom, 'SmartLocker');
    $mailer->addAddress($email);
    $mailer->Subject = 'Confirm your SmartLocker password reset';
    $mailer->Body = "Hello {$firstName},\n\nOpen the link below, then select Confirm password reset to apply your new password:\n{$confirmationUrl}\n\nThis link expires in 1 hour.";

    try {
        return $mailer->send();
    } catch (Exception $exception) {
        return false;
    }
}