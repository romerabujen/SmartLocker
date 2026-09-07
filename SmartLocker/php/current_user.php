<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

$userId = $_SESSION['user_id'] ?? null;
$accountType = $_SESSION['account_type'] ?? 'student';
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'You must log in first.']);
    exit;
}

if ($accountType === 'admin') {
    $statement = getDatabaseConnection()->prepare(
        'SELECT id, username, email
         FROM admins
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute([':id' => $userId]);
    $user = $statement->fetch();

    if (!$user) {
        session_unset();
        session_destroy();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Your admin account could not be found.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'user' => [
            'first_name' => 'Administrator',
            'middle_initial' => null,
            'last_name' => 'Admin',
            'student_id' => null,
            'email' => $user['email'],
            'profile_picture' => null,
            'username' => $user['username'],
            'account_type' => 'admin',
        ],
    ]);
    exit;
}

$statement = getDatabaseConnection()->prepare(
    'SELECT first_name, middle_initial, last_name, student_id, email, profile_picture
     FROM users
     WHERE id = :id
     LIMIT 1'
);
$statement->execute([':id' => $userId]);
$user = $statement->fetch();

if (!$user) {
    session_unset();
    session_destroy();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your account could not be found.']);
    exit;
}

echo json_encode(['success' => true, 'user' => $user]);