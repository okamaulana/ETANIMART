<?php
// =============================================================================
// pesanan.php - Halaman Riwayat & Daftar Pesanan Pembeli (FIXED CACHE + POLLING)
// =============================================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'koneksi.php';

// ==========================================
// ANTI-CACHE HEADERS (BARU)
// ==========================================
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// ==========================================
// AUTO DELETE: Pesanan cancelled > 24 jam
// ==========================================
try {
    $stmt = $pdo->prepare("
        DELETE FROM pesanan 
        WHERE status = 'cancelled' 
        AND updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ");
    $stmt->execute();
} catch (PDOException $e) {
    // Silent fail
}

function getProfilePic($foto) {
    return !empty($foto) ? 'uploads/profil/' . $foto : 'https://placehold.co/100?text=U';
}

// ==========================================
// CEK STATUS LOGIN
// ==========================================
$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $isLoggedIn ? ($_SESSION['role'] ?? 'pembeli') : null;
$userName = $isLoggedIn ? ($_SESSION['nama'] ?? 'Pengguna') : null;
$userFoto = $isLoggedIn ? ($_SESSION['foto_profil'] ?? null) : null;

// Redirect penjual ke dashboard penjual
if ($isLoggedIn && $userRole === 'penjual') {
    header('Location: dashboard_penjual.php');
    exit;
}

// Redirect admin ke dashboard admin
if ($isLoggedIn && $userRole === 'admin') {
    header('Location: admin/dashboard.php');
    exit;
}

// Hitung total item keranjang untuk badge
$totalKeranjang = 0;
if ($isLoggedIn && $userRole === 'pembeli') {
    try {
        $stmtCart = $pdo->prepare("SELECT COALESCE(SUM(jumlah), 0) as total FROM keranjang WHERE id_user = :idu");
        $stmtCart->execute(['idu' => ($_SESSION['user_id'] ?? 0)]);
        $totalKeranjang = (int)$stmtCart->fetchColumn();
    } catch (PDOException $e) {
        $totalKeranjang = 0;
    }
}

$userId = (int)$_SESSION['user_id'];

// ==========================================
// PROSES: Batalkan Pesanan
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_order'])) {
    $orderIdCancel = $_POST['order_id'] ?? '';
    $stmt = $pdo->prepare("UPDATE pesanan SET status = 'cancelled' WHERE order_id = ? AND id_user = ? AND status IN ('pending', '')");
    $stmt->execute([$orderIdCancel, $userId]);
    header("Location: pesanan.php");
    exit();
}

// ==========================================
// PROSES: Bayar Ulang / Regenerate Snap Token
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bayar_ulang'])) {
    $orderIdUlang = $_POST['order_id'] ?? '';

    $stmtCheck = $pdo->prepare("SELECT * FROM pesanan WHERE order_id = ? AND id_user = ? AND status IN ('pending', 'expire', 'cancelled', 'deny', 'failure')");
    $stmtCheck->execute([$orderIdUlang, $userId]);
    $pesananUlang = $stmtCheck->fetch();

    if ($pesananUlang) {
        $midtransConfig = require 'config/midtrans.php';

        try {
            $autoloadPath = __DIR__ . '/vendor/autoload.php';
            if (!file_exists($autoloadPath)) {
                throw new Exception("Midtrans library tidak ditemukan.");
            }
            require_once $autoloadPath;

            $stmtItems = $pdo->prepare("SELECT * FROM detail_pesanan WHERE id_pesanan = ?");
            $stmtItems->execute([$pesananUlang['id']]);
            $itemsUlang = $stmtItems->fetchAll();

            Midtrans\Config::$serverKey = $midtransConfig['server_key'];
            Midtrans\Config::$isProduction = $midtransConfig['is_production'];
            Midtrans\Config::$isSanitized = $midtransConfig['is_sanitized'];
            Midtrans\Config::$is3ds = $midtransConfig['is_3ds'];

            $params = [
                'transaction_details' => [
                    'order_id' => $pesananUlang['order_id'],
                    'gross_amount' => (int)$pesananUlang['total_harga'],
                ],
                'customer_details' => [
                    'first_name' => substr($pesananUlang['nama_penerima'], 0, 50),
                    'phone' => $pesananUlang['no_telepon'],
                ],
                'item_details' => array_map(function($item) {
                    return [
                        'id' => (string)$item['id_produk'],
                        'price' => (int)$item['harga_satuan'],
                        'quantity' => (int)$item['jumlah'],
                        'name' => substr($item['nama_produk'], 0, 50),
                    ];
                }, $itemsUlang),
            ];

            $snapToken = Midtrans\Snap::getSnapToken($params);

            $stmtUpdate = $pdo->prepare("UPDATE pesanan SET snap_token = ?, status = 'pending' WHERE order_id = ?");
            $stmtUpdate->execute([$snapToken, $orderIdUlang]);

            header("Location: pembayaran.php?order_id=" . urlencode($orderIdUlang));
            exit();

        } catch (Exception $e) {
            $errorUlang = 'Gagal regenerate token: ' . $e->getMessage();
        }
    }
}

