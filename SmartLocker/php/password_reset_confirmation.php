<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim((string) ($_GET['token'] ?? ''));
    $confirmationUrl = APP_BASE_URL . '/pages/verify/verify.html';
    $confirmationUrl .= preg_match('/^[a-f0-9]{64}$/', $token)
        ? '?reset_token=' . rawurlencode($token)
        : '?status=reset-expired';
    header('Location: ' . $confirmationUrl, true, 303);
    exit;
}

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$token = trim((string) ($_POST['token'] ?? ''));
if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'This confirmation link is invalid or expired. Request a new password reset.']);
    exit;
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $statement = $connection->prepare(
        'SELECT id, user_id, pending_password_hash FROM password_reset_tokens
         WHERE confirmation_token_hash = :token_hash AND confirmed_at IS NULL
           AND confirmation_expires_at > NOW() AND pending_password_hash IS NOT NULL
         LIMIT 1 FOR UPDATE'
    );
    $statement->execute([':token_hash' => hash('sha256', $token)]);
    $reset = $statement->fetch();
    if (!$reset) {
        $connection->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This confirmation link is invalid or expired. Request a new password reset.']);
        exit;
    }

    $statement = $connection->prepare('UPDATE users SET password_hash = :password_hash WHERE id = :user_id');
    $statement->execute([':password_hash' => $reset['pending_password_hash'], ':user_id' => $reset['user_id']]);
    $statement = $connection->prepare(
        'UPDATE password_reset_tokens
         SET confirmed_at = NOW(), pending_password_hash = NULL
         WHERE user_id = :user_id AND confirmed_at IS NULL AND pending_password_hash IS NOT NULL'
    );
    $statement->execute([':user_id' => $reset['user_id']]);
    $statement = $connection->prepare('DELETE FROM login_attempts WHERE user_id = :user_id');
    $statement->execute([':user_id' => $reset['user_id']]);
    $connection->commit();

    echo json_encode(['success' => true, 'message' => 'Your password has been changed successfully. You can now sign in securely.']);
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log('SmartLocker password reset confirmation failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to confirm the password reset. Please try again.']);
}