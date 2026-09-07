<?php
declare(strict_types=1);

const DB_HOST = '127.0.0.1';
const DB_NAME = 'SmartLocker';
const DB_USER = 'root';
const DB_PASSWORD = '';
const GOOGLE_CLIENT_ID = '1009087321451-dr4fi376q2ujk6bujo7kqbk7ca6eo73c.apps.googleusercontent.com';
const APP_BASE_URL = 'http://localhost/SmartLocker';
const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;
const SMTP_USERNAME = 'umaksmartlocker@gmail.com';
const SMTP_PASSWORD = 'ugyk hjeb mxym heoy';
const MAIL_FROM = SMTP_USERNAME;

function getDatabaseConnection(): PDO
{
    static $connection = null;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $connection = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASSWORD,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    return $connection;
}