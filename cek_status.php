<?php
session_start();
require_once 'koneksi.php';

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Content-Type: application/json");

$orderId = $_GET['order_id'] ?? '';

if (empty($orderId)) {
    echo json_encode(['status' => 'error']);
    exit();
}

$stmt = $pdo->prepare("SELECT status FROM pesanan WHERE order_id = ?");
$stmt->execute([$orderId]);
$status = $stmt->fetchColumn() ?? 'error';

echo json_encode(['status' => $status]);