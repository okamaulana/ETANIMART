<?php
// =============================================================================
// profil.php - Halaman Profil Pembeli
// =============================================================================
session_start();
require_once 'koneksi.php';

// Proteksi: hanya pembeli
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'pembeli') {
    header("Location: login.php");
    exit();
}

$userId = (int)$_SESSION['user_id'];



// ==========================================
// AMBIL DATA PEMBELI
// ==========================================
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND role = 'pembeli'");
$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    header("Location: login.php");
    exit();
}


// ==========================================
// STATISTIK PEMBELI
// ==========================================
try {
    // Total pesanan
    $stmtTotalPesanan = $pdo->prepare("SELECT COUNT(*) FROM pesanan WHERE id_user = ?");
    $stmtTotalPesanan->execute([$userId]);
    $totalPesanan = (int)$stmtTotalPesanan->fetchColumn();

    // Total belanja
    $stmtTotalBelanja = $pdo->prepare("SELECT COALESCE(SUM(total_harga), 0) FROM pesanan WHERE id_user = ? AND status IN ('paid', 'shipped', 'completed')");
    $stmtTotalBelanja->execute([$userId]);
    $totalBelanja = (float)$stmtTotalBelanja->fetchColumn();

    // Pesanan selesai
    $stmtSelesai = $pdo->prepare("SELECT COUNT(*) FROM pesanan WHERE id_user = ? AND status = 'completed'");
    $stmtSelesai->execute([$userId]);
    $pesananSelesai = (int)$stmtSelesai->fetchColumn();

    // Pesanan pending
    $stmtPending = $pdo->prepare("SELECT COUNT(*) FROM pesanan WHERE id_user = ? AND status = 'pending'");
    $stmtPending->execute([$userId]);
    $pesananPending = (int)$stmtPending->fetchColumn();

} catch (PDOException $e) {
    $totalPesanan = 0;
    $totalBelanja = 0;
    $pesananSelesai = 0;
    $pesananPending = 0;
}