// ==========================================
// PROSES: Pesanan Diterima (Selesai)
// ==========================================
if (isset($_POST['terima_pesanan'])) {
    $orderIdTerima = $_POST['order_id'];
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("
            UPDATE pesanan 
            SET status = 'completed', updated_at = NOW() 
            WHERE order_id = ? AND id_user = ? AND status = 'shipped'
        ");
        $stmt->execute([$orderIdTerima, $userId]);
        
        if ($stmt->rowCount() > 0) {
            $stmtPesanan = $pdo->prepare("
                SELECT p.id, p.total_harga, dp.id_penjual, dp.id_produk, dp.jumlah, dp.harga_satuan
                FROM pesanan p
                JOIN detail_pesanan dp ON p.id = dp.id_pesanan
                WHERE p.order_id = ?
            ");
            $stmtPesanan->execute([$orderIdTerima]);
            $detailItems = $stmtPesanan->fetchAll();
            
            foreach ($detailItems as $item) {
                $idPenjual = $item['id_penjual'];
                $subtotal = $item['jumlah'] * $item['harga_satuan'];
                $komisi = $subtotal * 0.05;
                $penjualDapat = $subtotal - $komisi;
                
                $stmtKomisi = $pdo->prepare("
                    INSERT INTO komisi_transaksi 
                    (id_pesanan, id_penjual, total_transaksi, persen_komisi, jumlah_komisi, jumlah_penjual, created_at)
                    VALUES (?, ?, ?, 5.00, ?, ?, NOW())
                ");
                $stmtKomisi->execute([
                    $item['id'], 
                    $idPenjual, 
                    $subtotal, 
                    $komisi, 
                    $penjualDapat
                ]);
                
                $stmtSaldo = $pdo->prepare("UPDATE users SET saldo = saldo + ? WHERE id = ?");
                $stmtSaldo->execute([$penjualDapat, $idPenjual]);
            }
            
            $pdo->commit();
            $successMsg = 'Pesanan telah diterima. Terima kasih telah berbelanja!';
        } else {
            $pdo->rollBack();
            $errorMsg = 'Gagal menerima pesanan. Pastikan pesanan sudah dikirim.';
        }
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        $errorMsg = 'Terjadi kesalahan: ' . $e->getMessage();
    }
}

// ==========================================
// FILTER & PAGINATION
// ==========================================
$statusFilter = $_GET['status'] ?? 'semua';
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

$whereClauses = ["p.id_user = ?"];
$params = [$userId];

$validStatuses = ['pending','paid','shipped','completed','cancelled','expire','deny','failure'];
if ($statusFilter !== 'semua' && in_array($statusFilter, $validStatuses)) {
    $whereClauses[] = "p.status = ?";
    $params[] = $statusFilter;
}

if (!empty($search)) {
    $whereClauses[] = "(p.order_id LIKE ? OR p.nama_penerima LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereSql = implode(' AND ', $whereClauses);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM pesanan p WHERE $whereSql");
$countStmt->execute($params);
$totalItems = (int)$countStmt->fetchColumn();
$totalPages = max(1, ceil($totalItems / $perPage));

$orderStmt = $pdo->prepare("
    SELECT p.*, COUNT(dp.id) as total_item
    FROM pesanan p
    LEFT JOIN detail_pesanan dp ON p.id = dp.id_pesanan
    WHERE $whereSql
    GROUP BY p.id
    ORDER BY p.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$orderStmt->execute($params);
$pesananList = $orderStmt->fetchAll();

$detailMap = [];
if (!empty($pesananList)) {
    $pesananIds = array_column($pesananList, 'id');
    $placeholders = implode(',', array_fill(0, count($pesananIds), '?'));
    $detailStmt = $pdo->prepare("
        SELECT dp.*, pr.gambar, pr.id_penjual, u.nama_toko
        FROM detail_pesanan dp
        LEFT JOIN produk pr ON dp.id_produk = pr.id
        LEFT JOIN users u ON pr.id_penjual = u.id
        WHERE dp.id_pesanan IN ($placeholders)
    ");
    $detailStmt->execute($pesananIds);
    foreach ($detailStmt->fetchAll() as $d) {
        $detailMap[$d['id_pesanan']][] = $d;
    }
}

function clean($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function formatRupiah($angka) { return 'Rp ' . number_format((int)($angka ?? 0), 0, ',', '.'); }
function formatTanggal($datetime) {
    if (empty($datetime)) return '-';
    $date = new DateTime($datetime);
    return $date->format('d M Y, H:i');
}
function getThumb($gambarJson) {
    if (empty($gambarJson)) return 'https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image';
    $decoded = json_decode($gambarJson, true);
    if (is_array($decoded) && !empty($decoded[0])) return 'uploads/' . $decoded[0];
    return 'https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image';
}

$statusConfig = [
    'pending'    => ['label' => 'Menunggu Pembayaran', 'color' => 'amber',  'bg' => 'bg-amber-50',  'text' => 'text-amber-700',  'border' => 'border-amber-200',  'icon' => 'fa-clock', 'step' => 1],
    'paid'       => ['label' => 'Diproses Penjual',    'color' => 'blue',   'bg' => 'bg-blue-50',   'text' => 'text-blue-700',   'border' => 'border-blue-200',   'icon' => 'fa-box-open', 'step' => 2],
    'shipped'    => ['label' => 'Dalam Pengiriman',    'color' => 'indigo', 'bg' => 'bg-indigo-50', 'text' => 'text-indigo-700', 'border' => 'border-indigo-200', 'icon' => 'fa-truck-fast', 'step' => 3],
    'completed'  => ['label' => 'Pesanan Selesai',     'color' => 'emerald','bg' => 'bg-emerald-50','text' => 'text-emerald-700','border' => 'border-emerald-200','icon' => 'fa-circle-check', 'step' => 4],
    'cancelled'  => ['label' => 'Dibatalkan',          'color' => 'rose',   'bg' => 'bg-rose-50',   'text' => 'text-rose-700',   'border' => 'border-rose-200',   'icon' => 'fa-circle-xmark', 'step' => 0],
    'expire'     => ['label' => 'Kadaluarsa',          'color' => 'gray',   'bg' => 'bg-gray-50',   'text' => 'text-gray-700',   'border' => 'border-gray-200',   'icon' => 'fa-clock', 'step' => 0],
    'deny'       => ['label' => 'Ditolak',             'color' => 'rose',   'bg' => 'bg-rose-50',   'text' => 'text-rose-700',   'border' => 'border-rose-200',   'icon' => 'fa-circle-xmark', 'step' => 0],
    'failure'    => ['label' => 'Gagal',               'color' => 'rose',   'bg' => 'bg-rose-50',   'text' => 'text-rose-700',   'border' => 'border-rose-200',   'icon' => 'fa-circle-xmark', 'step' => 0],
];

$filterOptions = [
    'semua'      => 'Semua',
    'pending'    => 'Menunggu',
    'paid'       => 'Diproses',
    'shipped'    => 'Dikirim',
    'completed'  => 'Selesai',
    'cancelled'  => 'Dibatalkan',
];

$timelineSteps = [
    1 => ['icon' => 'fa-file-invoice', 'label' => 'Pesanan Dibuat', 'desc' => 'Menunggu pembayaran'],
    2 => ['icon' => 'fa-box-open', 'label' => 'Diproses', 'desc' => 'Penjual menyiapkan pesanan'],
    3 => ['icon' => 'fa-truck-fast', 'label' => 'Dikirim', 'desc' => 'Pesanan dalam perjalanan'],
    4 => ['icon' => 'fa-house-chimney', 'label' => 'Selesai', 'desc' => 'Pesanan diterima'],
];

// Cek apakah ada pesanan pending untuk polling
$hasPendingOrder = false;
foreach ($pesananList as $p) {
    if (($p['status'] ?? '') === 'pending') {
        $hasPendingOrder = true;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <!-- ANTI-CACHE META (BARU) -->
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Pesanan Saya - Etanimart</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

       /* ===== NAVBAR ===== */
       .nav-link {
            position: relative;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            width: 0;
            height: 2px;
            background: #10b981;
            transition: width 0.3s ease;
        }
        .nav-link:hover::after {
            width: 100%;
        }
        .btn-primary { 
            background: linear-gradient(135deg, #10b981 0%, #059669 100%); 
            transition: all 0.3s ease; 
        }
        .btn-primary:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.5); 
        }

        .order-card { transition: all 0.2s ease; }
        .order-card:hover { 
            transform: translateY(-2px); 
            box-shadow: 0 10px 40px -10px rgba(0,0,0,0.08); 
        }

        .drawer-overlay { 
            opacity: 0; 
            pointer-events: none; 
            transition: opacity 0.3s ease; 
        }
        .drawer-overlay.active { 
            opacity: 1; 
            pointer-events: auto; 
        }
        .drawer-panel { 
            transform: translateX(100%); 
            transition: transform 0.3s ease; 
        }
        .drawer-overlay.active .drawer-panel { 
            transform: translateX(0); 
        }

        /* Timeline tracking */
        .timeline-track {
            position: relative;
            padding-left: 32px;
        }
        .timeline-track::before {
            content: '';
            position: absolute;
            left: 11px;
            top: 24px;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }
        .timeline-item:last-child { padding-bottom: 0; }
        .timeline-dot {
            position: absolute;
            left: -32px;
            top: 2px;
            width: 24px;
            height: 24px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            z-index: 2;
        }
        .timeline-dot.active {
            background: #10b981;
            color: white;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.2);
        }
        .timeline-dot.inactive {
            background: #e2e8f0;
            color: #94a3b8;
        }
        .timeline-dot.current {
            background: #3b82f6;
            color: white;
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.2);
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.2); }
            50% { box-shadow: 0 0 0 8px rgba(59, 130, 246, 0.1); }
        }

        /* ===== USER DROPDOWN - FIXED ===== */
        .user-dropdown {
            position: relative;
        }
        .user-dropdown-menu {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            min-width: 240px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.15);
            border: 1px solid #e2e8f0;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 9999 !important; /* HIGHEST z-index */
            overflow: hidden;
        }
        .user-dropdown-menu.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }
        .user-dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: #374151;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .user-dropdown-item:hover {
            background: #f0fdf4;
            color: #059669;
        }
        .user-dropdown-item i {
            width: 20px;
            text-align: center;
            color: #10b981;
        }
        .user-dropdown-divider {
            height: 1px;
            background: #e2e8f0;
            margin: 4px 12px;
        }

        /* ===== MOBILE MENU ===== */
        #mobile-menu {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: top;
        }
        #mobile-menu:not(.hidden) {
            animation: slideDown 0.3s ease-out;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Menu icon animation */
        #menuIcon {
            transition: transform 0.3s ease;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased">

   <!-- ==================== NAVBAR ==================== -->
   <nav class="fixed top-0 left-0 right-0 z-[100] transition-all duration-300" id="navbar">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-20 items-center">
            <!-- Logo -->
            <a href="index.php" class="flex items-center gap-2 font-bold text-2xl text-white">
    <img src="uploads/logo/tani.png" 
         alt="Etanimart" 
         class="logo-img"
         style="height: 70px; width: auto; object-fit: contain;"
         onerror="this.style.display='none'">
