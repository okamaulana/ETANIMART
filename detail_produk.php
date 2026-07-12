<?php
// =============================================================================
// detail_produk.php - Etanimart Product Detail with Reviews & Auth
// =============================================================================
session_start();

// Detect current page for active menu
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

require_once 'koneksi.php';

// ==========================================
// CEK LOGIN STATUS & AMBIL DATA USER
// ==========================================
$isLoggedIn = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$userName = $isLoggedIn ? ($_SESSION['user_name'] ?? 'User') : null;
$userFoto = $isLoggedIn ? ($_SESSION['user_foto'] ?? '') : '';
$userId = $isLoggedIn ? ($_SESSION['user_id'] ?? 0) : 0;
$userRole = $isLoggedIn ? ($_SESSION['user_role'] ?? 'pembeli') : '';

function getProfilePic($foto) {
    return !empty($foto) ? 'uploads/profil/' . $foto : 'https://placehold.co/100?text=U';
}

if ($isLoggedIn && in_array($userRole, ['admin', 'penjual'])) {
    if ($userRole === 'admin') {
        header('Location: admin/admin_dashboard.php');
    } else {
        header('Location: penjual/penjual_dashboard.php');
    }
    exit;
}

$userData = null;
if ($isLoggedIn) {
    try {
        $stmtUser = $pdo->prepare("SELECT nama, foto_profil FROM users WHERE id = ?");
        $stmtUser->execute([$_SESSION['user_id']]);
        $userData = $stmtUser->fetch();
    } catch (PDOException $e) {
        $userData = null;
    }
}

if ($userData) {
    $userName = $userData['nama'];
    $userFoto = $userData['foto_profil'];
}

$currentPage = basename($_SERVER['PHP_SELF'], '.php');

if ($isLoggedIn && isset($_SESSION['just_logged_in']) && $_SESSION['just_logged_in'] === true) {
    unset($_SESSION['just_logged_in']);
}

// ==========================================
// AMBIL DATA PRODUK
// ==========================================
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header("Location: index.php");
    exit();
}

