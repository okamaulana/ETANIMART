<?php
// =============================================================================
// keranjang.php - Etanimart Shopping Cart
// =============================================================================
session_start();

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
require_once 'koneksi.php';


// Auto delete pesanan cancelled > 24 jam
try {
    $pdo->query("DELETE FROM pesanan WHERE status = 'cancelled' AND updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
} catch (PDOException $e) {}

// ==========================================
// CEK LOGIN & ROLE
// ==========================================
// ==========================================
// CEK LOGIN & ROLE
// ==========================================
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header("Location: login.php?redirect=" . urlencode('keranjang.php'));
    exit();
}

// FIX: support both user_role dan role
$userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
if ($userRole !== 'pembeli') {
    header("Location: index.php");
    exit();
}



$userId   = (int)$_SESSION['user_id'];
// Ambil data user dari database (sama seperti index.php)
$userData = null;
try {
    $stmtUser = $pdo->prepare("SELECT nama, foto_profil FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $userData = $stmtUser->fetch();
} catch (PDOException $e) {
    $userData = null;
}

if ($userData) {
    $userName = $userData['nama'];
    $userFoto = $userData['foto_profil'];
} else {
    $userName = $_SESSION['nama'] ?? 'User';
    $userFoto = $_SESSION['foto_profil'] ?? '';
}

function getProfilePic($foto) {
    if (!empty($foto) && file_exists('uploads/profil/' . $foto)) {
        return 'uploads/profil/' . $foto;
    }
    return 'https://placehold.co/100x100/e2e8f0/94a3b8?text=' . urlencode(substr($foto ?? 'U', 0, 1));
}
function clean($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function formatRupiah($angka) {
    return 'Rp ' . number_format((int)($angka ?? 0), 0, ',', '.');
}
function getThumb($gambarJson) {
    if (empty($gambarJson)) return 'https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image';
    $decoded = json_decode($gambarJson, true);
    if (is_array($decoded) && !empty($decoded[0])) return 'uploads/' . $decoded[0];
    return 'https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image';
}

// ==========================================
// PROSES AJAX: UPDATE JUMLAH
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_qty') {
    header('Content-Type: application/json');
    $cartId = (int)($_POST['cart_id'] ?? 0);
    $jumlah = (int)($_POST['jumlah'] ?? 1);

    if ($cartId <= 0 || $jumlah < 1) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
        exit();
    }

    try {
        // Verifikasi kepemilikan
        $stmt = $pdo->prepare("SELECT k.id, k.id_produk, p.stok FROM keranjang k JOIN produk p ON k.id_produk = p.id WHERE k.id = :cid AND k.id_user = :idu LIMIT 1");
        $stmt->execute(['cid' => $cartId, 'idu' => $userId]);
        $item = $stmt->fetch();

        if (!$item) {
            echo json_encode(['success' => false, 'message' => 'Item tidak ditemukan']);
            exit();
        }

        if ($jumlah > $item['stok']) {
            echo json_encode(['success' => false, 'message' => 'Stok tidak mencukupi. Tersisa ' . $item['stok'] . ' unit']);
            exit();
        }

        $stmt = $pdo->prepare("UPDATE keranjang SET jumlah = :jum, updated_at = NOW() WHERE id = :cid AND id_user = :idu");
        $stmt->execute(['jum' => $jumlah, 'cid' => $cartId, 'idu' => $userId]);

        echo json_encode(['success' => true, 'message' => 'Jumlah diperbarui']);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
        exit();
    }
}

// ==========================================
// PROSES AJAX: HAPUS ITEM
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hapus_item') {
    header('Content-Type: application/json');
    $cartId = (int)($_POST['cart_id'] ?? 0);

    if ($cartId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Data tidak valid']);
        exit();
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM keranjang WHERE id = :cid AND id_user = :idu");
        $stmt->execute(['cid' => $cartId, 'idu' => $userId]);

        // Hitung total item
        $stmtCount = $pdo->prepare("SELECT COALESCE(SUM(jumlah), 0) as total FROM keranjang WHERE id_user = :idu");
        $stmtCount->execute(['idu' => $userId]);
        $totalKeranjang = (int)$stmtCount->fetchColumn();

        echo json_encode(['success' => true, 'message' => 'Item dihapus', 'total_keranjang' => $totalKeranjang]);
        exit();
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
        exit();
    }
}

// ==========================================
// PROSES: HAPUS SEMUA
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'hapus_semua') {
    try {
        $stmt = $pdo->prepare("DELETE FROM keranjang WHERE id_user = :idu");
        $stmt->execute(['idu' => $userId]);
        header("Location: keranjang.php?clear=success");
        exit();
    } catch (PDOException $e) {
        $errorMsg = 'Gagal mengosongkan keranjang: ' . $e->getMessage();
    }
}

