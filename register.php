<?php
// =============================================================================
// register.php - Halaman Registrasi Etanimart
// =============================================================================
session_start();
require_once 'koneksi.php';

// Jika sudah login, redirect ke index
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nama = trim($_POST['nama'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $konfirmasi_password = $_POST['konfirmasi_password'] ?? '';
    $no_telepon = trim($_POST['no_telepon'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $role = 'pembeli'; // Default role untuk registrasi

    // Validasi
    if (empty($nama) || empty($email) || empty($password) || empty($konfirmasi_password) || empty($no_telepon) || empty($alamat)) {
        $error = 'Semua field wajib diisi!';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Format email tidak valid!';
    } elseif (strlen($password) < 6) {
        $error = 'Password minimal 6 karakter!';
    } elseif ($password !== $konfirmasi_password) {
        $error = 'Konfirmasi password tidak cocok!';
    } elseif (!preg_match('/^[0-9]{10,15}$/', $no_telepon)) {
        $error = 'Nomor telepon tidak valid! (10-15 digit)';
    } else {
        // Cek email sudah terdaftar
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            $error = 'Email sudah terdaftar! Silakan login.';
        } else {
            // Proses upload foto profil
            $foto_profil = null;
            if (isset($_FILES['foto_profil']) && $_FILES['foto_profil']['error'] === UPLOAD_ERR_OK) {
                $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
                $maxSize = 2 * 1024 * 1024; // 2MB
                $fileType = $_FILES['foto_profil']['type'];
                $fileSize = $_FILES['foto_profil']['size'];

                if (!in_array($fileType, $allowedTypes)) {
                    $error = 'Format foto harus JPG, PNG, atau WEBP!';
                } elseif ($fileSize > $maxSize) {
                    $error = 'Ukuran foto maksimal 2MB!';
                } else {
                    $ext = pathinfo($_FILES['foto_profil']['name'], PATHINFO_EXTENSION);
                    $foto_profil = uniqid('profil_') . '_' . time() . '.' . $ext;
                    $uploadDir = 'uploads/profil/';

                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }

                    if (!move_uploaded_file($_FILES['foto_profil']['tmp_name'], $uploadDir . $foto_profil)) {
                        $error = 'Gagal mengupload foto profil!';
                        $foto_profil = null;
                    }
                }
            }

            if (empty($error)) {
                // Hash password
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);

                // Insert ke database
                $stmt = $pdo->prepare("
                    INSERT INTO users (nama, email, password, no_telepon, alamat, foto_profil, role, created_at) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                ");

                try {
                    $stmt->execute([$nama, $email, $passwordHash, $no_telepon, $alamat, $foto_profil, $role]);
                    $success = 'Registrasi berhasil! Silakan login.';
                } catch (PDOException $e) {
                    $error = 'Terjadi kesalahan saat registrasi. Silakan coba lagi.';
                    // Hapus foto jika insert gagal
                    if ($foto_profil && file_exists('uploads/profil/' . $foto_profil)) {
                        unlink('uploads/profil/' . $foto_profil);
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Daftar - Etanimart</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }

        .btn-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.5);
        }

        .input-field {
            transition: all 0.3s ease;
        }
        .input-field:focus {
            box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
            border-color: #10b981;
        }

        .file-upload {
            position: relative;
            overflow: hidden;
            display: inline-block;
        }
        .file-upload input[type=file] {
            position: absolute;
            left: 0;
            top: 0;
            opacity: 0;
            cursor: pointer;
            width: 100%;
            height: 100%;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }
        .shake { animation: shake 0.4s ease-in-out; }

        .preview-img {
            width: 120px;
            height: 120px;
            object-fit: cover;
            border-radius: 50%;
            border: 3px solid #e2e8f0;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-emerald-50 via-white to-teal-50 min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-2xl">
        <!-- Logo -->
        <div class="text-center mb-8">
            <a href="index.php" class="inline-flex items-center gap-2.5 font-bold text-3xl text-emerald-600">
                <div class="w-12 h-12 bg-emerald-100 rounded-2xl flex items-center justify-center">
                    <i class="fa-solid fa-seedling text-emerald-600 text-xl"></i>
                </div>
                <span>Etani<span class="text-emerald-800">mart</span></span>
            </a>
            <p class="text-gray-500 mt-2 text-sm">Bergabunglah dan mulai deteksi penyakit tanaman dengan AI</p>
        </div>

        <!-- Card -->
        <div class="bg-white rounded-3xl shadow-xl border border-gray-100 p-8 md:p-10">
            <h1 class="text-2xl font-bold text-gray-900 mb-2">Buat Akun Baru</h1>
            <p class="text-gray-500 text-sm mb-8">Lengkapi data diri Anda untuk memulai</p>

            <?php if ($error): ?>
                <div class="shake bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-6 flex items-center gap-3 text-sm">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl mb-6 flex items-center gap-3 text-sm">
                    <i class="fa-solid fa-circle-check"></i>
                    <span><?= htmlspecialchars($success) ?></span>
                </div>
                <div class="text-center mt-4">
                    <a href="login.php" class="btn-primary text-white px-8 py-3 rounded-xl font-semibold inline-flex items-center gap-2">
                        <i class="fa-solid fa-arrow-right"></i> Ke Halaman Login
                    </a>
                </div>
            <?php else: ?>
                <form method="POST" action="" enctype="multipart/form-data" class="space-y-5">

                    <!-- Foto Profil -->
                    <div class="flex flex-col items-center mb-6">
                        <div class="relative mb-4">
                            <img id="previewFoto" src="https://placehold.co/120x120/e2e8f0/94a3b8?text=Foto" 
                                 alt="Preview" class="preview-img">
                            <button type="button" onclick="document.getElementById('foto_profil').click()" 
                                    class="absolute bottom-0 right-0 w-8 h-8 bg-emerald-500 hover:bg-emerald-600 text-white rounded-full flex items-center justify-center shadow-lg transition-colors">
                                <i class="fa-solid fa-camera text-xs"></i>
                            </button>
                        </div>
                        <input type="file" id="foto_profil" name="foto_profil" accept="image/jpeg,image/png,image/jpg,image/webp" 
                               class="hidden" onchange="previewImage(this)">
                        <p class="text-xs text-gray-400">Klik ikon kamera untuk upload foto (Max 2MB, JPG/PNG/WEBP)</p>
                    </div>

                    <div class="grid md:grid-cols-2 gap-5">
                        <!-- Nama Lengkap -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fa-solid fa-user text-emerald-500 mr-1"></i> Nama Lengkap
                            </label>
                            <input type="text" name="nama" required 
                                   class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none"
                                   placeholder="Masukkan nama lengkap" value="<?= htmlspecialchars($_POST['nama'] ?? '') ?>">
                        </div>

                        <!-- Email -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fa-solid fa-envelope text-emerald-500 mr-1"></i> Email
                            </label>
                            <input type="email" name="email" required 
                                   class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none"
                                   placeholder="nama@email.com" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-5">
                        <!-- No Telepon -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fa-solid fa-phone text-emerald-500 mr-1"></i> Nomor Telepon
                            </label>
                            <input type="tel" name="no_telepon" required 
                                   class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none"
                                   placeholder="081234567890" value="<?= htmlspecialchars($_POST['no_telepon'] ?? '') ?>">
                            <p class="text-xs text-gray-400 mt-1">Contoh: 081234567890 (10-15 digit)</p>
                        </div>

                        <!-- Password -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fa-solid fa-lock text-emerald-500 mr-1"></i> Password
                            </label>
                            <div class="relative">
                                <input type="password" name="password" id="password" required 
                                       class="input-field w-full px-4 py-3 pr-10 border border-gray-200 rounded-xl text-sm focus:outline-none"
                                       placeholder="Minimal 6 karakter">
                                <button type="button" onclick="togglePassword('password', this)" 
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-emerald-500">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="grid md:grid-cols-2 gap-5">
                        <!-- Konfirmasi Password -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fa-solid fa-lock text-emerald-500 mr-1"></i> Konfirmasi Password
                            </label>
                            <div class="relative">
                                <input type="password" name="konfirmasi_password" id="konfirmasi_password" required 
                                       class="input-field w-full px-4 py-3 pr-10 border border-gray-200 rounded-xl text-sm focus:outline-none"
                                       placeholder="Ulangi password">
                                <button type="button" onclick="togglePassword('konfirmasi_password', this)" 
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-emerald-500">
                                    <i class="fa-solid fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Alamat -->
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">
                                <i class="fa-solid fa-location-dot text-emerald-500 mr-1"></i> Alamat Lengkap
                            </label>
                            <textarea name="alamat" required rows="3"
                                      class="input-field w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none resize-none"
                                      placeholder="Jl. Mawar No. 123, Jakarta Selatan"><?= htmlspecialchars($_POST['alamat'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <!-- Terms -->
                    <div class="flex items-start gap-3">
                        <input type="checkbox" id="terms" required 
                               class="mt-1 w-4 h-4 text-emerald-600 border-gray-300 rounded focus:ring-emerald-500">
                        <label for="terms" class="text-xs text-gray-500">
                            Saya menyetujui <a href="syarat_ketentuan.php" class="text-emerald-600 hover:underline">Syarat & Ketentuan</a> 
                            dan <a href="kebijakan_privasi.php" class="text-emerald-600 hover:underline">Kebijakan Privasi</a> Etanimart
                        </label>
                    </div>

                    <!-- Submit -->
                    <button type="submit" class="btn-primary w-full text-white py-4 rounded-xl font-bold text-lg shadow-lg flex items-center justify-center gap-2">
                        <i class="fa-solid fa-user-plus"></i>
                        Daftar Sekarang
                    </button>
                </form>

                <div class="mt-6 text-center">
                    <p class="text-sm text-gray-500">
                        Mau jadi Penjual? 
                        <a href="register_penjual.php" class="text-emerald-600 font-semibold hover:text-emerald-700">Daftarkan Toko Anda Disini</a>
                    </p>
                </div>
                <div class="mt-6 text-center">
                    <p class="text-sm text-gray-500">
                        Sudah punya akun? 
                        <a href="login.php" class="text-emerald-600 font-semibold hover:text-emerald-700">Masuk di sini</a>
                    </p>
                </div>
            <?php endif; ?>
        </div>

        <p class="text-center text-xs text-gray-400 mt-6">
            &copy; 2026 Etanimart. All Rights Reserved.
        </p>
    </div>

    <script>
        // Preview foto profil
        function previewImage(input) {
            if (input.files && input.files[0]) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    document.getElementById('previewFoto').src = e.target.result;
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Toggle password visibility
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
    </script>
</body>
</html>