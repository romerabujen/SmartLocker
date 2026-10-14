<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $token = trim((string) ($_GET['token'] ?? ''));
    $verificationUrl = APP_BASE_URL . '/pages/verify/verify.html';
    $verificationUrl .= preg_match('/^[a-f0-9]{64}$/', $token)
        ? '?verify_token=' . rawurlencode($token)
        : '?status=invalid';
    header('Location: ' . $verificationUrl, true, 303);
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
    echo json_encode(['success' => false, 'message' => 'This verification link is invalid or expired. Request a new one.']);
    exit;
}

$connection = getDatabaseConnection();
try {
    $connection->beginTransaction();
    $statement = $connection->prepare(
        'SELECT id FROM email_verification_tokens
         WHERE token_hash = :token_hash AND verified_at IS NULL AND expires_at > NOW()
         LIMIT 1 FOR UPDATE'
    );
    $statement->execute([':token_hash' => hash('sha256', $token)]);
    $verification = $statement->fetch();
    if (!$verification) {
        $connection->rollBack();
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'This verification link is invalid, expired, or already used.']);
        exit;
    }

    $statement = $connection->prepare('UPDATE email_verification_tokens SET verified_at = NOW() WHERE id = :id');
    $statement->execute([':id' => $verification['id']]);
    $connection->commit();

    echo json_encode(['success' => true, 'message' => 'Your email has been verified. You can now sign in.']);
} catch (Throwable $exception) {
    if ($connection->inTransaction()) {
        $connection->rollBack();
    }
    error_log('SmartLocker email verification failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to verify the email right now. Please try again.']);
}