// ==========================================
// PROSES: CHECKOUT TERPILIH
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'checkout') {
    $selected = $_POST['selected'] ?? [];

    if (empty($selected)) {
        $errorMsg = 'Pilih minimal satu item untuk checkout.';
    } else {
        // Simpan ke session
        $_SESSION['checkout_cart'] = array_map('intval', $selected);
        header("Location: checkout.php?from=keranjang");
        exit();
    }
}

// ==========================================
// AMBIL DATA KERANJANG
// ==========================================
try {
    $stmt = $pdo->prepare("
        SELECT k.id as cart_id, k.jumlah, k.created_at,
               p.id as produk_id, p.nama, p.harga, p.gambar, p.stok, p.kategori
        FROM keranjang k
        JOIN produk p ON k.id_produk = p.id
        WHERE k.id_user = :idu
        ORDER BY k.created_at DESC
    ");
    $stmt->execute(['idu' => $userId]);
    $keranjangItems = $stmt->fetchAll();

    $totalItem = count($keranjangItems);
    $totalHarga = 0;
    foreach ($keranjangItems as $item) {
        $totalHarga += $item['harga'] * $item['jumlah'];
    }

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Hitung badge
$totalKeranjang = 0;
try {
    $stmtCart = $pdo->prepare("SELECT COALESCE(SUM(jumlah), 0) as total FROM keranjang WHERE id_user = :idu");
    $stmtCart->execute(['idu' => $userId]);
    $totalKeranjang = (int)$stmtCart->fetchColumn();
} catch (PDOException $e) {
    $totalKeranjang = 0;
}

// Flash message
$successMsg = '';
if (isset($_GET['clear']) && $_GET['clear'] === 'success') {
    $successMsg = 'Keranjang berhasil dikosongkan.';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Keranjang Belanja - Etanimart</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        html { scroll-behavior: smooth; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .nav-link { position: relative; }
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -4px; left: 0;
            width: 0; height: 2px;
            background: #10b981;
            transition: width 0.3s ease;
        }
        .nav-link:hover::after { width: 100%; }

        .btn-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.5);
        }

        .cart-item {
            transition: all 0.3s ease;
            animation: slideIn 0.4s ease-out;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateX(-20px); }
            to { opacity: 1; transform: translateX(0); }
        }

        .cart-item:hover {
            box-shadow: 0 8px 30px -8px rgba(0,0,0,0.1);
        }

        .qty-btn-sm {
            width: 32px; height: 32px;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid #e2e8f0;
            background: white;
            color: #374151;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            border-radius: 8px;
        }
        .qty-btn-sm:hover { background: #f0fdf4; color: #059669; border-color: #10b981; }
        .qty-btn-sm:disabled { opacity: 0.5; cursor: not-allowed; }

        .qty-input-sm {
            width: 48px; height: 32px;
            border: 1px solid #e2e8f0;
            text-align: center;
            font-weight: 600;
            color: #374151;
            background: white;
            border-radius: 8px;
            margin: 0 4px;
        }
        .qty-input-sm:focus { outline: none; border-color: #10b981; }

        .checkbox-custom {
            width: 20px; height: 20px;
            border: 2px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            appearance: none;
            -webkit-appearance: none;
        }
        .checkbox-custom:checked {
            background: #10b981;
            border-color: #10b981;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z'/%3E%3C/svg%3E");
            background-size: 14px;
            background-position: center;
            background-repeat: no-repeat;
        }

        .summary-card {
            position: sticky;
            top: 100px;
        }

        .empty-state {
            animation: float 3s ease-in-out infinite;
        }
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        .delete-btn {
            transition: all 0.2s;
        }
        .delete-btn:hover {
            background: #fee2e2;
            color: #dc2626;
        }

        /* User dropdown */
        .user-dropdown { position: relative; }
        .user-dropdown-menu {
            position: absolute;
            top: calc(100% + 8px); right: 0;
            min-width: 240px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.15);
            border: 1px solid #e2e8f0;
            opacity: 0; visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 100; overflow: hidden;
        }
        .user-dropdown-menu.active {
            opacity: 1; visibility: visible;
            transform: translateY(0) scale(1);
        }
        .user-dropdown-item {
            display: flex; align-items: center; gap: 12px;
            padding: 12px 16px;
            color: #374151; font-size: 14px; font-weight: 500;
            transition: all 0.2s ease; text-decoration: none;
        }
        .user-dropdown-item:hover { background: #f0fdf4; color: #059669; }
        .user-dropdown-item i { width: 20px; text-align: center; color: #10b981; }
        .user-dropdown-divider { height: 1px; background: #e2e8f0; margin: 4px 12px; }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 24px; left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: #10b981; color: white;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 14px; font-weight: 600;
            box-shadow: 0 10px 40px -10px rgba(16, 185, 129, 0.4);
            z-index: 9999; opacity: 0;
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex; align-items: center; gap: 8px;
        }
        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
        .toast.error { background: #ef4444; box-shadow: 0 10px 40px -10px rgba(239, 68, 68, 0.4); }

        /* Mobile menu */
        #mobile-menu { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); transform-origin: top; }
        #mobile-menu:not(.hidden) { animation: slideDown 0.3s ease-out; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        #menuIcon { transition: transform 0.3s ease; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased overflow-x-hidden">

<!-- ==================== NAVBAR ==================== -->
<nav class="fixed top-0 left-0 right-0 z-50 bg-white/95 backdrop-blur-xl shadow-sm transition-all duration-300" id="navbar">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-20 items-center">
        <a href="index.php" class="flex items-center gap-2 font-bold text-2xl text-white">
    <img src="uploads/logo/tani.png" 
         alt="Etanimart" 
         class="logo-img"
         style="height: 70px; width: auto; object-fit: contain;"
         onerror="this.style.display='none'">
</a>

            <div class="hidden lg:flex items-center space-x-8 font-medium">
                <a href="index.php" class="nav-link text-gray-600 hover:text-emerald-600 transition-colors">Beranda</a>
                <a href="scan.php" class="nav-link text-gray-600 hover:text-emerald-600 transition-colors flex items-center gap-1.5">
                    <i class="fa-solid fa-qrcode text-emerald-500"></i> Scan AI
                </a>
                <a href="produk.php" class="nav-link text-gray-600 hover:text-emerald-600 transition-colors">Katalog</a>
               
                <a href="index.php#tentang" class="nav-link text-gray-600 hover:text-emerald-600 transition-colors">Tentang</a>
            </div>

            <div class="flex items-center gap-2 sm:gap-4">
                <a href="keranjang.php" class="hidden lg:flex relative p-2.5 text-emerald-600 bg-emerald-50 rounded-xl transition-colors">
                    <i class="fa-solid fa-cart-shopping text-lg"></i>
                    <span id="cartBadge" class="absolute -top-1 -right-1 w-5 h-5 bg-emerald-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center <?= $totalKeranjang > 0 ? '' : 'hidden' ?>"><?= $totalKeranjang ?></span>
                </a>

                <div class="user-dropdown relative hidden lg:block">
                    <button class="flex items-center gap-2 sm:gap-3 pl-2 pr-1 sm:pr-2 py-1.5 rounded-full hover:bg-gray-100 transition-colors" onclick="toggleUserDropdown(event)">
                        <img src="<?= getProfilePic($userFoto) ?>" alt="<?= clean($userName) ?>" class="w-9 h-9 rounded-full object-cover border-2 border-emerald-200">
                        <div class="hidden sm:flex flex-col items-start">
                            <span class="text-sm font-bold text-gray-800 max-w-[100px] truncate leading-tight"><?= clean($userName) ?></span>
                            <span class="text-[10px] text-gray-400 font-medium leading-tight">Pembeli</span>
                        </div>
                        <i class="fa-solid fa-chevron-down text-xs text-gray-400 mr-1 transition-transform duration-200" id="userDropdownIcon"></i>
                    </button>
                    <div class="user-dropdown-menu" id="userDropdownMenu">
                        <div class="px-4 py-3 border-b border-gray-100">
                            <p class="text-sm font-bold text-gray-800"><?= clean($userName) ?></p>
                            <p class="text-xs text-gray-500">Pembeli</p>
                        </div>
                        <a href="profil.php" class="user-dropdown-item"><i class="fa-solid fa-user"></i> Profil Saya</a>
                        <a href="pesanan.php" class="user-dropdown-item"><i class="fa-solid fa-bag-shopping"></i> Pesanan Saya</a>
                        <a href="keranjang.php" class="user-dropdown-item"><i class="fa-solid fa-cart-shopping"></i> Keranjang</a>
                        <div class="user-dropdown-divider"></div>
                        <a href="logout.php" class="user-dropdown-item text-red-500 hover:text-red-600 hover:bg-red-50"><i class="fa-solid fa-right-from-bracket text-red-400"></i> Keluar</a>
                    </div>
                </div>

                <button id="btn-menu" class="lg:hidden p-2.5 text-gray-600 hover:text-emerald-600 hover:bg-emerald-50 transition-all rounded-xl">
                    <i class="fa-solid fa-bars text-xl" id="menuIcon"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Mobile Menu -->
    <div id="mobile-menu" class="hidden lg:hidden bg-white/95 backdrop-blur-xl border-t border-gray-100 shadow-xl max-h-[85vh] overflow-y-auto">
        <div class="max-w-7xl mx-auto px-4 py-4 space-y-1">
            <div class="bg-gradient-to-r from-emerald-50 to-teal-50 rounded-2xl p-4 mb-4 border border-emerald-100">
                <div class="flex items-center gap-4">
                    <img src="<?= getProfilePic($userFoto) ?>" alt="<?= clean($userName) ?>" class="w-14 h-14 rounded-full object-cover border-2 border-emerald-300 shadow-sm">
                    <div class="flex-1 min-w-0">
                        <p class="text-base font-bold text-gray-900 truncate"><?= clean($userName) ?></p>
                        <p class="text-xs text-emerald-600 font-medium flex items-center gap-1"><i class="fa-solid fa-circle-check text-[10px]"></i> Pembeli</p>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-2 mt-4">
                    <a href="profil.php" class="flex flex-col items-center gap-1 py-2 bg-white rounded-xl border border-emerald-100 hover:border-emerald-300 transition-colors"><i class="fa-solid fa-user text-emerald-600 text-sm"></i><span class="text-[10px] font-semibold text-gray-600">Profil</span></a>
                    <a href="pesanan.php" class="flex flex-col items-center gap-1 py-2 bg-white rounded-xl border border-emerald-100 hover:border-emerald-300 transition-colors"><i class="fa-solid fa-bag-shopping text-emerald-600 text-sm"></i><span class="text-[10px] font-semibold text-gray-600">Pesanan</span></a>
                    <a href="keranjang.php" class="flex flex-col items-center gap-1 py-2 bg-white rounded-xl border border-emerald-100 hover:border-emerald-300 transition-colors relative"><i class="fa-solid fa-cart-shopping text-emerald-600 text-sm"></i><span class="text-[10px] font-semibold text-gray-600">Keranjang</span><span class="absolute -top-1 -right-1 w-4 h-4 bg-emerald-500 text-white text-[8px] font-bold rounded-full flex items-center justify-center <?= $totalKeranjang > 0 ? '' : 'hidden' ?>"><?= $totalKeranjang ?></span></a>
                </div>
            </div>
            <div class="space-y-1">
                <p class="px-4 py-2 text-xs font-bold text-gray-400 uppercase tracking-wider">Menu</p>
                <a href="index.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium text-gray-700 hover:bg-emerald-50 hover:text-emerald-600 transition-all"><i class="fa-solid fa-house w-5 text-center text-emerald-500"></i> Beranda</a>
                <a href="scan.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium text-gray-700 hover:bg-emerald-50 hover:text-emerald-600 transition-all"><i class="fa-solid fa-qrcode w-5 text-center text-emerald-500"></i> Scan AI</a>
                <a href="produk.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium text-gray-700 hover:bg-emerald-50 hover:text-emerald-600 transition-all"><i class="fa-solid fa-shop w-5 text-center text-emerald-500"></i> Katalog Produk</a>
               
                <a href="index.php#tentang" class="flex items-center gap-3 py-3 px-4 rounded-xl text-gray-700 hover:bg-emerald-50 hover:text-emerald-600 font-medium transition-all"><i class="fa-solid fa-circle-info w-5 text-center text-emerald-500"></i> Tentang</a>
            </div>
            <div class="pt-4 mt-4 border-t border-gray-100">
                <a href="logout.php" class="flex items-center gap-3 py-3 px-4 rounded-xl text-red-600 hover:bg-red-50 font-medium transition-all"><i class="fa-solid fa-right-from-bracket w-5 text-center"></i> Keluar Akun</a>
            </div>
        </div>
    </div>
</nav>
<div class="h-20"></div>

<!-- ==================== BREADCRUMB ==================== -->
<div class="bg-white border-b border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3">
        <nav class="flex items-center gap-2 text-xs text-gray-500">
            <a href="index.php" class="hover:text-emerald-600 transition-colors">Beranda</a>
            <i class="fa-solid fa-chevron-right text-[10px] text-gray-300"></i>
            <span class="text-gray-800 font-medium">Keranjang Belanja</span>
        </nav>
    </div>
</div>

<!-- ==================== MAIN CONTENT ==================== -->
<main class="flex-grow py-8 md:py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

        <div class="flex items-center justify-between mb-8">
            <h1 class="text-2xl md:text-3xl font-bold text-gray-900 flex items-center gap-3">
                <i class="fa-solid fa-cart-shopping text-emerald-500"></i>
                Keranjang Belanja
                <?php if ($totalItem > 0): ?>
                    <span class="text-lg font-normal text-gray-400">(<?= $totalItem ?> item)</span>
                <?php endif; ?>
            </h1>
            <?php if ($totalItem > 0): ?>
            <form method="POST" action="" class="hidden sm:block" onsubmit="return confirm('Yakin ingin mengosongkan keranjang?')">
                <input type="hidden" name="action" value="hapus_semua">
                <button type="submit" class="text-sm text-red-500 hover:text-red-600 font-medium flex items-center gap-2 px-4 py-2 rounded-xl hover:bg-red-50 transition-all">
                    <i class="fa-solid fa-trash-can"></i> Kosongkan
                </button>
            </form>
            <?php endif; ?>
        </div>

        <?php if (!empty($successMsg)): ?>
        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-2xl text-emerald-700 text-sm font-medium flex items-center gap-2">
            <i class="fa-solid fa-check-circle"></i> <?= clean($successMsg) ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($errorMsg)): ?>
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-2xl text-red-700 text-sm font-medium flex items-center gap-2">
            <i class="fa-solid fa-circle-exclamation"></i> <?= clean($errorMsg) ?>
        </div>
        <?php endif; ?>

        <?php if ($totalItem === 0): ?>
        <!-- EMPTY STATE -->
        <div class="text-center py-20">
            <div class="empty-state w-32 h-32 bg-emerald-50 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fa-solid fa-cart-arrow-down text-5xl text-emerald-300"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-900 mb-2">Keranjang Masih Kosong</h2>
            <p class="text-sm text-gray-500 mb-8 max-w-md mx-auto">Yuk jelajahi produk pertanian terbaik kami dan tambahkan ke keranjang!</p>
            <a href="produk.php" class="btn-primary text-white px-8 py-3.5 rounded-2xl font-bold shadow-lg inline-flex items-center gap-2">
                <i class="fa-solid fa-shop"></i> Jelajahi Produk
            </a>
        </div>
        <?php else: ?>

        <form method="POST" action="" id="checkoutForm">
            <input type="hidden" name="action" value="checkout">

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                <!-- LEFT: CART ITEMS -->
                <div class="lg:col-span-2 space-y-4">

                    <!-- Select All Mobile -->
                    <div class="lg:hidden flex items-center justify-between bg-white rounded-2xl border border-gray-100 p-4 shadow-sm">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="checkbox" class="checkbox-custom" id="selectAllMobile" onchange="toggleSelectAll()">
                            <span class="text-sm font-semibold text-gray-700">Pilih Semua</span>
                        </label>
                        <span class="text-xs text-gray-400" id="selectedCountMobile">0 dipilih</span>
                    </div>

                    <?php foreach ($keranjangItems as $index => $item): 
                        $subtotal = $item['harga'] * $item['jumlah'];
                        $thumb = getThumb($item['gambar']);
                    ?>
                    <div class="cart-item bg-white rounded-2xl border border-gray-100 p-4 sm:p-5 shadow-sm flex flex-col sm:flex-row gap-4" data-cart-id="<?= $item['cart_id'] ?>" data-harga="<?= $item['harga'] ?>" data-stok="<?= $item['stok'] ?>">

                        <div class="flex items-start gap-4 flex-1">
                            <input type="checkbox" name="selected[]" value="<?= $item['cart_id'] ?>" class="checkbox-custom mt-1 cart-checkbox" onchange="updateSummary()">

                            <a href="detail_produk.php?id=<?= $item['produk_id'] ?>" class="shrink-0">
                                <div class="w-24 h-24 sm:w-28 sm:h-28 rounded-xl overflow-hidden bg-gray-50 border border-gray-100">
                                    <img src="<?= $thumb ?>" alt="<?= clean($item['nama']) ?>" class="w-full h-full object-cover hover:scale-110 transition-transform duration-500" onerror="this.src='https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image'">
                                </div>
                            </a>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2 mb-1">
                                    <div>
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md"><?= clean($item['kategori']) ?></span>
                                        <h3 class="text-sm sm:text-base font-bold text-gray-900 mt-1.5 line-clamp-2"><?= clean($item['nama']) ?></h3>
                                    </div>
                                    <button type="button" onclick="hapusItem(<?= $item['cart_id'] ?>)" class="delete-btn w-8 h-8 rounded-lg flex items-center justify-center text-gray-400 shrink-0" title="Hapus item">
                                        <i class="fa-solid fa-trash-can text-sm"></i>
                                    </button>
                                </div>

                                <p class="text-lg font-bold text-emerald-600 font-mono mb-3"><?= formatRupiah($item['harga']) ?></p>

                                <div class="flex items-center justify-between">
                                    <div class="flex items-center">
                                        <button type="button" class="qty-btn-sm" onclick="updateQty(<?= $item['cart_id'] ?>, -1)" data-action="minus"><i class="fa-solid fa-minus text-xs"></i></button>
                                        <input type="number" class="qty-input-sm" value="<?= $item['jumlah'] ?>" min="1" max="<?= $item['stok'] ?>" id="qty-<?= $item['cart_id'] ?>" readonly>
                                        <button type="button" class="qty-btn-sm" onclick="updateQty(<?= $item['cart_id'] ?>, 1)" data-action="plus"><i class="fa-solid fa-plus text-xs"></i></button>
                                    </div>
                                    <div class="text-right">
                                        <p class="text-xs text-gray-400">Subtotal</p>
                                        <p class="text-sm font-bold text-gray-900 font-mono" id="subtotal-<?= $item['cart_id'] ?>"><?= formatRupiah($subtotal) ?></p>
                                    </div>
                                </div>

                                <?php if ($item['jumlah'] >= $item['stok']): ?>
                                <p class="text-[10px] text-amber-600 mt-1.5 flex items-center gap-1"><i class="fa-solid fa-triangle-exclamation"></i> Stok tersisa <?= $item['stok'] ?> unit</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>

                </div>

                <!-- RIGHT: SUMMARY -->
                <div class="lg:col-span-1">
                    <div class="summary-card bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                        <h2 class="text-lg font-bold text-gray-900 mb-5 flex items-center gap-2">
                            <i class="fa-solid fa-receipt text-emerald-500"></i> Ringkasan Belanja
                        </h2>

                        <!-- Select All Desktop -->
                        <div class="hidden lg:flex items-center justify-between mb-4 pb-4 border-b border-gray-100">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" class="checkbox-custom" id="selectAllDesktop" onchange="toggleSelectAll()">
                                <span class="text-sm font-semibold text-gray-700">Pilih Semua</span>
                            </label>
                            <span class="text-xs text-gray-400" id="selectedCountDesktop">0 dipilih</span>
                        </div>

                        <div class="space-y-3 mb-6">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Total Item</span>
                                <span class="font-semibold text-gray-900" id="summaryItem">0</span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500">Total Kuantitas</span>
                                <span class="font-semibold text-gray-900" id="summaryQty">0</span>
                            </div>
                            <div class="h-px bg-gray-100"></div>
                            <div class="flex justify-between">
                                <span class="text-gray-900 font-bold">Total Harga</span>
                                <span class="text-xl font-bold text-emerald-600 font-mono" id="summaryTotal">Rp 0</span>
                            </div>
                        </div>

                        <button type="submit" id="btnCheckout" class="w-full btn-primary text-white font-bold py-4 rounded-2xl shadow-lg flex items-center justify-center gap-2 disabled:opacity-50 disabled:cursor-not-allowed disabled:transform-none" disabled>
                            <i class="fa-solid fa-credit-card"></i>
                            <span>Checkout Sekarang</span>
                        </button>

                        <a href="produk.php" class="w-full mt-3 py-3 text-emerald-600 border-2 border-emerald-100 hover:border-emerald-300 rounded-2xl font-semibold text-sm text-center block transition-all hover:bg-emerald-50">
                            Lanjut Belanja
                        </a>

                        <div class="mt-6 space-y-2">
                            <div class="flex items-center gap-2 text-xs text-gray-500">
                                <i class="fa-solid fa-shield-halved text-emerald-500"></i>
                                <span>Pembayaran aman & terjamin</span>
                            </div>
                            <div class="flex items-center gap-2 text-xs text-gray-500">
                                <i class="fa-solid fa-truck-fast text-emerald-500"></i>
                                <span>Pengiriman cepat ke seluruh Indonesia</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <?php endif; ?>
    </div>
</main>

<!-- ==================== FOOTER ==================== -->
<footer class="bg-gray-950 text-gray-400 pt-20 pb-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-12 pb-12 border-b border-gray-900">
            <div class="space-y-4">
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
            <div><h4 class="text-white font-semibold mb-4">Menu Cepat</h4><ul class="space-y-3 text-sm"><li><a href="index.php#beranda" class="hover:text-emerald-500 transition-colors">Beranda</a></li><li><a href="scan.php" class="hover:text-emerald-500 transition-colors">Scan AI</a></li><li><a href="produk.php" class="hover:text-emerald-500 transition-colors">Katalog Produk</a></li><li><a href="index.php#tentang" class="hover:text-emerald-500 transition-colors">Tentang Kami</a></li></ul></div>
            <div><h4 class="text-white font-semibold mb-4">Layanan</h4><ul class="space-y-3 text-sm"><li><a href="#" class="hover:text-emerald-500 transition-colors">Deteksi Penyakit</a></li><li><a href="#" class="hover:text-emerald-500 transition-colors">Rekomendasi Obat</a></li><li><a href="#" class="hover:text-emerald-500 transition-colors">Konsultasi Ahli</a></li><li><a href="#" class="hover:text-emerald-500 transition-colors">Panduan Pertanian</a></li></ul></div>
            <div><h4 class="text-white font-semibold mb-4">Hubungi Kami</h4><ul class="space-y-3 text-sm"><li class="flex items-center gap-3"><i class="fa-solid fa-phone text-emerald-500 w-4"></i><span>+62 812-3456-7890</span></li><li class="flex items-center gap-3"><i class="fa-solid fa-envelope text-emerald-500 w-4"></i><span>support@etanimart.com</span></li><li class="flex items-center gap-3"><i class="fa-solid fa-location-dot text-emerald-500 w-4"></i><span>Jakarta, Indonesia</span></li></ul></div>
        </div>
        <div class="flex flex-col md:flex-row justify-between items-center gap-4 pt-8">
            <p class="text-xs text-gray-600">&copy; 2026 Etanimart Project. All Rights Reserved.</p>
            <div class="flex gap-6 text-xs text-gray-600"><a href="#" class="hover:text-emerald-500 transition-colors">Kebijakan Privasi</a><a href="#" class="hover:text-emerald-500 transition-colors">Syarat & Ketentuan</a></div>
        </div>
    </div>
</footer>

<!-- Toast -->
<div id="toast" class="toast"><i class="fa-solid fa-check-circle"></i><span id="toastText">Berhasil!</span></div>

<!-- ==================== JAVASCRIPT ==================== -->
<script>
// ===== FORMAT RUPIAH =====
function formatRupiah(angka) {
    return 'Rp ' + angka.toLocaleString('id-ID');
}

// ===== UPDATE QTY =====
async function updateQty(cartId, delta) {
    const qtyInput = document.getElementById('qty-' + cartId);
    const itemCard = document.querySelector('[data-cart-id="' + cartId + '"]');
    const stok = parseInt(itemCard.dataset.stok);
    const harga = parseInt(itemCard.dataset.harga);

    let newQty = parseInt(qtyInput.value) + delta;
    if (newQty < 1) newQty = 1;
    if (newQty > stok) {
        showToast('Stok tidak mencukupi. Tersisa ' + stok + ' unit', true);
        return;
    }

    // Update UI sementara
    qtyInput.value = newQty;
    document.getElementById('subtotal-' + cartId).textContent = formatRupiah(harga * newQty);

    // Update summary
    updateSummary();

    // Kirim ke server
    try {
        const formData = new FormData();
        formData.append('action', 'update_qty');
        formData.append('cart_id', cartId);
        formData.append('jumlah', newQty);

        const response = await fetch('keranjang.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        if (!data.success) {
            showToast(data.message, true);
            // Revert
            qtyInput.value = newQty - delta;
            document.getElementById('subtotal-' + cartId).textContent = formatRupiah(harga * (newQty - delta));
            updateSummary();
        }
    } catch (err) {
        showToast('Gagal memperbarui jumlah', true);
    }
}

// ===== HAPUS ITEM =====
async function hapusItem(cartId) {
    if (!confirm('Yakin ingin menghapus item ini dari keranjang?')) return;

    const itemCard = document.querySelector('[data-cart-id="' + cartId + '"]');

    try {
        const formData = new FormData();
        formData.append('action', 'hapus_item');
        formData.append('cart_id', cartId);

        const response = await fetch('keranjang.php', {
            method: 'POST',
            body: formData
        });

        const data = await response.json();
        if (data.success) {
            // Animasi hapus
            itemCard.style.transform = 'translateX(100px)';
            itemCard.style.opacity = '0';
            setTimeout(() => {
                itemCard.remove();
                updateSummary();

                // Update badge
                const badges = document.querySelectorAll('#cartBadge, .cart-badge-mobile');
                badges.forEach(badge => {
                    if (data.total_keranjang > 0) {
                        badge.textContent = data.total_keranjang;
                    } else {
                        badge.classList.add('hidden');
                    }
                });

                // Reload kalau kosong
                if (data.total_keranjang === 0) {
                    location.reload();
                }
            }, 300);

            showToast('Item dihapus dari keranjang');
        } else {
            showToast(data.message, true);
        }
    } catch (err) {
        showToast('Gagal menghapus item', true);
    }
}

// ===== SELECT ALL =====
function toggleSelectAll() {
    const selectAllMobile = document.getElementById('selectAllMobile');
    const selectAllDesktop = document.getElementById('selectAllDesktop');
    const checkboxes = document.querySelectorAll('.cart-checkbox');

    const isChecked = selectAllMobile?.checked || selectAllDesktop?.checked;

    checkboxes.forEach(cb => {
        cb.checked = isChecked;
    });

    // Sinkronkan kedua checkbox "Pilih Semua"
    if (selectAllMobile) selectAllMobile.checked = isChecked;
    if (selectAllDesktop) selectAllDesktop.checked = isChecked;

    updateSummary();
}

// ===== UPDATE SUMMARY =====
function updateSummary() {
    const checkboxes = document.querySelectorAll('.cart-checkbox:checked');
    let totalItem = 0;
    let totalQty = 0;
    let totalHarga = 0;

    checkboxes.forEach(cb => {
        const itemCard = cb.closest('.cart-item');
        const cartId = itemCard.dataset.cartId;
        const harga = parseInt(itemCard.dataset.harga);
        const qty = parseInt(document.getElementById('qty-' + cartId).value);

        totalItem++;
        totalQty += qty;
        totalHarga += harga * qty;
    });

    // Update UI
    document.getElementById('summaryItem').textContent = totalItem;
    document.getElementById('summaryQty').textContent = totalQty;
    document.getElementById('summaryTotal').textContent = formatRupiah(totalHarga);

    const countText = totalItem + ' dipilih';
    const elMobile = document.getElementById('selectedCountMobile');
    const elDesktop = document.getElementById('selectedCountDesktop');
    if (elMobile) elMobile.textContent = countText;
    if (elDesktop) elDesktop.textContent = countText;

    // Enable/disable checkout button
    const btnCheckout = document.getElementById('btnCheckout');
    if (btnCheckout) {
        btnCheckout.disabled = totalItem === 0;
    }

    // Update select all checkbox state
    const allCheckboxes = document.querySelectorAll('.cart-checkbox');
    const allChecked = allCheckboxes.length > 0 && allCheckboxes.length === checkboxes.length;
    const selectAllMobile = document.getElementById('selectAllMobile');
    const selectAllDesktop = document.getElementById('selectAllDesktop');
    if (selectAllMobile) selectAllMobile.checked = allChecked;
    if (selectAllDesktop) selectAllDesktop.checked = allChecked;
}

// ===== TOAST =====
function showToast(message, isError = false) {
    const toast = document.getElementById('toast');
    const toastText = document.getElementById('toastText');
    toastText.textContent = message;
    toast.className = 'toast' + (isError ? ' error' : '') + ' show';
    setTimeout(() => toast.classList.remove('show'), 3000);
}

// ===== USER DROPDOWN =====
function toggleUserDropdown(event) {
    event.stopPropagation();
    const menu = document.getElementById('userDropdownMenu');
    const icon = document.getElementById('userDropdownIcon');
    if (menu) {
        menu.classList.toggle('active');
        if (icon) icon.style.transform = menu.classList.contains('active') ? 'rotate(180deg)' : 'rotate(0deg)';
    }
}
document.addEventListener('click', function(event) {
    const dropdown = document.querySelector('.user-dropdown');
    const menu = document.getElementById('userDropdownMenu');
    const icon = document.getElementById('userDropdownIcon');
    if (dropdown && !dropdown.contains(event.target)) {
        if (menu) menu.classList.remove('active');
        if (icon) icon.style.transform = 'rotate(0deg)';
    }
});

// ===== MOBILE MENU =====
(function() {
    const btnMenu = document.getElementById('btn-menu');
    const mobileMenu = document.getElementById('mobile-menu');
    const menuIcon = document.getElementById('menuIcon');
    if (btnMenu && mobileMenu) {
        btnMenu.addEventListener('click', function() {
            mobileMenu.classList.toggle('hidden');
            if (menuIcon) {
                if (mobileMenu.classList.contains('hidden')) {
                    menuIcon.classList.remove('fa-xmark');
                    menuIcon.classList.add('fa-bars');
                } else {
                    menuIcon.classList.remove('fa-bars');
                    menuIcon.classList.add('fa-xmark');
                }
            }
        });
    }
})();

// ===== INIT =====
document.addEventListener('DOMContentLoaded', function() {
    updateSummary();
});
</script>
</body>
</html>