<?php
declare(strict_types=1);

header('Content-Type: application/json');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/verification_mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$token = trim((string) ($_POST['token'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/', $token)
    || strlen($password) < 8
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/\d/', $password)
    || !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Use a valid reset link and a password of at least 8 characters.']);
    exit;
}

$connection = getDatabaseConnection();
$statement = $connection->prepare(
    'SELECT password_reset_tokens.id, password_reset_tokens.user_id, users.email, users.first_name
     FROM password_reset_tokens
     INNER JOIN users ON users.id = password_reset_tokens.user_id
     WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > NOW()
     LIMIT 1'
);
$statement->execute([':token_hash' => hash('sha256', $token)]);
$reset = $statement->fetch();
if (!$reset) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'This password reset link is invalid or expired.']);
    exit;
}

$connection->beginTransaction();
$statement = $connection->prepare('UPDATE password_reset_tokens SET pending_password_hash = :password_hash, used_at = NOW() WHERE id = :id');
$statement->execute([
    ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ':id' => $reset['id'],
]);
$sent = call_user_func('sendPasswordResetConfirmationEmail', $connection, (int) $reset['id'], $reset['email'], $reset['first_name']);
if (!$sent) {
    $connection->rollBack();
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The confirmation email could not be sent.']);
    exit;
}
$connection->commit();

echo json_encode(['success' => true, 'message' => 'Password saved temporarily. Check your email for the confirmation link before logging in.']);