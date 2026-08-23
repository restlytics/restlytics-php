<?php

declare(strict_types=1);

$capturePath = getenv('RESTLYTICS_TEST_CAPTURE_PATH');
$statusPath = getenv('RESTLYTICS_TEST_STATUS_PATH');
if (! is_string($capturePath) || ! is_string($statusPath)) {
    http_response_code(500);
    exit;
}

$status = (int) trim((string) @file_get_contents($statusPath));
if ($status < 100 || $status > 599) {
    $status = 202;
}

$record = [
    'path' => parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH),
    'key' => (string) ($_SERVER['HTTP_X_RESTLYTICS_KEY'] ?? ''),
    'encoding' => (string) ($_SERVER['HTTP_CONTENT_ENCODING'] ?? ''),
    'body' => base64_encode((string) file_get_contents('php://input')),
];
file_put_contents($capturePath, json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);

http_response_code($status);
header('Content-Type: application/json');
echo '{}';