</a>

            <!-- Desktop Navigation -->
            <div class="hidden lg:flex items-center space-x-8 font-medium">
                <a href="index.php" class="nav-link <?= $currentPage === 'index' ? 'text-emerald-600' : 'text-gray-600' ?> hover:text-emerald-600 transition-colors">Beranda</a>
                <a href="scan.php" class="nav-link <?= $currentPage === 'scan' ? 'text-emerald-600' : 'text-gray-600' ?> hover:text-emerald-600 transition-colors flex items-center gap-1.5">
                    <i class="fa-solid fa-qrcode <?= $currentPage === 'scan' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Scan AI
                </a>
                <a href="produk.php" class="nav-link <?= $currentPage === 'produk' ? 'text-emerald-600' : 'text-gray-600' ?> hover:text-emerald-600 transition-colors">Katalog</a>
                
                <a href="index.php#tentang" class="nav-link text-gray-600 hover:text-emerald-600 transition-colors">Tentang</a>
            </div>

            <!-- Right Side Actions -->
            <div class="flex items-center gap-2 sm:gap-4">
                <?php if (!$isLoggedIn): ?>
                    <!-- BELUM LOGIN -->
                    <div class="hidden sm:flex items-center gap-3">
                        <a href="login.php" class="px-5 py-2.5 text-emerald-600 hover:text-emerald-700 font-semibold transition-colors rounded-xl hover:bg-emerald-50">
                            Masuk
                        </a>
                        <a href="register.php" class="btn-primary text-white px-5 py-2.5 rounded-xl font-semibold shadow-lg text-sm">
                            Daftar
                        </a>
                    </div>
                <?php else: ?>
                    <!-- SUDAH LOGIN (PEMBELI) -->
                    <!-- Cart Icon (Desktop only) -->
                    <a href="keranjang.php" class="hidden lg:flex relative p-2.5 text-gray-600 hover:text-emerald-600 transition-colors rounded-xl hover:bg-emerald-50">
                        <i class="fa-solid fa-cart-shopping text-lg"></i>
                        <span class="absolute -top-1 -right-1 w-5 h-5 bg-emerald-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center <?= $totalKeranjang > 0 ? '' : 'hidden' ?>"><?= $totalKeranjang ?></span>
                    </a>

                    <!-- User Profile Dropdown (Desktop only) -->
                    <div class="user-dropdown relative hidden lg:block" style="position: relative; z-index: 9999;">
                        <button type="button" class="flex items-center gap-2 sm:gap-3 pl-2 pr-1 sm:pr-2 py-1.5 rounded-full hover:bg-gray-100 transition-colors" onclick="toggleUserDropdown(event)">
                            <img src="<?= getProfilePic($userFoto) ?>" alt="<?= clean($userName) ?>" class="w-9 h-9 rounded-full object-cover border-2 border-emerald-200">
                            <div class="hidden sm:flex flex-col items-start">
                                <span class="text-sm font-bold text-gray-800 max-w-[100px] truncate leading-tight"><?= clean($userName) ?></span>
                                <span class="text-[10px] text-gray-400 font-medium leading-tight">Pembeli</span>
                            </div>
                            <i class="fa-solid fa-chevron-down text-xs text-gray-400 mr-1 transition-transform duration-200" id="userDropdownIcon"></i>
                        </button>

                        <!-- Dropdown Menu -->
                        <div class="user-dropdown-menu" id="userDropdownMenu">
                            <!-- Mobile-only user info header -->
                            <div class="sm:hidden px-4 py-3 border-b border-gray-100 flex items-center gap-3">
                                <img src="<?= getProfilePic($userFoto) ?>" alt="<?= clean($userName) ?>" class="w-12 h-12 rounded-full object-cover border-2 border-emerald-200">
                                <div>
                                    <p class="text-sm font-bold text-gray-800"><?= clean($userName) ?></p>
                                    <p class="text-xs text-gray-500">Pembeli</p>
                                </div>
                            </div>

                            <div class="hidden sm:block px-4 py-3 border-b border-gray-100">
                                <p class="text-sm font-bold text-gray-800"><?= clean($userName) ?></p>
                                <p class="text-xs text-gray-500">Pembeli</p>
                            </div>

                            <a href="profil.php" class="user-dropdown-item">
                                <i class="fa-solid fa-user"></i> Profil Saya
                            </a>
                            <a href="pesanan.php" class="user-dropdown-item">
                                <i class="fa-solid fa-bag-shopping"></i> Pesanan Saya
                            </a>
                            <a href="keranjang.php" class="user-dropdown-item">
                                <i class="fa-solid fa-cart-shopping"></i> Keranjang
                            </a>
                            <div class="user-dropdown-divider"></div>
                            <a href="logout.php" class="user-dropdown-item text-red-500 hover:text-red-600 hover:bg-red-50">
                                <i class="fa-solid fa-right-from-bracket text-red-400"></i> Keluar
                            </a>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Mobile Menu Toggle Button -->
                <button id="btn-menu" class="lg:hidden p-2.5 text-gray-600 hover:text-emerald-600 hover:bg-emerald-50 transition-all rounded-xl">
                    <i class="fa-solid fa-bars text-xl" id="menuIcon"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile Menu -->
    <div id="mobile-menu" class="hidden lg:hidden bg-white/95 backdrop-blur-xl border-t border-gray-100 shadow-xl max-h-[85vh] overflow-y-auto">
        <div class="max-w-7xl mx-auto px-4 py-4 space-y-1">

            <?php if ($isLoggedIn): ?>
            <!-- Mobile: User Profile Card (Top) -->
            <div class="bg-gradient-to-r from-emerald-50 to-teal-50 rounded-2xl p-4 mb-4 border border-emerald-100">
                <div class="flex items-center gap-4">
                    <img src="<?= getProfilePic($userFoto) ?>" alt="<?= clean($userName) ?>" class="w-14 h-14 rounded-full object-cover border-2 border-emerald-300 shadow-sm">
                    <div class="flex-1 min-w-0">
                        <p class="text-base font-bold text-gray-900 truncate"><?= clean($userName) ?></p>
                        <p class="text-xs text-emerald-600 font-medium flex items-center gap-1">
                            <i class="fa-solid fa-circle-check text-[10px]"></i> Pembeli
                        </p>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-2 mt-4">
                    <a href="profil.php" class="flex flex-col items-center gap-1 py-2 bg-white rounded-xl border border-emerald-100 hover:border-emerald-300 transition-colors">
                        <i class="fa-solid fa-user text-emerald-600 text-sm"></i>
                        <span class="text-[10px] font-semibold text-gray-600">Profil</span>
                    </a>
                    <a href="pesanan.php" class="flex flex-col items-center gap-1 py-2 bg-white rounded-xl border border-emerald-100 hover:border-emerald-300 transition-colors">
                        <i class="fa-solid fa-bag-shopping text-emerald-600 text-sm"></i>
                        <span class="text-[10px] font-semibold text-gray-600">Pesanan</span>
                    </a>
                    <a href="keranjang.php" class="flex flex-col items-center gap-1 py-2 bg-white rounded-xl border border-emerald-100 hover:border-emerald-300 transition-colors relative">
                        <i class="fa-solid fa-cart-shopping text-emerald-600 text-sm"></i>
                        <span class="text-[10px] font-semibold text-gray-600">Keranjang</span>
                        <span class="absolute -top-1 -right-1 w-5 h-5 bg-emerald-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center <?= $totalKeranjang > 0 ? '' : 'hidden' ?>"><?= $totalKeranjang ?></span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Navigation Links -->
            <div class="space-y-1">
                <p class="px-4 py-2 text-xs font-bold text-gray-400 uppercase tracking-wider">Menu</p>
                <a href="index.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium transition-all <?= $currentPage === 'index' ? 'text-emerald-700 bg-emerald-50 border border-emerald-100' : 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-600' ?>">
                    <i class="fa-solid fa-house w-5 text-center <?= $currentPage === 'index' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Beranda
                </a>
                <a href="scan.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium transition-all <?= $currentPage === 'scan' ? 'text-emerald-700 bg-emerald-50 border border-emerald-100' : 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-600' ?>">
                    <i class="fa-solid fa-qrcode w-5 text-center <?= $currentPage === 'scan' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Scan AI
                </a>
                <a href="produk.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium transition-all <?= $currentPage === 'produk' ? 'text-emerald-700 bg-emerald-50 border border-emerald-100' : 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-600' ?>">
                    <i class="fa-solid fa-shop w-5 text-center <?= $currentPage === 'produk' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Katalog Produk
                </a>
              
                <a href="index.php#tentang" class="flex items-center gap-3 py-3 px-4 rounded-xl text-gray-700 hover:bg-emerald-50 hover:text-emerald-600 font-medium transition-all">
                    <i class="fa-solid fa-circle-info w-5 text-center text-emerald-500"></i> Tentang
                </a>
            </div>

            <?php if (!$isLoggedIn): ?>
            <!-- Guest: Auth Buttons -->
            <div class="pt-4 mt-4 border-t border-gray-100">
                <div class="grid grid-cols-2 gap-3">
                    <a href="login.php" class="text-center py-3 text-emerald-600 border-2 border-emerald-600 rounded-xl font-semibold hover:bg-emerald-50 transition-all">
                        Masuk
                    </a>
                    <a href="register.php" class="text-center py-3 btn-primary text-white rounded-xl font-semibold shadow-md">
                        Daftar
                    </a>
                </div>
            </div>
            <?php else: ?>
            <!-- Logged In: Logout -->
            <div class="pt-4 mt-4 border-t border-gray-100">
                <a href="logout.php" class="flex items-center gap-3 py-3 px-4 rounded-xl text-red-600 hover:bg-red-50 font-medium transition-all">
                    <i class="fa-solid fa-right-from-bracket w-5 text-center"></i> Keluar Akun
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

