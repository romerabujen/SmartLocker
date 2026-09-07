<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$identifier = trim((string) ($_POST['identifier'] ?? ''));
$normalizedIdentifier = strtolower($identifier);
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$userStatement = getDatabaseConnection()->prepare(
    'SELECT id, email, student_id
     FROM users
     WHERE LOWER(email) = LOWER(:email_identifier) OR student_id = :student_identifier
     LIMIT 1'
);
$userStatement->execute([
    ':email_identifier' => $normalizedIdentifier,
    ':student_identifier' => $identifier,
]);
$user = $userStatement->fetch();

$verificationStatement = null;
$pendingResetStatement = null;
$message = null;
if ($user) {
    $verificationStatement = getDatabaseConnection()->prepare(
        'SELECT 1 FROM email_verification_tokens WHERE user_id = :user_id AND verified_at IS NOT NULL LIMIT 1'
    );
    $verificationStatement->execute([':user_id' => $user['id']]);
    if (!$verificationStatement->fetchColumn()) {
        $message = 'Please verify your email before logging in.';
    }

    $pendingResetStatement = getDatabaseConnection()->prepare(
        'SELECT 1 FROM password_reset_tokens
         WHERE user_id = :user_id AND pending_password_hash IS NOT NULL
           AND confirmed_at IS NULL AND confirmation_expires_at > NOW()
         LIMIT 1'
    );
    $pendingResetStatement->execute([':user_id' => $user['id']]);
    if ($pendingResetStatement->fetchColumn()) {
        $message = 'Please verify your password change before logging in. Check the email link sent to confirm the password reset.';
    }
}

$statement = getDatabaseConnection()->prepare(
    'SELECT failed_attempts FROM login_attempts
     WHERE identifier = :identifier AND ip_address = :ip_address LIMIT 1'
);
$statement->execute([':identifier' => $normalizedIdentifier, ':ip_address' => $ipAddress]);
$attempt = $statement->fetch();
$failedAttempts = (int) ($attempt['failed_attempts'] ?? 0);

$locked = $failedAttempts >= 5 && $message === null;

echo json_encode([
    'success' => true,
    'locked' => $locked,
    'requires_verification' => $message !== null,
    'message' => $message,
    'attempts_remaining' => max(0, 5 - $failedAttempts),
]);