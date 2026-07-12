<?php
// =============================================================================
// login.php - Etanimart Login Page with Role-Based Redirect
// =============================================================================
session_start();
require_once 'koneksi.php';

$isLoggedIn = isset($_SESSION['user_id']);
$userRole = $isLoggedIn ? ($_SESSION['user_role'] ?? 'pembeli') : null;
$userName = $isLoggedIn ? ($_SESSION['nama'] ?? 'Pengguna') : null;
$userFoto = $isLoggedIn ? ($_SESSION['foto_profil'] ?? null) : null;

// Redirect kalau sudah login (sesuai role)
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'] ?? 'pembeli';
    switch ($role) {
        case 'admin':
            header("Location: admin/dashboard.php");
            break;
        case 'penjual':
            header("Location: penjual/dashboard_penjual.php");
            break;
        default:
            header("Location: index.php");
    }
    exit();
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




$errors = [];
$redirect = $_GET['redirect'] ?? '';



// ==========================================
// PROSES LOGIN
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);

    // Validasi
    if (empty($email) || empty($password)) {
        $errors[] = 'Email dan password wajib diisi.';
    }

    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("SELECT id, nama, email, password, role FROM users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                // Set session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['nama'];
                $_SESSION['nama'] = $user['nama']; 
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_role'] = $user['role'];
                $_SESSION['foto_profil'] = $user['foto_profil'];

                $_SESSION['just_logged_in'] = true;

                // Remember me (30 hari)
                if ($remember) {
                    $token = bin2hex(random_bytes(32));
                    setcookie('remember_token', $token, time() + 30 * 24 * 60 * 60, '/', '', false, true);
                }

                // Redirect berdasarkan role
                if (!empty($redirect)) {
                    $safeRedirect = filter_var($redirect, FILTER_SANITIZE_URL);
                    header("Location: " . $safeRedirect);
                } else {
                    switch ($user['role']) {
                        case 'admin':
                            header("Location: admin/dashboard.php");
                            break;
                        case 'penjual':
                            header("Location: penjual/dashboard_penjual.php");
                            break;
                        default:
                            header("Location: index.php");
                    }
                }
                exit();

            } else {
                $errors[] = 'Email atau password salah.';
            }

        } catch (PDOException $e) {
            $errors[] = 'Terjadi kesalahan. Coba lagi nanti.';
        }
    }
}