<main class="py-8 md:py-12">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="mb-8">
            <h1 class="text-2xl md:text-3xl font-bold text-gray-900 flex items-center gap-3 mt-15">
                <i class="fa-solid fa-bag-shopping text-emerald-500"></i> Pesanan Saya
            </h1>
            <p class="text-gray-500 mt-1">Kelola dan lacak semua pesanan Anda</p>
        </div>

        <!-- Filter & Search -->
        <div class="bg-white rounded-2xl border border-gray-100 p-4 mb-6 shadow-sm">
            <form method="GET" action="" class="flex flex-col sm:flex-row gap-3">
                <div class="flex-1 relative">
                    <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                    <input type="text" name="search" value="<?= clean($search) ?>" 
                        placeholder="Cari nomor order atau nama penerima..."
                        class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all">
                </div>
                <select name="status" onchange="this.form.submit()"
                    class="px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all bg-white cursor-pointer">
                    <?php foreach ($filterOptions as $val => $label): ?>
                    <option value="<?= $val ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (!empty($search) || $statusFilter !== 'semua'): ?>
                <a href="pesanan.php" class="px-4 py-2.5 border border-gray-200 rounded-xl text-sm text-gray-600 hover:bg-gray-50 transition-colors flex items-center justify-center gap-2">
                    <i class="fa-solid fa-rotate-left"></i> Reset
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Daftar Pesanan -->
        <?php if (empty($pesananList)): ?>
        <div class="bg-white rounded-2xl border border-gray-100 p-12 text-center shadow-sm">
            <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-gray-50 flex items-center justify-center">
                <i class="fa-solid fa-bag-shopping text-3xl text-gray-300"></i>
            </div>
            <h3 class="text-lg font-bold text-gray-900 mb-1">Belum Ada Pesanan</h3>
            <p class="text-gray-500 mb-6">Anda belum memiliki pesanan. Yuk mulai berbelanja!</p>
            <a href="index.php" class="inline-flex items-center gap-2 btn-primary text-white font-bold px-6 py-3 rounded-xl">
                <i class="fa-solid fa-store"></i> Mulai Belanja
            </a>
        </div>
        <?php else: ?>

        <div class="space-y-4">
            <?php foreach ($pesananList as $p): 
                $status = $p['status'] ?? 'pending';
                $info = $statusConfig[$status] ?? $statusConfig['pending'];
                $items = $detailMap[$p['id']] ?? [];
                $firstItem = $items[0] ?? null;
                $itemCount = count($items);
                $currentStep = $info['step'] ?? 1;
                $isActiveOrder = in_array($status, ['pending', 'paid', 'shipped']);
            ?>
            <div class="order-card bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden" id="order-<?= clean($p['order_id']) ?>">
                <!-- Header Card -->
                <div class="p-5">
                    <div class="flex flex-col md:flex-row md:items-center justify-between gap-4 mb-4">
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-1 flex-wrap">
                                <span class="font-mono font-bold text-gray-900"><?= clean($p['order_id']) ?></span>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-semibold <?= $info['bg'] ?> <?= $info['text'] ?> border <?= $info['border'] ?> flex items-center gap-1">
                                    <i class="fa-solid <?= $info['icon'] ?> text-[10px]"></i>
                                    <?= $info['label'] ?>
                                </span>
                            </div>
                            <p class="text-xs text-gray-400"><?= formatTanggal($p['created_at']) ?></p>
                        </div>
                        <div class="text-right">
                            <p class="text-xs text-gray-500">Total Bayar</p>
                            <p class="text-lg font-bold text-emerald-600 font-mono"><?= formatRupiah($p['total_harga']) ?></p>
                        </div>
                    </div>

                    <!-- Timeline Tracking (hanya untuk pesanan aktif) -->
                    <?php if ($isActiveOrder && $currentStep > 0): ?>
                    <div class="mb-4 p-4 bg-gray-50 rounded-xl">
                        <div class="flex items-center justify-between relative">
                            <?php 
                            $stepLabels = [
                                1 => ['icon' => 'fa-file-invoice', 'label' => 'Dibuat'],
                                2 => ['icon' => 'fa-box-open', 'label' => 'Diproses'],
                                3 => ['icon' => 'fa-truck-fast', 'label' => 'Dikirim'],
                                4 => ['icon' => 'fa-check', 'label' => 'Selesai'],
                            ];
                            $totalSteps = 4;
                            for ($s = 1; $s <= $totalSteps; $s++): 
                                $stepData = $stepLabels[$s];
                                if ($s < $currentStep) {
                                    $stepClass = 'bg-emerald-500 text-white';
                                    $lineClass = 'bg-emerald-500';
                                } elseif ($s == $currentStep) {
                                    $stepClass = 'bg-blue-500 text-white ring-4 ring-blue-200';
                                    $lineClass = 'bg-gray-200';
                                } else {
                                    $stepClass = 'bg-gray-200 text-gray-400';
                                    $lineClass = 'bg-gray-200';
                                }
                            ?>
                            <div class="flex flex-col items-center relative z-10" style="flex: 1;">
                                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold <?= $stepClass ?> transition-all duration-500">
                                    <i class="fa-solid <?= $stepData['icon'] ?>"></i>
                                </div>
                                <span class="text-[10px] font-medium mt-1.5 <?= $s <= $currentStep ? 'text-gray-700' : 'text-gray-400' ?>"><?= $stepData['label'] ?></span>
                            </div>
                            <?php if ($s < $totalSteps): ?>
                            <div class="flex-1 h-0.5 mx-2 <?= $s < $currentStep ? 'bg-emerald-500' : 'bg-gray-200' ?> transition-all duration-500"></div>
                            <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Item Preview -->
                    <div class="flex items-center gap-4 py-3 border-t border-gray-50">
                        <?php if ($firstItem): 
                            $thumb = getThumb($firstItem['gambar'] ?? '');
                        ?>
                        <img src="<?= $thumb ?>" class="w-14 h-14 rounded-lg object-cover bg-gray-50 border border-gray-100" 
                             onerror="this.src='https://placehold.co/100x100/e2e8f0/94a3b8?text=No+Image'">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-gray-900 truncate"><?= clean($firstItem['nama_produk']) ?></p>
                            <p class="text-xs text-gray-500">
                                <?= $firstItem['jumlah'] ?> x <?= formatRupiah($firstItem['harga_satuan']) ?>
                                <?php if ($itemCount > 1): ?>
                                <span class="text-emerald-600 font-medium">+<?= $itemCount - 1 ?> item lainnya</span>
                                <?php endif; ?>
                            </p>
                            <?php if (!empty($firstItem['nama_toko'])): ?>
                            <p class="text-[10px] text-gray-400 mt-0.5"><i class="fa-solid fa-store mr-1"></i><?= clean($firstItem['nama_toko']) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- Actions -->
                    <div class="flex flex-wrap items-center gap-2 pt-3 border-t border-gray-50">
                        <button onclick="openDetail(<?= (int)$p['id'] ?>)" 
                            class="px-4 py-2 rounded-xl text-sm font-medium text-gray-700 bg-gray-50 hover:bg-gray-100 transition-colors flex items-center gap-2">
                            <i class="fa-solid fa-eye"></i> Detail
                        </button>

                        <?php if (in_array($status, ['pending', ''])): ?>
                        <a href="pembayaran.php?order_id=<?= urlencode($p['order_id']) ?>" 
                            class="px-4 py-2 rounded-xl text-sm font-medium text-white btn-primary flex items-center gap-2">
                            <i class="fa-solid fa-credit-card"></i> Bayar Sekarang
                        </a>
                        <form method="POST" action="" class="inline" onsubmit="return confirm('Yakin ingin membatalkan pesanan ini?')">
                            <input type="hidden" name="order_id" value="<?= clean($p['order_id']) ?>">
                            <button type="submit" name="cancel_order" 
                                class="px-4 py-2 rounded-xl text-sm font-medium text-rose-600 bg-rose-50 hover:bg-rose-100 transition-colors flex items-center gap-2">
                                <i class="fa-solid fa-xmark"></i> Batalkan
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if (in_array($status, ['expire', 'deny', 'failure'])): ?>
                        <form method="POST" action="" class="inline">
                            <input type="hidden" name="order_id" value="<?= clean($p['order_id']) ?>">
                            <button type="submit" name="bayar_ulang" 
                                class="px-4 py-2 rounded-xl text-sm font-medium text-white btn-primary flex items-center gap-2">
                                <i class="fa-solid fa-rotate"></i> Bayar Ulang
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if ($status === 'shipped'): ?>
                        <form method="POST" action="" class="inline" onsubmit="return confirm('Pesanan sudah diterima?')">
                            <input type="hidden" name="order_id" value="<?= clean($p['order_id']) ?>">
                            <button type="submit" name="terima_pesanan" 
                                class="px-4 py-2 rounded-xl text-sm font-medium text-white bg-emerald-600 hover:bg-emerald-700 transition-colors flex items-center gap-2 shadow-md shadow-emerald-200">
                                <i class="fa-solid fa-check"></i> Pesanan Diterima
                            </button>
                        </form>
                        <?php endif; ?>

                        <?php if ($status === 'completed'): ?>
                        <span class="px-4 py-2 rounded-xl text-sm font-medium text-emerald-700 bg-emerald-50 flex items-center gap-2">
                            <i class="fa-solid fa-circle-check"></i> Pesanan Selesai
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="flex items-center justify-center gap-2 mt-8">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&status=<?= $statusFilter ?>&search=<?= urlencode($search) ?>" 
                class="w-10 h-10 rounded-xl border border-gray-200 flex items-center justify-center text-gray-600 hover:bg-gray-50 transition-colors">
                <i class="fa-solid fa-chevron-left text-sm"></i>
            </a>
            <?php endif; ?>

            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?page=<?= $i ?>&status=<?= $statusFilter ?>&search=<?= urlencode($search) ?>" 
                class="w-10 h-10 rounded-xl flex items-center justify-center text-sm font-semibold transition-colors <?= $i === $page ? 'bg-emerald-600 text-white' : 'border border-gray-200 text-gray-600 hover:bg-gray-50' ?>">
                <?= $i ?>
            </a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&status=<?= $statusFilter ?>&search=<?= urlencode($search) ?>" 
                class="w-10 h-10 rounded-xl border border-gray-200 flex items-center justify-center text-gray-600 hover:bg-gray-50 transition-colors">
                <i class="fa-solid fa-chevron-right text-sm"></i>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
    </div>
