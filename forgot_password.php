<?php
// =============================================================================
// forgot_password.php - Etanimart Forgot Password with OTP
// =============================================================================

// ==========================================
// ERROR REPORTING (DEBUG MODE)
// ==========================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

session_start();

// ==========================================
// LOAD KONEKSI DATABASE
// ==========================================
try {
    require_once 'koneksi.php';
} catch (Exception $e) {
    error_log("KONEKSI ERROR: " . $e->getMessage());
    die(json_encode(['success' => false, 'message' => 'Gagal koneksi database: ' . $e->getMessage()]));
}

// ==========================================
// LOAD PHPMAILER (HARUS DI ATAS, JANGAN DI DALAM IF!)
// ==========================================
$phpmailer_available = false;
$phpmailer_error = '';

try {
    if (file_exists('vendor/autoload.php')) {
        require 'vendor/autoload.php';
        $phpmailer_available = true;
    } elseif (file_exists('PHPMailer/src/PHPMailer.php')) {
        require 'PHPMailer/src/Exception.php';
        require 'PHPMailer/src/PHPMailer.php';
        require 'PHPMailer/src/SMTP.php';
        $phpmailer_available = true;
    } else {
        $phpmailer_error = 'PHPMailer tidak ditemukan. Install: composer require phpmailer/phpmailer';
    }
} catch (Exception $e) {
    $phpmailer_error = 'Error load PHPMailer: ' . $e->getMessage();
}

// Use statements HARUS di top level setelah require
if ($phpmailer_available) {
    // PHPMailer classes sudah diload via autoload/manual require di atas
    // Tidak perlu use statement lagi karena sudah di-load
}

$errors = [];
$success = '';
$step = $_SESSION['forgot_step'] ?? 1;
$userEmail = $_SESSION['forgot_email'] ?? '';