function clean($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Masuk - Etanimart</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        html { scroll-behavior: smooth; }

        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-fade-in-up {
            animation: fadeInUp 0.7s ease-out forwards;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-15px); }
        }
        .animate-float { animation: float 5s ease-in-out infinite; }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-4px); }
            20%, 40%, 60%, 80% { transform: translateX(4px); }
        }
        .animate-shake {
            animation: shake 0.5s ease-in-out;
        }

        .nav-link { position: relative; }
        .nav-link::after { content: ''; position: absolute; bottom: -4px; left: 0; width: 0; height: 2px; background: #10b981; transition: width 0.3s ease; }
        .nav-link:hover::after { width: 100%; }

        .input-group {
            position: relative;
        }
        .input-group input:focus ~ label,
        .input-group input:not(:placeholder-shown) ~ label {
            top: -10px;
            left: 12px;
            font-size: 11px;
            color: #10b981;
            background: white;
            padding: 0 6px;
        }
        .input-group label {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 14px;
            transition: all 0.2s ease;
            pointer-events: none;
        }
        .input-group input {
            transition: all 0.2s ease;
        }
        .input-group input:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
        }

        .btn-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.5);
        }

        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            cursor: pointer;
            transition: color 0.2s;
        }
        .password-toggle:hover {
            color: #10b981;
        }

        .custom-checkbox {
            appearance: none;
            width: 18px;
            height: 18px;
            border: 2px solid #d1d5db;
            border-radius: 5px;
            cursor: pointer;
            transition: all 0.2s;
            position: relative;
        }
        .custom-checkbox:checked {
            background: #10b981;
            border-color: #10b981;
        }
        .custom-checkbox:checked::after {
            content: '';
            position: absolute;
            left: 4px;
            top: 1px;
            width: 5px;
            height: 9px;
            border: solid white;
            border-width: 0 2px 2px 0;
            transform: rotate(45deg);
        }

        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.15;
            pointer-events: none;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .spinner {
            animation: spin 1s linear infinite;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased overflow-x-hidden min-h-screen">

    <!-- Decorative Background -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="blob w-96 h-96 bg-emerald-400 top-0 right-0 translate-x-1/2 -translate-y-1/2"></div>
        <div class="blob w-80 h-80 bg-teal-400 bottom-0 left-0 -translate-x-1/3 translate-y-1/3"></div>
        <div class="blob w-64 h-64 bg-emerald-300 top-1/3 left-1/3"></div>
    </div>


    <!-- Spacer -->
    <div class="h-20"></div>

    <!-- ==================== LOGIN SECTION ==================== -->
    <section class="relative z-10 min-h-[calc(100vh-80px)] flex items-center justify-center py-1 px-4">
        <div class="w-full max-w-5xl mx-auto">
            <div class="grid lg:grid-cols-5 gap-0 bg-white rounded-3xl shadow-2xl overflow-hidden animate-fade-in-up">

                <!-- LEFT: Form -->
                <div class="lg:col-span-3 p-8 lg:p-10 order-2 lg:order-1">

                    <div class="mb-8">
                        <h1 class="text-2xl font-bold text-gray-900 mb-1">Selamat Datang Kembali</h1>
                        <p class="text-sm text-gray-500">Masuk ke akun Etanimart-mu</p>
                    </div>

                    <!-- Error Messages -->
                    <?php if (!empty($errors)): ?>
                    <div id="errorBox" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-xl animate-shake">
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-circle-exclamation text-red-500 mt-0.5"></i>
                            <div class="space-y-1">
                                <?php foreach ($errors as $error): ?>
                                <p class="text-xs text-red-700"><?= clean($error) ?></p>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="login.php<?= !empty($redirect) ? '?redirect=' . urlencode($redirect) : '' ?>" class="space-y-5" id="loginForm">

                        <!-- Email -->
                        <div class="input-group">
                            <input type="email" name="email" id="email" placeholder=" " required
                                value="<?= clean($_POST['email'] ?? '') ?>"
                                class="w-full px-4 py-3.5 border border-gray-200 rounded-xl text-sm focus:outline-none bg-white">
                            <label for="email"><i class="fa-regular fa-envelope mr-1"></i> Alamat Email</label>
                        </div>

                        <!-- Password -->
                        <div class="input-group">
                            <input type="password" name="password" id="password" placeholder=" " required
                                class="w-full px-4 py-3.5 border border-gray-200 rounded-xl text-sm focus:outline-none bg-white pr-12">
                            <label for="password"><i class="fa-solid fa-lock mr-1"></i> Password</label>
                            <span class="password-toggle" onclick="togglePassword('password', this)">
                                <i class="fa-regular fa-eye"></i>
                            </span>
                        </div>

                        <!-- Remember & Forgot -->
                        <div class="flex items-center justify-between">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="remember" class="custom-checkbox">
                                <span class="text-xs text-gray-600">Ingat saya</span>
                            </label>
                            <a href="forgot_password.php" class="text-xs text-emerald-600 font-semibold hover:text-emerald-700 transition-colors">Lupa password?</a>
                        </div>

                        <!-- Submit Button -->
                        <button type="submit" id="submitBtn"
                            class="w-full py-4 btn-primary text-white font-bold rounded-xl shadow-lg flex items-center justify-center gap-2 mt-2">
                            <i class="fa-solid fa-right-to-bracket"></i>
                            <span>Masuk</span>
                        </button>

                        <!-- Divider -->
                        <div class="relative my-6">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-gray-200"></div>
                            </div>
                            <div class="relative flex justify-center text-xs">
                                <span class="px-4 bg-white text-gray-400">atau</span>
                            </div>
                        </div>

                        <!-- Register Link -->
                        <p class="text-center text-sm text-gray-500">
                            Belum punya akun? 
                            <a href="register.php" class="text-emerald-600 font-semibold hover:text-emerald-700 transition-colors">Daftar gratis</a>
                        </p>
                    </form>
                </div>

                <!-- RIGHT: Visual & Info -->
                <div class="lg:col-span-2 bg-gradient-to-br from-emerald-600 to-teal-700 p-5 lg:p-10 flex flex-col justify-between text-white relative overflow-hidden order-1 lg:order-2">
                    <div class="absolute top-0 left-0 w-40 h-40 bg-white/10 rounded-full -translate-y-1/2 -translate-x-1/2"></div>
                    <div class="absolute bottom-0 right-0 w-32 h-32 bg-white/10 rounded-full translate-y-1/2 translate-x-1/2"></div>

                    <div class="relative z-10">
                        <div class="w-14 h-14 bg-white/20 backdrop-blur rounded-2xl flex items-center justify-center mb-6">
                            <i class="fa-solid fa-shield-halved text-2xl"></i>
                        </div>
                        <h2 class="text-2xl lg:text-3xl font-bold mb-3 leading-tight">Keamanan Data<br>Terjamin</h2>
                        <p class="text-emerald-100 text-sm leading-relaxed">
                            Data dan privasimu dilindungi dengan enkripsi tingkat tinggi. Aman dan terpercaya.
                        </p>
                    </div>

                    <div class="lg:col-span-2 relative z-10 mt-4 lg:mt-0">
                        <div class="space-y-4">
                        <div class="flex items-center gap-2 lg:gap-3">
                                <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-check"></i>
                                </div>
                                <span class="text-xs lg:text-sm font-medium">Akses Scan AI Gratis</span>
                            </div>
                            <div class="flex items-center gap-2 lg:gap-3">
                                <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-check"></i>
                                </div>
                                <span class="text-xs lg:text-sm font-medium">Riwayat Pembelian Tersimpan</span>
                            </div>
                            <div class="flex items-center gap-2 lg:gap-3">
                                <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-check"></i>
                                </div>
                                <span class="text-xs lg:text-sm font-medium">Rekomendasi Personal</span>
                            </div>
                        </div>

                        <div class="mt-8 pt-6 border-t border-white/20">
                            <p class="text-xs text-emerald-200">Belum punya akun?</p>
                            <a href="register.php" class="inline-flex items-center gap-2 mt-2 text-sm font-bold hover:text-emerald-200 transition-colors">
                                Daftar Gratis <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

   

    <script>
        const btnMenu = document.getElementById('btn-menu');
        const mobileMenu = document.getElementById('mobile-menu');
        const menuIcon = document.getElementById('menu-icon');
        let menuOpen = false;

        btnMenu.addEventListener('click', () => {
            menuOpen = !menuOpen;
            if (menuOpen) {
                mobileMenu.style.maxHeight = '500px';
                mobileMenu.style.opacity = '1';
                menuIcon.classList.remove('fa-bars');
                menuIcon.classList.add('fa-xmark');
            } else {
                mobileMenu.style.maxHeight = '0';
                mobileMenu.style.opacity = '0';
                menuIcon.classList.remove('fa-xmark');
                menuIcon.classList.add('fa-bars');
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

        function togglePassword(inputId, toggleEl) {
            const input = document.getElementById(inputId);
            const icon = toggleEl.querySelector('i');
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

        const loginForm = document.getElementById('loginForm');
        const submitBtn = document.getElementById('submitBtn');

        loginForm.addEventListener('submit', () => {
            submitBtn.innerHTML = '<i class="fa-solid fa-circle-notch spinner"></i> <span>Memproses...</span>';
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.8';
        });

        <?php if (!empty($errors)): ?>
        document.getElementById('email').focus();
        <?php endif; ?>


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