// ==========================================
// PROSES UPDATE PROFIL
// ==========================================
$flash_msg = '';
$flash_type = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = trim($_POST['nama'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $no_telepon = trim($_POST['no_telepon'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $password_lama = $_POST['password_lama'] ?? '';
    $password_baru = $_POST['password_baru'] ?? '';
    $password_konfirmasi = $_POST['password_konfirmasi'] ?? '';

    if (empty($nama) || empty($email)) {
        $flash_msg = 'Nama dan email wajib diisi.';
        $flash_type = 'error';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $flash_msg = 'Format email tidak valid.';
        $flash_type = 'error';
    } else {
        try {
            // Cek email sudah dipakai orang lain?
            $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmtCheck->execute([$email, $userId]);
            if ($stmtCheck->fetch()) {
                $flash_msg = 'Email sudah digunakan oleh pengguna lain.';
                $flash_type = 'error';
            } else {
                // Update data dasar
                $stmtUpdate = $pdo->prepare("
                    UPDATE users 
                    SET nama = ?, email = ?, no_telepon = ?, alamat = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$nama, $email, $no_telepon, $alamat, $userId]);

                // Update session
                $_SESSION['nama'] = $nama;

                // Update password kalau diisi
                if (!empty($password_baru)) {
                    if (empty($password_lama)) {
                        $flash_msg = 'Password lama harus diisi untuk mengubah password.';
                        $flash_type = 'error';
                    } elseif (!password_verify($password_lama, $user['password'])) {
                        $flash_msg = 'Password lama tidak sesuai.';
                        $flash_type = 'error';
                    } elseif ($password_baru !== $password_konfirmasi) {
                        $flash_msg = 'Password baru dan konfirmasi tidak cocok.';
                        $flash_type = 'error';
                    } elseif (strlen($password_baru) < 6) {
                        $flash_msg = 'Password baru minimal 6 karakter.';
                        $flash_type = 'error';
                    } else {
                        $hash = password_hash($password_baru, PASSWORD_DEFAULT);
                        $stmtPass = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
                        $stmtPass->execute([$hash, $userId]);
                        $flash_msg = 'Profil dan password berhasil diperbarui!';
                        $flash_type = 'success';
                    }
                } else {
                    $flash_msg = 'Profil berhasil diperbarui!';
                    $flash_type = 'success';
                }

                // Refresh data
                $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $user = $stmt->fetch();
            }
        } catch (PDOException $e) {
            $flash_msg = 'Terjadi kesalahan: ' . $e->getMessage();
            $flash_type = 'error';
        }
    }
}

// ==========================================
// AMBIL FLASH DARI SESSION (upload foto)
// ==========================================
if (isset($_SESSION['flash'])) {
    $flash_msg = $_SESSION['flash']['msg'];
    $flash_type = $_SESSION['flash']['type'];
    unset($_SESSION['flash']);
}

// ==========================================
// HELPERS
// ==========================================
function clean($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function formatRupiah($angka) {
    return 'Rp ' . number_format((int)($angka ?? 0), 0, ',', '.');
}
function getProfilePic($foto) {
    if (!empty($foto) && file_exists('uploads/profil/' . $foto)) {
        return 'uploads/profil/' . $foto;
    }
    return 'https://placehold.co/120x120/e2e8f0/94a3b8?text=' . urlencode(substr($foto ?? 'U', 0, 1));
}

$currentPage = 'profil';

// Hitung total item keranjang untuk badge navbar
$totalKeranjang = 0;
try {
    $stmtCart = $pdo->prepare("SELECT COALESCE(SUM(jumlah), 0) as total FROM keranjang WHERE id_user = ?");
    $stmtCart->execute([$userId]);
    $totalKeranjang = (int)$stmtCart->fetchColumn();
} catch (PDOException $e) {
    $totalKeranjang = 0;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Profil Saya - eTanimart</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        .card-hover { transition: all 0.3s ease; }
        .card-hover:hover { transform: translateY(-4px); box-shadow: 0 20px 40px -12px rgba(0,0,0,0.12); }

        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(12px); } to { opacity: 1; transform: translateY(0); } }

        /* Modal */
        .modal-overlay {
            opacity: 0; visibility: hidden;
            transition: all 0.3s ease;
        }
        .modal-overlay.active {
            opacity: 1; visibility: visible;
        }
        .modal-content {
            transform: scale(0.95) translateY(10px);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .modal-overlay.active .modal-content {
            transform: scale(1) translateY(0);
        }

        /* Navbar same as index */
        .nav-link { position: relative; }
        .nav-link::after {
            content: ''; position: absolute; bottom: -4px; left: 0;
            width: 0; height: 2px; background: #10b981; transition: width 0.3s ease;
        }
        .nav-link:hover::after { width: 100%; }

        .user-dropdown-menu {
            position: absolute; top: calc(100% + 8px); right: 0; min-width: 240px;
            background: white; border-radius: 16px;
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
            padding: 12px 16px; color: #374151; font-size: 14px; font-weight: 500;
            transition: all 0.2s ease; text-decoration: none;
        }
        .user-dropdown-item:hover { background: #f0fdf4; color: #059669; }
        .user-dropdown-item i { width: 20px; text-align: center; color: #10b981; }
        .user-dropdown-divider { height: 1px; background: #e2e8f0; margin: 4px 12px; }

        #mobile-menu {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: top;
        }
        #mobile-menu:not(.hidden) { animation: slideDown 0.3s ease-out; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased overflow-x-hidden">

    <!-- ==================== NAVBAR ==================== -->
    <nav class="fixed top-0 left-0 right-0 z-50 bg-white/95 backdrop-blur-xl shadow-sm" id="navbar">
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
                    <a href="index.php" class="nav-link text-gray-600 hover:text-emerald-600 transition-colors">Beranda</a>
                    <a href="scan.php" class="nav-link text-gray-600 hover:text-emerald-600 transition-colors flex items-center gap-1.5">
                        <i class="fa-solid fa-qrcode text-emerald-500"></i> Scan AI
                    </a>
                    <a href="produk.php" class="nav-link text-gray-600 hover:text-emerald-600 transition-colors">Katalog</a>
                   
                    <a href="index.php#tentang" class="nav-link text-gray-600 hover:text-emerald-600 transition-colors">Tentang</a>
                </div>

                <!-- Right Side Actions -->
                <div class="flex items-center gap-2 sm:gap-4">
                    <!-- Cart Icon (Desktop only) -->
                    <a href="keranjang.php" class="hidden lg:flex relative p-2.5 text-gray-600 hover:text-emerald-600 transition-colors rounded-xl hover:bg-emerald-50">
                        <i class="fa-solid fa-cart-shopping text-lg"></i>
                        <span class="absolute -top-1 -right-1 w-5 h-5 bg-emerald-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center <?= $totalKeranjang > 0 ? '' : 'hidden' ?>"><?= $totalKeranjang ?></span>
                    </a>

                    <!-- User Profile Dropdown (Desktop only) -->
                    <div class="user-dropdown relative hidden lg:block">
                        <button class="flex items-center gap-2 sm:gap-3 pl-2 pr-1 sm:pr-2 py-1.5 rounded-full hover:bg-gray-100 transition-colors" onclick="toggleUserDropdown(event)">
                            <img src="<?= getProfilePic($user['foto_profil']) ?>" alt="<?= clean($user['nama']) ?>" class="w-9 h-9 rounded-full object-cover border-2 border-emerald-200">
                            <div class="hidden sm:flex flex-col items-start">
                                <span class="text-sm font-bold text-gray-800 max-w-[100px] truncate leading-tight"><?= clean($user['nama']) ?></span>
                                <span class="text-[10px] text-gray-400 font-medium leading-tight">Pembeli</span>
                            </div>
                            <i class="fa-solid fa-chevron-down text-xs text-gray-400 mr-1 transition-transform duration-200" id="userDropdownIcon"></i>
                        </button>

                        <!-- Dropdown Menu -->
                        <div class="user-dropdown-menu" id="userDropdownMenu">
                            <div class="px-4 py-3 border-b border-gray-100">
                                <p class="text-sm font-bold text-gray-800"><?= clean($user['nama']) ?></p>
                                <p class="text-xs text-gray-500">Pembeli</p>
                            </div>
                            <a href="profil.php" class="user-dropdown-item bg-emerald-50 text-emerald-700">
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
                <!-- Mobile: User Profile Card (Top) -->
                <div class="bg-gradient-to-r from-emerald-50 to-teal-50 rounded-2xl p-4 mb-4 border border-emerald-100">
                    <div class="flex items-center gap-4">
                        <img src="<?= getProfilePic($user['foto_profil']) ?>" alt="<?= clean($user['nama']) ?>" class="w-14 h-14 rounded-full object-cover border-2 border-emerald-300 shadow-sm">
                        <div class="flex-1 min-w-0">
                            <p class="text-base font-bold text-gray-900 truncate"><?= clean($user['nama']) ?></p>
                            <p class="text-xs text-emerald-600 font-medium flex items-center gap-1">
                                <i class="fa-solid fa-circle-check text-[10px]"></i> Pembeli
                            </p>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mt-4">
                        <a href="profil.php" class="flex flex-col items-center gap-1 py-2 bg-emerald-100 rounded-xl border border-emerald-200 transition-colors">
                            <i class="fa-solid fa-user text-emerald-700 text-sm"></i>
                            <span class="text-[10px] font-semibold text-emerald-700">Profil</span>
                        </a>
                        <a href="pesanan.php" class="flex flex-col items-center gap-1 py-2 bg-white rounded-xl border border-emerald-100 hover:border-emerald-300 transition-colors">
                            <i class="fa-solid fa-bag-shopping text-emerald-600 text-sm"></i>
                            <span class="text-[10px] font-semibold text-gray-600">Pesanan</span>
                        </a>
                        <a href="keranjang.php" class="flex flex-col items-center gap-1 py-2 bg-white rounded-xl border border-emerald-100 hover:border-emerald-300 transition-colors relative">
                            <i class="fa-solid fa-cart-shopping text-emerald-600 text-sm"></i>
                            <span class="text-[10px] font-semibold text-gray-600">Keranjang</span>
                            <span class="absolute -top-1 -right-1 w-4 h-4 bg-emerald-500 text-white text-[8px] font-bold rounded-full flex items-center justify-center <?= $totalKeranjang > 0 ? '' : 'hidden' ?>"><?= $totalKeranjang ?></span>
                        </a>
                    </div>
                </div>

                <!-- Navigation Links -->
                <div class="space-y-1">
                    <p class="px-4 py-2 text-xs font-bold text-gray-400 uppercase tracking-wider">Menu</p>
                    <a href="index.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium transition-all text-gray-700 hover:bg-emerald-50 hover:text-emerald-600">
                        <i class="fa-solid fa-house w-5 text-center text-emerald-500"></i> Beranda
                    </a>
                    <a href="scan.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium transition-all text-gray-700 hover:bg-emerald-50 hover:text-emerald-600">
                        <i class="fa-solid fa-qrcode w-5 text-center text-emerald-500"></i> Scan AI
                    </a>
                    <a href="produk.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium transition-all text-gray-700 hover:bg-emerald-50 hover:text-emerald-600">
                        <i class="fa-solid fa-shop w-5 text-center text-emerald-500"></i> Katalog Produk
                    </a>
                </div>

                <!-- Logout -->
                <div class="pt-4 mt-4 border-t border-gray-100">
                    <a href="logout.php" class="flex items-center gap-3 py-3 px-4 rounded-xl text-red-600 hover:bg-red-50 font-medium transition-all">
                        <i class="fa-solid fa-right-from-bracket w-5 text-center"></i> Keluar Akun
                    </a>
                </div>
            </div>
        </div>
    </nav>

    <!-- ==================== MAIN CONTENT ==================== -->
    <main class="pt-24 pb-16 min-h-screen">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

            <!-- Header -->
            <div class="mb-8">
                <h1 class="text-2xl md:text-3xl font-bold text-gray-900">Profil Saya</h1>
                <p class="text-sm text-gray-500 mt-1">Kelola informasi akun dan keamanan Anda</p>
            </div>

            <!-- Flash Message -->
            <?php if (!empty($flash_msg)): ?>
            <div class="mb-6 p-4 rounded-2xl text-sm border shadow-sm flex items-center gap-3 fade-in <?= $flash_type === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-800' : 'bg-red-50 border-red-200 text-red-800' ?>">
                <div class="w-8 h-8 rounded-xl flex items-center justify-center shrink-0 <?= $flash_type === 'success' ? 'bg-emerald-100 text-emerald-600' : 'bg-red-100 text-red-600' ?>">
                    <i class="fa-solid <?= $flash_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>"></i>
                </div>
                <div class="flex-grow font-medium"><?= clean($flash_msg) ?></div>
            </div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Kolom Kiri: Info Profil & Statistik -->
                <div class="lg:col-span-1 space-y-6">

                    <!-- Card Profil -->
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 text-center card-hover fade-in">
                        <div class="relative inline-block mb-4">
                            <button type="button" onclick="openModal()" class="group cursor-pointer relative">
                                <img src="<?= getProfilePic($user['foto_profil']) ?>" 
                                     alt="Profil" 
                                     class="w-28 h-28 rounded-2xl object-cover border-2 border-gray-100 mx-auto transition-all group-hover:brightness-75">
                                <div class="absolute inset-0 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                    <i class="fa-solid fa-camera text-white text-2xl drop-shadow-lg"></i>
                                </div>
                            </button>
                            <div class="absolute -bottom-1 -right-1 w-7 h-7 bg-emerald-500 rounded-full border-2 border-white flex items-center justify-center">
                                <i class="fa-solid fa-check text-white text-[10px]"></i>
                            </div>
                        </div>
                        <h3 class="text-lg font-bold text-gray-900"><?= clean($user['nama']) ?></h3>
                        <p class="text-sm text-gray-500"><?= clean($user['email']) ?></p>
                        <span class="inline-flex items-center gap-1 px-3 py-1 bg-emerald-50 text-emerald-700 text-xs font-bold rounded-full border border-emerald-200 mt-3">
                            <i class="fa-solid fa-user text-[10px]"></i> Pembeli
                        </span>

                        <div class="mt-6 pt-6 border-t border-gray-50 space-y-3 text-left">
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-500">ID Pengguna</span>
                                <span class="text-xs font-mono font-semibold text-gray-700">#<?= $user['id'] ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-500">No. Telepon</span>
                                <span class="text-xs font-semibold text-gray-700"><?= clean($user['no_telepon'] ?? '-') ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-500">Bergabung</span>
                                <span class="text-xs font-semibold text-gray-700"><?= date('d M Y', strtotime($user['created_at'])) ?></span>
                            </div>
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-500">Terakhir Update</span>
                                <span class="text-xs font-semibold text-gray-700"><?= $user['updated_at'] ? date('d M Y H:i', strtotime($user['updated_at'])) : '-' ?></span>
                            </div>
                        </div>

                        <button type="button" onclick="openModal()" class="mt-5 w-full py-2.5 bg-emerald-50 text-emerald-700 rounded-xl text-sm font-semibold hover:bg-emerald-100 transition-all border border-emerald-200">
                            <i class="fa-solid fa-camera mr-1"></i> Ganti Foto Profil
                        </button>
                    </div>

                    <!-- Statistik Ringkasan -->
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-6 fade-in" style="animation-delay: 0.1s">
                        <h3 class="text-sm font-bold text-gray-900 mb-4 flex items-center gap-2">
                            <i class="fa-solid fa-chart-pie text-emerald-500"></i> Ringkasan Akun
                        </h3>
                        <div class="grid grid-cols-2 gap-3">
                            <div class="bg-gray-50 rounded-xl p-4 text-center border border-gray-100">
                                <p class="text-2xl font-bold text-emerald-600"><?= $totalPesanan ?></p>
                                <p class="text-[10px] text-gray-500 font-medium mt-1">Total Pesanan</p>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-4 text-center border border-gray-100">
                                <p class="text-2xl font-bold text-emerald-600"><?= $pesananSelesai ?></p>
                                <p class="text-[10px] text-gray-500 font-medium mt-1">Selesai</p>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-4 text-center border border-gray-100">
                                <p class="text-2xl font-bold text-amber-600"><?= $pesananPending ?></p>
                                <p class="text-[10px] text-gray-500 font-medium mt-1">Menunggu</p>
                            </div>
                            <div class="bg-gray-50 rounded-xl p-4 text-center border border-gray-100">
                                <p class="text-lg font-bold text-emerald-600"><?= formatRupiah($totalBelanja) ?></p>
                                <p class="text-[10px] text-gray-500 font-medium mt-1">Total Belanja</p>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Actions -->
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm p-4 fade-in" style="animation-delay: 0.2s">
                        <h3 class="text-sm font-bold text-gray-900 mb-3">Aksi Cepat</h3>
                        <div class="space-y-2">
                            <a href="pesanan.php" class="flex items-center gap-3 p-3 rounded-xl hover:bg-emerald-50 transition-all group">
                                <div class="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center text-emerald-600 group-hover:bg-emerald-600 group-hover:text-white transition-all">
                                    <i class="fa-solid fa-bag-shopping"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-semibold text-gray-800">Pesanan Saya</p>
                                    <p class="text-[10px] text-gray-400">Lihat riwayat pembelian</p>
                                </div>
                                <i class="fa-solid fa-chevron-right text-xs text-gray-300"></i>
                            </a>
                            <a href="keranjang.php" class="flex items-center gap-3 p-3 rounded-xl hover:bg-emerald-50 transition-all group">
                                <div class="w-10 h-10 bg-blue-100 rounded-xl flex items-center justify-center text-blue-600 group-hover:bg-blue-600 group-hover:text-white transition-all">
                                    <i class="fa-solid fa-cart-shopping"></i>
                                </div>
                                <div class="flex-1">
                                    <p class="text-sm font-semibold text-gray-800">Keranjang</p>
                                    <p class="text-[10px] text-gray-400"><?= $totalKeranjang ?> item di keranjang</p>
                                </div>
                                <i class="fa-solid fa-chevron-right text-xs text-gray-300"></i>
                            </a>
                        </div>
                    </div>
                </div>

                <!-- Kolom Kanan: Form Edit -->
                <div class="lg:col-span-2">
                    <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden fade-in" style="animation-delay: 0.15s">
                        <div class="px-6 py-4 border-b border-gray-50 flex items-center gap-3">
                            <div class="w-10 h-10 bg-emerald-100 rounded-xl flex items-center justify-center text-emerald-600">
                                <i class="fa-solid fa-user-pen"></i>
                            </div>
                            <div>
                                <h2 class="text-sm font-bold text-gray-900">Edit Profil</h2>
                                <p class="text-xs text-gray-400">Perbarui informasi akun Anda</p>
                            </div>
                        </div>

                        <form method="POST" action="" class="p-6 space-y-6">

                            <!-- Data Pribadi -->
                            <div class="space-y-4">
                                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider flex items-center gap-2">
                                    <span class="w-6 h-px bg-gray-300"></span> Informasi Pribadi
                                </h3>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Nama Lengkap <span class="text-red-500">*</span></label>
                                        <div class="relative">
                                            <i class="fa-solid fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                            <input type="text" name="nama" value="<?= clean($user['nama']) ?>" required
                                                   class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Email <span class="text-red-500">*</span></label>
                                        <div class="relative">
                                            <i class="fa-solid fa-envelope absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                            <input type="email" name="email" value="<?= clean($user['email']) ?>" required
                                                   class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all">
                                        </div>
                                    </div>
                                </div>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">No. Telepon</label>
                                        <div class="relative">
                                            <i class="fa-solid fa-phone absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                            <input type="tel" name="no_telepon" value="<?= clean($user['no_telepon'] ?? '') ?>"
                                                   class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all">
                                        </div>
                                    </div>
                                </div>

                                <div>
                                    <label class="block text-xs font-semibold text-gray-700 mb-1.5">Alamat Lengkap</label>
                                    <div class="relative">
                                        <i class="fa-solid fa-location-dot absolute left-3.5 top-3 text-gray-400 text-xs"></i>
                                        <textarea name="alamat" rows="3" placeholder="Masukkan alamat lengkap Anda..."
                                                  class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all resize-none"><?= clean($user['alamat'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>

                            <hr class="border-gray-100">

                            <!-- Ganti Password -->
                            <div class="space-y-4">
                                <h3 class="text-xs font-bold text-gray-400 uppercase tracking-wider flex items-center gap-2">
                                    <span class="w-6 h-px bg-gray-300"></span> Ganti Password
                                </h3>
                                <p class="text-xs text-gray-500">Kosongkan jika tidak ingin mengubah password</p>

                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Password Lama</label>
                                        <div class="relative">
                                            <i class="fa-solid fa-lock absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                            <input type="password" name="password_lama" placeholder="Masukkan password lama"
                                                   class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all">
                                        </div>
                                    </div>
                                    <div></div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Password Baru</label>
                                        <div class="relative">
                                            <i class="fa-solid fa-key absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                            <input type="password" name="password_baru" placeholder="Minimal 6 karakter" minlength="6"
                                                   class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Konfirmasi Password Baru</label>
                                        <div class="relative">
                                            <i class="fa-solid fa-check-double absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-xs"></i>
                                            <input type="password" name="password_konfirmasi" placeholder="Ulangi password baru"
                                                   class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all">
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Buttons -->
                            <div class="flex items-center gap-3 pt-2">
                                <button type="submit" class="px-6 py-2.5 bg-emerald-600 text-white rounded-xl text-sm font-semibold hover:bg-emerald-700 transition-all hover:shadow-md flex items-center gap-2">
                                    <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan
                                </button>
                                <a href="index.php" class="px-6 py-2.5 border border-gray-200 text-gray-600 rounded-xl text-sm font-semibold hover:bg-gray-50 transition-all">
                                    Kembali ke Beranda
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- ==================== MODAL UPLOAD FOTO ==================== -->
    <div id="modalUpload" class="modal-overlay fixed inset-0 bg-black/50 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="modal-content bg-white rounded-2xl shadow-xl max-w-md w-full p-6">
            <div class="flex items-center justify-between mb-4">
                <h3 class="text-lg font-bold text-gray-900">Ganti Foto Profil</h3>
                <button onclick="closeModal()" class="text-gray-400 hover:text-gray-600 transition-colors w-8 h-8 rounded-lg hover:bg-gray-100 flex items-center justify-center">
                    <i class="fa-solid fa-xmark text-xl"></i>
                </button>
            </div>
            
            <form action="upload_foto_profil.php" method="POST" enctype="multipart/form-data" id="formUpload">
                <div class="space-y-4">
                    <!-- Preview Area -->
                    <div class="flex justify-center">
                        <div class="relative">
                            <img id="previewImg" src="<?= getProfilePic($user['foto_profil']) ?>" 
                                 class="w-32 h-32 rounded-2xl object-cover border-2 border-gray-200 shadow-sm">
                        </div>
                    </div>
                    
                    <!-- File Input -->
                    <div>
                        <label class="block text-xs font-semibold text-gray-700 mb-1.5">Pilih Foto</label>
                        <input type="file" name="foto_profil" id="inputFoto" accept="image/*" required
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-emerald-50 file:text-emerald-700 hover:file:bg-emerald-100 transition-all">
                        <p class="text-[10px] text-gray-400 mt-1">Format: JPG, PNG, GIF. Maks 2MB.</p>
                    </div>
                </div>
                
                <div class="flex items-center gap-3 mt-6">
                    <button type="submit" class="flex-1 px-4 py-2.5 bg-emerald-600 text-white rounded-xl text-sm font-semibold hover:bg-emerald-700 transition-all flex items-center justify-center gap-2">
                        <i class="fa-solid fa-upload"></i> Upload
                    </button>
                    <button type="button" onclick="closeModal()" class="px-4 py-2.5 border border-gray-200 text-gray-600 rounded-xl text-sm font-semibold hover:bg-gray-50 transition-all">
                        Batal
                    </button>
                </div>
            </form>
        </div>
    </div>

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
                    &copy; 2026 Etanimart Project. All Rights Reserved.
                </p>
                <div class="flex gap-6 text-xs text-gray-600">
                    <a href="#" class="hover:text-emerald-500 transition-colors">Kebijakan Privasi</a>
                    <a href="#" class="hover:text-emerald-500 transition-colors">Syarat & Ketentuan</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- ==================== SCRIPTS ==================== -->
    <script>
        // ===== MODAL =====
        function openModal() {
            document.getElementById('modalUpload').classList.add('active');
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            document.getElementById('modalUpload').classList.remove('active');
            document.body.style.overflow = '';
            document.getElementById('formUpload').reset();
            document.getElementById('previewImg').src = '<?= getProfilePic($user['foto_profil']) ?>';
        }

        // Preview gambar sebelum upload
        document.getElementById('inputFoto').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewImg').src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        });

        // Tutup modal klik luar
        document.getElementById('modalUpload').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });

        // Tutup modal ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeModal();
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

        document.querySelectorAll('#mobile-menu a').forEach(link => {
            link.addEventListener('click', () => closeMobileMenu());
        });

        document.addEventListener('click', (e) => {
            if (!mobileMenu.contains(e.target) && !btnMenu.contains(e.target)) {
                closeMobileMenu();
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
                closeModal();
            }
        });
    </script>
</body>
</html>