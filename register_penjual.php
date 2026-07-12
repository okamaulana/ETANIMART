<?php
// =============================================================================
// register_penjual.php - Registrasi Akun Penjual
// =============================================================================
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once 'koneksi.php';

// Kalau sudah login, redirect
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'penjual') {
        header("Location: dashboard_penjual.php");
    } else {
        header("Location: index.php");
    }
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register'])) {
    $nama           = trim($_POST['nama'] ?? '');
    $email          = trim($_POST['email'] ?? '');
    $password       = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $noTelepon      = trim($_POST['no_telepon'] ?? '');
    $alamat         = trim($_POST['alamat'] ?? '');
    $namaToko       = trim($_POST['nama_toko'] ?? '');
    $deskripsiToko  = trim($_POST['deskripsi_toko'] ?? '');
    $namaBank       = trim($_POST['nama_bank'] ?? '');
    $noRekening     = trim($_POST['no_rekening'] ?? '');
    $atasNamaRek    = trim($_POST['atas_nama_rekening'] ?? '');

    // Validasi
    if (empty($nama) || empty($email) || empty($password) || empty($noTelepon) || 
        empty($alamat) || empty($namaToko) || empty($namaBank) || empty($noRekening) || empty($atasNamaRek)) {
        $error = 'Semua field wajib diisi.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid.';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Password dan konfirmasi password tidak cocok.';
    } elseif (strlen($noTelepon) < 10) {
        $error = 'Nomor telepon minimal 10 digit.';
    } else {
        // Cek email sudah terdaftar
        $stmtCheck = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetch()) {
            $error = 'Email sudah terdaftar. Silakan login.';
        } else {
            // Handle upload foto profil
            $fotoProfil = '';
            if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
                $fileType = $_FILES['foto_profil']['type'];
                $fileSize = $_FILES['foto_profil']['size'];

                if (in_array($fileType, $allowed) && $fileSize <= 2 * 1024 * 1024) {
                    $ext = pathinfo($_FILES['foto_profil']['name'], PATHINFO_EXTENSION);
                    $fotoProfil = 'profil_' . time() . '_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['foto_profil']['tmp_name'], 'uploads/profil/' . $fotoProfil);
                }
            }

            // Handle upload foto toko
            $fotoToko = '';
            if (isset($_FILES['foto_toko']) && $_FILES['foto_toko']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
                $fileType = $_FILES['foto_toko']['type'];
                $fileSize = $_FILES['foto_toko']['size'];

                if (in_array($fileType, $allowed) && $fileSize <= 2 * 1024 * 1024) {
                    $ext = pathinfo($_FILES['foto_toko']['name'], PATHINFO_EXTENSION);
                    $fotoToko = 'toko_' . time() . '_' . uniqid() . '.' . $ext;
                    move_uploaded_file($_FILES['foto_toko']['tmp_name'], 'uploads/toko/' . $fotoToko);
                }
            }

            try {
                $hashPassword = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    INSERT INTO users 
                    (nama, email, password, no_telepon, alamat, role, 
                     nama_toko, deskripsi_toko, foto_profil, foto_toko,
                     nama_bank, no_rekening, atas_nama_rekening, saldo, is_verified, created_at)
                    VALUES (?, ?, ?, ?, ?, 'penjual', ?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())
                ");
                $stmt->execute([
                    $nama, $email, $hashPassword, $noTelepon, $alamat,
                    $namaToko, $deskripsiToko, $fotoProfil, $fotoToko,
                    $namaBank, $noRekening, $atasNamaRek
                ]);

                $success = 'Pendaftaran berhasil! Akun penjual Anda sedang menunggu verifikasi admin.';

            } catch (PDOException $e) {
                $error = 'Gagal mendaftar: ' . $e->getMessage();
            }
        }
    }
}

