<?php
// =============================================================================
// midtrans_callback.php - Handle notifikasi dari Midtrans
// =============================================================================
session_start();
require_once 'koneksi.php';

// Ambil data notifikasi
$json = file_get_contents('php://input');
$notification = json_decode($json, true);

// Log untuk debugging
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) mkdir($logDir, 0755, true);
file_put_contents($logDir . '/midtrans.log', date('Y-m-d H:i:s') . " | RAW: " . $json . "\n", FILE_APPEND);

if (!$notification) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON']);
    exit();
}

$orderId = $notification['order_id'] ?? '';
$transactionStatus = $notification['transaction_status'] ?? '';
$fraudStatus = $notification['fraud_status'] ?? null;
$paymentType = $notification['payment_type'] ?? '';
$transactionId = $notification['transaction_id'] ?? '';
$pdfUrl = $notification['pdf_url'] ?? '';

if (empty($orderId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Order ID missing']);
    exit();
}

// Map Midtrans status ke DB status
$statusMap = [
    'capture'    => 'capture',
    'settlement' => 'paid',      // settlement = paid (sudah dibayar)
    'pending'    => 'pending',
    'deny'       => 'deny',
    'expire'     => 'expire',
    'cancel'     => 'cancelled', // Midtrans kirim 'cancel', DB pakai 'cancelled'
];

$status = $statusMap[$transactionStatus] ?? 'pending';

// Khusus capture, cek fraud status
if ($transactionStatus == 'capture') {
    if ($fraudStatus == 'challenge') {
        $status = 'pending';
    } elseif ($fraudStatus == 'accept') {
        $status = 'paid'; // capture + accept = paid
    }
}

try {
    $stmt = $pdo->prepare("
        UPDATE pesanan 
        SET status = ?, 
            midtrans_transaction_id = ?, 
            midtrans_payment_type = ?, 
            midtrans_pdf_url = ?,
            metode_pembayaran = ?,
            updated_at = NOW()
        WHERE order_id = ?
    ");
    $stmt->execute([$status, $transactionId, $paymentType, $pdfUrl, $paymentType, $orderId]);
    
    file_put_contents($logDir . '/midtrans.log', date('Y-m-d H:i:s') . " | SUCCESS: Order $orderId -> $status\n", FILE_APPEND);
    echo json_encode(['success' => true, 'status' => $status]);
    
} catch (PDOException $e) {
    file_put_contents($logDir . '/midtrans.log', date('Y-m-d H:i:s') . " | DB ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error']);
}