</main>

<!-- Detail Drawer -->
<div id="detailDrawer" class="drawer-overlay fixed inset-0 z-[60] bg-black/40 backdrop-blur-sm flex justify-end">
    <div class="drawer-panel w-full max-w-md bg-white h-full overflow-y-auto shadow-2xl">
        <div class="sticky top-0 bg-white border-b border-gray-100 p-5 flex items-center justify-between z-10">
            <h2 class="text-lg font-bold text-gray-900">Detail Pesanan</h2>
            <button onclick="closeDetail()" class="w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center text-gray-500 transition-colors">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
        <div id="detailContent" class="p-5">
            <!-- Content diisi via JS -->
        </div>
    </div>
</div>

 <!-- ==================== FOOTER ==================== -->
 <footer class="bg-gray-950 text-gray-400 pt-20 pb-10">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-12 pb-12 border-b border-gray-900">
                <div class="space-y-4">
                    <!-- Footer Logo - GANTI GAMBAR LOGO DI SINI JUGA -->
                  <!-- Footer Logo - TANPA FILTER -->
<a href="index.php" class="flex items-center gap-2 font-bold text-2xl text-white">
    <img src="uploads/logo/tani.png" 
         alt="Etanimart" 
         class="logo-img"
         style="height: 120px; width: auto; object-fit: contain;"
         onerror="this.style.display='none'">
