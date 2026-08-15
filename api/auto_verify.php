<?php
ini_set('error_log', 'error_log');
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../text.php';

global $pdo;

// 1. Get POST raw payload
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

$result = processAutoVerifyPayment($pdo, $data);
http_response_code($result['http_code']);
echo json_encode($result['response']);
