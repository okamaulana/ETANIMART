<?php
// =============================================================================
// cek_status_midtrans.php - Cek status transaksi langsung ke Midtrans API
// =============================================================================
session_start();
require_once 'koneksi.php';

// Load Midtrans config
$midtransConfig = require 'config/midtrans.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Content-Type: application/json");

$orderId = $_GET['order_id'] ?? '';

if (empty($orderId)) {
    echo json_encode(['status' => 'error', 'message' => 'Order ID missing']);
    exit();
}

// Cek dulu di DB lokal
$stmt = $pdo->prepare("SELECT status FROM pesanan WHERE order_id = ?");
$stmt->execute([$orderId]);
$dbStatus = $stmt->fetchColumn();

// Kalau DB sudah berubah, return langsung
if ($dbStatus && $dbStatus !== 'pending') {
    echo json_encode(['status' => $dbStatus, 'source' => 'database']);
    exit();
}

// Kalau DB masih pending, cek langsung ke Midtrans API
try {
    $serverKey = $midtransConfig['server_key'];
    $isProduction = $midtransConfig['is_production'];
    
    $apiUrl = $isProduction 
        ? "https://api.midtrans.com/v2/$orderId/status"
        : "https://api.sandbox.midtrans.com/v2/$orderId/status";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
    curl_setopt($ch, CURLOPT_USERPWD, $serverKey . ':');
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200 && $response) {
        $result = json_decode($response, true);
        $transactionStatus = $result['transaction_status'] ?? '';
        
        // Map ke status DB
        $statusMap = [
            'capture'    => 'capture',
            'settlement' => 'paid',
            'pending'    => 'pending',
            'deny'       => 'deny',
            'expire'     => 'expire',
            'cancel'     => 'cancelled',
        ];
        
        $midtransStatus = $statusMap[$transactionStatus] ?? 'pending';
        
        // Update DB kalau status berubah
        if ($midtransStatus !== 'pending' && $midtransStatus !== $dbStatus) {
            $stmt = $pdo->prepare("
                UPDATE pesanan 
                SET status = ?, 
                    midtrans_transaction_id = ?,
                    midtrans_payment_type = ?,
                    metode_pembayaran = ?,
                    updated_at = NOW()
                WHERE order_id = ?
            ");
            $stmt->execute([
                $midtransStatus,
                $result['transaction_id'] ?? null,
                $result['payment_type'] ?? null,
                $result['payment_type'] ?? null,
                $orderId
            ]);
        }
        
        echo json_encode([
            'status' => $midtransStatus,
            'source' => 'midtrans_api',
            'transaction_status' => $transactionStatus,
            'payment_type' => $result['payment_type'] ?? null
        ]);
    } else {
        // Fallback ke DB status
        echo json_encode(['status' => $dbStatus ?: 'pending', 'source' => 'database_fallback']);
    }
    
} catch (Exception $e) {
    echo json_encode(['status' => $dbStatus ?: 'pending', 'source' => 'error', 'message' => $e->getMessage()]);
}