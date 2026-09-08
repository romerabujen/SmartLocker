<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$credential = (string) ($_POST['credential'] ?? '');
if ($credential === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'A Google credential is required.']);
    exit;
}

$tokenContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
 $tokenResponse = @file_get_contents(
    'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential),
    false,
    $tokenContext
);
$tokenResponse = $tokenResponse === false ? null : $tokenResponse;
if ($tokenResponse === null) {
    http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Google verification is temporarily unavailable. Please try again.']);
    exit;
}
$googleUser = $tokenResponse !== false ? json_decode($tokenResponse, true) : null;
$email = strtolower((string) ($googleUser['email'] ?? ''));
$isValidToken = is_array($googleUser)
    && defined('GOOGLE_CLIENT_ID')
    && ($googleUser['aud'] ?? '') === constant('GOOGLE_CLIENT_ID')
    && filter_var($googleUser['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)
    && str_ends_with($email, '@umak.edu.ph');

if (!$isValidToken) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Only verified @umak.edu.ph Google accounts are allowed.']);
    exit;
}

$statement = getDatabaseConnection()->prepare(
    'SELECT id, first_name, last_name, student_id, email, profile_picture
     FROM users
     WHERE email = :email
     LIMIT 1'
);
$statement->execute([':email' => $email]);
$user = $statement->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'This Google account is not yet registered. Please sign up first.']);
    exit;
}

$attemptStatement = getDatabaseConnection()->prepare(
    'SELECT failed_attempts FROM login_attempts
     WHERE identifier = :identifier AND ip_address = :ip_address LIMIT 1'
);
$attemptStatement->execute([
    ':identifier' => $email,
    ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
]);
$attempt = $attemptStatement->fetch();
if ($attempt && (int) $attempt['failed_attempts'] >= 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'locked' => true, 'message' => 'Login attempts are exhausted. Reset your password first.']);
    exit;
}

$resetStatement = getDatabaseConnection()->prepare(
    'SELECT 1 FROM password_reset_tokens
     WHERE user_id = :user_id AND pending_password_hash IS NOT NULL
       AND confirmed_at IS NULL AND confirmation_expires_at > NOW()
     LIMIT 1'
);
$resetStatement->execute([':user_id' => $user['id']]);
if ($resetStatement->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please confirm your password reset using the link sent to your email before logging in.']);
    exit;
}

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['account_type'] = 'student';

$activityStatement = getDatabaseConnection()->prepare(
    'INSERT INTO login_activity (user_id, identifier, was_successful, ip_address)
     VALUES (:user_id, :identifier, TRUE, :ip_address)'
);
$activityStatement->execute([
    ':user_id' => $user['id'],
    ':identifier' => $email,
    ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
]);

echo json_encode([
    'success' => true,
    'user' => [
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'student_id' => $user['student_id'],
        'email' => $user['email'],
        'profile_picture' => $user['profile_picture'],
        'account_type' => 'student',
    ],
]);