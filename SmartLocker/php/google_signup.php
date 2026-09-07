<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/verification_mail.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Only POST requests are allowed.']);
    exit;
}

$credential = (string) ($_POST['credential'] ?? '');
$studentId = trim((string) ($_POST['student_id'] ?? ''));
$password = (string) ($_POST['password'] ?? '');

if ($credential === '' || $studentId === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Enter your student ID and password.']);
    exit;
}

$tokenContext = stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 10]]);
$tokenResponse = file_get_contents(
    'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($credential),
    false,
    $tokenContext
);
$googleUser = $tokenResponse !== false ? json_decode($tokenResponse, true) : null;

$email = strtolower((string) ($googleUser['email'] ?? ''));
$isValidToken = is_array($googleUser)
    && defined('GOOGLE_CLIENT_ID')
    && ($googleUser['aud'] ?? '') === constant('GOOGLE_CLIENT_ID')
    && ($googleUser['email_verified'] ?? '') === 'true'
    && str_ends_with($email, '@umak.edu.ph');

if (!$isValidToken) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Only verified @umak.edu.ph Google accounts are allowed.']);
    exit;
}

try {
    $connection = getDatabaseConnection();
    $connection->beginTransaction();
    $statement = $connection->prepare(
        'INSERT INTO users (last_name, first_name, middle_initial, student_id, email, password_hash)
         VALUES (:last_name, :first_name, :middle_initial, :student_id, :email, :password_hash)'
    );
    $statement->execute([
        ':last_name' => trim((string) ($googleUser['family_name'] ?? '')),
        ':first_name' => trim((string) ($googleUser['given_name'] ?? '')),
        ':middle_initial' => null,
        ':student_id' => $studentId,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    $userId = (int) $connection->lastInsertId();
    if (!call_user_func('sendVerificationEmail', $connection, $userId, $email, (string) ($googleUser['given_name'] ?? 'Student'))) {
        $connection->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The account could not be created because the verification email could not be sent.']);
        exit;
    }
    $connection->commit();
    echo json_encode(['success' => true, 'message' => 'Account created. Check your UMAK email for the verification link.']);
} catch (PDOException $exception) {
    if (getDatabaseConnection()->inTransaction()) {
        getDatabaseConnection()->rollBack();
    }
    if (($exception->errorInfo[1] ?? null) === 1062) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That email or student ID is already registered.']);
        exit;
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to create the account right now.']);
}