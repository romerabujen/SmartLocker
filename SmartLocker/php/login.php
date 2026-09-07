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

$identifier = trim((string) ($_POST['identifier'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$normalizedIdentifier = strtolower($identifier);

if ($identifier === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter your email, username, or student ID and password.']);
    exit;
}

$adminStatement = getDatabaseConnection()->prepare(
    'SELECT id, username, email, password_hash
     FROM admins
     WHERE LOWER(username) = LOWER(:identifier) OR LOWER(email) = LOWER(:email_identifier)
     LIMIT 1'
);
$adminStatement->execute([
    ':identifier' => $identifier,
    ':email_identifier' => $normalizedIdentifier,
]);
$adminUser = $adminStatement->fetch();

$userStatement = getDatabaseConnection()->prepare(
    'SELECT id, first_name, last_name, student_id, email, password_hash, profile_picture
     FROM users
     WHERE LOWER(email) = LOWER(:email_identifier) OR student_id = :student_identifier
     LIMIT 1'
);
$userStatement->execute([
    ':email_identifier' => $normalizedIdentifier,
    ':student_identifier' => $identifier,
]);
$user = $userStatement->fetch();

if ($user) {
    $verificationStatement = getDatabaseConnection()->prepare(
        'SELECT 1 FROM email_verification_tokens WHERE user_id = :user_id AND verified_at IS NOT NULL LIMIT 1'
    );
    $verificationStatement->execute([':user_id' => $user['id']]);
    if (!$verificationStatement->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Please verify your email before logging in.']);
        exit;
    }
}

$attemptStatement = getDatabaseConnection()->prepare(
    'SELECT failed_attempts, locked_at FROM login_attempts
     WHERE identifier = :identifier AND ip_address = :ip_address LIMIT 1'
);
$attemptStatement->execute([':identifier' => $normalizedIdentifier, ':ip_address' => $ipAddress]);
$attempt = $attemptStatement->fetch();
if ($attempt && (int) $attempt['failed_attempts'] >= 5) {
    http_response_code(429);
    echo json_encode(['success' => false, 'locked' => true, 'attempts_remaining' => 0, 'message' => 'Login attempts are exhausted. Please verify your email before logging in.']);
    exit;
}

if ($adminUser && password_verify($password, $adminUser['password_hash'])) {
    getDatabaseConnection()->prepare('DELETE FROM login_attempts WHERE identifier = :identifier AND ip_address = :ip_address')
        ->execute([':identifier' => $normalizedIdentifier, ':ip_address' => $ipAddress]);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $adminUser['id'];
    $_SESSION['account_type'] = 'admin';

    $activityStatement = getDatabaseConnection()->prepare(
        'INSERT INTO login_activity (user_id, identifier, was_successful, ip_address)
         VALUES (:user_id, :identifier, TRUE, :ip_address)'
    );
    $activityStatement->execute([
        ':user_id' => null,
        ':identifier' => $identifier,
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    echo json_encode([
        'success' => true,
        'user' => [
            'first_name' => 'Administrator',
            'last_name' => 'Admin',
            'username' => $adminUser['username'],
            'email' => $adminUser['email'],
            'account_type' => 'admin',
        ],
    ]);
    exit;
}

if (!$user && !$adminUser) {
    $identifierType = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email address' : 'student ID';
    if ($adminUser) {
        $identifierType = 'admin account';
    }
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => "That {$identifierType} does not exist. Please sign up to create an account."]);
    exit;
}

if ($user) {
    $resetStatement = getDatabaseConnection()->prepare(
        'SELECT 1 FROM password_reset_tokens
         WHERE user_id = :user_id AND pending_password_hash IS NOT NULL
           AND confirmed_at IS NULL AND confirmation_expires_at > NOW()
         LIMIT 1'
    );
    $resetStatement->execute([':user_id' => $user['id']]);
    if ($resetStatement->fetchColumn()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Please verify your password change before logging in. Check the email link sent to confirm the password reset.']);
        exit;
    }
}

if (!$user || !password_verify($password, $user['password_hash'])) {
    $failedAttempts = ((int) ($attempt['failed_attempts'] ?? 0)) + 1;
    $attemptUpdate = getDatabaseConnection()->prepare(
        'INSERT INTO login_attempts (user_id, identifier, ip_address, failed_attempts, locked_at)
         VALUES (:user_id, :identifier, :ip_address, :failed_attempts, IF(:lock_threshold_attempts >= 5, NOW(), NULL))
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), failed_attempts = VALUES(failed_attempts), locked_at = VALUES(locked_at), last_attempt_at = NOW()'
    );
    $attemptUpdate->execute([
        ':user_id' => $user['id'] ?? null,
        ':identifier' => $normalizedIdentifier,
        ':ip_address' => $ipAddress,
        ':failed_attempts' => $failedAttempts,
        ':lock_threshold_attempts' => $failedAttempts,
    ]);
    $remaining = max(0, 5 - $failedAttempts);
    $activityStatement = getDatabaseConnection()->prepare(
        'INSERT INTO login_activity (user_id, identifier, was_successful, failure_reason, ip_address)
         VALUES (:user_id, :identifier, FALSE, :failure_reason, :ip_address)'
    );
    $activityStatement->execute([
        ':user_id' => $user['id'] ?? null,
        ':identifier' => $identifier,
        ':failure_reason' => $user ? 'invalid_password' : ($adminUser ? 'invalid_admin_password' : 'user_not_found'),
        ':ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    http_response_code($remaining === 0 ? 429 : 401);
    echo json_encode(['success' => false, 'locked' => $remaining === 0, 'attempts_remaining' => $remaining, 'message' => $remaining === 0 ? 'Login attempts are exhausted. Reset your password using the button below.' : 'Invalid email, username, student ID, or password.']);
    exit;
}

getDatabaseConnection()->prepare('DELETE FROM login_attempts WHERE identifier = :identifier AND ip_address = :ip_address')
    ->execute([':identifier' => $normalizedIdentifier, ':ip_address' => $ipAddress]);

session_regenerate_id(true);
$_SESSION['user_id'] = $user['id'];
$_SESSION['account_type'] = 'student';

$activityStatement = getDatabaseConnection()->prepare(
    'INSERT INTO login_activity (user_id, identifier, was_successful, ip_address)
     VALUES (:user_id, :identifier, TRUE, :ip_address)'
);
$activityStatement->execute([
    ':user_id' => $user['id'],
    ':identifier' => $identifier,
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