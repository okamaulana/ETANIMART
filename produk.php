<?php
// =============================================================================
// produk.php - Etanimart Product Catalog (Modern)
// =============================================================================
session_start();

// Detect current page for active menu
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
require_once 'koneksi.php';

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

// ==========================================
// KONFIGURASI
// ==========================================
define('ITEMS_PER_PAGE', 12);
define('UPLOAD_URL', 'uploads/');

// ==========================================
// HELPER FUNCTIONS
// ==========================================
function clean($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
function formatRupiah($angka) {
    return 'Rp ' . number_format((int)($angka ?? 0), 0, ',', '.');
}
function getThumb($gambarJson) {
    if (empty($gambarJson)) return 'https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image';
    $decoded = json_decode($gambarJson, true);
    if (is_array($decoded) && !empty($decoded[0])) return UPLOAD_URL . $decoded[0];
    return 'https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image';
}

// ==========================================
// GET PARAMETERS
// ==========================================
$search     = isset($_GET['search']) ? trim($_GET['search']) : '';
$kategori   = isset($_GET['kategori']) ? $_GET['kategori'] : '';
$sort       = isset($_GET['sort']) ? $_GET['sort'] : 'terbaru';
$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$view       = isset($_GET['view']) ? $_GET['view'] : 'grid';
$minHarga   = isset($_GET['min_harga']) ? (int)$_GET['min_harga'] : 0;
$maxHarga   = isset($_GET['max_harga']) ? (int)$_GET['max_harga'] : 0;

$allowedSort = ['terbaru', 'termurah', 'termahal', 'nama', 'stok'];
if (!in_array($sort, $allowedSort)) $sort = 'terbaru';
if (!in_array($view, ['grid', 'list'])) $view = 'grid';

// ==========================================
// BUILD QUERY
// ==========================================
$where = ["1=1"];
$params = [];

if (!empty($search)) {
    // Search di nama produk (prioritas utama)
    // Kalau ada spasi, cari setiap kata di nama
    $searchTerms = array_filter(explode(' ', trim($search)));

    if (count($searchTerms) > 1) {
        // Multi-word search: semua kata harus ada di nama produk
        $searchConditions = [];
        foreach ($searchTerms as $idx => $term) {
            $key = 'search' . $idx;
            $searchConditions[] = "p.nama LIKE :" . $key;
            $params[$key] = '%' . $term . '%';
        }
        $where[] = "(" . implode(' AND ', $searchConditions) . ")";
    } else {
        // Single word search: cari di nama produk (prioritas), fallback ke deskripsi/kategori
        $where[] = "(p.nama LIKE :search OR p.deskripsi LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }
}
if (!empty($kategori)) {
    $where[] = "p.kategori = :kategori";
    $params['kategori'] = $kategori;
}
if ($minHarga > 0) {
    $where[] = "p.harga >= :minHarga";
    $params['minHarga'] = $minHarga;
}
if ($maxHarga > 0) {
    $where[] = "p.harga <= :maxHarga";
    $params['maxHarga'] = $maxHarga;
}

$whereClause = implode(' AND ', $where);

$orderBy = match($sort) {
    'termurah'  => 'p.harga ASC',
    'termahal'  => 'p.harga DESC',
    'nama'      => 'p.nama ASC',
    'stok'      => 'p.stok DESC',
    default     => 'p.id DESC'
};

try {
    // Kategori untuk filter
    $stmtKat = $pdo->query("SELECT DISTINCT kategori FROM produk WHERE kategori != '' ORDER BY kategori ASC");
    $kategoriList = $stmtKat->fetchAll(PDO::FETCH_COLUMN);

    // Count total
    $countSql = "SELECT COUNT(*) FROM produk p WHERE " . $whereClause;
    $stmtCount = $pdo->prepare($countSql);
    $stmtCount->execute($params);
    $totalItems = $stmtCount->fetchColumn();
    $totalPages = ceil($totalItems / ITEMS_PER_PAGE);
    $page = min($page, max(1, $totalPages));
    $offset = ($page - 1) * ITEMS_PER_PAGE;

    // Ambil produk
    $sql = "SELECT p.* FROM produk p WHERE {$whereClause} ORDER BY {$orderBy} LIMIT :limit OFFSET :offset";
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $val) {
        $stmt->bindValue(':' . $key, $val);
    }
    $stmt->bindValue(':limit', ITEMS_PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $products = $stmt->fetchAll();

    // Stats
    $stmtTotal = $pdo->query("SELECT COUNT(*) FROM produk");
    $totalAll = $stmtTotal->fetchColumn();

    // Price range
    $stmtPrice = $pdo->query("SELECT MIN(harga) as min, MAX(harga) as max FROM produk");
    $priceRange = $stmtPrice->fetch();

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

function buildUrl($changes = []) {
    $current = $_GET;
    foreach ($changes as $k => $v) {
        if ($v === null || $v === '' || $v === 0) unset($current[$k]);
        else $current[$k] = $v;
    }
    return 'produk.php?' . http_build_query($current);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Katalog Produk - Etanimart</title>
    <meta name="description" content="Katalog produk pertanian Etanimart - Temukan kebutuhan pertanian terbaik.">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        html { scroll-behavior: smooth; }
        
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
        
        /* ===== PRODUCT CARD ===== */
        .product-card {
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
        }
        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
        }
        .product-card:hover .card-img {
            transform: scale(1.1);
        }
        .card-img {
            transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .card-overlay {
            opacity: 0;
            transition: all 0.3s ease;
        }
        .product-card:hover .card-overlay {
            opacity: 1;
        }
        
        /* ===== LIST VIEW ===== */
        .list-view .product-card {
            display: flex;
            flex-direction: row;
        }
        .list-view .card-image-wrapper {
            width: 200px;
            min-width: 200px;
        }
        
        /* ===== FILTER CHIP ===== */
        .filter-chip {
            transition: all 0.2s;
        }
        .filter-chip.active {
            background: #10b981;
            color: white;
            border-color: #10b981;
        }
        .filter-chip:not(.active):hover {
            background: #ecfdf5;
            border-color: #10b981;
            color: #059669;
        }
        
        /* ===== ANIMATIONS ===== */
        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-slide-up {
            animation: slideUp 0.6s ease-out forwards;
        }
        
        .reveal {
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.6s ease-out;
        }
        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }
        
        /* ===== MOBILE FILTER ===== */
        .mobile-filter-overlay {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 80;
            background: rgba(0, 0, 0, 0.5);
        }
        .mobile-filter-overlay.active {
            display: block;
        }
        .mobile-filter-panel {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: 85%;
            max-width: 360px;
            background: white;
            transform: translateX(-100%);
            transition: transform 0.3s ease;
            z-index: 90;
            overflow-y: auto;
        }
        .mobile-filter-panel.active {
            transform: translateX(0);
        }
        
        /* ===== QUICK VIEW MODAL ===== */
        .quick-view-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 100;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(8px);
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .quick-view-modal.active {
            display: flex;
            opacity: 1;
        }
        .quick-view-content {
            background: white;
            border-radius: 1.5rem;
            max-width: 900px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            transform: scale(0.95);
            transition: transform 0.3s ease;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        .quick-view-modal.active .quick-view-content {
            transform: scale(1);
        }
        
        /* ===== SIDEBAR STICKY ===== */
        .sidebar-sticky {
            position: sticky;
            top: 6rem;
        }

                /* ===== USER DROPDOWN ===== */
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
            z-index: 100;
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

        /* ===== FLOATING SCAN AI BUTTON ===== */
.floating-scan-btn {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 60;
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, #10b981, #059669);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 22px;
    box-shadow: 0 8px 24px -4px rgba(16, 185, 129, 0.4), 
                0 4px 12px -2px rgba(0, 0, 0, 0.1);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
    border: none;
    outline: none;
    text-decoration: none;
}

.floating-scan-btn:hover {
    transform: translateY(-4px) scale(1.1);
    box-shadow: 0 12px 32px -4px rgba(16, 185, 129, 0.5), 
                0 6px 16px -2px rgba(0, 0, 0, 0.15);
}

.floating-scan-btn:active {
    transform: translateY(-2px) scale(1.05);
}

/* Pulse animation ring */
.floating-scan-btn::before {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    border: 2px solid rgba(16, 185, 129, 0.3);
    animation: pulseRing 2s ease-out infinite;
}

@keyframes pulseRing {
    0% {
        transform: scale(1);
        opacity: 0.6;
    }
    100% {
        transform: scale(1.6);
        opacity: 0;
    }
}

/* ===== FLOATING SCAN AI BUTTON ===== */
.floating-scan-btn {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 60;
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, #10b981, #059669);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 22px;
    box-shadow: 0 8px 24px -4px rgba(16, 185, 129, 0.4), 
                0 4px 12px -2px rgba(0, 0, 0, 0.1);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    cursor: pointer;
    border: none;
    outline: none;
    text-decoration: none;
}

.floating-scan-btn:hover {
    transform: translateY(-4px) scale(1.1);
    box-shadow: 0 12px 32px -4px rgba(16, 185, 129, 0.5), 
                0 6px 16px -2px rgba(0, 0, 0, 0.15);
}

.floating-scan-btn:active {
    transform: translateY(-2px) scale(1.05);
}

/* Pulse animation ring */
.floating-scan-btn::before {
    content: '';
    position: absolute;
    inset: -4px;
    border-radius: 50%;
    border: 2px solid rgba(16, 185, 129, 0.3);
    animation: pulseRing 2s ease-out infinite;
}

@keyframes pulseRing {
    0% { transform: scale(1); opacity: 0.6; }
    100% { transform: scale(1.6); opacity: 0; }
}

/* Tooltip on hover */
.floating-scan-btn::after {
    content: 'Scan AI';
    position: absolute;
    right: calc(100% + 12px);
    top: 50%;
    transform: translateY(-50%) translateX(8px);
    background: #1f2937;
    color: white;
    padding: 6px 14px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 600;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: all 0.25s ease;
}

.floating-scan-btn:hover::after {
    opacity: 1;
    transform: translateY(-50%) translateX(0);
}

/* Mobile adjustment */
@media (max-width: 640px) {
    .floating-scan-btn {
        width: 52px;
        height: 52px;
        bottom: 20px;
        right: 20px;
        font-size: 20px;
    }
    .floating-scan-btn::after {
        display: none;
    }
}

/* ===== CAMERA MODAL (shared) ===== */
.camera-modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 100;
    background: rgba(0, 0, 0, 0.9);
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.camera-modal.active {
    display: flex;
}
.camera-content {
    background: #1a1a2e;
    border-radius: 1.5rem;
    max-width: 640px;
    width: 100%;
    overflow: hidden;
    position: relative;
}

@keyframes scanLine {
    0% { top: 0%; }
    50% { top: 100%; }
    100% { top: 0%; }
}
.scan-line {
    position: absolute;
    left: 10%;
    right: 10%;
    height: 2px;
    background: linear-gradient(90deg, transparent, #10b981, transparent);
    animation: scanLine 2s ease-in-out infinite;
    z-index: 10;
}

/* ===== BUTTON PRIMARY ===== */
.btn-primary {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    transition: all 0.3s ease;
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.5);
}
        /* ===== TOOLBAR STICKY ===== */
        /* Pakai Tailwind class: sticky top-[5.5rem] z-30 self-start */
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased">

   <!-- ==================== NAVBAR ==================== -->
<nav class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" id="navbar">
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
                    <div class="user-dropdown relative hidden lg:block">
                        <button class="flex items-center gap-2 sm:gap-3 pl-2 pr-1 sm:pr-2 py-1.5 rounded-full hover:bg-gray-100 transition-colors" onclick="toggleUserDropdown(event)">
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

    <!-- ==================== HERO ==================== -->
    <div class="bg-gradient-to-br from-emerald-90 to-teal-100 text-black pt-32 pb-12 md:pb-16 relative overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-96 h-96 bg-white rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-64 h-64 bg-white rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <h1 class="text-3xl md:text-4xl font-bold mb-3">Katalog Produk</h1>
            <p class="text-black-100 text-sm md:text-base max-w-xl">
                Temukan kebutuhan pertanian terbaik dari benih unggul hingga pestisida berkualitas.
            </p>
        </div>
    </div>

    <!-- ==================== MOBILE FILTER OVERLAY ==================== -->
    <div id="mobileFilterOverlay" class="mobile-filter-overlay" onclick="toggleMobileFilter()"></div>
    <div id="mobileFilterPanel" class="mobile-filter-panel">
        <div class="p-5 border-b border-gray-100 flex items-center justify-between bg-white sticky top-0 z-10">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center">
                    <i class="fa-solid fa-sliders text-emerald-600"></i>
                </div>
                <div>
                    <h3 class="font-bold text-gray-900">Filter</h3>
                    <p class="text-xs text-gray-400">Atur tampilan produk</p>
                </div>
            </div>
            <button onclick="toggleMobileFilter()" class="w-10 h-10 hover:bg-gray-100 rounded-xl transition-colors flex items-center justify-center">
                <i class="fa-solid fa-xmark text-gray-500 text-lg"></i>
            </button>
        </div>

        <div class="p-5 space-y-8">


            <div class="h-px bg-gray-100"></div>

            <!-- Filter Section -->
            <?php renderFilterContent(); ?>
        </div>

        <!-- Bottom Sticky Apply Button -->
        <div class="sticky bottom-0 bg-white border-t border-gray-100 p-4">
            <button onclick="toggleMobileFilter()" class="w-full py-3.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl font-semibold transition-colors shadow-lg shadow-emerald-200">
                <i class="fa-solid fa-check mr-2"></i> Terapkan Filter
            </button>
        </div>
    </div>

    <?php
    function renderFilterContent() {
        global $kategoriList, $kategori, $search, $minHarga, $maxHarga, $priceRange, $pdo;
    ?>
        <!-- Search -->
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-2">Cari Produk</label>
            <form method="GET" action="produk.php" class="relative">
                <!-- Cuma bawa sort, reset filter lain pas search -->
                <?php if (!empty($sort) && $sort !== 'terbaru'): ?>
                    <input type="hidden" name="sort" value="<?= clean($sort) ?>">
                <?php endif; ?>
                <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none"></i>
                <input type="text" name="search" value="<?= clean($search) ?>" 
                    placeholder="Ketik nama produk..."
                    class="w-full pl-10 pr-10 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all">
                <?php if (!empty($search)): ?>
                <a href="produk.php<?= !empty($sort) && $sort !== 'terbaru' ? '?sort=' . urlencode($sort) : '' ?>" 
                   class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-red-500 transition-colors"
                   onclick="event.preventDefault(); window.location.href='produk.php<?= !empty($sort) && $sort !== 'terbaru' ? '?sort=' . urlencode($sort) : '' ?>';">
                    <i class="fa-solid fa-times-circle"></i>
                </a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Kategori -->
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-2">Kategori</label>
            <div class="space-y-1.5">
                <a href="<?= buildUrl(['kategori' => null, 'page' => 1]) ?>" 
                    class="filter-chip block px-3 py-2 rounded-lg text-sm font-medium border border-gray-200 text-gray-600 <?= empty($kategori) ? 'active' : '' ?>">
                    Semua Kategori
                </a>
                <?php foreach ($kategoriList as $kat): 
                    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM produk WHERE kategori = ?");
                    $stmtCount->execute([$kat]);
                    $count = $stmtCount->fetchColumn();
                ?>
                <a href="<?= buildUrl(['kategori' => $kat, 'page' => 1]) ?>" 
                    class="filter-chip block px-3 py-2 rounded-lg text-sm font-medium border border-gray-200 text-gray-600 <?= $kategori === $kat ? 'active' : '' ?> flex justify-between items-center">
                    <span><?= clean($kat) ?></span>
                    <span class="text-xs opacity-70"><?= $count ?></span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Harga -->
        <div>
            <label class="block text-xs font-bold uppercase tracking-wider text-gray-400 mb-2">Rentang Harga</label>
            <form method="GET" action="produk.php" class="space-y-3" onsubmit="return validateHarga(this)">
                <!-- Cuma bawa search dan sort -->
                <?php if (!empty($search)): ?>
                    <input type="hidden" name="search" value="<?= clean($search) ?>">
                <?php endif; ?>
                <?php if (!empty($sort) && $sort !== 'terbaru'): ?>
                    <input type="hidden" name="sort" value="<?= clean($sort) ?>">
                <?php endif; ?>
                <div class="grid grid-cols-2 gap-2">
                    <input type="number" name="min_harga" value="<?= $minHarga > 0 ? $minHarga : '' ?>" 
                        placeholder="Min" min="0"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-emerald-500 font-mono">
                    <input type="number" name="max_harga" value="<?= $maxHarga > 0 ? $maxHarga : '' ?>" 
                        placeholder="Max" min="0"
                        class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:border-emerald-500 font-mono">
                </div>
                <button type="submit" class="w-full py-2 bg-emerald-600 text-white rounded-lg text-sm font-semibold hover:bg-emerald-700 transition-colors">
                    Terapkan
                </button>
            </form>
        </div>

        <!-- Reset -->
        <a href="produk.php" class="block w-full py-2.5 text-center text-sm font-semibold text-gray-600 hover:text-emerald-600 border border-gray-200 hover:border-emerald-200 rounded-xl transition-all">
            <i class="fa-solid fa-rotate-left mr-1"></i> Reset Filter
        </a>
    <?php } ?>

    <!-- ==================== MAIN CONTENT ==================== -->
    <main class="py-8 md:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex gap-8">
                
                <!-- SIDEBAR (Desktop) -->
                <aside class="hidden lg:block w-72 shrink-0">
                    <div class="sidebar-sticky bg-white rounded-2xl border border-gray-100 p-5 shadow-sm space-y-6">
                        <div class="flex items-center justify-between">
                            <h3 class="font-bold text-gray-900">Filter</h3>
                            <?php if (!empty($kategori) || !empty($search) || $minHarga > 0 || $maxHarga > 0): ?>
                                <a href="produk.php" class="text-xs text-emerald-600 hover:text-emerald-700 font-semibold">
                                    Reset
                                </a>
                            <?php endif; ?>
                        </div>
                        
                        <?php renderFilterContent(); ?>
                    </div>
                </aside>

                <!-- CONTENT -->
                <div class="flex-1 min-w-0">
                    
                    <!-- Toolbar -->
                    <div class="sticky top-[5.5rem] z-30 self-start mb-6">
                        <div class="bg-white rounded-2xl border border-gray-100 p-4 md:p-5 shadow-sm flex flex-col sm:flex-row gap-4 items-start sm:items-center justify-between">
                        <div class="flex items-center gap-3 flex-wrap">
                            <span class="text-sm text-gray-500 font-medium">
                                <?php if (!empty($search)): ?>
                                    <?= $totalItems ?> hasil untuk "<?= clean($search) ?>"
                                <?php else: ?>
                                    <?= $totalItems ?> produk
                                <?php endif; ?>
                            </span>
                            
                            <?php if (!empty($search)): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-blue-50 text-blue-700 rounded-lg text-xs font-semibold border border-blue-200">
                                    "<?= clean($search) ?>"
                                    <a href="<?= buildUrl(['search' => null, 'page' => 1]) ?>" class="hover:text-blue-900"><i class="fa-solid fa-times"></i></a>
                                </span>
                            <?php endif; ?>
                            <?php if (!empty($kategori)): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-50 text-emerald-700 rounded-lg text-xs font-semibold border border-emerald-200">
                                    <?= clean($kategori) ?>
                                    <a href="<?= buildUrl(['kategori' => null, 'page' => 1]) ?>" class="hover:text-emerald-900"><i class="fa-solid fa-times"></i></a>
                                </span>
                            <?php endif; ?>
                            <?php if ($minHarga > 0 || $maxHarga > 0): ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-purple-50 text-purple-700 rounded-lg text-xs font-semibold border border-purple-200">
                                    <?= $minHarga > 0 ? formatRupiah($minHarga) : 'Rp 0' ?> - <?= $maxHarga > 0 ? formatRupiah($maxHarga) : '∞' ?>
                                    <a href="<?= buildUrl(['min_harga' => null, 'max_harga' => null, 'page' => 1]) ?>" class="hover:text-purple-900"><i class="fa-solid fa-times"></i></a>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="flex items-center gap-3">
                            <!-- Mobile Filter Button -->
                            <button onclick="toggleMobileFilter()" class="lg:hidden flex items-center gap-2 px-4 py-2.5 bg-emerald-50 text-emerald-700 rounded-xl text-sm font-semibold hover:bg-emerald-100 transition-colors border border-emerald-200">
                                <i class="fa-solid fa-sliders"></i> Filter
                                <?php if (!empty($kategori) || $minHarga > 0 || $maxHarga > 0): ?>
                                    <span class="w-2 h-2 bg-emerald-500 rounded-full"></span>
                                <?php endif; ?>
                            </button>
                            
                            <!-- Sort Dropdown -->
                            <div class="relative group" id="sortDropdownContainer">
                                <button type="button" onclick="toggleSortDropdown()" 
                                    class="flex items-center gap-2 bg-gray-50 border border-gray-200 rounded-xl pl-4 pr-3 py-2.5 text-sm font-medium hover:bg-gray-100 hover:border-emerald-300 transition-all min-w-[140px] justify-between">
                                    <span class="flex items-center gap-2">
                                        <i class="fa-solid fa-arrow-down-wide-short text-emerald-500 text-xs"></i>
                                        <span id="sortLabel"><?= match($sort) { 'termurah' => 'Termurah', 'termahal' => 'Termahal', 'nama' => 'Nama A-Z', 'stok' => 'Stok', default => 'Terbaru' } ?></span>
                                    </span>
                                    <i class="fa-solid fa-chevron-down text-gray-400 text-xs transition-transform duration-200" id="sortChevron"></i>
                                </button>

                                <!-- Custom Dropdown Menu -->
                                <div id="sortDropdownMenu" class="hidden absolute top-full left-0 right-0 mt-2 bg-white border border-gray-200 rounded-xl shadow-xl shadow-black/10 overflow-hidden z-50 min-w-[180px]">
                                    <div class="p-1.5 space-y-0.5">
                                        <a href="<?= buildUrl(['sort' => 'terbaru', 'page' => 1]) ?>" 
                                            class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm <?= $sort === 'terbaru' ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                                            <i class="fa-solid fa-clock text-xs w-4 <?= $sort === 'terbaru' ? 'text-emerald-500' : 'text-gray-400' ?>"></i>
                                            Terbaru
                                            <?php if ($sort === 'terbaru'): ?><i class="fa-solid fa-check text-emerald-500 text-xs ml-auto"></i><?php endif; ?>
                                        </a>
                                        <a href="<?= buildUrl(['sort' => 'termurah', 'page' => 1]) ?>" 
                                            class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm <?= $sort === 'termurah' ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                                            <i class="fa-solid fa-arrow-down-1-9 text-xs w-4 <?= $sort === 'termurah' ? 'text-emerald-500' : 'text-gray-400' ?>"></i>
                                            Termurah
                                            <?php if ($sort === 'termurah'): ?><i class="fa-solid fa-check text-emerald-500 text-xs ml-auto"></i><?php endif; ?>
                                        </a>
                                        <a href="<?= buildUrl(['sort' => 'termahal', 'page' => 1]) ?>" 
                                            class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm <?= $sort === 'termahal' ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                                            <i class="fa-solid fa-arrow-up-9-1 text-xs w-4 <?= $sort === 'termahal' ? 'text-emerald-500' : 'text-gray-400' ?>"></i>
                                            Termahal
                                            <?php if ($sort === 'termahal'): ?><i class="fa-solid fa-check text-emerald-500 text-xs ml-auto"></i><?php endif; ?>
                                        </a>
                                        <a href="<?= buildUrl(['sort' => 'nama', 'page' => 1]) ?>" 
                                            class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm <?= $sort === 'nama' ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                                            <i class="fa-solid fa-font text-xs w-4 <?= $sort === 'nama' ? 'text-emerald-500' : 'text-gray-400' ?>"></i>
                                            Nama A-Z
                                            <?php if ($sort === 'nama'): ?><i class="fa-solid fa-check text-emerald-500 text-xs ml-auto"></i><?php endif; ?>
                                        </a>
                                        <a href="<?= buildUrl(['sort' => 'stok', 'page' => 1]) ?>" 
                                            class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm <?= $sort === 'stok' ? 'bg-emerald-50 text-emerald-700 font-semibold' : 'text-gray-600 hover:bg-gray-50' ?> transition-colors">
                                            <i class="fa-solid fa-boxes-stacked text-xs w-4 <?= $sort === 'stok' ? 'text-emerald-500' : 'text-gray-400' ?>"></i>
                                            Stok Terbanyak
                                            <?php if ($sort === 'stok'): ?><i class="fa-solid fa-check text-emerald-500 text-xs ml-auto"></i><?php endif; ?>
                                        </a>
                                    </div>
                                </div>
                            </div>

                            <!-- View Toggle -->
                            <div class="flex bg-gray-100 rounded-xl p-1">
                                <a href="<?= buildUrl(['view' => 'grid']) ?>" 
                                    class="p-2 rounded-lg transition-all <?= $view === 'grid' ? 'bg-white text-emerald-600 shadow-sm' : 'text-gray-400 hover:text-gray-600' ?>">
                                    <i class="fa-solid fa-grid-2"></i>
                                </a>
                                <a href="<?= buildUrl(['view' => 'list']) ?>" 
                                    class="p-2 rounded-lg transition-all <?= $view === 'list' ? 'bg-white text-emerald-600 shadow-sm' : 'text-gray-400 hover:text-gray-600' ?>">
                                    <i class="fa-solid fa-list"></i>
                                </a>
                            </div>
                        </div>
                    </div>

                    <?php if (empty($products)): ?>
                        <!-- Empty State -->
                        <div class="bg-white rounded-2xl border border-gray-100 p-16 text-center shadow-sm">
                            <div class="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fa-solid fa-search text-3xl text-gray-300"></i>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 mb-2">Produk Tidak Ditemukan</h3>
                            <p class="text-sm text-gray-500 mb-6">
                                <?= !empty($search) ? 'Tidak ada produk yang cocok dengan "' . clean($search) . '"' : 'Belum ada produk di kategori ini.' ?>
                            </p>
                            <a href="produk.php" class="inline-flex items-center gap-2 px-6 py-3 bg-emerald-600 text-white rounded-xl text-sm font-semibold hover:bg-emerald-700 transition-colors">
                                <i class="fa-solid fa-rotate-left"></i> Lihat Semua Produk
                            </a>
                        </div>
                    <?php else: ?>

                        <?php if ($view === 'grid'): ?>
                            <!-- GRID VIEW -->
                            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-4 md:gap-5">
                                <?php foreach ($products as $idx => $p): 
                                    $thumb = getThumb($p['gambar']);
                                    $delay = min($idx * 50, 300);
                                ?>
                                <div class="product-card rounded-2xl border border-gray-100 overflow-hidden shadow-sm animate-slide-up" style="animation-delay: <?= $delay ?>ms">
                                    <div class="aspect-square overflow-hidden bg-gray-50 relative group card-image-wrapper">
                                        <img src="<?= $thumb ?>" alt="<?= clean($p['nama']) ?>" 
                                            class="card-img w-full h-full object-cover"
                                            onerror="this.src='https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image'"
                                            loading="lazy">
                                        
                                        <div class="card-overlay absolute inset-0 bg-black/30 flex items-center justify-center gap-2">
                                            <button onclick="openQuickView(<?= $p['id'] ?>)" class="w-10 h-10 bg-white rounded-full flex items-center justify-center text-gray-800 hover:bg-emerald-600 hover:text-white transition-all transform hover:scale-110" title="Quick View">
                                                <i class="fa-solid fa-eye text-sm"></i>
                                            </button>
                                            <a href="detail_produk.php?id=<?= $p['id'] ?>" class="w-10 h-10 bg-white rounded-full flex items-center justify-center text-gray-800 hover:bg-emerald-600 hover:text-white transition-all transform hover:scale-110" title="Detail">
                                                <i class="fa-solid fa-arrow-right text-sm"></i>
                                            </a>
                                        </div>

                                        <?php if ($p['stok'] <= 0): ?>
                                            <div class="absolute top-3 left-3 bg-rose-500 text-white px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase">Habis</div>
                                        <?php elseif ($p['stok'] <= 5): ?>
                                            <div class="absolute top-3 left-3 bg-yellow-500 text-white px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase">Sisa <?= $p['stok'] ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="p-4">
                                        <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md">
                                            <?= clean($p['kategori']) ?>
                                        </span>
                                        <h3 class="font-bold text-gray-900 mt-2 mb-1 line-clamp-2 text-sm hover:text-emerald-600 transition-colors">
                                            <a href="detail_produk.php?id=<?= $p['id'] ?>"><?= clean($p['nama']) ?></a>
                                        </h3>
                                        <div class="flex items-end justify-between mt-3">
                                            <p class="text-base font-bold text-emerald-600 font-mono"><?= formatRupiah($p['harga']) ?></p>
                                            <span class="text-[11px] text-gray-400">Stok: <?= $p['stok'] ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                        <?php else: ?>
                            <!-- LIST VIEW -->
                            <div class="space-y-4">
                                <?php foreach ($products as $idx => $p): 
                                    $thumb = getThumb($p['gambar']);
                                ?>
                                <div class="product-card rounded-2xl border border-gray-100 overflow-hidden shadow-sm flex flex-col sm:flex-row">
                                    <div class="w-full sm:w-48 h-48 sm:h-auto shrink-0 overflow-hidden bg-gray-50 relative group">
                                        <img src="<?= $thumb ?>" alt="<?= clean($p['nama']) ?>" 
                                            class="card-img w-full h-full object-cover"
                                            onerror="this.src='https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image'"
                                            loading="lazy">
                                        
                                        <?php if ($p['stok'] <= 0): ?>
                                            <div class="absolute top-3 left-3 bg-rose-500 text-white px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase">Habis</div>
                                        <?php elseif ($p['stok'] <= 5): ?>
                                            <div class="absolute top-3 left-3 bg-yellow-500 text-white px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase">Sisa <?= $p['stok'] ?></div>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="p-5 flex-1 flex flex-col justify-between">
                                        <div>
                                            <div class="flex items-start justify-between gap-4 mb-2">
                                                <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md">
                                                    <?= clean($p['kategori']) ?>
                                                </span>
                                                <span class="text-xs text-gray-400">Stok: <?= $p['stok'] ?> unit</span>
                                            </div>
                                            <h3 class="font-bold text-gray-900 mb-2 hover:text-emerald-600 transition-colors">
                                                <a href="detail_produk.php?id=<?= $p['id'] ?>"><?= clean($p['nama']) ?></a>
                                            </h3>
                                            <?php if (!empty($p['deskripsi'])): ?>
                                                <p class="text-sm text-gray-500 line-clamp-2 mb-3"><?= clean(substr($p['deskripsi'], 0, 150)) ?>...</p>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="flex items-center justify-between mt-4">
                                            <p class="text-xl font-bold text-emerald-600 font-mono"><?= formatRupiah($p['harga']) ?></p>
                                            <div class="flex gap-2">
                                                <button onclick="openQuickView(<?= $p['id'] ?>)" class="px-4 py-2 bg-gray-100 hover:bg-emerald-50 text-gray-600 hover:text-emerald-600 rounded-xl text-sm font-semibold transition-colors">
                                                    <i class="fa-solid fa-eye mr-1"></i> Quick View
                                                </button>
                                                <a href="detail_produk.php?id=<?= $p['id'] ?>" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-sm font-semibold transition-colors">
                                                    Detail <i class="fa-solid fa-arrow-right ml-1"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <!-- Pagination -->
                        <?php if ($totalPages > 1): ?>
                        <div class="mt-10 flex items-center justify-center gap-2">
                            <?php if ($page > 1): ?>
                                <a href="<?= buildUrl(['page' => $page - 1]) ?>" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-gray-200 text-gray-600 hover:bg-emerald-50 hover:text-emerald-600 hover:border-emerald-200 transition-all">
                                    <i class="fa-solid fa-chevron-left text-sm"></i>
                                </a>
                            <?php endif; ?>

                            <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            if ($start > 1) {
                                echo '<a href="' . buildUrl(['page' => 1]) . '" class="w-10 h-10 flex items-center justify-center rounded-xl text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors">1</a>';
                                if ($start > 2) echo '<span class="px-2 text-gray-400">...</span>';
                            }
                            for ($i = $start; $i <= $end; $i++): ?>
                                <a href="<?= buildUrl(['page' => $i]) ?>" 
                                    class="w-10 h-10 flex items-center justify-center rounded-xl text-sm font-medium transition-all <?= $i === $page ? 'bg-emerald-600 text-white shadow-lg shadow-emerald-200' : 'bg-white border border-gray-200 text-gray-600 hover:bg-emerald-50 hover:text-emerald-600' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor;
                            if ($end < $totalPages) {
                                if ($end < $totalPages - 1) echo '<span class="px-2 text-gray-400">...</span>';
                                echo '<a href="' . buildUrl(['page' => $totalPages]) . '" class="w-10 h-10 flex items-center justify-center rounded-xl text-sm font-medium text-gray-600 hover:bg-gray-100 transition-colors">' . $totalPages . '</a>';
                            }
                            ?>

                            <?php if ($page < $totalPages): ?>
                                <a href="<?= buildUrl(['page' => $page + 1]) ?>" class="w-10 h-10 flex items-center justify-center rounded-xl bg-white border border-gray-200 text-gray-600 hover:bg-emerald-50 hover:text-emerald-600 hover:border-emerald-200 transition-all">
                                    <i class="fa-solid fa-chevron-right text-sm"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <!-- ==================== QUICK VIEW MODAL ==================== -->
    <div id="quickViewModal" class="quick-view-modal" onclick="closeQuickView(event)">
        <div class="quick-view-content" onclick="event.stopPropagation()">
            <div class="p-6 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-bold text-lg text-gray-900">Quick View</h3>
                <button onclick="closeQuickView()" class="p-2 hover:bg-gray-100 rounded-lg transition-colors">
                    <i class="fa-solid fa-times text-gray-600"></i>
                </button>
            </div>
            <div id="quickViewBody" class="p-6">
                <div class="animate-pulse flex gap-6">
                    <div class="w-1/3 h-64 bg-gray-200 rounded-2xl"></div>
                    <div class="flex-1 space-y-4">
                        <div class="h-4 bg-gray-200 rounded w-3/4"></div>
                        <div class="h-4 bg-gray-200 rounded w-1/2"></div>
                        <div class="h-20 bg-gray-200 rounded"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ==================== FOOTER (SAMA SEPERTI INDEX) ==================== -->
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
                    <p class="text-sm text-gray-500 leading-relaxed">
                        Platform e-commerce pertanian dengan deteksi penyakit tanaman berbasis AI. Solusi modern untuk petani Indonesia.
                    </p>
                    <div class="flex gap-3">
                        <a href="#" class="w-10 h-10 bg-gray-900 hover:bg-emerald-600 rounded-xl flex items-center justify-center text-gray-400 hover:text-white transition-all">
                            <i class="fa-brands fa-instagram"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gray-900 hover:bg-emerald-600 rounded-xl flex items-center justify-center text-gray-400 hover:text-white transition-all">
                            <i class="fa-brands fa-facebook-f"></i>
                        </a>
                        <a href="#" class="w-10 h-10 bg-gray-900 hover:bg-emerald-600 rounded-xl flex items-center justify-center text-gray-400 hover:text-white transition-all">
                            <i class="fa-brands fa-youtube"></i>
                        </a>
                    </div>
                </div>

                <div>
                    <h4 class="text-white font-semibold mb-4">Menu Cepat</h4>
                    <ul class="space-y-3 text-sm">
                        <li><a href="index.php" class="hover:text-emerald-500 transition-colors">Beranda</a></li>
                        <li><a href="scan.php" class="hover:text-emerald-500 transition-colors">Scan AI</a></li>
                        <li><a href="produk.php" class="hover:text-emerald-500 transition-colors">Katalog Produk</a></li>
                        <li><a href="index.php#tentang" class="hover:text-emerald-500 transition-colors">Tentang Kami</a></li>
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
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-phone text-emerald-500 w-4"></i>
                            <span>+62 812-3456-7890</span>
                        </li>
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-envelope text-emerald-500 w-4"></i>
                            <span>support@etanimart.com</span>
                        </li>
                        <li class="flex items-center gap-3">
                            <i class="fa-solid fa-location-dot text-emerald-500 w-4"></i>
                            <span>Jakarta, Indonesia</span>
                        </li>
                    </ul>
                </div>
            </div>

            <div class="flex flex-col md:flex-row justify-between items-center gap-4 pt-8">
                <p class="text-xs text-gray-600">
                    &copy; <?= date('Y') ?> Etanimart Project. All Rights Reserved.
                </p>
                <div class="flex gap-6 text-xs text-gray-600">
                    <a href="#" class="hover:text-emerald-500 transition-colors">Kebijakan Privasi</a>
                    <a href="#" class="hover:text-emerald-500 transition-colors">Syarat & Ketentuan</a>
                </div>
            </div>
        </div>
    </footer>

<!-- ==================== FLOATING SCAN AI BUTTON ==================== -->
<button onclick="openCamera()" class="floating-scan-btn" title="Scan AI" aria-label="Buka Scan AI">
    <i class="fa-solid fa-camera"></i>
</button>

<!-- ==================== CAMERA MODAL ==================== -->
<div id="cameraModal" class="camera-modal" onclick="closeCamera(event)">
    <div class="camera-content" onclick="event.stopPropagation()">
        <!-- Header -->
        <div class="p-4 border-b border-gray-800 flex items-center justify-between">
            <h3 class="font-bold text-white text-sm flex items-center gap-2">
                <i class="fa-solid fa-camera text-emerald-400"></i>
                Ambil Foto
            </h3>
            <button onclick="closeCamera()" class="w-8 h-8 bg-gray-800 hover:bg-gray-700 rounded-lg flex items-center justify-center text-gray-400 hover:text-white transition-colors">
                <i class="fa-solid fa-times text-sm"></i>
            </button>
        </div>

        <!-- Video Preview -->
        <div class="relative bg-black aspect-[3/4] sm:aspect-video">
            <video id="cameraVideo" autoplay playsinline class="w-full h-full object-cover"></video>

            <!-- Scan overlay -->
            <div class="absolute inset-0 pointer-events-none">
                <div class="absolute inset-8 border-2 border-dashed border-emerald-400/50 rounded-2xl">
                    <div class="scan-line"></div>
                </div>
                <div class="absolute bottom-4 left-0 right-0 text-center">
                    <p class="text-white/70 text-xs font-medium bg-black/50 inline-block px-3 py-1 rounded-full">
                        Arahkan kamera ke daun/batang yang sakit
                    </p>
                </div>
            </div>
        </div>

        <!-- Controls -->
        <div class="p-4 bg-gray-900 flex items-center justify-center gap-6">
            <button onclick="switchCamera()" class="w-12 h-12 bg-gray-800 hover:bg-gray-700 rounded-full flex items-center justify-center text-gray-400 hover:text-white transition-colors" title="Ganti Kamera">
                <i class="fa-solid fa-rotate"></i>
            </button>
            <button onclick="takePhoto()" class="w-16 h-16 bg-white rounded-full flex items-center justify-center shadow-lg hover:scale-105 transition-transform">
                <div class="w-12 h-12 bg-emerald-500 rounded-full border-4 border-gray-900"></div>
            </button>
            <button onclick="closeCamera()" class="w-12 h-12 bg-gray-800 hover:bg-gray-700 rounded-full flex items-center justify-center text-gray-400 hover:text-white transition-colors" title="Batal">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>
    </div>
</div>

<!-- Hidden canvas for photo capture -->
<canvas id="photoCanvas" class="hidden"></canvas>

    <!-- ==================== JAVASCRIPT ==================== -->
    <script>


    // ===== MOBILE FILTER TOGGLE =====


    // ===== QUICK VIEW MODAL =====
    function openQuickView(productId) {
        const modal = document.getElementById('quickViewModal');
        const body = document.getElementById('quickViewBody');
        
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        
        // Load product data via AJAX
        fetch(`ajax_quickview.php?id=${productId}`)
            .then(res => res.text())
            .then(html => {
                body.innerHTML = html;
            })
            .catch(() => {
                body.innerHTML = `
                    <div class="text-center py-8 text-gray-500">
                        <i class="fa-solid fa-circle-exclamation text-3xl mb-3 text-red-400"></i>
                        <p>Gagal memuat data produk.</p>
                    </div>
                `;
            });
    }

    function closeQuickView(event) {
        if (event && event.target !== event.currentTarget) return;
        const modal = document.getElementById('quickViewModal');
        modal.classList.remove('active');
        document.body.style.overflow = '';
        
        // Reset to skeleton
        setTimeout(() => {
            document.getElementById('quickViewBody').innerHTML = `
                <div class="animate-pulse flex gap-6">
                    <div class="w-1/3 h-64 bg-gray-200 rounded-2xl"></div>
                    <div class="flex-1 space-y-4">
                        <div class="h-4 bg-gray-200 rounded w-3/4"></div>
                        <div class="h-4 bg-gray-200 rounded w-1/2"></div>
                        <div class="h-20 bg-gray-200 rounded"></div>
                    </div>
                </div>
            `;
        }, 300);
    }

    // Close modal on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            closeQuickView();
            if (!document.getElementById('mobileFilterPanel').classList.contains('active')) {
                mobileMenu.classList.add('hidden');
            }
        }
    });

    // ===== SCROLL REVEAL =====
    const revealElements = document.querySelectorAll('.reveal');
    const revealObserver = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.classList.add('active');
            }
        });
    }, { threshold: 0.1, rootMargin: '0px 0px -50px 0px' });
    
    revealElements.forEach(el => revealObserver.observe(el));

    // ===== SORT DROPDOWN TOGGLE =====
    function toggleSortDropdown() {
        const menu = document.getElementById('sortDropdownMenu');
        const chevron = document.getElementById('sortChevron');
        const isHidden = menu.classList.contains('hidden');

        if (isHidden) {
            menu.classList.remove('hidden');
            chevron.style.transform = 'rotate(180deg)';
        } else {
            menu.classList.add('hidden');
            chevron.style.transform = 'rotate(0deg)';
        }
    }

    // Close sort dropdown when clicking outside
    document.addEventListener('click', (e) => {
        const container = document.getElementById('sortDropdownContainer');
        const menu = document.getElementById('sortDropdownMenu');
        const chevron = document.getElementById('sortChevron');
        if (container && !container.contains(e.target)) {
            menu?.classList.add('hidden');
            if (chevron) chevron.style.transform = 'rotate(0deg)';
        }
    });

    // ===== MOBILE FILTER TOGGLE =====
    function toggleMobileFilter() {
        const overlay = document.getElementById('mobileFilterOverlay');
        const panel = document.getElementById('mobileFilterPanel');
        const isActive = panel.classList.contains('active');

        if (isActive) {
            overlay.classList.remove('active');
            panel.classList.remove('active');
            document.body.style.overflow = '';
        } else {
            overlay.classList.add('active');
            panel.classList.add('active');
            document.body.style.overflow = 'hidden';
        }
    }

    // ===== PRICE VALIDATION =====
    const minPriceInput = document.querySelector('input[name="min_harga"]');
    const maxPriceInput = document.querySelector('input[name="max_harga"]');

    if (minPriceInput && maxPriceInput) {
        minPriceInput.addEventListener('change', function() {
            const min = parseInt(this.value) || 0;
            const max = parseInt(maxPriceInput.value) || 0;
            if (max > 0 && min > max) {
                maxPriceInput.value = min;
            }
        });
        
        maxPriceInput.addEventListener('change', function() {
            const min = parseInt(minPriceInput.value) || 0;
            const max = parseInt(this.value) || 0;
            if (min > 0 && max < min) {
                minPriceInput.value = max;
            }
        });
    }

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
                closeCamera();
            }
        });

        // ===== CAMERA FUNCTIONS (Floating Button) =====
