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

$lastName = trim((string) ($_POST['last_name'] ?? ''));
$firstName = trim((string) ($_POST['first_name'] ?? ''));
$middleInitial = strtoupper(trim((string) ($_POST['middle_initial'] ?? '')));
$studentId = trim((string) ($_POST['student_id'] ?? ''));
$email = strtolower(trim((string) ($_POST['email'] ?? '')));
$password = (string) ($_POST['password'] ?? '');
$profilePicturePath = null;

if ($lastName === '' || $firstName === '' || $studentId === '' || $email === '' || $password === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Please complete all required fields.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_ends_with($email, '@umak.edu.ph') || strlen($middleInitial) > 1) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Only @umak.edu.ph email addresses are allowed.']);
    exit;
}

if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
    $profilePicture = $_FILES['profile_picture'];
    $allowedTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    if ($profilePicture['error'] !== UPLOAD_ERR_OK || $profilePicture['size'] > 2 * 1024 * 1024) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Profile picture must be an image up to 2 MB.']);
        exit;
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($profilePicture['tmp_name']);
    if (!isset($allowedTypes[$mimeType])) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, and WEBP profile pictures are allowed.']);
        exit;
    }

    $uploadDirectory = __DIR__ . '/../assets/uploads/profile';
    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to save the profile picture.']);
        exit;
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $allowedTypes[$mimeType];
    if (!move_uploaded_file($profilePicture['tmp_name'], $uploadDirectory . '/' . $fileName)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to save the profile picture.']);
        exit;
    }
    $profilePicturePath = 'assets/uploads/profile/' . $fileName;
}

try {
    $connection = getDatabaseConnection();
    $connection->beginTransaction();
    $statement = $connection->prepare(
        'INSERT INTO users (last_name, first_name, middle_initial, student_id, email, password_hash, profile_picture)
         VALUES (:last_name, :first_name, :middle_initial, :student_id, :email, :password_hash, :profile_picture)'
    );
    $statement->execute([
        ':last_name' => $lastName,
        ':first_name' => $firstName,
        ':middle_initial' => $middleInitial !== '' ? $middleInitial : null,
        ':student_id' => $studentId,
        ':email' => $email,
        ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ':profile_picture' => $profilePicturePath,
    ]);

    $userId = (int) $connection->lastInsertId();
    if (!call_user_func('sendVerificationEmail', $connection, $userId, $email, $firstName)) {
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
    if ($exception->errorInfo[1] ?? null === 1062) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'That email or student ID is already registered.']);
        exit;
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to create the account right now.']);
}