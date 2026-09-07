<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$token = trim((string) ($_GET['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    header('Location: ' . APP_BASE_URL . '/pages/verify/verify.html?status=reset-invalid');
    exit;
}

$connection = getDatabaseConnection();
$statement = $connection->prepare(
    'SELECT id, user_id, pending_password_hash FROM password_reset_tokens
     WHERE confirmation_token_hash = :token_hash AND confirmed_at IS NULL
       AND confirmation_expires_at > NOW() AND pending_password_hash IS NOT NULL
     LIMIT 1'
);
$statement->execute([':token_hash' => hash('sha256', $token)]);
$reset = $statement->fetch();
if (!$reset) {
    header('Location: ' . APP_BASE_URL . '/pages/verify/verify.html?status=reset-expired');
    exit;
}

$connection->beginTransaction();
$statement = $connection->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id');
$statement->execute([':password_hash' => $reset['pending_password_hash'], ':user_id' => $reset['user_id']]);
$statement = $connection->prepare('UPDATE password_reset_tokens SET confirmed_at = NOW(), pending_password_hash = NULL WHERE id = :id');
$statement->execute([':id' => $reset['id']]);
$statement = $connection->prepare('DELETE FROM login_attempts WHERE user_id = :user_id');
$statement->execute([':user_id' => $reset['user_id']]);
$connection->commit();

header('Location: ' . APP_BASE_URL . '/pages/verify/verify.html?status=reset-success');
exit;