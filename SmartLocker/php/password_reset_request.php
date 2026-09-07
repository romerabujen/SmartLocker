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

$identifier = strtolower(trim((string) ($_POST['identifier'] ?? '')));
$statement = getDatabaseConnection()->prepare(
    'SELECT id, first_name, email FROM users WHERE email = :identifier OR student_id = :student_id LIMIT 1'
);
$statement->execute([':identifier' => $identifier, ':student_id' => trim((string) ($_POST['identifier'] ?? ''))]);
$user = $statement->fetch();

if ($user) {
    $verificationStatement = getDatabaseConnection()->prepare(
        'SELECT 1 FROM email_verification_tokens WHERE user_id = :user_id AND verified_at IS NOT NULL LIMIT 1'
    );
    $verificationStatement->execute([':user_id' => $user['id']]);
    if (!$verificationStatement->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Please verify your email before requesting a password reset link.']);
        exit;
    }
}

if ($user && !sendPasswordResetEmail(getDatabaseConnection(), (int) $user['id'], $user['email'], $user['first_name'])) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The password reset email could not be sent.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'If the account exists, a password reset link has been sent to its registered email.']);