// ==========================================
// HELPER FUNCTIONS
// ==========================================
function clean($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function generateOTP() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function sendOTPEmail($email, $otp, $name = '') {
    global $phpmailer_available, $phpmailer_error;

    if (!$phpmailer_available) {
        return ['success' => false, 'error' => $phpmailer_error];
    }

    // Pakai fully qualified name karena use statement bermasalah
    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'atomclan40@gmail.com';
        $mail->Password = 'cjfr snpu cbkg wzwf';
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        $mail->Timeout = 10;

        $mail->setFrom('atomclan40@gmail.com', 'Etanimart');
        $mail->addAddress($email, $name);

        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Kode Verifikasi Reset Password - Etanimart';
        $mail->Body = '
        <!DOCTYPE html>
        <html>
        <head><meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; }
                .container { max-width: 500px; margin: 0 auto; background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
                .header { background: linear-gradient(135deg, #10b981, #059669); padding: 30px; text-align: center; }
                .header h1 { color: white; margin: 0; font-size: 22px; }
                .content { padding: 30px; }
                .otp-box { background: #f0fdf4; border: 2px dashed #10b981; border-radius: 12px; padding: 20px; text-align: center; margin: 20px 0; }
                .otp-code { font-size: 36px; font-weight: bold; color: #059669; letter-spacing: 8px; }
                .warning { background: #fef3c7; border-left: 4px solid #f59e0b; padding: 12px; margin-top: 20px; font-size: 13px; color: #92400e; }
                .footer { text-align: center; padding: 20px; color: #9ca3af; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header"><h1>🔐 Reset Password Etanimart</h1></div>
                <div class="content">
                    <p>Halo <strong>' . clean($name ?: 'Pengguna') . '</strong>,</p>
                    <p>Kamu telah meminta reset password untuk akun Etanimart. Gunakan kode verifikasi berikut:</p>
                    <div class="otp-box"><div class="otp-code">' . $otp . '</div><p style="color: #6b7280; font-size: 13px; margin-top: 10px;">Berlaku selama 15 menit</p></div>
                    <p style="color: #6b7280; font-size: 13px;">Jika kamu tidak meminta reset password, abaikan email ini.</p>
                    <div class="warning"><strong>⚠️ Jangan bagikan kode ini ke siapapun!</strong> Tim Etanimart tidak akan pernah meminta kode verifikasi.</div>
                </div>
                <div class="footer">© ' . date('Y') . ' Etanimart. Semua hak dilindungi.</div>
            </div>
        </body></html>';

        $mail->send();
        return ['success' => true, 'error' => ''];
    } catch (\Exception $e) {
        $error_msg = $mail->ErrorInfo ?: $e->getMessage();
        error_log("PHPMailer Error: " . $error_msg);
        return ['success' => false, 'error' => $error_msg];
    }
}

// ==========================================
// STEP 1: SEND OTP
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_otp') {
    $email = trim($_POST['email'] ?? '');

    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Email wajib diisi.']);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Format email tidak valid.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, nama FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user) {
            echo json_encode(['success' => false, 'message' => 'Email tidak terdaftar.']);
            exit;
        }

        $otp = generateOTP();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->execute([$email]);

        $stmt = $pdo->prepare("INSERT INTO password_resets (email, otp_code, expires_at, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$email, password_hash($otp, PASSWORD_DEFAULT), $expiresAt]);

        $emailResult = sendOTPEmail($email, $otp, $user['nama']);

        if ($emailResult['success']) {
            $_SESSION['forgot_email'] = $email;
            $_SESSION['forgot_step'] = 2;
            $_SESSION['forgot_user_id'] = $user['id'];
            echo json_encode(['success' => true, 'message' => 'Kode verifikasi berhasil dikirim!']);
        } else {
            // MODE DEBUG: Kalau email gagal, tetap lanjut tapi kasih tau OTP-nya
            $_SESSION['forgot_email'] = $email;
            $_SESSION['forgot_step'] = 2;
            $_SESSION['forgot_user_id'] = $user['id'];
            echo json_encode([
                'success' => true, 
                'message' => 'OTP berhasil dibuat (mode debug - email belum dikirim)',
                'debug_mode' => true,
                'debug_otp' => $otp,
                'email_error' => $emailResult['error']
            ]);
        }

    } catch (PDOException $e) {
        error_log("DB Error send_otp: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// STEP 2: VERIFY OTP
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_otp') {
    $otp = trim($_POST['otp'] ?? '');
    $email = $_SESSION['forgot_email'] ?? '';

    if (empty($otp) || strlen($otp) !== 6) {
        echo json_encode(['success' => false, 'message' => 'Masukkan 6 digit kode OTP.']);
        exit;
    }

    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Sesi habis. Silakan mulai ulang.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT otp_code, expires_at, used FROM password_resets WHERE email = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email]);
        $reset = $stmt->fetch();

        if (!$reset) {
            echo json_encode(['success' => false, 'message' => 'Kode tidak ditemukan. Silakan kirim ulang.']);
            exit;
        }

        if ($reset['used']) {
            echo json_encode(['success' => false, 'message' => 'Kode sudah digunakan.']);
            exit;
        }

        if (strtotime($reset['expires_at']) < time()) {
            echo json_encode(['success' => false, 'message' => 'Kode sudah kadaluarsa. Silakan kirim ulang.']);
            exit;
        }

        if (!password_verify($otp, $reset['otp_code'])) {
            echo json_encode(['success' => false, 'message' => 'Kode OTP salah.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE password_resets SET used = 1 WHERE email = ?");
        $stmt->execute([$email]);

        $_SESSION['forgot_step'] = 3;
        $_SESSION['otp_verified'] = true;

        echo json_encode(['success' => true, 'message' => 'Verifikasi berhasil!']);

    } catch (PDOException $e) {
        error_log("DB Error verify_otp: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// STEP 3: RESET PASSWORD
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $email = $_SESSION['forgot_email'] ?? '';
    $userId = $_SESSION['forgot_user_id'] ?? '';
    $otpVerified = $_SESSION['otp_verified'] ?? false;

    if (!$otpVerified || empty($email) || empty($userId)) {
        echo json_encode(['success' => false, 'message' => 'Sesi tidak valid. Silakan mulai ulang.']);
        exit;
    }

    if (empty($newPassword) || empty($confirmPassword)) {
        echo json_encode(['success' => false, 'message' => 'Semua field wajib diisi.']);
        exit;
    }

    if (strlen($newPassword) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password minimal 8 karakter.']);
        exit;
    }

    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'message' => 'Password tidak cocok.']);
        exit;
    }

    try {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $userId]);

        $stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->execute([$email]);

        unset($_SESSION['forgot_step']);
        unset($_SESSION['forgot_email']);
        unset($_SESSION['forgot_user_id']);
        unset($_SESSION['otp_verified']);

        echo json_encode(['success' => true, 'message' => 'Password berhasil diubah!']);

    } catch (PDOException $e) {
        error_log("DB Error reset_password: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}

// ==========================================
// RESEND OTP
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resend_otp') {
    $email = $_SESSION['forgot_email'] ?? '';

    if (empty($email)) {
        echo json_encode(['success' => false, 'message' => 'Sesi habis. Silakan mulai ulang.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("SELECT created_at FROM password_resets WHERE email = ? ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$email]);
        $lastReset = $stmt->fetch();

        if ($lastReset && strtotime($lastReset['created_at']) > strtotime('-1 minute')) {
            echo json_encode(['success' => false, 'message' => 'Tunggu 1 menit sebelum kirim ulang.']);
            exit;
        }

        $stmt = $pdo->prepare("SELECT nama FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        $otp = generateOTP();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+15 minutes'));

        $stmt = $pdo->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->execute([$email]);

        $stmt = $pdo->prepare("INSERT INTO password_resets (email, otp_code, expires_at, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$email, password_hash($otp, PASSWORD_DEFAULT), $expiresAt]);

        $emailResult = sendOTPEmail($email, $otp, $user['nama']);

        if ($emailResult['success']) {
            echo json_encode(['success' => true, 'message' => 'Kode baru telah dikirim!']);
        } else {
            echo json_encode([
                'success' => true, 
                'message' => 'OTP baru dibuat (mode debug)',
                'debug_mode' => true,
                'debug_otp' => $otp
            ]);
        }

    } catch (PDOException $e) {
        error_log("DB Error resend_otp: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Lupa Password - Etanimart</title>
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

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-4px); }
            20%, 40%, 60%, 80% { transform: translateX(4px); }
        }
        .animate-shake {
            animation: shake 0.5s ease-in-out;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .spinner {
            animation: spin 1s linear infinite;
        }

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
        .btn-primary:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none;
        }

        .blob {
            position: absolute;
            border-radius: 50%;
            filter: blur(60px);
            opacity: 0.15;
            pointer-events: none;
        }

        .step-indicator {
            transition: all 0.4s ease;
        }
        .step-indicator.active {
            background: #10b981;
            color: white;
            border-color: #10b981;
        }
        .step-indicator.completed {
            background: #059669;
            color: white;
            border-color: #059669;
        }
        .step-line {
            transition: all 0.4s ease;
        }
        .step-line.active {
            background: #10b981;
        }

        .otp-input {
            width: 48px;
            height: 56px;
            text-align: center;
            font-size: 20px;
            font-weight: 700;
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            transition: all 0.2s ease;
        }
        .otp-input:focus {
            border-color: #10b981;
            box-shadow: 0 0 0 4px rgba(16, 185, 129, 0.1);
            outline: none;
        }

        .toast {
            animation: slideInRight 0.4s ease-out forwards;
        }
        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        .toast-exit {
            animation: slideOutRight 0.3s ease-in forwards;
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }

        .debug-box {
            background: #1e293b;
            color: #e2e8f0;
            font-family: monospace;
            font-size: 12px;
            padding: 12px;
            border-radius: 8px;
            margin-top: 10px;
            word-break: break-all;
        }

        .debug-banner {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 9999;
            background: #ef4444;
            color: white;
            text-align: center;
            font-size: 12px;
            padding: 8px;
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased overflow-x-hidden min-h-screen">

    <!-- Debug Banner -->
    <?php if (!$phpmailer_available): ?>
    <div class="debug-banner">
        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
        <strong>MODE DEBUG:</strong> Email tidak aktif. Install PHPMailer: <code>composer require phpmailer/phpmailer</code>
    </div>
    <div class="h-10"></div>
    <?php else: ?>
    <div class="h-20"></div>
    <?php endif; ?>

    <!-- Decorative Background -->
    <div class="fixed inset-0 overflow-hidden pointer-events-none z-0">
        <div class="blob w-96 h-96 bg-emerald-400 top-0 right-0 translate-x-1/2 -translate-y-1/2"></div>
        <div class="blob w-80 h-80 bg-teal-400 bottom-0 left-0 -translate-x-1/3 translate-y-1/3"></div>
        <div class="blob w-64 h-64 bg-emerald-300 top-1/3 left-1/3"></div>
    </div>

    <!-- ==================== FORGOT PASSWORD SECTION ==================== -->
    <section class="relative z-10 min-h-[calc(100vh-80px)] flex items-center justify-center py-8 px-4">
        <div class="w-full max-w-5xl mx-auto">
            <div class="grid lg:grid-cols-5 gap-0 bg-white rounded-3xl shadow-2xl overflow-hidden animate-fade-in-up">

                <!-- LEFT: Form -->
                <div class="lg:col-span-3 p-8 lg:p-10 order-2 lg:order-1">

                    <!-- Back Link -->
                    <a href="login.php" class="inline-flex items-center gap-2 text-sm text-gray-500 hover:text-emerald-600 transition-colors mb-6">
                        <i class="fa-solid fa-arrow-left"></i>
                        Kembali ke Login
                    </a>

                    <div class="mb-6">
                        <h1 class="text-2xl font-bold text-gray-900 mb-1">Lupa Password?</h1>
                        <p class="text-sm text-gray-500">Jangan khawatir, kami bantu pulihkan akunmu</p>
                    </div>

                    <!-- Step Indicators -->
                    <div class="flex items-center justify-between mb-8 px-2">
                        <div class="flex flex-col items-center gap-2">
                            <div id="step1-indicator" class="step-indicator active w-10 h-10 rounded-full border-2 border-emerald-500 bg-emerald-500 text-white flex items-center justify-center text-sm font-bold">
                                1
                            </div>
                            <span class="text-xs text-gray-500 font-medium">Email</span>
                        </div>
                        <div id="line1" class="step-line flex-1 h-0.5 bg-gray-200 mx-3"></div>
                        <div class="flex flex-col items-center gap-2">
                            <div id="step2-indicator" class="step-indicator w-10 h-10 rounded-full border-2 border-gray-300 bg-white text-gray-400 flex items-center justify-center text-sm font-bold">
                                2
                            </div>
                            <span class="text-xs text-gray-500 font-medium">Verifikasi</span>
                        </div>
                        <div id="line2" class="step-line flex-1 h-0.5 bg-gray-200 mx-3"></div>
                        <div class="flex flex-col items-center gap-2">
                            <div id="step3-indicator" class="step-indicator w-10 h-10 rounded-full border-2 border-gray-300 bg-white text-gray-400 flex items-center justify-center text-sm font-bold">
                                3
                            </div>
                            <span class="text-xs text-gray-500 font-medium">Reset</span>
                        </div>
                    </div>

                    <!-- Error Messages -->
                    <div id="errorBox" class="hidden mb-6 p-4 bg-red-50 border border-red-200 rounded-xl">
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-circle-exclamation text-red-500 mt-0.5"></i>
                            <p id="errorText" class="text-xs text-red-700"></p>
                        </div>
                    </div>

                    <!-- Success Messages -->
                    <div id="successBox" class="hidden mb-6 p-4 bg-emerald-50 border border-emerald-200 rounded-xl">
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-circle-check text-emerald-500 mt-0.5"></i>
                            <p id="successText" class="text-xs text-emerald-700"></p>
                        </div>
                    </div>

                    <!-- ===== STEP 1: Email Input ===== -->
                    <div id="step1" class="space-y-5">
                        <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 mb-4">
                            <div class="flex items-start gap-3">
                                <i class="fa-solid fa-circle-info text-blue-500 mt-0.5"></i>
                                <p class="text-xs text-blue-700 leading-relaxed">
                                    Masukkan alamat email yang terdaftar. Kami akan mengirimkan kode verifikasi untuk memulihkan akunmu.
                                </p>
                            </div>
                        </div>

                        <div class="input-group">
                            <input type="email" id="email" placeholder=" " required
                                value="<?= clean($userEmail) ?>"
                                class="w-full px-4 py-3.5 border border-gray-200 rounded-xl text-sm focus:outline-none bg-white">
                            <label for="email"><i class="fa-regular fa-envelope mr-1"></i> Alamat Email</label>
                        </div>

                        <button type="button" id="btnSendOTP"
                            class="w-full py-4 btn-primary text-white font-bold rounded-xl shadow-lg flex items-center justify-center gap-2">
                            <i class="fa-solid fa-paper-plane"></i>
                            <span>Kirim Kode Verifikasi</span>
                        </button>

                        <!-- Debug OTP display (mode debug) -->
                        <div id="debugOtpBox" class="hidden">
                            <div class="debug-box">
                                <strong>🐛 MODE DEBUG - OTP:</strong> <span id="debugOtpValue" style="color: #4ade80; font-size: 18px;"></span><br>
                                <small style="color: #94a3b8;">(Email belum dikirim karena PHPMailer belum terinstall. OTP ini valid 15 menit.)</small>
                            </div>
                        </div>
                    </div>

                    <!-- ===== STEP 2: OTP Verification ===== -->
                    <div id="step2" class="hidden space-y-5">
                        <div class="text-center mb-2">
                            <div class="w-16 h-16 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-4">
                                <i class="fa-solid fa-envelope-open-text text-emerald-600 text-2xl"></i>
                            </div>
                            <p class="text-sm text-gray-600 mb-1">Kode verifikasi telah dibuat untuk</p>
                            <p id="displayEmail" class="text-sm font-bold text-gray-900"><?= clean($userEmail) ?></p>
                        </div>

                        <div class="flex justify-center gap-3 mb-4">
                            <input type="text" maxlength="1" class="otp-input" data-index="0">
                            <input type="text" maxlength="1" class="otp-input" data-index="1">
                            <input type="text" maxlength="1" class="otp-input" data-index="2">
                            <input type="text" maxlength="1" class="otp-input" data-index="3">
                            <input type="text" maxlength="1" class="otp-input" data-index="4">
                            <input type="text" maxlength="1" class="otp-input" data-index="5">
                        </div>

                        <div class="text-center">
                            <p class="text-xs text-gray-500 mb-2">Tidak menerima kode?</p>
                            <button type="button" id="btnResend" class="text-xs text-emerald-600 font-semibold hover:text-emerald-700 transition-colors disabled:text-gray-400 disabled:cursor-not-allowed">
                                Kirim Ulang (<span id="countdown">60</span>s)
                            </button>
                        </div>

                        <button type="button" id="btnVerifyOTP"
                            class="w-full py-4 btn-primary text-white font-bold rounded-xl shadow-lg flex items-center justify-center gap-2">
                            <i class="fa-solid fa-shield-halved"></i>
                            <span>Verifikasi Kode</span>
                        </button>
                    </div>

                    <!-- ===== STEP 3: Reset Password ===== -->
                    <div id="step3" class="hidden space-y-5">
                        <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-4 mb-4">
                            <div class="flex items-start gap-3">
                                <i class="fa-solid fa-shield-halved text-emerald-500 mt-0.5"></i>
                                <p class="text-xs text-emerald-700 leading-relaxed">
                                    Buat password baru yang kuat. Minimal 8 karakter dengan kombinasi huruf, angka, dan simbol.
                                </p>
                            </div>
                        </div>

                        <div class="input-group">
                            <input type="password" id="newPassword" placeholder=" " required
                                class="w-full px-4 py-3.5 border border-gray-200 rounded-xl text-sm focus:outline-none bg-white pr-12">
                            <label for="newPassword"><i class="fa-solid fa-lock mr-1"></i> Password Baru</label>
                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 cursor-pointer hover:text-emerald-500 transition-colors" onclick="togglePassword('newPassword', this)">
                                <i class="fa-regular fa-eye"></i>
                            </span>
                        </div>

                        <!-- Password Strength -->
                        <div id="passwordStrength" class="hidden">
                            <div class="flex gap-1 mb-2">
                                <div id="strength1" class="h-1 flex-1 rounded-full bg-gray-200 transition-all"></div>
                                <div id="strength2" class="h-1 flex-1 rounded-full bg-gray-200 transition-all"></div>
                                <div id="strength3" class="h-1 flex-1 rounded-full bg-gray-200 transition-all"></div>
                                <div id="strength4" class="h-1 flex-1 rounded-full bg-gray-200 transition-all"></div>
                            </div>
                            <p id="strengthText" class="text-xs text-gray-500"></p>
                        </div>

                        <div class="input-group">
                            <input type="password" id="confirmPassword" placeholder=" " required
                                class="w-full px-4 py-3.5 border border-gray-200 rounded-xl text-sm focus:outline-none bg-white pr-12">
                            <label for="confirmPassword"><i class="fa-solid fa-lock mr-1"></i> Konfirmasi Password</label>
                            <span class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 cursor-pointer hover:text-emerald-500 transition-colors" onclick="togglePassword('confirmPassword', this)">
                                <i class="fa-regular fa-eye"></i>
                            </span>
                        </div>

                        <button type="button" id="btnResetPassword"
                            class="w-full py-4 btn-primary text-white font-bold rounded-xl shadow-lg flex items-center justify-center gap-2">
                            <i class="fa-solid fa-rotate"></i>
                            <span>Reset Password</span>
                        </button>
                    </div>

                    <!-- Divider -->
                    <div class="relative my-6">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-gray-200"></div>
                        </div>
                        <div class="relative flex justify-center text-xs">
                            <span class="px-4 bg-white text-gray-400">atau</span>
                        </div>
                    </div>

                    <!-- Login Link -->
                    <p class="text-center text-sm text-gray-500">
                        Ingat passwordmu? 
                        <a href="login.php" class="text-emerald-600 font-semibold hover:text-emerald-700 transition-colors">Masuk sekarang</a>
                    </p>
                </div>

                <!-- RIGHT: Visual & Info -->
                <div class="lg:col-span-2 bg-gradient-to-br from-emerald-600 to-teal-700 p-5 lg:p-10 flex flex-col justify-between text-white relative overflow-hidden order-1 lg:order-2">
                    <div class="absolute top-0 left-0 w-40 h-40 bg-white/10 rounded-full -translate-y-1/2 -translate-x-1/2"></div>
                    <div class="absolute bottom-0 right-0 w-32 h-32 bg-white/10 rounded-full translate-y-1/2 translate-x-1/2"></div>

                    <div class="relative z-10">
                        <div class="w-14 h-14 bg-white/20 backdrop-blur rounded-2xl flex items-center justify-center mb-6">
                            <i class="fa-solid fa-key text-2xl"></i>
                        </div>
                        <h2 class="text-2xl lg:text-3xl font-bold mb-3 leading-tight">Pulihkan<br>Akunmu</h2>
                        <p class="text-emerald-100 text-sm leading-relaxed">
                            Ikuti langkah-langkah sederhana ini untuk mengatur ulang password dan kembali mengakses akun Etanimart-mu.
                        </p>
                    </div>

                    <div class="relative z-10 mt-4 lg:mt-0">
                        <div class="space-y-4">
                            <div class="flex items-center gap-2 lg:gap-3">
                                <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-envelope"></i>
                                </div>
                                <span class="text-xs lg:text-sm font-medium">Masukkan Email</span>
                            </div>
                            <div class="flex items-center gap-2 lg:gap-3">
                                <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-shield-halved"></i>
                                </div>
                                <span class="text-xs lg:text-sm font-medium">Verifikasi Kode OTP</span>
                            </div>
                            <div class="flex items-center gap-2 lg:gap-3">
                                <div class="w-8 h-8 bg-white/20 rounded-lg flex items-center justify-center text-sm">
                                    <i class="fa-solid fa-lock"></i>
                                </div>
                                <span class="text-xs lg:text-sm font-medium">Buat Password Baru</span>
                            </div>
                        </div>

                        <div class="mt-8 pt-6 border-t border-white/20">
                            <p class="text-xs text-emerald-200">Masih butuh bantuan?</p>
                            <a href="#" class="inline-flex items-center gap-2 mt-2 text-sm font-bold hover:text-emerald-200 transition-colors">
                                Hubungi Support <i class="fa-solid fa-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- Toast Container -->
    <div id="toastContainer" class="fixed top-4 right-4 z-50 space-y-3"></div>

    <script>
        // ===== STATE =====
        let currentStep = <?= $step ?>;
        let userEmail = '<?= clean($userEmail) ?>';
        let countdownInterval = null;

        // ===== DOM ELEMENTS =====
        const step1 = document.getElementById('step1');
        const step2 = document.getElementById('step2');
        const step3 = document.getElementById('step3');
        const errorBox = document.getElementById('errorBox');
        const successBox = document.getElementById('successBox');
        const errorText = document.getElementById('errorText');
        const successText = document.getElementById('successText');

        // ===== TOAST =====
        function showToast(message, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            const icon = type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation';
            const colorClass = type === 'success' ? 'bg-emerald-500' : 'bg-red-500';
            toast.className = `toast flex items-center gap-3 ${colorClass} text-white px-5 py-3 rounded-xl shadow-lg text-sm font-medium`;
            toast.innerHTML = `<i class="fa-solid ${icon}"></i> ${message}`;
            container.appendChild(toast);
            setTimeout(() => {
                toast.classList.add('toast-exit');
                setTimeout(() => toast.remove(), 300);
            }, 4000);
        }

        // ===== ERROR / SUCCESS =====
        function showError(msg) {
            errorText.textContent = msg;
            errorBox.classList.remove('hidden');
            errorBox.classList.add('animate-shake');
            successBox.classList.add('hidden');
            setTimeout(() => errorBox.classList.remove('animate-shake'), 500);
        }
        function showSuccess(msg) {
            successText.textContent = msg;
            successBox.classList.remove('hidden');
            errorBox.classList.add('hidden');
        }
        function clearMessages() {
            errorBox.classList.add('hidden');
            successBox.classList.add('hidden');
        }

        // ===== STEP NAVIGATION =====
        function goToStep(step) {
            currentStep = step;

            step1.classList.add('hidden');
            step2.classList.add('hidden');
            step3.classList.add('hidden');

            document.getElementById(`step${step}`).classList.remove('hidden');

            for (let i = 1; i <= 3; i++) {
                const indicator = document.getElementById(`step${i}-indicator`);
                const line = document.getElementById(`line${i}`);

                if (i < step) {
                    indicator.className = 'step-indicator completed w-10 h-10 rounded-full border-2 flex items-center justify-center text-sm font-bold';
                    indicator.innerHTML = '<i class="fa-solid fa-check"></i>';
                    if (line) line.classList.add('active');
                } else if (i === step) {
                    indicator.className = 'step-indicator active w-10 h-10 rounded-full border-2 border-emerald-500 bg-emerald-500 text-white flex items-center justify-center text-sm font-bold';
                    indicator.innerHTML = i;
                    if (line) line.classList.add('active');
                } else {
                    indicator.className = 'step-indicator w-10 h-10 rounded-full border-2 border-gray-300 bg-white text-gray-400 flex items-center justify-center text-sm font-bold';
                    indicator.innerHTML = i;
                    if (line) line.classList.remove('active');
                }
            }
            clearMessages();
        }

        // ===== PASSWORD TOGGLE =====
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

        // ===== AJAX HELPER =====
        async function postData(action, data) {
            const formData = new FormData();
            formData.append('action', action);
            for (const [key, value] of Object.entries(data)) {
                formData.append(key, value);
            }

            try {
                const response = await fetch('forgot_password.php', {
                    method: 'POST',
                    body: formData
                });

                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }

                const text = await response.text();

                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Response bukan JSON:', text);
                    throw new Error('Server error: Response bukan JSON. Cek console untuk detail.');
                }
            } catch (err) {
                console.error('Fetch error:', err);
                throw err;
            }
        }

        // ===== STEP 1: SEND OTP =====
        document.getElementById('btnSendOTP').addEventListener('click', async function() {
            const email = document.getElementById('email').value.trim();
            const btn = this;

            if (!email) {
                showError('Email wajib diisi.');
                return;
            }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                showError('Format email tidak valid.');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-circle-notch spinner"></i> <span>Mengirim...</span>';

            try {
                const result = await postData('send_otp', { email });

                if (result.success) {
                    userEmail = email;
                    document.getElementById('displayEmail').textContent = email;

                    if (result.debug_mode && result.debug_otp) {
                        document.getElementById('debugOtpValue').textContent = result.debug_otp;
                        document.getElementById('debugOtpBox').classList.remove('hidden');
                        showToast('Mode Debug: OTP ditampilkan di bawah', 'success');
                    } else {
                        showToast(result.message, 'success');
                    }

                    goToStep(2);
                    startCountdown();
                    document.querySelector('.otp-input[data-index="0"]').focus();
                } else {
                    showError(result.message);
                }
            } catch (err) {
                showError('Error: ' + err.message);
                console.error('Error detail:', err);
            }

            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> <span>Kirim Kode Verifikasi</span>';
        });

        // ===== STEP 2: OTP INPUTS =====
        const otpInputs = document.querySelectorAll('.otp-input');
        otpInputs.forEach((input, index) => {
            input.addEventListener('input', function(e) {
                const val = this.value.replace(/\D/g, '');
                this.value = val;
                if (val.length === 1 && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
            });

            input.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && this.value === '' && index > 0) {
                    otpInputs[index - 1].focus();
                }
                if (e.key === 'ArrowLeft' && index > 0) {
                    otpInputs[index - 1].focus();
                }
                if (e.key === 'ArrowRight' && index < otpInputs.length - 1) {
                    otpInputs[index + 1].focus();
                }
            });

            input.addEventListener('paste', function(e) {
                e.preventDefault();
                const pasteData = e.clipboardData.getData('text').replace(/\D/g, '').slice(0, 6);
                pasteData.split('').forEach((char, i) => {
                    if (otpInputs[i]) otpInputs[i].value = char;
                });
                if (pasteData.length === 6) {
                    otpInputs[5].focus();
                } else if (otpInputs[pasteData.length]) {
                    otpInputs[pasteData.length].focus();
                }
            });
        });

        // ===== COUNTDOWN =====
        function startCountdown() {
            let seconds = 60;
            const btnResend = document.getElementById('btnResend');
            const countdownEl = document.getElementById('countdown');

            btnResend.disabled = true;

            if (countdownInterval) clearInterval(countdownInterval);

            countdownInterval = setInterval(() => {
                seconds--;
                countdownEl.textContent = seconds;

                if (seconds <= 0) {
                    clearInterval(countdownInterval);
                    btnResend.disabled = false;
                    btnResend.innerHTML = 'Kirim Ulang';
                }
            }, 1000);
        }

        // ===== RESEND OTP =====
        document.getElementById('btnResend').addEventListener('click', async function() {
            const btn = this;
            btn.disabled = true;
            btn.innerHTML = 'Mengirim...';

            try {
                const result = await postData('resend_otp', {});

                if (result.success) {
                    if (result.debug_mode && result.debug_otp) {
                        document.getElementById('debugOtpValue').textContent = result.debug_otp;
                        document.getElementById('debugOtpBox').classList.remove('hidden');
                        showToast('Mode Debug: OTP baru ditampilkan', 'success');
                    } else {
                        showToast(result.message, 'success');
                    }
                    startCountdown();
                    otpInputs.forEach(i => i.value = '');
                    otpInputs[0].focus();
                } else {
                    showError(result.message);
                }
            } catch (err) {
                showError('Error: ' + err.message);
                btn.disabled = false;
                btn.innerHTML = 'Kirim Ulang';
            }
        });

        // ===== VERIFY OTP =====
        document.getElementById('btnVerifyOTP').addEventListener('click', async function() {
            const btn = this;
            let enteredOTP = '';
            otpInputs.forEach(input => enteredOTP += input.value);

            if (enteredOTP.length !== 6) {
                showError('Masukkan 6 digit kode OTP.');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-circle-notch spinner"></i> <span>Memverifikasi...</span>';

            try {
                const result = await postData('verify_otp', { otp: enteredOTP });

                if (result.success) {
                    showToast(result.message, 'success');
                    goToStep(3);
                } else {
                    showError(result.message);
                    otpInputs.forEach(i => { i.value = ''; i.classList.add('border-red-300'); });
                    setTimeout(() => {
                        otpInputs.forEach(i => i.classList.remove('border-red-300'));
                    }, 2000);
                    otpInputs[0].focus();
                }
            } catch (err) {
                showError('Error: ' + err.message);
            }

            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-shield-halved"></i> <span>Verifikasi Kode</span>';
        });

        // ===== PASSWORD STRENGTH =====
        document.getElementById('newPassword').addEventListener('input', function() {
            const val = this.value;
            const strengthBox = document.getElementById('passwordStrength');

            if (val.length > 0) {
                strengthBox.classList.remove('hidden');
            } else {
                strengthBox.classList.add('hidden');
                return;
            }

            let score = 0;
            if (val.length >= 8) score++;
            if (/[A-Z]/.test(val)) score++;
            if (/[0-9]/.test(val)) score++;
            if (/[^A-Za-z0-9]/.test(val)) score++;

            const bars = [
                document.getElementById('strength1'),
                document.getElementById('strength2'),
                document.getElementById('strength3'),
                document.getElementById('strength4')
            ];
            const strengthText = document.getElementById('strengthText');

            const colors = ['bg-red-400', 'bg-orange-400', 'bg-yellow-400', 'bg-emerald-500'];
            const texts = ['Lemah', 'Cukup', 'Kuat', 'Sangat Kuat'];

            bars.forEach((bar, i) => {
                bar.className = 'h-1 flex-1 rounded-full bg-gray-200 transition-all';
                if (i < score) {
                    bar.classList.add(colors[score - 1]);
                }
            });

            strengthText.textContent = texts[score - 1] || '';
            strengthText.className = `text-xs font-medium ${score === 1 ? 'text-red-500' : score === 2 ? 'text-orange-500' : score === 3 ? 'text-yellow-600' : 'text-emerald-600'}`;
        });

        // ===== RESET PASSWORD =====
        document.getElementById('btnResetPassword').addEventListener('click', async function() {
            const newPass = document.getElementById('newPassword').value;
            const confirmPass = document.getElementById('confirmPassword').value;
            const btn = this;

            if (!newPass || !confirmPass) {
                showError('Semua field wajib diisi.');
                return;
            }

            if (newPass.length < 8) {
                showError('Password minimal 8 karakter.');
                return;
            }

            if (newPass !== confirmPass) {
                showError('Password tidak cocok.');
                document.getElementById('confirmPassword').classList.add('border-red-300');
                setTimeout(() => {
                    document.getElementById('confirmPassword').classList.remove('border-red-300');
                }, 2000);
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-circle-notch spinner"></i> <span>Memproses...</span>';

            try {
                const result = await postData('reset_password', { 
                    new_password: newPass, 
                    confirm_password: confirmPass 
                });

                if (result.success) {
                    showToast(result.message, 'success');

                    step3.innerHTML = `
                        <div class="text-center py-8">
                            <div class="w-20 h-20 bg-emerald-100 rounded-full flex items-center justify-center mx-auto mb-6 animate-bounce">
                                <i class="fa-solid fa-check text-emerald-600 text-3xl"></i>
                            </div>
                            <h3 class="text-xl font-bold text-gray-900 mb-2">Berhasil!</h3>
                            <p class="text-sm text-gray-500 mb-6">Passwordmu telah berhasil diatur ulang.</p>
                            <a href="login.php" class="inline-flex items-center gap-2 px-8 py-3 btn-primary text-white font-bold rounded-xl shadow-lg">
                                <i class="fa-solid fa-right-to-bracket"></i>
                                Masuk Sekarang
                            </a>
                        </div>
                    `;
                } else {
                    showError(result.message);
                }
            } catch (err) {
                showError('Error: ' + err.message);
            }

            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-rotate"></i> <span>Reset Password</span>';
        });

        // ===== INIT: Restore step from session =====
        if (currentStep > 1) {
            goToStep(currentStep);
            if (currentStep === 2) {
                startCountdown();
            }
        }
    </script>
</body>
</html>