let currentStream = null;
let facingMode = 'environment';

async function openCamera() {
    const modal = document.getElementById('cameraModal');
    const video = document.getElementById('cameraVideo');

    try {
        currentStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: facingMode, width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: false
        });
        video.srcObject = currentStream;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    } catch (err) {
        console.error('Camera error:', err);
        // Kalau gak bisa buka kamera, redirect ke scan.php aja
        window.location.href = 'scan.php';
    }
}

function closeCamera(event) {
    if (event && event.target !== event.currentTarget) return;

    const modal = document.getElementById('cameraModal');
    const video = document.getElementById('cameraVideo');

    modal.classList.remove('active');
    document.body.style.overflow = '';

    if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
        currentStream = null;
    }
    video.srcObject = null;
}

async function switchCamera() {
    facingMode = facingMode === 'environment' ? 'user' : 'environment';

    if (currentStream) {
        currentStream.getTracks().forEach(track => track.stop());
    }

    try {
        const video = document.getElementById('cameraVideo');
        currentStream = await navigator.mediaDevices.getUserMedia({
            video: { facingMode: facingMode, width: { ideal: 1280 }, height: { ideal: 720 } },
            audio: false
        });
        video.srcObject = currentStream;
    } catch (err) {
        console.error('Switch camera error:', err);
        facingMode = facingMode === 'environment' ? 'user' : 'environment';
        alert('Gagal mengganti kamera.');
    }
}

function takePhoto() {
    const video = document.getElementById('cameraVideo');
    const canvas = document.getElementById('photoCanvas');

    if (!video.videoWidth) {
        alert('Kamera belum siap. Tunggu sebentar.');
        return;
    }

    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    const ctx = canvas.getContext('2d');
    ctx.drawImage(video, 0, 0);

    // Convert ke blob, terus redirect ke scan.php dengan foto
    canvas.toBlob((blob) => {
        // Simpan foto ke sessionStorage sebagai base64
        const reader = new FileReader();
        reader.onloadend = function() {
            sessionStorage.setItem('scan_foto_temp', reader.result);
            closeCamera();
            // Redirect ke scan.php dengan flag auto-process
            window.location.href = 'scan.php?autostart=1';
        };
        reader.readAsDataURL(blob);
    }, 'image/jpeg', 0.9);
}

// ===== CLOSE MODAL ON ESCAPE =====
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeCamera();
    }
});
    </script>

</body>
</html>