function clean($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Daftar Penjual - Etanimart</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        .btn-primary { background: linear-gradient(135deg, #10b981 0%, #059669 100%); transition: all 0.3s ease; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.5); }
        .input-field { transition: all 0.2s ease; }
        .input-field:focus { border-color: #10b981; box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.15); }
        .step-indicator { transition: all 0.3s ease; }
        .step-active { background: #10b981; color: white; border-color: #10b981; }
        .step-done { background: #d1fae5; color: #059669; border-color: #10b981; }
        .file-upload-zone { 
            border: 2px dashed #d1d5db; 
            transition: all 0.2s ease;
            background-size: cover;
            background-position: center;
        }
        .file-upload-zone:hover, .file-upload-zone.dragover { 
            border-color: #10b981; 
            background-color: #ecfdf5;
        }
        .file-upload-zone.has-image {
            border-style: solid;
            border-color: #10b981;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased min-h-screen">

<!-- Navbar -->
<nav class="bg-white border-b border-gray-100 sticky top-0 z-40">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">
        <a href="index.php" class="flex items-center gap-2 font-bold text-2xl text-white">
    <img src="uploads/logo/tani.png" 
         alt="Etanimart" 
         class="logo-img"
         style="height: 70px; width: auto; object-fit: contain;"
         onerror="this.style.display='none'">
</a>
            <div class="flex items-center gap-4 text-sm">
                <span class="text-gray-500">Sudah punya akun?</span>
                <a href="login.php" class="font-semibold text-emerald-600 hover:text-emerald-700 transition-colors">
                    Masuk
                </a>
            </div>
        </div>
    </div>
</nav>

<main class="py-8 md:py-12">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="text-center mb-8">
            <div class="w-16 h-16 mx-auto mb-4 rounded-2xl bg-emerald-100 flex items-center justify-center text-emerald-600 text-2xl">
                <i class="fa-solid fa-store"></i>
            </div>
            <h1 class="text-2xl md:text-3xl font-bold text-gray-900">Daftar Sebagai Penjual</h1>
            <p class="text-gray-500 mt-2">Bergabung dan mulai jualan di eTanimart</p>
        </div>

        <!-- Alert -->
        <?php if (!empty($error)): ?>
        <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-2xl text-red-700 text-sm font-medium flex items-center gap-3">
            <i class="fa-solid fa-circle-exclamation text-lg"></i>
            <div><?= clean($error) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
        <div class="mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-2xl text-emerald-700 text-sm font-medium flex items-center gap-3">
            <i class="fa-solid fa-circle-check text-lg"></i>
            <div>
                <p class="font-semibold"><?= clean($success) ?></p>
                <p class="mt-1">Silakan tunggu verifikasi dari admin. Anda akan dihubungi via email.</p>
                <a href="login.php" class="inline-block mt-2 text-emerald-800 font-semibold hover:underline">
                    <i class="fa-solid fa-arrow-right mr-1"></i> Ke Halaman Login
                </a>
            </div>
        </div>
        <?php else: ?>

        <!-- Form -->
        <form method="POST" action="" enctype="multipart/form-data" class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">

            <!-- Step 1: Data Akun -->
            <div class="p-6 md:p-8 border-b border-gray-100">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-8 h-8 rounded-full border-2 border-emerald-500 bg-emerald-500 text-white flex items-center justify-center text-sm font-bold">1</div>
                    <h2 class="text-lg font-bold text-gray-900">Data Akun</h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Nama Lengkap <span class="text-red-500">*</span></label>
                        <input type="text" name="nama" required value="<?= clean($_POST['nama'] ?? '') ?>"
                            class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none"
                            placeholder="Nama lengkap pemilik toko">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Email <span class="text-red-500">*</span></label>
                        <input type="email" name="email" required value="<?= clean($_POST['email'] ?? '') ?>"
                            class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none"
                            placeholder="email@contoh.com">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Nomor Telepon <span class="text-red-500">*</span></label>
                        <input type="tel" name="no_telepon" required value="<?= clean($_POST['no_telepon'] ?? '') ?>"
                            class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none"
                            placeholder="0812-3456-7890">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Password <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" name="password" id="password" required minlength="6"
                                class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none pr-10"
                                placeholder="Minimal 6 karakter">
                            <button type="button" onclick="togglePassword('password', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Konfirmasi Password <span class="text-red-500">*</span></label>
                        <div class="relative">
                            <input type="password" name="password_confirm" id="password_confirm" required
                                class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none pr-10"
                                placeholder="Ulangi password">
                            <button type="button" onclick="togglePassword('password_confirm', this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        </div>
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Alamat Lengkap <span class="text-red-500">*</span></label>
                        <textarea name="alamat" rows="3" required
                            class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none resize-none"
                            placeholder="Jalan, RT/RW, Kelurahan, Kecamatan, Kota, Kode Pos"><?= clean($_POST['alamat'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Step 2: Data Toko -->
            <div class="p-6 md:p-8 border-b border-gray-100">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-8 h-8 rounded-full border-2 border-emerald-500 bg-emerald-500 text-white flex items-center justify-center text-sm font-bold">2</div>
                    <h2 class="text-lg font-bold text-gray-900">Data Toko</h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Nama Toko <span class="text-red-500">*</span></label>
                        <input type="text" name="nama_toko" required value="<?= clean($_POST['nama_toko'] ?? '') ?>"
                            class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none"
                            placeholder="Nama toko Anda">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Deskripsi Toko</label>
                        <textarea name="deskripsi_toko" rows="3"
                            class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none resize-none"
                            placeholder="Ceritakan tentang toko Anda..."><?= clean($_POST['deskripsi_toko'] ?? '') ?></textarea>
                    </div>

                    <!-- Upload Foto Profil -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Foto Profil</label>
                        <div class="file-upload-zone rounded-xl p-4 text-center cursor-pointer relative overflow-hidden" 
                             onclick="document.getElementById('foto_profil').click()"
                             id="zone_profil">
                            <input type="file" name="foto_profil" id="foto_profil" accept="image/*" class="hidden"
                                onchange="previewImage(this, 'zone_profil', 'preview_profil')">
                            <div id="preview_profil" class="space-y-2">
                                <i class="fa-solid fa-user-circle text-4xl text-gray-300"></i>
                                <p class="text-xs text-gray-500">Klik untuk upload foto profil</p>
                                <p class="text-[10px] text-gray-400">Max 2MB (JPG, PNG, WEBP)</p>
                            </div>
                        </div>
                    </div>

                    <!-- Upload Foto Toko -->
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Foto Toko</label>
                        <div class="file-upload-zone rounded-xl p-4 text-center cursor-pointer relative overflow-hidden" 
                             onclick="document.getElementById('foto_toko').click()"
                             id="zone_toko">
                            <input type="file" name="foto_toko" id="foto_toko" accept="image/*" class="hidden"
                                onchange="previewImage(this, 'zone_toko', 'preview_toko')">
                            <div id="preview_toko" class="space-y-2">
                                <i class="fa-solid fa-store text-4xl text-gray-300"></i>
                                <p class="text-xs text-gray-500">Klik untuk upload foto toko</p>
                                <p class="text-[10px] text-gray-400">Max 2MB (JPG, PNG, WEBP)</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 3: Data Rekening -->
            <div class="p-6 md:p-8 border-b border-gray-100">
                <div class="flex items-center gap-3 mb-6">
                    <div class="w-8 h-8 rounded-full border-2 border-emerald-500 bg-emerald-500 text-white flex items-center justify-center text-sm font-bold">3</div>
                    <h2 class="text-lg font-bold text-gray-900">Data Rekening Bank</h2>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Nama Bank <span class="text-red-500">*</span></label>
                        <select name="nama_bank" required
                            class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none bg-white">
                            <option value="">Pilih Bank</option>
                            <option value="BCA" <?= ($_POST['nama_bank'] ?? '') === 'BCA' ? 'selected' : '' ?>>BCA</option>
                            <option value="BNI" <?= ($_POST['nama_bank'] ?? '') === 'BNI' ? 'selected' : '' ?>>BNI</option>
                            <option value="BRI" <?= ($_POST['nama_bank'] ?? '') === 'BRI' ? 'selected' : '' ?>>BRI</option>
                            <option value="Mandiri" <?= ($_POST['nama_bank'] ?? '') === 'Mandiri' ? 'selected' : '' ?>>Mandiri</option>
                            <option value="BSI" <?= ($_POST['nama_bank'] ?? '') === 'BSI' ? 'selected' : '' ?>>BSI</option>
                            <option value="BJB" <?= ($_POST['nama_bank'] ?? '') === 'BJB' ? 'selected' : '' ?>>BJB</option>
                            <option value="Lainnya" <?= ($_POST['nama_bank'] ?? '') === 'Lainnya' ? 'selected' : '' ?>>Lainnya</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Nomor Rekening <span class="text-red-500">*</span></label>
                        <input type="text" name="no_rekening" required value="<?= clean($_POST['no_rekening'] ?? '') ?>"
                            class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none"
                            placeholder="1234567890">
                    </div>

                    <div class="md:col-span-2">
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Atas Nama Rekening <span class="text-red-500">*</span></label>
                        <input type="text" name="atas_nama_rekening" required value="<?= clean($_POST['atas_nama_rekening'] ?? '') ?>"
                            class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none"
                            placeholder="Nama sesuai di buku rekening">
                    </div>
                </div>
            </div>

            <!-- Submit -->
            <div class="p-6 md:p-8 bg-gray-50">
                <div class="flex items-start gap-3 mb-6">
                    <input type="checkbox" id="syarat" required class="mt-1 w-4 h-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500">
                    <label for="syarat" class="text-sm text-gray-600">
                        Saya menyetujui <a href="syarat_ketentuan.php " class="text-emerald-600 font-semibold hover:underline">Syarat dan Ketentuan</a> 
                        serta <a href="kebijakan_privasi.php" class="text-emerald-600 font-semibold hover:underline">Kebijakan Privasi</a> eTanimart
                    </label>
                </div>

                <button type="submit" name="register" class="w-full btn-primary text-white font-bold py-4 rounded-2xl shadow-lg flex items-center justify-center gap-2">
                    <i class="fa-solid fa-store"></i> Daftar Sekarang
                </button>

                <p class="text-center text-sm text-gray-500 mt-4">
                    Sudah punya akun? <a href="login.php" class="text-emerald-600 font-semibold hover:underline">Masuk di sini</a>
                </p>
            </div>
        </form>

        <?php endif; ?>
    </div>
</main>

<script>
function togglePassword(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function previewImage(input, zoneId, previewId) {
    const zone = document.getElementById(zoneId);
    const preview = document.getElementById(previewId);

    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = function(e) {
            zone.style.backgroundImage = 'url(' + e.target.result + ')';
            zone.classList.add('has-image');
            preview.innerHTML = '<p class="text-xs text-emerald-700 font-semibold bg-white/80 rounded-lg px-2 py-1 inline-block">' + input.files[0].name + '</p>';
        };
        reader.readAsDataURL(input.files[0]);
    }
}
</script>

</body>
</html>