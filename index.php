<?php
session_start();
require_once 'koneksi.php';

$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $isLoggedIn ? ($_SESSION['user_role'] ?? 'pembeli') : null;
$userName = $isLoggedIn ? ($_SESSION['nama'] ?? 'Pengguna') : null;
$userFoto = $isLoggedIn ? ($_SESSION['foto_profil'] ?? null) : null;

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

try {
    $stmtProduk = $pdo->query("
        SELECT id, nama, kategori, harga, stok, gambar 
        FROM produk 
        ORDER BY id DESC 
    ");
    $produkUnggulan = $stmtProduk->fetchAll();

    $stmtTotal = $pdo->query("SELECT COUNT(*) FROM produk");
    $totalProduk = $stmtTotal->fetchColumn();

    $stmtKat = $pdo->query("SELECT COUNT(DISTINCT kategori) FROM produk");
    $totalKategori = $stmtKat->fetchColumn();

    $stmtKatPop = $pdo->query("
        SELECT kategori, COUNT(*) as jumlah 
        FROM produk 
        GROUP BY kategori 
        ORDER BY jumlah DESC 
        LIMIT 6
    ");
    $kategoriPopuler = $stmtKatPop->fetchAll();

} catch (PDOException $e) {
    $produkUnggulan = [];
    $totalProduk = 0;
    $totalKategori = 0;
    $kategoriPopuler = [];
}

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
function getProfilePic($foto) {
    if (!empty($foto) && file_exists('uploads/profil/' . $foto)) {
        return 'uploads/profil/' . $foto;
    }
    return 'https://placehold.co/100x100/e2e8f0/94a3b8?text=' . urlencode(substr($foto ?? 'U', 0, 1));
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Etanimart - Solusi Pertanian Modern dengan AI</title>
    <meta name="description" content="Etanimart - Platform e-commerce pertanian dengan deteksi penyakit tanaman berbasis AI.">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        html { scroll-behavior: smooth; }
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        /* ===== HORIZONTAL SCROLL PRODUCTS - 1 BARIS ===== */
        .product-scroll-container{
    display:flex;
    overflow-x:auto;
    gap:18px;
    scroll-behavior:smooth;
    padding-bottom:8px;

    scrollbar-width:none;
    -ms-overflow-style:none;
}

.product-scroll-container::-webkit-scrollbar{
    display:none;
}
        .product-scroll-item{

flex:0 0 calc((100% - 100px)/6);

min-width:180px;

}
        @media (max-width: 1400px) { .product-scroll-item { flex: 0 0 calc(16.66% - 1.1rem); min-width: 180px; } }
        @media (max-width: 1200px) { .product-scroll-item { flex: 0 0 calc(20% - 1.1rem); min-width: 170px; } }
        @media (max-width: 992px) { .product-scroll-item { flex: 0 0 calc(25% - 1.1rem); min-width: 160px; } }
        @media (max-width: 768px) { .product-scroll-item { flex: 0 0 calc(33.33% - 1.1rem); min-width: 150px; } }
        @media (max-width: 480px) { .product-scroll-item { flex: 0 0 calc(50% - 0.75rem); min-width: 140px; } }

        @keyframes float { 0%, 100% { transform: translateY(0px); } 50% { transform: translateY(-20px); } }
        .animate-float { animation: float 6s ease-in-out infinite; }
        @keyframes slide-up { from { opacity: 0; transform: translateY(40px); } to { opacity: 1; transform: translateY(0); } }
        .animate-slide-up { opacity: 0; animation: slide-up 0.8s ease-out forwards; }
        .delay-100 { animation-delay: 0.1s; }
        .delay-200 { animation-delay: 0.2s; }
        .delay-300 { animation-delay: 0.3s; }
        .delay-400 { animation-delay: 0.4s; }

        .glass-card { background: rgba(255,255,255,0.7); backdrop-filter: blur(20px); border: 1px solid rgba(255,255,255,0.3); }
        .product-card { transition: all 0.4s cubic-bezier(0.4,0,0.2,1); background: white; }
        .product-card:hover { transform: translateY(-6px); box-shadow: 0 20px 40px -12px rgba(0,0,0,0.15); }
        .product-card:hover .card-img { transform: scale(1.08); }
        .card-img { transition: transform 0.6s cubic-bezier(0.4,0,0.2,1); }
        .feature-icon { transition: all 0.3s ease; }
        .feature-card:hover .feature-icon { transform: scale(1.1) rotate(5deg); background: #10b981; color: white; }

        .nav-link { position: relative; }
        .nav-link::after { content: ''; position: absolute; bottom: -4px; left: 0; width: 0; height: 2px; background: #10b981; transition: width 0.3s ease; }
        .nav-link:hover::after { width: 100%; }

        .btn-primary { background: linear-gradient(135deg, #10b981 0%, #059669 100%); transition: all 0.3s ease; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px -10px rgba(16,185,129,0.5); }
        .cat-pill { transition: all 0.3s ease; }
        .cat-pill:hover { transform: translateY(-2px); box-shadow: 0 8px 20px -8px rgba(16,185,129,0.3); }

        .reveal { opacity: 0; transform: translateY(30px); transition: all 0.8s ease-out; }
        .reveal.active { opacity: 1; transform: translateY(0); }
        .scroll-btn { transition: all 0.3s ease; }
        .scroll-btn:hover { background: #10b981; color: white; border-color: #10b981; }

        .user-dropdown { position: relative; }
        .user-dropdown-menu { position: absolute; top: calc(100% + 8px); right: 0; min-width: 240px; background: white; border-radius: 16px; box-shadow: 0 20px 40px -10px rgba(0,0,0,0.15); border: 1px solid #e2e8f0; opacity: 0; visibility: hidden; transform: translateY(-10px) scale(0.95); transition: all 0.25s cubic-bezier(0.4,0,0.2,1); z-index: 100; overflow: hidden; }
        .user-dropdown-menu.active { opacity: 1; visibility: visible; transform: translateY(0) scale(1); }
        .user-dropdown-item { display: flex; align-items: center; gap: 12px; padding: 12px 16px; color: #374151; font-size: 14px; font-weight: 500; transition: all 0.2s ease; text-decoration: none; }
        .user-dropdown-item:hover { background: #f0fdf4; color: #059669; }
        .user-dropdown-item i { width: 20px; text-align: center; color: #10b981; }
        .user-dropdown-divider { height: 1px; background: #e2e8f0; margin: 4px 12px; }

        #mobile-menu { transition: all 0.3s cubic-bezier(0.4,0,0.2,1); transform-origin: top; }
        #mobile-menu:not(.hidden) { animation: slideDown 0.3s ease-out; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
        #menuIcon { transition: transform 0.3s ease; }





        @media (max-width: 768px) {
    .product-scroll-container{
        gap: 12px;
        padding: 0 12px; /* INI penting biar ga nempel pinggir */
        scroll-snap-type: x mandatory;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    .product-scroll-item{
        flex: 0 0 calc(50% - 6px); /* FIX: 2 item full presisi */
        min-width: 0; /* hilangin paksa lebar */
        scroll-snap-align: start;
    }

    .product-card{
        height: 100%;
        display: flex;
        flex-direction: column;
    }
}

.product-card .aspect-\[4\/3\]{
    aspect-ratio: 4/3;
}

.product-card img{
    width:100%;
    height:100%;
    object-fit:cover;
}

.product-card{
    height: 100%;
    display:flex;
    flex-direction:column;
}

.product-card .p-5{
    flex:1;
    display:flex;
    flex-direction:column;
    justify-content:space-between;
}
.product-card .p-5{
    padding:14px;
}

.product-card h3{
    font-size:13px;
}

.product-card p{
    font-size:14px;
}



@media(max-width:768px){

#beranda{
    min-height:auto;
    padding-top:90px;
    padding-bottom:50px;
}

#beranda h1{
    font-size:30px;
    line-height:1.2;
}

#beranda p{
    font-size:14px;
}

}
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

    <!-- ==================== HERO SECTION ==================== -->
    <section id="beranda" class="relative min-h-[55vh] flex items-center pt-16 overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-emerald-50 via-white to-teal-50"></div>
        <div class="absolute top-0 right-0 w-[600px] h-[600px] bg-emerald-200/20 rounded-full blur-3xl -translate-y-1/2 translate-x-1/4"></div>
        <div class="absolute bottom-0 left-0 w-[500px] h-[500px] bg-teal-200/20 rounded-full blur-3xl translate-y-1/4 -translate-x-1/4"></div>
        <div class="relative z-10 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 w-full py-8">
            <div class="grid lg:grid-cols-2 gap-8 lg:gap-12 items-center">
                <div class="space-y-2 text-center lg:text-left">
                    <div class="animate-slide-up inline-flex items-center gap-2 px-4 py-2 bg-white rounded-full shadow-md border border-emerald-100">
                        <span class="w-2 h-2 bg-emerald-500 rounded-full animate-pulse"></span>
                        <span class="text-sm font-semibold text-emerald-700">AI Detection System v2.0</span>
                    </div>
                    <h1 class="animate-slide-up delay-100 text-3xl sm:text-4xl lg:text-5xl font-extrabold text-gray-900 leading-[1.15] tracking-tight">
                        Deteksi Penyakit <br>
                        <span class="text-transparent bg-clip-text bg-gradient-to-r from-emerald-600 to-teal-500">Tanaman dengan AI</span>
                    </h1>
                    <p class="animate-slide-up delay-200 text-base text-gray-600 max-w-xl mx-auto lg:mx-0 leading-relaxed">
                        Ambil foto gejala, jawab kuesioner singkat, dan biarkan AI kami menganalisis masalah tanaman Anda. Dapatkan rekomendasi obat tepat sasaran dari katalog terpercaya.
                    </p>
                    <div class="animate-slide-up delay-300 flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
                        <a href="scan.php" class="group btn-primary text-white px-6 py-3 rounded-xl font-bold text-base shadow-lg shadow-emerald-200 flex items-center justify-center gap-2">
                            <i class="fa-solid fa-camera text-xl group-hover:rotate-12 transition-transform"></i>
                            <span>Mulai Scan Sekarang</span>
                        </a>
                        <a href="produk.php" class="bg-white border-2 border-gray-200 hover:border-emerald-300 text-gray-700 hover:text-emerald-700 px-6 py-3 rounded-xl font-bold text-base flex items-center justify-center gap-2 transition-all hover:shadow-lg">
                            <i class="fa-solid fa-shop"></i>
                            <span>Jelajahi Katalog</span>
                        </a>
                    </div>
                    <div class="animate-slide-up delay-400 flex items-center gap-5 justify-center lg:justify-start pt-2">
                        <div class="text-center">
                            <div class="text-xl font-bold text-gray-900" data-count="<?= $totalProduk ?>">0</div>
                            <div class="text-xs text-gray-500 font-medium">Produk</div>
                        </div>
                        <div class="w-px h-10 bg-gray-200"></div>
                        <div class="text-center">
                            <div class="text-xl font-bold text-gray-900" data-count="<?= $totalKategori ?>">0</div>
                            <div class="text-xs text-gray-500 font-medium">Kategori</div>
                        </div>
                        <div class="w-px h-10 bg-gray-200"></div>
                        <div class="text-center">
                            <div class="text-2xl font-bold text-gray-900">98%</div>
                            <div class="text-xs text-gray-500 font-medium">Akurasi AI</div>
                        </div>
                    </div>
                </div>
                <div class="relative hidden lg:block">
    <div class="animate-float relative z-10">
        <div class="glass-card rounded-3xl p-3 shadow-xl max-w-sm mx-auto">

            <!-- IMAGE diperkecil -->
            <div class="relative rounded-2xl overflow-hidden aspect-[4/3] bg-gradient-to-br from-emerald-100 to-teal-50 flex items-center justify-center mb-3">
                <img src="https://images.unsplash.com/photo-1530836369250-ef72a3f5cda8?w=500&h=500&fit=crop"
                     alt="Plant Analysis"
                     class="w-full h-full object-cover"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">

                <div class="hidden absolute inset-0 items-center justify-center">
                    <i class="fa-solid fa-leaf text-6xl text-emerald-300"></i>
                </div>

                <div class="absolute inset-3 border-2 border-dashed border-emerald-400/50 rounded-xl animate-pulse"></div>

                <div class="absolute top-3 left-3 bg-white/90 backdrop-blur px-2 py-1 rounded-lg shadow-sm">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 bg-red-500 rounded-full animate-pulse"></span>
                        <span class="text-[10px] font-bold text-gray-700">Terdeteksi</span>
                    </div>
                </div>
            </div>

            <!-- CONTENT dipadatkan -->
            <div class="space-y-2">

                <div class="flex items-center gap-2 p-2 bg-red-50 rounded-xl border border-red-100">
                    <div class="w-8 h-8 bg-red-100 rounded-lg flex items-center justify-center text-red-600 shrink-0">
                        <i class="fa-solid fa-triangle-exclamation text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-800">Bercak Daun</p>
                        <p class="text-[10px] text-gray-500">Alternaria solani</p>
                    </div>
                </div>

                <div class="flex items-center gap-2 p-2 bg-emerald-50 rounded-xl border border-emerald-100">
                    <div class="w-8 h-8 bg-emerald-100 rounded-lg flex items-center justify-center text-emerald-600 shrink-0">
                        <i class="fa-solid fa-prescription-bottle-medical text-sm"></i>
                    </div>
                    <div>
                        <p class="text-xs font-bold text-gray-800">Fungisida</p>
                        <p class="text-[10px] text-gray-500">3 produk</p>
                    </div>
                </div>

            </div>

        </div>
    </div>
</div>
            </div>
        </div>
      
    </section>

    <!-- ==================== FEATURED PRODUCTS (HORIZONTAL SCROLL - 1 BARIS) ==================== -->
    <section id="produk" class="py-3 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-end justify-between mb-8 reveal">
                <div>
                    <span class="inline-block px-4 py-1.5 bg-emerald-50 text-emerald-700 rounded-full text-xs font-bold uppercase tracking-wider mb-4">Produk Unggulan</span>
                    <h2 class="text-3xl md:text-4xl font-bold text-gray-900">Pilihan Terbaik</h2>
                    <p class="text-gray-500 mt-2">Geser untuk melihat lebih banyak produk</p>
                </div>
              
            </div>

            <?php if (!empty($produkUnggulan)): ?>

                <?php
$rows = array_chunk($produkUnggulan, 10);

foreach($rows as $index => $row):
?>

<div class="relative mb-10">

<button onclick="scrollRow('left', <?= $index ?>)"
class="hidden md:flex absolute -left-5 top-1/2 -translate-y-1/2 z-20 w-10 h-10 rounded-full bg-white shadow">
        <i class="fa-solid fa-chevron-left"></i>
    </button>

    <div class="product-scroll-container"
         id="row<?= $index ?>">

         

        <?php foreach($row as $p): ?>
            <?php
$thumb = getThumb($p['gambar']);
?>
            
                <div class="product-scroll-item">
                    <a href="detail_produk.php?id=<?= $p['id'] ?>" class="product-card block rounded-2xl border border-gray-100 overflow-hidden shadow-sm h-full">
                        <div class="aspect-[4/3] overflow-hidden bg-gray-50 relative group">
                            <img src="<?= $thumb ?>" alt="<?= clean($p['nama']) ?>" class="card-img w-full h-full object-cover" onerror="this.src='https://placehold.co/400x300/e2e8f0/94a3b8?text=No+Image'">
                            <div class="absolute inset-0 bg-black/40 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                                <span class="bg-white/90 text-gray-800 px-4 py-2 rounded-full text-xs font-bold">Lihat Detail <i class="fa-solid fa-arrow-right ml-1"></i></span>
                            </div>
                            <?php if ($p['stok'] <= 0): ?>
                                <div class="absolute top-3 left-3 bg-rose-500 text-white px-3 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider">Habis</div>
                            <?php elseif ($p['stok'] <= 5): ?>
                                <div class="absolute top-3 left-3 bg-yellow-500 text-white px-3 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider">Sisa <?= $p['stok'] ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="p-5">
                            <span class="text-[10px] font-bold uppercase tracking-wider text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-md"><?= clean($p['kategori']) ?></span>
                            <h3 class="font-bold text-gray-900 mt-3 mb-2 line-clamp-2 text-sm leading-snug hover:text-emerald-600 transition-colors"><?= clean($p['nama']) ?></h3>
                            <div class="flex items-end justify-between mt-3">
                                <p class="text-lg font-bold text-emerald-600 font-mono"><?= formatRupiah($p['harga']) ?></p>
                                <span class="text-[11px] text-gray-400">Stok: <?= $p['stok'] ?></span>
                            </div>
                        </div>
                    </a>
                </div>
                <?php endforeach; ?>
            </div>

            <button onclick="scrollRow('right', <?= $index ?>)"
    class="hidden md:flex absolute -right-5 top-1/2 -translate-y-1/2 z-20 w-10 h-10 rounded-full bg-white shadow">
        <i class="fa-solid fa-chevron-right"></i>
    </button>

</div>

<?php endforeach; ?>

            <!-- Mobile scroll hint -->
            <div class="md:hidden flex items-center justify-center gap-2 mt-4 text-gray-400 text-xs">
                <i class="fa-solid fa-hand-pointer"></i>
                <span>Geser ke kiri/kanan untuk melihat produk lainnya</span>
            </div>

          
            <?php else: ?>
            <div class="bg-white rounded-2xl border border-gray-100 p-16 text-center shadow-sm reveal">
                <div class="w-20 h-20 bg-gray-50 rounded-full flex items-center justify-center mx-auto mb-4"><i class="fa-solid fa-box-open text-3xl text-gray-300"></i></div>
                <h3 class="text-lg font-bold text-gray-900 mb-2">Belum Ada Produk</h3>
                <p class="text-sm text-gray-500 mb-6">Produk pertanian akan segera tersedia di katalog kami.</p>
                <a href="scan.php" class="inline-flex items-center gap-2 px-6 py-3 bg-emerald-600 text-white rounded-xl font-semibold hover:bg-emerald-700 transition-colors"><i class="fa-solid fa-camera"></i> Coba Fitur Scan AI</a>
            </div>
            <?php endif; ?>
        </div>
    </section>

    
    <!-- ==================== HOW IT WORKS ==================== -->
    <section class="py-10 bg-gradient-to-b from-gray-50 to-white">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center max-w-2xl mx-auto mb-16 reveal">
                <span class="inline-block px-4 py-1.5 bg-emerald-50 text-emerald-700 rounded-full text-xs font-bold uppercase tracking-wider mb-4">Cara Kerja</span>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-4">3 Langkah Mudah</h2>
                <p class="text-gray-600">Dari deteksi hingga pembelian, semua bisa dilakukan dalam hitungan menit.</p>
            </div>
            <div class="grid md:grid-cols-3 gap-8 relative">
                <div class="hidden md:block absolute top-24 left-[20%] right-[20%] h-0.5 bg-gradient-to-r from-emerald-200 via-emerald-400 to-emerald-200"></div>
                <?php 
                $steps = [
                    ['num' => '01', 'icon' => 'fa-camera', 'title' => 'Foto Gejala', 'desc' => 'Ambil foto daun atau batang tanaman yang menunjukkan gejala penyakit.'],
                    ['num' => '02', 'icon' => 'fa-clipboard-question', 'title' => 'Jawab Kuesioner', 'desc' => 'Isi 3 pertanyaan singkat yang dibuat AI sesuai gejala visual.'],
                    ['num' => '03', 'icon' => 'fa-cart-shopping', 'title' => 'Beli Obat', 'desc' => 'Dapatkan diagnosis dan rekomendasi obat. Langsung beli dari katalog.'],
                ];
                foreach ($steps as $idx => $s): 
                ?>
                <div class="reveal relative text-center" style="transition-delay: <?= $idx * 150 ?>ms">
                    <div class="w-20 h-20 bg-white rounded-2xl shadow-lg border-2 border-emerald-100 flex items-center justify-center mx-auto mb-6 relative z-10">
                        <span class="absolute -top-3 -right-3 w-8 h-8 bg-emerald-600 text-white rounded-full flex items-center justify-center text-xs font-bold"><?= $s['num'] ?></span>
                        <i class="fa-solid <?= $s['icon'] ?> text-2xl text-emerald-600"></i>
                    </div>
                    <h3 class="text-xl font-bold text-gray-900 mb-3"><?= $s['title'] ?></h3>
                    <p class="text-sm text-gray-600 leading-relaxed max-w-xs mx-auto"><?= $s['desc'] ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

   



    <!-- ==================== ABOUT SECTION ==================== -->
    <section id="tentang" class="py-10 bg-gray-50">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid lg:grid-cols-2 gap-16 items-center">
                <div class="reveal">
                    <span class="inline-block px-4 py-1.5 bg-emerald-50 text-emerald-700 rounded-full text-xs font-bold uppercase tracking-wider mb-4">Tentang Kami</span>
                    <h2 class="text-3xl md:text-4xl font-bold text-gray-900 mb-6 leading-tight">Mendigitalkan Sektor Pertanian <span class="text-emerald-600">Indonesia</span></h2>
                    <p class="text-gray-600 leading-relaxed mb-6"><strong>Etanimart</strong> hadir sebagai platform inovatif yang menggabungkan teknologi AI dengan e-commerce pertanian. Kami memahami bahwa masalah salah diagnosis tanaman sering berujung pada gagal panen dan kerugian finansial.</p>
                    <p class="text-gray-600 leading-relaxed mb-8">Dengan sistem deteksi penyakit berbasis computer vision, kami membantu petani mengidentifikasi masalah tanaman secara instan dan menyediakan akses ke produk penanganan yang tepat sasaran.</p>
                    <div class="grid grid-cols-2 gap-6">
                        <?php 
                        $abouts = [
                            ['icon' => 'fa-check', 'title' => 'Diagnosis Cepat', 'desc' => 'Hasil dalam hitungan detik'],
                            ['icon' => 'fa-check', 'title' => 'Produk Terjamin', 'desc' => 'Berkualitas & original'],
                            ['icon' => 'fa-check', 'title' => 'Harga Kompetitif', 'desc' => 'Langsung dari supplier'],
                            ['icon' => 'fa-check', 'title' => 'Support 24/7', 'desc' => 'Tim siap membantu'],
                        ];
                        foreach ($abouts as $a): 
                        ?>
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center text-emerald-600 shrink-0"><i class="fa-solid <?= $a['icon'] ?>"></i></div>
                            <div><p class="font-bold text-gray-900 text-sm"><?= $a['title'] ?></p><p class="text-xs text-gray-500"><?= $a['desc'] ?></p></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="reveal relative">
                    <div class="absolute inset-0 bg-emerald-200 rounded-3xl rotate-3 opacity-20"></div>
                    <div class="relative bg-white rounded-3xl p-8 shadow-xl border border-gray-100">
                        <img src="https://images.unsplash.com/photo-1625246333195-78d9c38ad449?w=600&h=400&fit=crop" alt="Farming" class="w-full h-64 object-cover rounded-2xl mb-6" onerror="this.style.display='none'">
                        <div class="grid grid-cols-3 gap-4 text-center">
                            <div><div class="text-2xl font-bold text-emerald-600" data-count="1500">0</div><div class="text-xs text-gray-500">Petani Terbantu</div></div>
                            <div><div class="text-2xl font-bold text-emerald-600" data-count="98">0</div><div class="text-xs text-gray-500">% Akurasi AI</div></div>
                            <div><div class="text-2xl font-bold text-emerald-600" data-count="24">0</div><div class="text-xs text-gray-500">Jam Support</div></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ==================== FAQ SECTION ==================== -->
    <section class="py-10 bg-white">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="text-center mb-16 reveal">
                <span class="inline-block px-4 py-1.5 bg-emerald-50 text-emerald-700 rounded-full text-xs font-bold uppercase tracking-wider mb-4">FAQ</span>
                <h2 class="text-3xl md:text-4xl font-bold text-gray-900">Pertanyaan Umum</h2>
            </div>
            <div class="space-y-4 reveal">
                <?php 
                $faqs = [
                    ['q' => 'Bagaimana cara kerja fitur Scan AI?', 'a' => 'Ambil foto gejala pada daun/batang tanaman, upload ke sistem, dan jawab 3 pertanyaan singkat yang dibuat AI. Sistem akan menganalisis gambar dan jawaban Anda untuk memberikan diagnosis serta rekomendasi obat yang tepat.'],
                    ['q' => 'Apakah hasil scan AI akurat?', 'a' => 'Sistem kami menggunakan model AI canggih dengan tingkat akurasi hingga 98%. Namun, untuk kasus serius kami tetap menyarankan konsultasi dengan ahli pertanian.'],
                    ['q' => 'Bagaimana cara membeli produk?', 'a' => 'Anda bisa menjelajahi katalog produk kami, menambahkan ke keranjang, dan melakukan checkout. Kami menerima berbagai metode pembayaran.'],
                    ['q' => 'Berapa lama pengiriman produk?', 'a' => 'Pengiriman biasanya memakan waktu 1-3 hari kerja untuk area Jawa dan 3-7 hari untuk luar Jawa.'],
                    ['q' => 'Apakah bisa memberikan ulasan produk?', 'a' => 'Ya! Setelah login, Anda dapat memberikan rating dan komentar pada produk yang pernah dibeli.'],
                ];
                foreach ($faqs as $faq): 
                ?>
                <div class="border border-gray-100 rounded-2xl overflow-hidden">
                    <button onclick="toggleFaq(this)" class="w-full flex items-center justify-between p-5 text-left hover:bg-gray-50 transition-colors">
                        <span class="font-semibold text-gray-800 text-sm pr-4"><?= $faq['q'] ?></span>
                        <i class="fa-solid fa-chevron-down text-gray-400 transition-transform shrink-0"></i>
                    </button>
                    <div class="hidden px-5 pb-5"><p class="text-sm text-gray-600 leading-relaxed"><?= $faq['a'] ?></p></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

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

    <!-- ==================== SCRIPTS ==================== -->
    <script>
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

        document.querySelectorAll('#mobile-menu a').forEach(link => {
            link.addEventListener('click', () => { closeMobileMenu(); });
        });

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
            document.querySelectorAll('.user-dropdown-menu').forEach(m => m.classList.remove('active'));
            document.querySelectorAll('#userDropdownIcon').forEach(i => i.style.transform = 'rotate(0deg)');
            if (!isActive) {
                menu.classList.add('active');
                icon.style.transform = 'rotate(180deg)';
            }
        }

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

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.user-dropdown-menu').forEach(m => m.classList.remove('active'));
                document.querySelectorAll('#userDropdownIcon').forEach(i => i.style.transform = 'rotate(0deg)');
                closeMobileMenu();
            }
        });

        // ===== HORIZONTAL SCROLL PRODUCTS - GESER KIRI/KANAN =====
        function scrollRow(direction,row){

const container=document.getElementById("row"+row);

const item=container.querySelector(".product-scroll-item");

const amount=item.offsetWidth*3;

container.scrollBy({

    left:direction==="left"?-amount:amount,

    behavior:"smooth"

});

}

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

        // ===== COUNTER ANIMATION =====
        const counters = document.querySelectorAll('[data-count]');
        const counterObserver = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    const target = parseInt(entry.target.dataset.count);
                    const duration = 2000;
                    const step = target / (duration / 16);
                    let current = 0;
                    const timer = setInterval(() => {
                        current += step;
                        if (current >= target) {
                            entry.target.textContent = target.toLocaleString('id-ID');
                            clearInterval(timer);
                        } else {
                            entry.target.textContent = Math.floor(current).toLocaleString('id-ID');
                        }
                    }, 16);
                    counterObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.5 });
        counters.forEach(c => counterObserver.observe(c));

        // ===== FAQ TOGGLE =====
        function toggleFaq(btn) {
            const content = btn.nextElementSibling;
            const icon = btn.querySelector('i');
            const isHidden = content.classList.contains('hidden');
            document.querySelectorAll('.border.rounded-2xl > div').forEach(div => { div.classList.add('hidden'); });
            document.querySelectorAll('.border.rounded-2xl button i').forEach(i => { i.style.transform = 'rotate(0deg)'; });
            if (isHidden) {
                content.classList.remove('hidden');
                icon.style.transform = 'rotate(180deg)';
            }
        }
    </script>
</body>
</html>