</a>
                    <p class="text-sm text-gray-500 leading-relaxed">Platform e-commerce pertanian dengan deteksi penyakit tanaman berbasis AI. Solusi modern untuk petani Indonesia.</p>
                    <div class="flex gap-3">
                        <a href="#" class="w-10 h-10 bg-gray-900 hover:bg-emerald-600 rounded-xl flex items-center justify-center text-gray-400 hover:text-white transition-all"><i class="fa-brands fa-instagram"></i></a>
                        <a href="#" class="w-10 h-10 bg-gray-900 hover:bg-emerald-600 rounded-xl flex items-center justify-center text-gray-400 hover:text-white transition-all"><i class="fa-brands fa-facebook-f"></i></a>
                        <a href="#" class="w-10 h-10 bg-gray-900 hover:bg-emerald-600 rounded-xl flex items-center justify-center text-gray-400 hover:text-white transition-all"><i class="fa-brands fa-youtube"></i></a>
                    </div>
                </div>
                <div>
                    <h4 class="text-white font-semibold mb-4">Menu Cepat</h4>
                    <ul class="space-y-3 text-sm">
                        <li><a href="#beranda" class="hover:text-emerald-500 transition-colors">Beranda</a></li>
                        <li><a href="scan.php" class="hover:text-emerald-500 transition-colors">Scan AI</a></li>
                        <li><a href="produk.php" class="hover:text-emerald-500 transition-colors">Katalog Produk</a></li>
                        <li><a href="#tentang" class="hover:text-emerald-500 transition-colors">Tentang Kami</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-semibold mb-4">Layanan</h4>
                    <ul class="space-y-3 text-sm">
                        <li><a href="#" class="hover:text-emerald-500 transition-colors">Deteksi Penyakit</a></li>
                        <li><a href="#" class="hover:text-emerald-500 transition-colors">Rekomendasi Obat</a></li>
                        <li><a href="#" class="hover:text-emerald-500 transition-colors">Konsultasi Ahli</a></li>
                        <li><a href="#" class="hover:text-emerald-500 transition-colors">Panduan Pertanian</a></li>
                    </ul>
                </div>
                <div>
                    <h4 class="text-white font-semibold mb-4">Hubungi Kami</h4>
                    <ul class="space-y-3 text-sm">
                        <li class="flex items-center gap-3"><i class="fa-solid fa-phone text-emerald-500 w-4"></i><span>+62 812-3456-7890</span></li>
                        <li class="flex items-center gap-3"><i class="fa-solid fa-envelope text-emerald-500 w-4"></i><span>support@etanimart.com</span></li>
                        <li class="flex items-center gap-3"><i class="fa-solid fa-location-dot text-emerald-500 w-4"></i><span>Jakarta, Indonesia</span></li>
                    </ul>
                </div>
            </div>
            <div class="flex flex-col md:flex-row justify-between items-center gap-4 pt-8">
                <p class="text-xs text-gray-600">&copy; 2026 Etanimart Project. All Rights Reserved.</p>
                <div class="flex gap-6 text-xs text-gray-600">
                    <a href="#" class="hover:text-emerald-500 transition-colors">Kebijakan Privasi</a>
                    <a href="#" class="hover:text-emerald-500 transition-colors">Syarat & Ketentuan</a>
                </div>
            </div>
        </div>
    </footer>

