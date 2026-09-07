<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

function encryptSensitiveValue(string $value): string
{
    $key = hash('sha256', (string) constant('APP_ENCRYPTION_KEY'), true);
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($value, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    if ($ciphertext === false) {
        throw new RuntimeException('Unable to encrypt sensitive value.');
    }

    return base64_encode($iv . $tag . $ciphertext);
}

function decryptSensitiveValue(string $value): string
{
    $payload = base64_decode($value, true);
    if ($payload === false || strlen($payload) < 28) {
        throw new RuntimeException('Invalid encrypted value.');
    }

    $key = hash('sha256', (string) constant('APP_ENCRYPTION_KEY'), true);
    $iv = substr($payload, 0, 12);
    $tag = substr($payload, 12, 16);
    $ciphertext = substr($payload, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

    if ($plaintext === false) {
        throw new RuntimeException('Unable to decrypt sensitive value.');
    }

    return $plaintext;
}

function requireRole(string ...$roles): array
{
    session_start();
    $userId = $_SESSION['user_id'] ?? null;
    $accountType = $_SESSION['account_type'] ?? 'student';
    if (!$userId) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'You must log in first.']);
        exit;
    }

    $statement = getDatabaseConnection()->prepare($accountType === 'admin'
        ? 'SELECT id, \'admin\' AS role FROM admins WHERE id = :id LIMIT 1'
        : 'SELECT id, \'student\' AS role FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute([':id' => $userId]);
    $user = $statement->fetch();
    if (!$user || !in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You are not authorized to access this resource.']);
        exit;
    }

    return $user;
}