<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$token = trim((string) ($_GET['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    header('Location: ' . APP_BASE_URL . '/pages/verify/verify.html?status=invalid');
    exit;
}

$connection = getDatabaseConnection();
$statement = $connection->prepare(
    'SELECT id, user_id FROM email_verification_tokens
     WHERE token_hash = :token_hash AND verified_at IS NULL AND expires_at > NOW()
     LIMIT 1'
);
$statement->execute([':token_hash' => hash('sha256', $token)]);
$verification = $statement->fetch();

if (!$verification) {
    header('Location: ' . APP_BASE_URL . '/pages/verify/verify.html?status=expired');
    exit;
}

$statement = $connection->prepare(
    'SELECT email FROM users WHERE id = :user_id LIMIT 1'
);
$statement->execute([':user_id' => $verification['user_id']]);
$user = $statement->fetch();

$statement = $connection->prepare(
    'UPDATE email_verification_tokens SET verified_at = NOW() WHERE id = :id'
);
$statement->execute([':id' => $verification['id']]);

header('Location: ' . APP_BASE_URL . '/pages/verify/verify.html?status=success&email=' . urlencode((string) ($user['email'] ?? '')));
exit;