try {
    // Data produk
    $stmt = $pdo->prepare("SELECT * FROM produk WHERE id = :id LIMIT 1");
    $stmt->execute(['id' => $id]);
    $produk = $stmt->fetch();

    if (!$produk) {
        header("Location: index.php");
        exit();
    }

    // Parse gambar
    $gambarList = [];
    if (!empty($produk['gambar'])) {
        $decoded = json_decode($produk['gambar'], true);
        if (is_array($decoded)) $gambarList = $decoded;
    }

    // ===== AMBIL ULASAN =====
    $stmtUlasan = $pdo->prepare("
        SELECT u.*, DATE_FORMAT(u.created_at, '%d %b %Y') as tgl_format
        FROM ulasan u 
        WHERE u.id_produk = :id 
        ORDER BY u.created_at DESC
    ");
    $stmtUlasan->execute(['id' => $id]);
    $ulasanList = $stmtUlasan->fetchAll();

    // Hitung rating rata-rata
    $avgRating = 0;
    $totalUlasan = count($ulasanList);
    if ($totalUlasan > 0) {
        $sum = array_sum(array_column($ulasanList, 'rating'));
        $avgRating = round($sum / $totalUlasan, 1);
    }

    // Cek apakah user sudah pernah kasih ulasan
    $sudahUlas = false;
    if ($isLoggedIn) {
        $stmtCheck = $pdo->prepare("SELECT id FROM ulasan WHERE id_produk = :idp AND id_user = :idu LIMIT 1");
        $stmtCheck->execute(['idp' => $id, 'idu' => $_SESSION['user_id']]);
        $sudahUlas = $stmtCheck->fetch() !== false;
    }

    // Produk serupa
    $stmtSerupa = $pdo->prepare("
        SELECT id, nama, kategori, harga, gambar 
        FROM produk 
        WHERE kategori = :kat AND id != :id 
        ORDER BY RAND() 
        LIMIT 4
    ");
    $stmtSerupa->execute(['kat' => $produk['kategori'], 'id' => $id]);
    $produkSerupa = $stmtSerupa->fetchAll();

} catch (PDOException $e) {
    die("Error: " . $e->getMessage());
}

// ==========================================
// PROSES SUBMIT ULASAN
// ==========================================
$ulasanMsg = '';
$ulasanType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_ulasan'])) {

    if (!$isLoggedIn) {
        $ulasanMsg = 'Silakan login terlebih dahulu untuk memberikan ulasan.';
    } else {
        $rating = (int)($_POST['rating'] ?? 0);
        $komentar = trim($_POST['komentar'] ?? '');

        if ($rating < 1 || $rating > 5) {
            $ulasanMsg = 'Pilih rating 1-5 bintang.';
        } elseif (empty($komentar) || strlen($komentar) < 5) {
            $ulasanMsg = 'Komentar minimal 5 karakter.';
        } elseif ($sudahUlas) {
            $ulasanMsg = 'Kamu sudah pernah memberikan ulasan untuk produk ini.';
        } else {
            try {
                $stmt = $pdo->prepare("
                    INSERT INTO ulasan (id_produk, id_user, nama_user, rating, komentar) 
                    VALUES (:idp, :idu, :nama, :rating, :komentar)
                ");
                $stmt->execute([
                    'idp' => $id,
                    'idu' => $_SESSION['user_id'],
                    'nama' => $userName,
                    'rating' => $rating,
                    'komentar' => $komentar
                ]);

                header("Location: detail_produk.php?id=" . $id . "&ulasan=success");
                exit();

            } catch (PDOException $e) {
                $ulasanMsg = 'Gagal menyimpan ulasan: ' . $e->getMessage();
            }
        }
    }
}

// ==========================================
// PROSES TAMBAH KE KERANJANG (AJAX / FORM)
// ==========================================
$keranjangMsg = '';
$keranjangType = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_keranjang'])) {
    header('Content-Type: application/json');
    
    if (!$isLoggedIn) {
        echo json_encode(['success' => false, 'message' => 'Silakan login terlebih dahulu.']);
        exit();
    }
    
    if ($userRole !== 'pembeli') {
        echo json_encode(['success' => false, 'message' => 'Hanya pembeli yang dapat menambahkan ke keranjang.']);
        exit();
    }

    $jumlah = (int)($_POST['jumlah'] ?? 1);
    if ($jumlah < 1) $jumlah = 1;

    try {
        // Cek apakah produk sudah ada di keranjang
        $stmtCheck = $pdo->prepare("
            SELECT id, jumlah FROM keranjang 
            WHERE id_user = :idu AND id_produk = :idp LIMIT 1
        ");
        $stmtCheck->execute(['idu' => $userId, 'idp' => $id]);
        $existing = $stmtCheck->fetch();

        if ($existing) {
            // Update jumlah
            $newJumlah = $existing['jumlah'] + $jumlah;
            $stmtUpdate = $pdo->prepare("
                UPDATE keranjang SET jumlah = :jum, updated_at = NOW() 
                WHERE id = :kid
            ");
            $stmtUpdate->execute(['jum' => $newJumlah, 'kid' => $existing['id']]);
        } else {
            // Insert baru
            $stmtInsert = $pdo->prepare("
                INSERT INTO keranjang (id_user, id_produk, jumlah, created_at, updated_at) 
                VALUES (:idu, :idp, :jum, NOW(), NOW())
            ");
            $stmtInsert->execute(['idu' => $userId, 'idp' => $id, 'jum' => $jumlah]);
        }

        // Hitung total item keranjang
        $stmtCount = $pdo->prepare("SELECT COALESCE(SUM(jumlah), 0) as total FROM keranjang WHERE id_user = :idu");
        $stmtCount->execute(['idu' => $userId]);
        $totalKeranjang = (int)$stmtCount->fetchColumn();

        echo json_encode([
            'success' => true, 
            'message' => 'Produk ditambahkan ke keranjang!',
            'total_keranjang' => $totalKeranjang
        ]);
        exit();

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Gagal: ' . $e->getMessage()]);
        exit();
    }
}

// ==========================================
// PROSES BELI SEKARANG (LANGSUNG CHECKOUT)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_beli'])) {
    if (!$isLoggedIn) {
        header("Location: login.php?redirect=" . urlencode('detail_produk.php?id=' . $id));
        exit();
    }
    
    if ($userRole !== 'pembeli') {
        $keranjangMsg = 'Hanya pembeli yang dapat melakukan pembelian.';
        $keranjangType = 'error';
    } else {
        $jumlah = (int)($_POST['jumlah'] ?? 1);
        if ($jumlah < 1) $jumlah = 1;
        if ($jumlah > $produk['stok']) $jumlah = $produk['stok'];

        // Simpan ke session untuk checkout
        $_SESSION['checkout_direct'] = [
            'id_produk' => $id,
            'jumlah' => $jumlah,
            'harga_satuan' => $produk['harga'],
            'total' => $produk['harga'] * $jumlah
        ];

        header("Location: checkout.php");
        exit();
    }
}

// Flash message ulasan
if (isset($_GET['ulasan']) && $_GET['ulasan'] === 'success') {
    $ulasanMsg = 'Ulasan berhasil ditambahkan! Terima kasih.';
    $ulasanType = 'success';
}

// Helper
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
function renderStars($rating, $size = 'text-sm') {
    $html = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $html .= '<i class="fa-solid fa-star text-yellow-400 ' . $size . '"></i>';
        } elseif ($i - 0.5 <= $rating) {
            $html .= '<i class="fa-solid fa-star-half-stroke text-yellow-400 ' . $size . '"></i>';
        } else {
            $html .= '<i class="fa-regular fa-star text-gray-300 ' . $size . '"></i>';
        }
    }
    return $html;
}

// Hitung total item keranjang untuk badge
$totalKeranjang = 0;
if ($isLoggedIn && $userRole === 'pembeli') {
    try {
        $stmtCart = $pdo->prepare("SELECT COALESCE(SUM(jumlah), 0) as total FROM keranjang WHERE id_user = :idu");
        $stmtCart->execute(['idu' => $userId]);
        $totalKeranjang = (int)$stmtCart->fetchColumn();
    } catch (PDOException $e) {
        $totalKeranjang = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title><?= clean($produk['nama']) ?> - Etanimart</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        html { scroll-behavior: smooth; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .gallery-main {
            aspect-ratio: 1;
            border-radius: 1.5rem;
            overflow: hidden;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .gallery-main img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        .gallery-main:hover img { transform: scale(1.03); }

        .gallery-thumb {
            width: 72px; height: 72px;
            border-radius: 1rem;
            overflow: hidden;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.2s;
            background: #f8fafc;
            flex-shrink: 0;
        }
        .gallery-thumb.active {
            border-color: #10b981;
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15);
        }
        .gallery-thumb:hover { border-color: #10b981; }
        .gallery-thumb img { width: 100%; height: 100%; object-fit: cover; }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in-up {
            animation: fadeInUp 0.6s ease-out forwards;
        }
        .delay-100 { animation-delay: 0.1s; opacity: 0; }
        .delay-200 { animation-delay: 0.2s; opacity: 0; }
        .delay-300 { animation-delay: 0.3s; opacity: 0; }

        .product-card { transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1); }
        .product-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.15);
        }
        .product-card:hover .card-img { transform: scale(1.08); }
        .card-img { transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1); }

        /* Rating stars interactive */
        .star-rating {
            display: flex;
            flex-direction: row-reverse;
            gap: 4px;
        }
        .star-rating input { display: none; }
        .star-rating label {
            cursor: pointer;
            font-size: 28px;
            color: #d1d5db;
            transition: color 0.2s;
        }
        .star-rating label:hover,
        .star-rating label:hover ~ label,
        .star-rating input:checked ~ label {
            color: #fbbf24;
        }

        /* Review card */
        .review-card {
            border-left: 3px solid #10b981;
            transition: all 0.2s;
        }
        .review-card:hover { background: #f8fafc; }

        /* Login modal - Enhanced */
        .login-modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(8px);
            z-index: 100;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .login-modal-overlay.active {
            display: flex;
            opacity: 1;
        }
        .login-modal-content {
            background: white;
            border-radius: 2rem;
            max-width: 460px;
            width: 100%;
            padding: 2.5rem;
            box-shadow: 0 25px 80px -12px rgba(0, 0, 0, 0.35);
            text-align: center;
            transform: scale(0.9) translateY(20px);
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            overflow: hidden;
        }
        .login-modal-overlay.active .login-modal-content {
            transform: scale(1) translateY(0);
        }
        .login-modal-content::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 5px;
            background: linear-gradient(90deg, #10b981, #34d399, #10b981);
        }

        /* Lock overlay on buttons */
        .btn-locked {
            position: relative;
            overflow: hidden;
        }

        /* Floating particles for modal */
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-10px) rotate(5deg); }
        }
        .float-icon { animation: float 3s ease-in-out infinite; }

        /* Pulse ring */
        @keyframes pulseRing {
            0% { transform: scale(1); opacity: 0.5; }
            100% { transform: scale(1.5); opacity: 0; }
        }
        .pulse-ring {
            position: absolute;
            inset: -8px;
            border-radius: 50%;
            border: 2px solid #10b981;
            animation: pulseRing 2s ease-out infinite;
        }

        /* Navbar - SAMA PERSIS INDEX */
        .nav-link { position: relative; }
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
        .nav-link:hover::after { width: 100%; }

        .btn-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.5);
        }

        /* ===== USER DROPDOWN ===== */
        .user-dropdown { position: relative; }
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
        #menuIcon { transition: transform 0.3s ease; }

        /* ===== QUANTITY INPUT ===== */
        .qty-btn {
            width: 36px; height: 36px;
            display: flex; align-items: center; justify-content: center;
            border: 1px solid #e2e8f0;
            background: white;
            color: #374151;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        .qty-btn:hover { background: #f0fdf4; color: #059669; border-color: #10b981; }
        .qty-btn:first-child { border-radius: 10px 0 0 10px; }
        .qty-btn:last-child { border-radius: 0 10px 10px 0; }
        .qty-input {
            width: 50px; height: 36px;
            border: 1px solid #e2e8f0;
            border-left: none; border-right: none;
            text-align: center;
            font-weight: 600;
            color: #374151;
            background: white;
        }
        .qty-input:focus { outline: none; }

        /* Toast notification */
        .toast {
            position: fixed;
            bottom: 24px;
            left: 50%;
            transform: translateX(-50%) translateY(100px);
            background: #10b981;
            color: white;
            padding: 12px 24px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 10px 40px -10px rgba(16, 185, 129, 0.4);
            z-index: 9999;
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .toast.show {
            transform: translateX(-50%) translateY(0);
            opacity: 1;
        }
        .toast.error { background: #ef4444; box-shadow: 0 10px 40px -10px rgba(239, 68, 68, 0.4); }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased overflow-x-hidden">

  <!-- ==================== NAVBAR ==================== -->
<nav class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" id="navbar">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-20 items-center">
            <!-- Logo - GANTI GAMBAR LOGO DI SINI -->
           <!-- Logo -->
<!-- Logo - HANYA GAMBAR, gak perlu fallback & text -->
<a href="index.php" class="flex items-center gap-2.5 group shrink-0">
    <img src="uploads/logo/tani.png" 
         alt="Etanimart" 
         class="logo-img"
         style="height: 70px; width: auto; object-fit: contain;">
</a>

            <!-- Desktop Navigation -->
            <div class="hidden lg:flex items-center space-x-8 font-medium">
                <a href="index.php" class="nav-link <?= $currentPage === 'index' ? 'text-emerald-600' : 'text-gray-600' ?> hover:text-emerald-600 transition-colors">Beranda</a>
                <a href="scan.php" class="nav-link <?= $currentPage === 'scan' ? 'text-emerald-600' : 'text-gray-600' ?> hover:text-emerald-600 transition-colors flex items-center gap-1.5">
                    <i class="fa-solid fa-qrcode <?= $currentPage === 'scan' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Scan AI
                </a>
                <a href="produk.php" class="nav-link <?= $currentPage === 'produk' ? 'text-emerald-600' : 'text-gray-600' ?> hover:text-emerald-600 transition-colors">Katalog</a>
                
                <a href="#tentang" class="nav-link text-gray-600 hover:text-emerald-600 transition-colors">Tentang</a>
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
                            <a href="profil.php" class="user-dropdown-item"><i class="fa-solid fa-user"></i> Profil Saya</a>
                            <a href="pesanan.php" class="user-dropdown-item"><i class="fa-solid fa-bag-shopping"></i> Pesanan Saya</a>
                            <a href="keranjang.php" class="user-dropdown-item"><i class="fa-solid fa-cart-shopping"></i> Keranjang</a>
                            <div class="user-dropdown-divider"></div>
                            <a href="logout.php" class="user-dropdown-item text-red-500 hover:text-red-600 hover:bg-red-50"><i class="fa-solid fa-right-from-bracket text-red-400"></i> Keluar</a>
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
            <?php endif; ?>
            <div class="space-y-1">
                <p class="px-4 py-2 text-xs font-bold text-gray-400 uppercase tracking-wider">Menu</p>
                <a href="index.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium transition-all <?= $currentPage === 'index' ? 'text-emerald-700 bg-emerald-50 border border-emerald-100' : 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-600' ?>"><i class="fa-solid fa-house w-5 text-center <?= $currentPage === 'index' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Beranda</a>
                <a href="scan.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium transition-all <?= $currentPage === 'scan' ? 'text-emerald-700 bg-emerald-50 border border-emerald-100' : 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-600' ?>"><i class="fa-solid fa-qrcode w-5 text-center <?= $currentPage === 'scan' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Scan AI</a>
                <a href="produk.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium transition-all <?= $currentPage === 'produk' ? 'text-emerald-700 bg-emerald-50 border border-emerald-100' : 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-600' ?>"><i class="fa-solid fa-shop w-5 text-center <?= $currentPage === 'produk' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Katalog Produk</a>
               
                <a href="#tentang" class="flex items-center gap-3 py-3 px-4 rounded-xl text-gray-700 hover:bg-emerald-50 hover:text-emerald-600 font-medium transition-all"><i class="fa-solid fa-circle-info w-5 text-center text-emerald-500"></i> Tentang</a>
            </div>
            <?php if (!$isLoggedIn): ?>
            <div class="pt-4 mt-4 border-t border-gray-100">
                <div class="grid grid-cols-2 gap-3">
                    <a href="login.php" class="text-center py-3 text-emerald-600 border-2 border-emerald-600 rounded-xl font-semibold hover:bg-emerald-50 transition-all">Masuk</a>
                    <a href="register.php" class="text-center py-3 btn-primary text-white rounded-xl font-semibold shadow-md">Daftar</a>
                </div>
            </div>
            <?php else: ?>
            <div class="pt-4 mt-4 border-t border-gray-100">
                <a href="logout.php" class="flex items-center gap-3 py-3 px-4 rounded-xl text-red-600 hover:bg-red-50 font-medium transition-all"><i class="fa-solid fa-right-from-bracket w-5 text-center"></i> Keluar Akun</a>
            </div>
            <?php endif; ?>
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
                <a href="index.php#produk" class="hover:text-emerald-600 transition-colors">Produk</a>
                <i class="fa-solid fa-chevron-right text-[10px] text-gray-300"></i>
                <span class="text-gray-800 font-medium truncate max-w-[200px]"><?= clean($produk['nama']) ?></span>
            </nav>
        </div>
    </div>

    <!-- ==================== MAIN CONTENT ==================== -->
    <main class="flex-grow py-8 md:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <!-- Product Detail -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 lg:gap-12 mb-16">

                <!-- LEFT: GALLERY -->
                <div class="animate-fade-in-up">
                    <div class="gallery-main mb-4 relative group">
                        <img id="mainImage" 
                            src="<?= !empty($gambarList) ? 'uploads/' . $gambarList[0] : 'https://placehold.co/600x600/e2e8f0/94a3b8?text=No+Image' ?>" 
                            alt="<?= clean($produk['nama']) ?>"
                            onerror="this.src='https://placehold.co/600x600/e2e8f0/94a3b8?text=No+Image'">
                        <?php if ($produk['stok'] <= 0): ?>
                            <div class="absolute inset-0 bg-black/50 flex items-center justify-center rounded-3xl">
                                <span class="bg-rose-500 text-white px-6 py-2 rounded-full font-bold text-sm tracking-wider uppercase">Stok Habis</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (count($gambarList) > 1): ?>
                    <div class="flex gap-3 overflow-x-auto pb-2">
                        <?php foreach ($gambarList as $idx => $img): ?>
                            <div class="gallery-thumb <?= $idx === 0 ? 'active' : '' ?>" onclick="changeImage(this, 'uploads/<?= $img ?>')">
                                <img src="uploads/<?= $img ?>" alt="Thumbnail <?= $idx + 1 ?>" onerror="this.src='https://placehold.co/100x100/e2e8f0/94a3b8?text=No+Image'">
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- RIGHT: INFO -->
                <div class="animate-fade-in-up delay-100">
                    <div class="flex items-center gap-3 mb-3 flex-wrap">
                        <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200/40"><?= clean($produk['kategori']) ?></span>
                        <?php if ($totalUlasan > 0): ?>
                            <div class="flex items-center gap-1.5">
                                <?= renderStars($avgRating, 'text-xs') ?>
                                <span class="text-xs font-semibold text-gray-700"><?= $avgRating ?></span>
                                <span class="text-xs text-gray-400">(<?= $totalUlasan ?> ulasan)</span>
                            </div>
                        <?php endif; ?>
                    </div>

                    <h1 class="text-2xl md:text-3xl font-bold text-gray-900 leading-tight mb-3"><?= clean($produk['nama']) ?></h1>

                    <div class="flex items-baseline gap-3 mb-6">
                        <span class="text-3xl font-bold text-emerald-600 font-mono"><?= formatRupiah($produk['harga']) ?></span>
                        <span class="text-sm text-gray-400">/ unit</span>
                    </div>

                    <!-- Quantity Selector -->
                    <div class="flex items-center gap-4 mb-6">
                        <span class="text-sm font-semibold text-gray-600">Jumlah:</span>
                        <div class="flex items-center">
                            <button type="button" class="qty-btn" onclick="updateQty(-1)">-</button>
                            <input type="number" id="qtyInput" value="1" min="1" max="<?= $produk['stok'] ?>" class="qty-input" readonly>
                            <button type="button" class="qty-btn" onclick="updateQty(1)">+</button>
                        </div>
                        <span class="text-xs text-gray-400">Stok: <?= $produk['stok'] ?> unit</span>
                    </div>

                    <!-- Description -->
                    <div class="bg-white rounded-2xl border border-gray-100 p-5 mb-6 shadow-sm">
                        <h3 class="text-sm font-bold text-gray-900 mb-3 flex items-center gap-2"><i class="fa-solid fa-circle-info text-emerald-500"></i> Deskripsi Produk</h3>
                        <p class="text-sm text-gray-600 leading-relaxed whitespace-pre-line"><?= !empty($produk['deskripsi']) ? nl2br(clean($produk['deskripsi'])) : '<span class="text-gray-400 italic">Tidak ada deskripsi produk.</span>' ?></p>
                    </div>

                    <!-- Stock -->
                    <div class="flex items-center gap-4 mb-6 text-sm">
                        <div class="flex items-center gap-2 text-gray-600"><i class="fa-solid fa-box text-gray-400"></i><span>Stok: <strong class="text-gray-900"><?= $produk['stok'] ?> unit</strong></span></div>
                        <div class="w-px h-4 bg-gray-200"></div>
                        <div class="flex items-center gap-2 text-gray-600"><i class="fa-solid fa-truck-fast text-gray-400"></i><span>Pengiriman tersedia</span></div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="flex flex-col sm:flex-row gap-3">
                        <?php if ($produk['stok'] > 0): ?>
                            <?php if ($isLoggedIn): ?>
                                <form method="POST" action="detail_produk.php?id=<?= $id ?>" class="flex-1">
                                    <input type="hidden" name="jumlah" id="beliJumlah" value="1">
                                    <button type="submit" name="action_beli" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-4 rounded-2xl shadow-lg shadow-emerald-200 transition-all transform hover:-translate-y-0.5 flex items-center justify-center gap-3 cursor-pointer">
                                        <i class="fa-solid fa-cart-shopping"></i><span>Beli Sekarang</span>
                                    </button>
                                </form>
                                <button onclick="tambahKeranjang()" id="btnKeranjang" class="flex-1 bg-gray-900 hover:bg-gray-800 text-white font-bold py-4 rounded-2xl shadow-lg transition-all transform hover:-translate-y-0.5 flex items-center justify-center gap-3 cursor-pointer">
                                    <i class="fa-solid fa-plus"></i><span>Tambah ke Keranjang</span>
                                </button>
                            <?php else: ?>
                                <button onclick="showLoginModal('beli')" class="btn-locked flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-4 rounded-2xl shadow-lg shadow-emerald-200 transition-all transform hover:-translate-y-0.5 flex items-center justify-center gap-3 cursor-pointer"><i class="fa-solid fa-lock"></i><span>Beli Sekarang</span></button>
                                <button onclick="showLoginModal('keranjang')" class="btn-locked flex-1 bg-gray-900 hover:bg-gray-800 text-white font-bold py-4 rounded-2xl shadow-lg transition-all transform hover:-translate-y-0.5 flex items-center justify-center gap-3 cursor-pointer"><i class="fa-solid fa-lock"></i><span>Tambah ke Keranjang</span></button>
                            <?php endif; ?>
                        <?php else: ?>
                            <button disabled class="flex-1 bg-gray-200 text-gray-400 font-bold py-4 rounded-2xl cursor-not-allowed flex items-center justify-center gap-3"><i class="fa-solid fa-ban"></i><span>Stok Habis</span></button>
                            <button onclick="notifStok()" class="flex-1 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-bold py-4 rounded-2xl border border-emerald-200 transition-all flex items-center justify-center gap-3 cursor-pointer"><i class="fa-solid fa-bell"></i><span>Notifikasi Stok</span></button>
                        <?php endif; ?>
                    </div>

                    <!-- Trust Badges -->
                    <div class="grid grid-cols-3 gap-4 mt-8 pt-6 border-t border-gray-100">
                        <div class="text-center"><div class="w-10 h-10 bg-emerald-50 rounded-xl flex items-center justify-center mx-auto mb-2"><i class="fa-solid fa-shield-halved text-emerald-600"></i></div><p class="text-[11px] font-semibold text-gray-700">Produk Original</p></div>
                        <div class="text-center"><div class="w-10 h-10 bg-blue-50 rounded-xl flex items-center justify-center mx-auto mb-2"><i class="fa-solid fa-rotate-left text-blue-600"></i></div><p class="text-[11px] font-semibold text-gray-700">Garansi 7 Hari</p></div>
                        <div class="text-center"><div class="w-10 h-10 bg-purple-50 rounded-xl flex items-center justify-center mx-auto mb-2"><i class="fa-solid fa-headset text-purple-600"></i></div><p class="text-[11px] font-semibold text-gray-700">Support 24/7</p></div>
                    </div>
                </div>
            </div>

            <!-- ==================== SECTION ULASAN ==================== -->
            <div class="mb-16 animate-fade-in-up delay-200">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold text-gray-900 flex items-center gap-2">
                        <i class="fa-solid fa-comments text-emerald-500"></i>
                        Ulasan Pembeli
                        <?php if ($totalUlasan > 0): ?>
                            <span class="text-sm font-normal text-gray-400">(<?= $totalUlasan ?>)</span>
                        <?php endif; ?>
                    </h2>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

                    <!-- LEFT: Rating Summary -->
                    <div class="lg:col-span-1">
                        <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm sticky top-24">
                            <div class="text-center mb-4">
                                <div class="text-5xl font-bold text-gray-900 mb-2"><?= $avgRating ?></div>
                                <div class="flex justify-center gap-1 mb-1"><?= renderStars($avgRating, 'text-lg') ?></div>
                                <p class="text-sm text-gray-400"><?= $totalUlasan ?> ulasan</p>
                            </div>

                            <?php
                            $ratingCounts = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0];
                            foreach ($ulasanList as $u) {
                                if (isset($ratingCounts[$u['rating']])) $ratingCounts[$u['rating']]++;
                            }
                            ?>
                            <div class="space-y-2">
                                <?php for ($i = 5; $i >= 1; $i--): 
                                    $count = $ratingCounts[$i];
                                    $percent = $totalUlasan > 0 ? round(($count / $totalUlasan) * 100) : 0;
                                ?>
                                <div class="flex items-center gap-3">
                                    <span class="text-xs font-semibold text-gray-600 w-3"><?= $i ?></span>
                                    <i class="fa-solid fa-star text-yellow-400 text-xs"></i>
                                    <div class="flex-1 h-2 bg-gray-100 rounded-full overflow-hidden"><div class="h-full bg-yellow-400 rounded-full transition-all" style="width: <?= $percent ?>%"></div></div>
                                    <span class="text-xs text-gray-400 w-6 text-right"><?= $count ?></span>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>

                    <!-- RIGHT: List Ulasan + Form -->
                    <div class="lg:col-span-2 space-y-6">

                        <!-- Form Ulasan -->
                        <?php if ($isLoggedIn && !$sudahUlas): ?>
                        <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                            <h3 class="text-sm font-bold text-gray-900 mb-4 flex items-center gap-2"><i class="fa-solid fa-pen-to-square text-emerald-500"></i> Tulis Ulasan</h3>

                            <?php if (!empty($ulasanMsg)): ?>
                                <div class="mb-4 p-3 rounded-xl text-xs <?= $ulasanType === 'success' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-red-50 text-red-700 border border-red-200' ?> flex items-center gap-2">
                                    <i class="fa-solid <?= $ulasanType === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation' ?>"></i> <?= clean($ulasanMsg) ?>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="detail_produk.php?id=<?= $id ?>">
                                <div class="mb-4">
                                    <label class="block text-xs font-semibold text-gray-600 mb-2">Rating</label>
                                    <div class="star-rating justify-start">
                                        <input type="radio" id="star5" name="rating" value="5"><label for="star5"><i class="fa-solid fa-star"></i></label>
                                        <input type="radio" id="star4" name="rating" value="4"><label for="star4"><i class="fa-solid fa-star"></i></label>
                                        <input type="radio" id="star3" name="rating" value="3"><label for="star3"><i class="fa-solid fa-star"></i></label>
                                        <input type="radio" id="star2" name="rating" value="2"><label for="star2"><i class="fa-solid fa-star"></i></label>
                                        <input type="radio" id="star1" name="rating" value="1"><label for="star1"><i class="fa-solid fa-star"></i></label>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <label class="block text-xs font-semibold text-gray-600 mb-2">Komentar</label>
                                    <textarea name="komentar" rows="3" required minlength="5" maxlength="500" placeholder="Bagaimana pengalamanmu dengan produk ini?" class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all resize-none placeholder:text-gray-400"></textarea>
                                    <p class="text-[10px] text-gray-400 mt-1">Minimal 5 karakter</p>
                                </div>

                                <button type="submit" name="submit_ulasan" class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white font-semibold rounded-xl text-sm transition-colors shadow-sm cursor-pointer flex items-center gap-2"><i class="fa-solid fa-paper-plane"></i> Kirim Ulasan</button>
                            </form>
                        </div>
                        <?php elseif ($isLoggedIn && $sudahUlas): ?>
                            <div class="bg-emerald-50 border border-emerald-200 rounded-2xl p-4 text-center">
                                <i class="fa-solid fa-check-circle text-emerald-500 text-xl mb-2"></i>
                                <p class="text-sm text-emerald-700 font-medium">Terima kasih! Kamu sudah memberikan ulasan untuk produk ini.</p>
                            </div>
                        <?php else: ?>
                            <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm relative overflow-hidden">
                                <div class="absolute inset-0 bg-gray-50/80 backdrop-blur-[1px] z-10 flex flex-col items-center justify-center">
                                    <div class="text-center p-4">
                                        <div class="w-14 h-14 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-3 float-icon"><i class="fa-solid fa-lock text-2xl text-emerald-600"></i></div>
                                        <h4 class="text-sm font-bold text-gray-800 mb-1">Login Diperlukan</h4>
                                        <p class="text-xs text-gray-500 mb-3 max-w-[200px]">Masuk untuk memberikan ulasan dan bagikan pengalamanmu</p>
                                        <button onclick="showLoginModal('ulasan')" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-semibold transition-colors flex items-center gap-2 mx-auto"><i class="fa-solid fa-right-to-bracket"></i> Login Sekarang</button>
                                    </div>
                                </div>
                                <h3 class="text-sm font-bold text-gray-900 mb-4 flex items-center gap-2 opacity-40"><i class="fa-solid fa-pen-to-square text-emerald-500"></i> Tulis Ulasan</h3>
                                <div class="mb-4 opacity-40"><label class="block text-xs font-semibold text-gray-600 mb-2">Rating</label><div class="flex gap-1 text-gray-300 text-xl"><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i><i class="fa-solid fa-star"></i></div></div>
                                <div class="mb-4 opacity-40"><label class="block text-xs font-semibold text-gray-600 mb-2">Komentar</label><div class="w-full h-20 bg-gray-100 rounded-xl"></div></div>
                                <div class="w-28 h-9 bg-emerald-200 rounded-xl opacity-40"></div>
                            </div>
                        <?php endif; ?>

                        <!-- List Ulasan -->
                        <div class="space-y-4">
                            <?php if (empty($ulasanList)): ?>
                                <div class="text-center py-12 text-gray-400"><i class="fa-regular fa-comment-dots text-4xl mb-3 text-gray-300"></i><p class="text-sm">Belum ada ulasan untuk produk ini.</p><p class="text-xs mt-1">Jadilah yang pertama memberikan ulasan!</p></div>
                            <?php else: ?>
                                <?php foreach ($ulasanList as $ulasan): ?>
                                <div class="review-card bg-white rounded-2xl border border-gray-100 p-5 shadow-sm">
                                    <div class="flex items-start justify-between mb-3">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-600 flex items-center justify-center font-bold text-sm"><?= strtoupper(substr(clean($ulasan['nama_user']), 0, 1)) ?></div>
                                            <div><p class="text-sm font-bold text-gray-900"><?= clean($ulasan['nama_user']) ?></p><p class="text-[11px] text-gray-400"><?= clean($ulasan['tgl_format']) ?></p></div>
                                        </div>
                                        <div class="flex gap-0.5"><?= renderStars($ulasan['rating'], 'text-xs') ?></div>
                                    </div>
                                    <p class="text-sm text-gray-600 leading-relaxed pl-[52px]"><?= nl2br(clean($ulasan['komentar'])) ?></p>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==================== PRODUK SERUPA ==================== -->
            <?php if (!empty($produkSerupa)): ?>
            <div class="mb-12 animate-fade-in-up delay-300">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-lg font-bold text-gray-900 flex items-center gap-2"><i class="fa-solid fa-layer-group text-emerald-500"></i> Produk Serupa</h2>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <?php foreach ($produkSerupa as $p): $thumb = getThumb($p['gambar']); ?>
                    <a href="detail_produk.php?id=<?= $p['id'] ?>" class="product-card bg-white rounded-2xl border border-gray-100 overflow-hidden shadow-sm block">
                        <div class="aspect-square overflow-hidden bg-gray-50"><img src="<?= $thumb ?>" alt="<?= clean($p['nama']) ?>" class="card-img w-full h-full object-cover" onerror="this.src='https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image'"></div>
                        <div class="p-4">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-md"><?= clean($p['kategori']) ?></span>
                            <h3 class="text-sm font-bold text-gray-900 mt-2 line-clamp-2 min-h-[2.5rem]"><?= clean($p['nama']) ?></h3>
                            <p class="text-sm font-bold text-emerald-600 mt-2 font-mono"><?= formatRupiah($p['harga']) ?></p>
                        </div>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </main>

    <!-- ==================== LOGIN MODAL ==================== -->
    <div id="loginModal" class="login-modal-overlay">
        <div class="login-modal-content">
            <button onclick="closeLoginModal()" class="absolute top-4 right-4 w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center text-gray-500 hover:text-gray-700 transition-colors"><i class="fa-solid fa-xmark"></i></button>
            <div class="relative w-20 h-20 mx-auto mb-5">
                <div class="pulse-ring"></div>
                <div class="w-20 h-20 bg-gradient-to-br from-emerald-100 to-emerald-50 rounded-full flex items-center justify-center mx-auto float-icon relative z-10 border-2 border-emerald-200"><i class="fa-solid fa-lock text-3xl text-emerald-600"></i></div>
            </div>
            <h3 class="text-xl font-bold text-gray-900 mb-2">Login Diperlukan</h3>
            <p class="text-sm text-gray-500 mb-6 leading-relaxed" id="loginModalText">Silakan login terlebih dahulu untuk melanjutkan pembelian.</p>
            <div class="bg-gray-50 rounded-xl p-4 mb-6 text-left">
                <p class="text-xs font-semibold text-gray-700 mb-2 flex items-center gap-1.5"><i class="fa-solid fa-sparkles text-yellow-500"></i> Keuntungan Login:</p>
                <div class="space-y-1.5">
                    <div class="flex items-center gap-2 text-xs text-gray-600"><i class="fa-solid fa-check text-emerald-500 text-[10px]"></i><span>Beli produk favoritmu</span></div>
                    <div class="flex items-center gap-2 text-xs text-gray-600"><i class="fa-solid fa-check text-emerald-500 text-[10px]"></i><span>Simpan ke keranjang belanja</span></div>
                    <div class="flex items-center gap-2 text-xs text-gray-600"><i class="fa-solid fa-check text-emerald-500 text-[10px]"></i><span>Berikan ulasan & rating</span></div>
                    <div class="flex items-center gap-2 text-xs text-gray-600"><i class="fa-solid fa-check text-emerald-500 text-[10px]"></i><span>Dapatkan promo eksklusif</span></div>
                </div>
            </div>
            <div class="flex flex-col gap-3">
                <a href="login.php?redirect=<?= urlencode('detail_produk.php?id=' . $id) ?>" class="w-full py-3.5 bg-gradient-to-r from-emerald-600 to-emerald-500 hover:from-emerald-700 hover:to-emerald-600 text-white font-bold rounded-xl transition-all flex items-center justify-center gap-2 shadow-lg shadow-emerald-200"><i class="fa-solid fa-right-to-bracket"></i> Login Sekarang</a>
                <button onclick="closeLoginModal()" class="w-full py-3 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold rounded-xl transition-colors">Nanti Saja</button>
            </div>
            <p class="text-xs text-gray-400 mt-5">Belum punya akun? <a href="register.php" class="text-emerald-600 hover:underline font-medium">Daftar Sekarang</a></p>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="toast" class="toast"><i class="fa-solid fa-check-circle"></i><span id="toastText">Berhasil!</span></div>

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


    <!-- ==================== JAVASCRIPT ==================== -->
    <script>
        // ===== GALLERY =====
        function changeImage(thumb, src) {
            document.getElementById('mainImage').src = src;
            document.querySelectorAll('.gallery-thumb').forEach(t => t.classList.remove('active'));
            thumb.classList.add('active');
        }

        // ===== QUANTITY =====
        function updateQty(delta) {
            const input = document.getElementById('qtyInput');
            const beliJumlah = document.getElementById('beliJumlah');
            let val = parseInt(input.value) + delta;
            const max = parseInt(input.max);
            if (val < 1) val = 1;
            if (val > max) val = max;
            input.value = val;
            if (beliJumlah) beliJumlah.value = val;
        }

        // ===== LOGIN MODAL =====
        const modalTexts = {
            'beli': 'Silakan login terlebih dahulu untuk melakukan pembelian produk ini.',
            'keranjang': 'Login dulu yuk, biar bisa simpan produk ke keranjang belanjamu.',
            'ulasan': 'Masuk untuk memberikan ulasan dan bagikan pengalamanmu dengan produk ini.'
        };

        function showLoginModal(type = 'default') {
            const modal = document.getElementById('loginModal');
            const textEl = document.getElementById('loginModalText');
            textEl.textContent = modalTexts[type] || 'Silakan login terlebih dahulu.';
            modal.classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeLoginModal() {
            const modal = document.getElementById('loginModal');
            modal.classList.remove('active');
            document.body.style.overflow = '';
        }

        document.getElementById('loginModal').addEventListener('click', (e) => {
            if (e.target === document.getElementById('loginModal')) closeLoginModal();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeLoginModal();
        });

        // ===== TOAST =====
        function showToast(message, isError = false) {
            const toast = document.getElementById('toast');
            const toastText = document.getElementById('toastText');
            toastText.textContent = message;
            toast.className = 'toast' + (isError ? ' error' : '') + ' show';
            setTimeout(() => toast.classList.remove('show'), 3000);
        }

        // ===== TAMBAH KERANJANG (AJAX) =====
        async function tambahKeranjang() {
            const btn = document.getElementById('btnKeranjang');
            if (!btn) return;
            
            const originalHTML = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i><span>Menyimpan...</span>';
            btn.disabled = true;

            const jumlah = document.getElementById('qtyInput').value;

            try {
                const formData = new FormData();
                formData.append('action_keranjang', '1');
                formData.append('jumlah', jumlah);

                const response = await fetch('detail_produk.php?id=<?= $id ?>', {
                    method: 'POST',
                    body: formData
                });

                const data = await response.json();

                if (data.success) {
                    showToast(data.message);
                    // Update badge keranjang
                    const badges = document.querySelectorAll('#cartBadge, .cart-badge-mobile');
                    badges.forEach(badge => {
                        if (data.total_keranjang > 0) {
                            badge.textContent = data.total_keranjang;
                            badge.classList.remove('hidden');
                        }
                    });
                    
                    btn.innerHTML = '<i class="fa-solid fa-check"></i><span>Ditambahkan!</span>';
                    btn.classList.remove('bg-gray-900');
                    btn.classList.add('bg-emerald-600');
                    setTimeout(() => {
                        btn.innerHTML = originalHTML;
                        btn.classList.remove('bg-emerald-600');
                        btn.classList.add('bg-gray-900');
                        btn.disabled = false;
                    }, 2000);
                } else {
                    showToast(data.message, true);
                    btn.innerHTML = originalHTML;
                    btn.disabled = false;
                }
            } catch (err) {
                showToast('Terjadi kesalahan. Coba lagi.', true);
                btn.innerHTML = originalHTML;
                btn.disabled = false;
            }
        }

        function notifStok() {
            showToast('Kamu akan mendapat notifikasi ketika produk tersedia kembali.');
        }

        // ===== MOBILE MENU =====

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

        // ===== USER DROPDOWN =====
        function toggleUserDropdown(event) {
            event.stopPropagation();
            const menu = document.getElementById('userDropdownMenu');
            const icon = document.getElementById('userDropdownIcon');

            if (menu) {
                menu.classList.toggle('active');
                if (icon) {
                    icon.style.transform = menu.classList.contains('active') ? 'rotate(180deg)' : 'rotate(0deg)';
                }
            }
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(event) {
            const dropdown = document.querySelector('.user-dropdown');
            const menu = document.getElementById('userDropdownMenu');
            const icon = document.getElementById('userDropdownIcon');

            if (dropdown && !dropdown.contains(event.target)) {
                if (menu) menu.classList.remove('active');
                if (icon) icon.style.transform = 'rotate(0deg)';
            }
        });

        // ===== NAVBAR SCROLL EFFECT =====
        (function() {
            const navbar = document.getElementById('navbar');
            let lastScroll = 0;

            window.addEventListener('scroll', function() {
                const currentScroll = window.pageYOffset;

                if (currentScroll > 50) {
                    navbar.classList.add('bg-white/95', 'backdrop-blur-xl', 'shadow-lg');
                } else {
                    navbar.classList.remove('bg-white/95', 'backdrop-blur-xl', 'shadow-lg');
                }

                lastScroll = currentScroll;
            });
        })();

        // ===== KEYBOARD NAVIGATION FOR QUANTITY =====
        document.addEventListener('keydown', function(e) {
            if (e.target.id === 'qtyInput') {
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    updateQty(1);
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    updateQty(-1);
                }
            }
        });

        // ===== PREVENT FORM RESUBMISSION ON REFRESH =====
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // ===== AUTO-HIDE SUCCESS MESSAGE =====
        (function() {
            const successMsg = document.querySelector('.bg-emerald-50.text-emerald-700');
            if (successMsg) {
                setTimeout(() => {
                    successMsg.style.transition = 'opacity 0.5s ease';
                    successMsg.style.opacity = '0';
                    setTimeout(() => successMsg.remove(), 500);
                }, 5000);
            }
        })();

        // ===== LAZY LOAD IMAGES =====
        (function() {
            const images = document.querySelectorAll('img[data-src]');
            const imageObserver = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const img = entry.target;
                        img.src = img.dataset.src;
                        img.removeAttribute('data-src');
                        observer.unobserve(img);
                    }
                });
            });

            images.forEach(img => imageObserver.observe(img));
        })();

        // ===== SMOOTH SCROLL FOR ANCHOR LINKS =====
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (href !== '#') {
                    e.preventDefault();
                    const target = document.querySelector(href);
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                }
            });
        });

        // ===== COPY TO CLIPBOARD (for sharing) =====
        function copyLink() {
            const url = window.location.href;
            navigator.clipboard.writeText(url).then(() => {
                showToast('Link produk disalin!');
            }).catch(() => {
                showToast('Gagal menyalin link', true);
            });
        }

        // ===== IMAGE ZOOM ON CLICK =====
        (function() {
            const mainImage = document.getElementById('mainImage');
            if (mainImage) {
                mainImage.addEventListener('click', function() {
                    this.classList.toggle('scale-110');
                });
            }
        })();

        // ===== CONFIRM BEFORE LEAVING WITH UNSAVED CHANGES =====
        let formChanged = false;
        const reviewForm = document.querySelector('form[method="POST"]');
        if (reviewForm) {
            reviewForm.addEventListener('input', () => formChanged = true);
            reviewForm.addEventListener('submit', () => formChanged = false);
        }

        window.addEventListener('beforeunload', function(e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    </script>
</body>
</html>