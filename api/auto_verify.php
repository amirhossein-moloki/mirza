<?php
ini_set('error_log', 'error_log');
header('Content-Type: application/json');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../text.php';

// Retrieve global api_token
global $api_token, $pdo;

// 1. Get POST raw payload
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid JSON input']);
    exit;
}

// Check api_token
if (!isset($data['api_token']) || $data['api_token'] !== $api_token) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$bank_name = $data['bank_name'] ?? '';
$amount = intval($data['amount'] ?? 0);
$source_card_last_four = $data['source_card_last_four'] ?? '';
$ref_num = $data['ref_num'] ?? '';
$sms_raw = $data['sms_raw'] ?? '';
$timestamp = $data['timestamp'] ?? time();

// 2. Prevent duplicate transaction (Check ref_num)
$stmt = $pdo->prepare("SELECT COUNT(*) FROM payment_auto_verify_logs WHERE ref_num = ? AND status = 'success'");
$stmt->execute([$ref_num]);
$duplicate_count = $stmt->fetchColumn();

if ($duplicate_count > 0) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Duplicate transaction']);
    exit;
}

// 3. Match payment
// payment_method = auto_card_to_card
// payment_status = unpaid
// amount == sms_amount
// card_last_four == source_card_last_four
$stmt_payment = $pdo->prepare("SELECT * FROM Payment_report WHERE Payment_Method = 'auto_card_to_card' AND payment_Status = 'unpaid' AND price = ? AND card_last_four = ? LIMIT 1");
$stmt_payment->execute([$amount, $source_card_last_four]);
$payment = $stmt_payment->fetch();

if (!$payment) {
    // Log mismatch/failed attempt
    $stmt_log = $pdo->prepare("INSERT INTO payment_auto_verify_logs (ref_num, amount, source_card, request_data, status, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt_log->execute([$ref_num, $amount, $source_card_last_four, $raw_input, 'mismatch', time()]);

    http_response_code(404);
    echo json_encode(['status' => 'error', 'message' => 'No matching unpaid payment found']);
    exit;
}

// Update payment status to paid
$stmt_update = $pdo->prepare("UPDATE Payment_report SET payment_Status = 'paid' WHERE id_order = ?");
$stmt_update->execute([$payment['id_order']]);

// Run the system's DirectPayment
DirectPayment($payment['id_order']);

// Send payment success notification (Section 12 and 7)
if (strpos($payment['invoice'], "getconfigafterpay") === 0) {
    sendmessage($payment['id_user'], "✅ پرداخت تایید شد.\n\nسرویس شما فعال گردید.", null, 'HTML');
}

// Log success
$stmt_log = $pdo->prepare("INSERT INTO payment_auto_verify_logs (ref_num, amount, source_card, request_data, status, created_at) VALUES (?, ?, ?, ?, ?, ?)");
$stmt_log->execute([$ref_num, $amount, $source_card_last_four, $raw_input, 'success', time()]);

echo json_encode(['status' => 'success', 'message' => 'Payment verified and service activated successfully']);