<script>
// Data pesanan untuk JS
const orderDetails = <?= json_encode($detailMap, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const pesananData = {};
<?php foreach ($pesananList as $p): ?>
pesananData[<?= (int)$p['id'] ?>] = <?= json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
<?php endforeach; ?>

const statusConfig = <?= json_encode($statusConfig, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const timelineSteps = <?= json_encode($timelineSteps, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function openDetail(pesananId) {
    const p = pesananData[pesananId];
    if (!p) return;

    const items = orderDetails[pesananId] || [];
    const info = statusConfig[p.status] || statusConfig['pending'];
    const currentStep = info.step || 1;
    const isActive = ['pending', 'paid', 'shipped'].includes(p.status);

    let itemsHtml = items.map(function(item) {
        let thumb = 'https://placehold.co/100x100/e2e8f0/94a3b8?text=No+Image';
        try {
            const gambarArr = JSON.parse(item.gambar || '[]');
            if (gambarArr && gambarArr[0]) thumb = 'uploads/' + gambarArr[0];
        } catch(e) {}

        let tokoHtml = item.nama_toko ? '<p class="text-[10px] text-gray-400 mt-0.5"><i class="fa-solid fa-store mr-1"></i>' + item.nama_toko + '</p>' : '';

        return '<div class="flex gap-3 py-3 border-b border-gray-50 last:border-0">' +
            '<img src="' + thumb + '" class="w-16 h-16 rounded-lg object-cover bg-gray-50 border border-gray-100" onerror="this.src=\'https://placehold.co/100x100/e2e8f0/94a3b8?text=No+Image\'">' +
            '<div class="flex-1">' +
                '<p class="text-sm font-semibold text-gray-900">' + item.nama_produk + '</p>' +
                '<p class="text-xs text-gray-500">' + item.jumlah + ' x Rp ' + parseInt(item.harga_satuan).toLocaleString('id-ID') + '</p>' +
                tokoHtml +
            '</div>' +
            '<p class="text-sm font-bold text-emerald-600 font-mono">Rp ' + parseInt(item.subtotal).toLocaleString('id-ID') + '</p>' +
        '</div>';
    }).join('');

    // Timeline HTML
    let timelineHtml = '';
    if (isActive && currentStep > 0) {
        timelineHtml = '<div class="mb-6">' +
            '<h3 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-2"><i class="fa-solid fa-route text-emerald-500"></i> Status Pengiriman</h3>' +
            '<div class="bg-gray-50 rounded-xl p-4">';

        const stepLabels = [
            {icon: 'fa-file-invoice', label: 'Pesanan Dibuat', desc: 'Menunggu pembayaran'},
            {icon: 'fa-box-open', label: 'Diproses Penjual', desc: 'Penjual menyiapkan pesanan'},
            {icon: 'fa-truck-fast', label: 'Dalam Pengiriman', desc: 'Pesanan dalam perjalanan'},
            {icon: 'fa-house-chimney', label: 'Pesanan Selesai', desc: 'Pesanan telah diterima'}
        ];

        for (let s = 0; s < stepLabels.length; s++) {
            const step = stepLabels[s];
            const stepNum = s + 1;
            let dotClass, textClass;

            if (stepNum < currentStep) {
                dotClass = 'bg-emerald-500 text-white';
                textClass = 'text-gray-700';
            } else if (stepNum === currentStep) {
                dotClass = 'bg-blue-500 text-white';
                textClass = 'text-gray-900 font-semibold';
            } else {
                dotClass = 'bg-gray-200 text-gray-400';
                textClass = 'text-gray-400';
            }

            timelineHtml += '<div class="flex items-start gap-3 ' + (s < stepLabels.length - 1 ? 'mb-4' : '') + '">' +
                '<div class="w-8 h-8 rounded-full flex items-center justify-center text-xs ' + dotClass + ' shrink-0">' +
                    '<i class="fa-solid ' + step.icon + '"></i>' +
                '</div>' +
                '<div>' +
                    '<p class="text-sm ' + textClass + '">' + step.label + '</p>' +
                    '<p class="text-xs text-gray-400">' + step.desc + '</p>' +
                '</div>' +
            '</div>';
        }
        timelineHtml += '</div></div>';
    }

    const catatanHtml = p.catatan ? 
        '<div class="flex items-start gap-3"><i class="fa-solid fa-note-sticky text-gray-400 mt-0.5 w-4"></i><p class="text-gray-700">' + p.catatan + '</p></div>' : '';

    let actionHtml = '';
    if (p.status === 'pending') {
        actionHtml = '<a href="pembayaran.php?order_id=' + encodeURIComponent(p.order_id) + '" class="w-full block text-center btn-primary text-white font-bold py-3 rounded-xl shadow-lg mt-4"><i class="fa-solid fa-credit-card mr-2"></i> Bayar Sekarang</a>';
    } else if (p.status === 'shipped') {
        actionHtml = '<form method="POST" action="" onsubmit="return confirm(\'Pesanan sudah diterima?\')">' +
            '<input type="hidden" name="order_id" value="' + p.order_id + '">' +
            '<button type="submit" name="terima_pesanan" class="w-full block text-center bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl shadow-lg mt-4 transition-colors"><i class="fa-solid fa-check mr-2"></i> Pesanan Diterima</button>' +
        '</form>';
    } else if (p.status === 'completed') {
        actionHtml = '<div class="w-full text-center bg-emerald-50 text-emerald-700 font-bold py-3 rounded-xl mt-4"><i class="fa-solid fa-circle-check mr-2"></i> Pesanan Selesai</div>';
    }

    const html = '<div class="space-y-6">' +
        '<div class="text-center pb-4 border-b border-gray-100">' +
            '<span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold ' + info.bg + ' ' + info.text + ' border ' + info.border + '">' +
                '<i class="fa-solid ' + info.icon + '"></i> ' + info.label +
            '</span>' +
            '<p class="font-mono font-bold text-gray-900 mt-2">' + p.order_id + '</p>' +
            '<p class="text-xs text-gray-400">' + new Date(p.created_at).toLocaleString('id-ID', {day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'}) + '</p>' +
        '</div>' +
        timelineHtml +
        '<div>' +
            '<h3 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-2"><i class="fa-solid fa-box text-emerald-500"></i> Item Pesanan (' + items.length + ')</h3>' +
            '<div class="bg-gray-50 rounded-xl p-3">' + itemsHtml + '</div>' +
        '</div>' +
        '<div>' +
            '<h3 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-2"><i class="fa-solid fa-truck-fast text-emerald-500"></i> Informasi Pengiriman</h3>' +
            '<div class="bg-gray-50 rounded-xl p-4 space-y-2 text-sm">' +
                '<div class="flex items-start gap-3"><i class="fa-solid fa-user text-gray-400 mt-0.5 w-4"></i><div><p class="font-semibold text-gray-900">' + p.nama_penerima + '</p><p class="text-gray-500">' + p.no_telepon + '</p></div></div>' +
                '<div class="flex items-start gap-3"><i class="fa-solid fa-location-dot text-gray-400 mt-0.5 w-4"></i><p class="text-gray-700">' + (p.alamat || '').replace(/\n/g, '<br>') + '</p></div>' +
                catatanHtml +
            '</div>' +
        '</div>' +
        '<div class="bg-emerald-50 rounded-xl p-4 space-y-2">' +
            '<div class="flex justify-between text-sm"><span class="text-gray-600">Subtotal</span><span class="font-semibold text-gray-900">Rp ' + parseInt(p.total_harga).toLocaleString('id-ID') + '</span></div>' +
            '<div class="flex justify-between text-sm"><span class="text-gray-600">Ongkir</span><span class="font-semibold text-emerald-600">Gratis</span></div>' +
            '<div class="h-px bg-emerald-200"></div>' +
            '<div class="flex justify-between"><span class="text-gray-900 font-bold">Total</span><span class="text-lg font-bold text-emerald-600 font-mono">Rp ' + parseInt(p.total_harga).toLocaleString('id-ID') + '</span></div>' +
        '</div>' +
        actionHtml +
    '</div>';

    document.getElementById('detailContent').innerHTML = html;
    document.getElementById('detailDrawer').classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeDetail() {
    document.getElementById('detailDrawer').classList.remove('active');
    document.body.style.overflow = '';
}

document.getElementById('detailDrawer').addEventListener('click', function(e) {
    if (e.target === this) closeDetail();
});

 // ===== MOBILE MENU =====
 const btnMenu = document.getElementById('btn-menu');
        const mobileMenu = document.getElementById('mobile-menu');
        const menuIcon = document.getElementById('menuIcon');

        btnMenu.addEventListener('click', (e) => {
            e.stopPropagation();
            const isHidden = mobileMenu.classList.contains('hidden');

            if (isHidden) {
                mobileMenu.classList.remove('hidden');
                btnMenu.classList.add('bg-emerald-50', 'text-emerald-600');
                menuIcon.classList.remove('fa-bars');
                menuIcon.classList.add('fa-xmark');
                document.body.style.overflow = 'hidden';
            } else {
                closeMobileMenu();
            }
        });

        function closeMobileMenu() {
            mobileMenu.classList.add('hidden');
            btnMenu.classList.remove('bg-emerald-50', 'text-emerald-600');
            menuIcon.classList.remove('fa-xmark');
            menuIcon.classList.add('fa-bars');
            document.body.style.overflow = '';
        }

        // Close mobile menu when clicking a link
        document.querySelectorAll('#mobile-menu a').forEach(link => {
            link.addEventListener('click', () => {
                closeMobileMenu();
            });
        });

        // Close mobile menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!mobileMenu.contains(e.target) && !btnMenu.contains(e.target)) {
                closeMobileMenu();
            }
        });

        // ===== NAVBAR SCROLL =====
        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                navbar.classList.add('bg-white/95', 'backdrop-blur-xl', 'shadow-sm');
            } else {
                navbar.classList.remove('bg-white/95', 'backdrop-blur-xl', 'shadow-sm');
            }
        });

        // ===== USER DROPDOWN =====
        function toggleUserDropdown(e) {
            e.stopPropagation();
            const menu = document.getElementById('userDropdownMenu');
            const icon = document.getElementById('userDropdownIcon');
            const isActive = menu.classList.contains('active');

            // Close all other dropdowns first
            document.querySelectorAll('.user-dropdown-menu').forEach(m => m.classList.remove('active'));
            document.querySelectorAll('#userDropdownIcon').forEach(i => i.style.transform = 'rotate(0deg)');

            if (!isActive) {
                menu.classList.add('active');
                icon.style.transform = 'rotate(180deg)';
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', (e) => {
            const dropdowns = document.querySelectorAll('.user-dropdown');
            dropdowns.forEach(dropdown => {
                if (!dropdown.contains(e.target)) {
                    const menu = dropdown.querySelector('.user-dropdown-menu');
                    const icon = dropdown.querySelector('#userDropdownIcon');
                    if (menu) menu.classList.remove('active');
                    if (icon) icon.style.transform = 'rotate(0deg)';
                }
            });
        });

        // Close dropdown on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.user-dropdown-menu').forEach(m => m.classList.remove('active'));
                document.querySelectorAll('#userDropdownIcon').forEach(i => i.style.transform = 'rotate(0deg)');
                closeMobileMenu();
                closeDetail();
            }
        });

