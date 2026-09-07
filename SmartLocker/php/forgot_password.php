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

$identifier = trim((string) ($_POST['identifier'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');

if ($identifier === '' || $password === '' || $password !== $confirmPassword) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter your account identifier and matching passwords.']);
    exit;
}

if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password) || !preg_match('/[!@#$%^&*()_+\-=\[\]{};\':"\\|,.<>\/?]/', $password)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Password must contain 8 characters, an uppercase letter, a number, and a special character.']);
    exit;
}

$statement = getDatabaseConnection()->prepare(
    'SELECT id, first_name, email FROM users
     WHERE email = :email_identifier OR student_id = :student_identifier
     LIMIT 1'
);
$statement->execute([
    ':email_identifier' => strtolower($identifier),
    ':student_identifier' => $identifier,
]);
$user = $statement->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'No SmartLocker account was found for that email or student ID.']);
    exit;
}

$connection = getDatabaseConnection();
$token = bin2hex(random_bytes(32));
$statement = $connection->prepare(
    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, used_at, pending_password_hash)
     VALUES (:user_id, :token_hash, DATE_ADD(NOW(), INTERVAL 1 HOUR), NOW(), :pending_password_hash)'
);
$statement->execute([
    ':user_id' => $user['id'],
    ':token_hash' => hash('sha256', $token),
    ':pending_password_hash' => password_hash($password, PASSWORD_DEFAULT),
]);
$resetId = (int) $connection->lastInsertId();

if (!call_user_func('sendPasswordResetConfirmationEmail', $connection, $resetId, $user['email'], $user['first_name'])) {
    $connection->prepare('DELETE FROM password_reset_tokens WHERE id = :id')->execute([':id' => $resetId]);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The confirmation email could not be sent.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Check your registered email for the verification link to confirm your password reset.']);