// ==========================================
// POLLING STATUS UNTUK PESANAN PENDING (BARU)
// ==========================================
<?php if ($hasPendingOrder): ?>
(function() {
    let pollCount = 0;
    const maxPoll = 24; // 2 menit (24 x 5 detik)
    
    const interval = setInterval(function() {
        pollCount++;
        if (pollCount > maxPoll) { clearInterval(interval); return; }
        
        // Cek semua order yang pending
        const pendingOrders = document.querySelectorAll('[id^="order-ETN-"]');
        let hasPending = false;
        
        pendingOrders.forEach(function(el) {
            const statusBadge = el.querySelector('.bg-amber-50');
            if (statusBadge) hasPending = true;
        });
        
        if (!hasPending) { clearInterval(interval); return; }
        
        // Fetch status terbaru
        fetch('cek_status_batch.php?_=' + Date.now())
            .then(res => res.json())
            .then(data => {
                let shouldReload = false;
                data.orders.forEach(function(order) {
                    if (order.status !== 'pending') shouldReload = true;
                });
                if (shouldReload) window.location.reload();
            })
            .catch(() => {});
    }, 5000);
})();
<?php endif; ?>
</script>

<script>
        // Fungsi untuk Toggle Dropdown Profil (Desktop)
        function toggleUserDropdown(event) {
            event.stopPropagation();
            const menu = document.getElementById('userDropdownMenu');
            const icon = document.getElementById('userDropdownIcon');
            
            menu.classList.toggle('active');
            if (icon) {
                icon.classList.toggle('rotate-180');
            }
        }

        // Fungsi untuk Toggle Mobile Menu (Hamburger)
        const btnMenu = document.getElementById('btn-menu');
        const mobileMenu = document.getElementById('mobile-menu');
        const menuIcon = document.getElementById('menuIcon');

        if (btnMenu && mobileMenu) {
            btnMenu.addEventListener('click', (e) => {
                e.stopPropagation();
                mobileMenu.classList.toggle('hidden');
                
                if (menuIcon.classList.contains('fa-bars')) {
                    menuIcon.classList.remove('fa-bars');
                    menuIcon.classList.add('fa-xmark');
                } else {
                    menuIcon.classList.remove('fa-xmark');
                    menuIcon.classList.add('fa-bars');
                }
            });
        }

        document.addEventListener('click', function (event) {
            const dropdownMenu = document.getElementById('userDropdownMenu');
            const dropdownButton = document.querySelector('.user-dropdown button');
            const icon = document.getElementById('userDropdownIcon');

            if (dropdownMenu && dropdownMenu.classList.contains('active')) {
                if (!dropdownButton.contains(event.target) && !dropdownMenu.contains(event.target)) {
                    dropdownMenu.classList.remove('active');
                    if (icon) icon.classList.remove('rotate-180');
                }
            }

            if (mobileMenu && !mobileMenu.classList.contains('hidden')) {
                if (!btnMenu.contains(event.target) && !mobileMenu.contains(event.target)) {
                    mobileMenu.classList.add('hidden');
                    if (menuIcon) {
                        menuIcon.classList.remove('fa-xmark');
                        menuIcon.classList.add('fa-bars');
                    }
                }
            }
        });
    </script>
</body>
</html>