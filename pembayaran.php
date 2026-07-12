<?php
// =============================================================================
// pembayaran.php - Halaman Pembayaran Midtrans Snap (PRODUCTION FIXED)
// =============================================================================
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once 'koneksi.php';

// Anti-cache
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Pragma: no-cache");

// Load Midtrans config
$midtransConfig = require 'config/midtrans.php';

// Cek login & role
$userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || $userRole !== 'pembeli') {
    header("Location: login.php?redirect=pembayaran.php");
    exit();
}

$userId = (int)$_SESSION['user_id'];

// Ambil order_id dari URL
$orderId = $_GET['order_id'] ?? '';

if (empty($orderId)) {
    header("Location: keranjang.php");
    exit();
}

// Ambil data pesanan FRESH dari database
$stmt = $pdo->prepare("
    SELECT p.*, u.nama as nama_user, u.email 
    FROM pesanan p 
    JOIN users u ON p.id_user = u.id 
    WHERE p.order_id = ? AND p.id_user = ?
");
$stmt->execute([$orderId, $userId]);
$pesanan = $stmt->fetch();

if (!$pesanan) {
    header("Location: keranjang.php");
    exit();
}

// Ambil detail item pesanan
$stmtItems = $pdo->prepare("SELECT * FROM detail_pesanan WHERE id_pesanan = ?");
$stmtItems->execute([$pesanan['id']]);
$items = $stmtItems->fetchAll();

// Cek snap token
$snapToken = $pesanan['snap_token'] ?? '';

if (empty($snapToken)) {
    header("Location: checkout.php");
    exit();
}

// Helper
function clean($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function formatRupiah($angka) { return 'Rp ' . number_format((int)($angka ?? 0), 0, ',', '.'); }

// Status label & color
$statusConfig = [
    'pending'    => ['label' => 'Menunggu Pembayaran', 'color' => 'yellow', 'icon' => 'fa-clock'],
    'paid'       => ['label' => 'Sudah Dibayar',       'color' => 'green',  'icon' => 'fa-check-circle'],
    'settlement' => ['label' => 'Pembayaran Berhasil', 'color' => 'green',  'icon' => 'fa-check-circle'],
    'capture'    => ['label' => 'Pembayaran Berhasil', 'color' => 'green',  'icon' => 'fa-check-circle'],
    'completed'  => ['label' => 'Pesanan Selesai',     'color' => 'green',  'icon' => 'fa-check-double'], // ← TAMBAH INI
    'expire'     => ['label' => 'Kadaluarsa',          'color' => 'gray',   'icon' => 'fa-times-circle'],
    'cancelled'  => ['label' => 'Dibatalkan',          'color' => 'red',    'icon' => 'fa-times-circle'],
    'deny'       => ['label' => 'Ditolak',             'color' => 'red',    'icon' => 'fa-times-circle'],
    'failure'    => ['label' => 'Gagal',               'color' => 'red',    'icon' => 'fa-times-circle'],
    'shipped'    => ['label' => 'Dikirim',             'color' => 'blue',   'icon' => 'fa-truck'],
];

$status = $pesanan['status'] ?? 'pending';
$statusInfo = $statusConfig[$status] ?? $statusConfig['pending'];

// Konfigurasi Midtrans
$clientKey = $midtransConfig['client_key'] ?? 'SB-Mid-client-XXXX';
$snapJsUrl = $midtransConfig['is_production'] 
    ? 'https://app.midtrans.com/snap/snap.js' 
    : 'https://app.sandbox.midtrans.com/snap/snap.js';
$isSandbox = !$midtransConfig['is_production'];
$isPending = ($status === 'pending');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Pembayaran - Etanimart</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="<?= $snapJsUrl ?>" data-client-key="<?= clean($clientKey) ?>"></script>
    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        .btn-primary { background: linear-gradient(135deg, #10b981 0%, #059669 100%); transition: all 0.3s ease; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.5); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }
        .pulse-ring { animation: pulse-ring 2s cubic-bezier(0.215, 0.61, 0.355, 1) infinite; }
        @keyframes pulse-ring {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }
        .loading-spinner {
            display: inline-block; width: 16px; height: 16px;
            border: 2px solid #ffffff; border-radius: 50%; border-top-color: transparent;
            animation: spin 1s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        .sandbox-badge {
            background: linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%);
            animation: shimmer 2s infinite;
        }
        @keyframes shimmer { 0% { opacity: 1; } 50% { opacity: 0.8; } 100% { opacity: 1; } }
        .success-pop { animation: successPop 0.5s ease; }
        @keyframes successPop { 0% { transform: scale(0.8); opacity: 0; } 50% { transform: scale(1.1); } 100% { transform: scale(1); opacity: 1; } }
        
        /* Progress bar untuk polling */
        .progress-bar {
            width: 100%; height: 4px; background: #e2e8f0; border-radius: 2px; overflow: hidden; margin-top: 12px;
        }
        .progress-fill {
            height: 100%; background: linear-gradient(90deg, #10b981, #059669);
            width: 0%; transition: width 0.3s ease;
            animation: progressMove 2s linear infinite;
        }
        @keyframes progressMove {
            0% { width: 0%; margin-left: 0%; }
            50% { width: 50%; margin-left: 25%; }
            100% { width: 0%; margin-left: 100%; }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased">

<nav class="bg-white border-b border-gray-100 sticky top-0 z-50">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">
        <a href="index.php" class="flex items-center gap-2 font-bold text-2xl text-white">
    <img src="uploads/logo/tani.png" 
         alt="Etanimart" 
         class="logo-img"
         style="height: 70px; width: auto; object-fit: contain;"
         onerror="this.style.display='none'">
</a>
            <div class="flex items-center gap-4">
                <?php if ($isSandbox): ?>
                <span class="sandbox-badge text-white text-xs font-bold px-3 py-1 rounded-full">
                    <i class="fa-solid fa-flask mr-1"></i> SANDBOX MODE
                </span>
                <?php endif; ?>
                <a href="pesanan.php" class="text-sm font-medium text-gray-600 hover:text-emerald-600 transition-colors">
                    <i class="fa-solid fa-bag-shopping mr-1"></i> Pesanan Saya
                </a>
            </div>
        </div>
    </div>
</nav>

<main class="py-8 md:py-12">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">

        <!-- Header -->
        <div class="text-center mb-8" id="status-header">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-<?= $statusInfo['color'] ?>-100 text-<?= $statusInfo['color'] ?>-600 text-2xl mb-4 <?= $status === 'pending' ? 'pulse-ring' : '' ?> success-pop">
                <i class="fa-solid <?= $statusInfo['icon'] ?>"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-900">Pembayaran</h1>
            <p class="text-gray-500 mt-1">Order ID: <span class="font-mono font-semibold text-gray-700"><?= clean($pesanan['order_id']) ?></span></p>
            <div class="mt-3 inline-flex items-center gap-2 px-4 py-1.5 rounded-full bg-<?= $statusInfo['color'] ?>-50 border border-<?= $statusInfo['color'] ?>-200 text-<?= $statusInfo['color'] ?>-700 text-sm font-medium">
                <i class="fa-solid <?= $statusInfo['icon'] ?>"></i>
                <?= $statusInfo['label'] ?>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">

            <!-- LEFT: Info Pesanan -->
            <div class="lg:col-span-3 space-y-6">

                <!-- Ringkasan -->
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                    <h2 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-receipt text-emerald-500"></i> Ringkasan Pesanan
                    </h2>
                    <div class="space-y-3 mb-4">
                        <?php foreach ($items as $item): ?>
                        <div class="flex justify-between items-center py-2 border-b border-gray-50 last:border-0">
                            <div>
                                <p class="text-sm font-semibold text-gray-900"><?= clean($item['nama_produk']) ?></p>
                                <p class="text-xs text-gray-500"><?= $item['jumlah'] ?> x <?= formatRupiah($item['harga_satuan']) ?></p>
                            </div>
                            <span class="text-sm font-bold text-emerald-600 font-mono"><?= formatRupiah($item['subtotal']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="border-t border-gray-100 pt-4 space-y-2">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Subtotal</span>
                            <span class="font-semibold text-gray-900"><?= formatRupiah($pesanan['total_harga']) ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Ongkir</span>
                            <span class="font-semibold text-emerald-600">Gratis</span>
                        </div>
                        <div class="h-px bg-gray-100 my-2"></div>
                        <div class="flex justify-between">
                            <span class="text-gray-900 font-bold">Total Bayar</span>
                            <span class="text-xl font-bold text-emerald-600 font-mono"><?= formatRupiah($pesanan['total_harga']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Info Pengiriman -->
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm">
                    <h2 class="text-lg font-bold text-gray-900 mb-4 flex items-center gap-2">
                        <i class="fa-solid fa-truck-fast text-emerald-500"></i> Informasi Pengiriman
                    </h2>
                    <div class="space-y-3 text-sm">
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-user text-gray-400 mt-0.5"></i>
                            <div>
                                <p class="font-semibold text-gray-900"><?= clean($pesanan['nama_penerima']) ?></p>
                                <p class="text-gray-500"><?= clean($pesanan['no_telepon']) ?></p>
                            </div>
                        </div>
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-location-dot text-gray-400 mt-0.5"></i>
                            <p class="text-gray-700"><?= nl2br(clean($pesanan['alamat'])) ?></p>
                        </div>
                        <?php if (!empty($pesanan['catatan'])): ?>
                        <div class="flex items-start gap-3">
                            <i class="fa-solid fa-note-sticky text-gray-400 mt-0.5"></i>
                            <p class="text-gray-700"><?= clean($pesanan['catatan']) ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($isSandbox && $status === 'pending'): ?>
                <!-- Info Kartu Test Sandbox -->
                <div class="bg-yellow-50 rounded-2xl border border-yellow-200 p-4">
                    <div class="flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-yellow-600 mt-0.5"></i>
                        <div>
                            <h3 class="text-sm font-bold text-yellow-800">Mode Sandbox</h3>
                            <p class="text-xs text-yellow-700 mt-1">Gunakan kartu test berikut:</p>
                            <div class="mt-3 space-y-2">
                                <div class="bg-white rounded-lg p-3 border border-yellow-200">
                                    <div class="flex justify-between items-center">
                                        <span class="text-xs font-semibold text-gray-700">Visa</span>
                                        <span class="text-xs font-mono font-bold text-gray-900">4811 1111 1111 1114</span>
                                    </div>
                                </div>
                                <div class="bg-white rounded-lg p-3 border border-yellow-200">
                                    <div class="flex justify-between items-center">
                                        <span class="text-xs font-semibold text-gray-700">Mastercard</span>
                                        <span class="text-xs font-mono font-bold text-gray-900">5211 1111 1111 1117</span>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div class="bg-white rounded-lg p-2 border border-yellow-200 text-center">
                                        <span class="text-[10px] text-gray-500">CVV</span>
                                        <p class="text-xs font-mono font-bold text-gray-900">123</p>
                                    </div>
                                    <div class="bg-white rounded-lg p-2 border border-yellow-200 text-center">
                                        <span class="text-[10px] text-gray-500">Exp</span>
                                        <p class="text-xs font-mono font-bold text-gray-900">01/2025</p>
                                    </div>
                                </div>
                                <div class="bg-white rounded-lg p-2 border border-yellow-200 text-center">
                                    <span class="text-[10px] text-gray-500">3DS OTP</span>
                                    <p class="text-xs font-mono font-bold text-gray-900">112233</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <!-- RIGHT: Aksi Pembayaran -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm sticky top-24" id="action-panel">

                    <?php if ($status === 'pending'): ?>
                        <!-- Status: Menunggu Pembayaran -->
                        <div class="text-center mb-6" id="pending-content">
                            <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-emerald-50 flex items-center justify-center">
                                <i class="fa-solid fa-credit-card text-3xl text-emerald-500"></i>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 mb-1">Selesaikan Pembayaran</h3>
                            <p class="text-sm text-gray-500">Klik tombol di bawah untuk memilih metode pembayaran</p>
                        </div>

                        <!-- Polling Status Content (hidden by default) -->
                        <div class="text-center mb-6 hidden" id="polling-content">
                            <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-blue-50 flex items-center justify-center">
                                <i class="fa-solid fa-spinner fa-spin text-3xl text-blue-500"></i>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 mb-1">Menunggu Konfirmasi</h3>
                            <p class="text-sm text-gray-500">Sedang memverifikasi pembayaran Anda...</p>
                            <div class="progress-bar">
                                <div class="progress-fill"></div>
                            </div>
                            <p class="text-xs text-gray-400 mt-2" id="poll-timer">Mencoba 0/60 detik</p>
                        </div>

                        <button id="pay-button" class="w-full btn-primary text-white font-bold py-4 rounded-2xl shadow-lg flex items-center justify-center gap-2 mb-4">
                            <i class="fa-solid fa-wallet" id="btn-icon"></i>
                            <span id="btn-text">Bayar Sekarang</span>
                        </button>

                        <!-- Tombol Refresh Manual (hidden by default) -->
                        <button id="refresh-btn" class="w-full hidden bg-gray-100 text-gray-700 font-bold py-3 rounded-xl flex items-center justify-center gap-2 mb-4 hover:bg-gray-200 transition-colors">
                            <i class="fa-solid fa-rotate"></i>
                            <span>Refresh Status</span>
                        </button>

                        <div class="text-center">
                            <a href="pesanan.php" class="text-sm text-gray-500 hover:text-emerald-600 transition-colors">
                                <i class="fa-solid fa-arrow-left mr-1"></i> Kembali ke Pesanan
                            </a>
                        </div>

                        <div class="mt-6 pt-4 border-t border-gray-100">
                            <p class="text-xs text-gray-400 text-center">
                                <i class="fa-solid fa-shield-halved mr-1"></i>
                                Pembayaran aman & terenkripsi oleh Midtrans
                            </p>
                        </div>

                    <?php elseif (in_array($status, ['paid', 'settlement', 'capture'])): ?>
                        <!-- Status: Sudah Dibayar -->
                        <div class="text-center success-pop">
                            <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-green-50 flex items-center justify-center">
                                <i class="fa-solid fa-circle-check text-3xl text-green-500"></i>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 mb-1">Pembayaran Berhasil!</h3>
                            <p class="text-sm text-gray-500 mb-6">Terima kasih, pesanan Anda sedang diproses.</p>
                            <a href="pesanan.php" class="w-full inline-flex items-center justify-center gap-2 bg-gray-900 text-white font-bold py-3 rounded-xl hover:bg-gray-800 transition-colors">
                                <i class="fa-solid fa-bag-shopping"></i> Lihat Pesanan Saya
                            </a>
                        </div>

                    <?php else: ?>
                        <!-- Status: Gagal/Expired/Cancel -->
                        <div class="text-center">
                            <div class="w-20 h-20 mx-auto mb-4 rounded-full bg-red-50 flex items-center justify-center">
                                <i class="fa-solid fa-circle-xmark text-3xl text-red-500"></i>
                            </div>
                            <h3 class="text-lg font-bold text-gray-900 mb-1">Pembayaran <?= $statusInfo['label'] ?></h3>
                            <p class="text-sm text-gray-500 mb-6">Silakan lakukan pemesanan ulang.</p>
                            <a href="index.php" class="w-full inline-flex items-center justify-center gap-2 bg-emerald-600 text-white font-bold py-3 rounded-xl hover:bg-emerald-700 transition-colors">
                                <i class="fa-solid fa-store"></i> Belanja Lagi
                            </a>
                        </div>
                    <?php endif; ?>

                </div>
            </div>

        </div>
    </div>
</main>

<?php if ($status === 'pending'): ?>
<script type="text/javascript">
    var payButton = document.getElementById('pay-button');
    var btnText = document.getElementById('btn-text');
    var btnIcon = document.getElementById('btn-icon');
    var pendingContent = document.getElementById('pending-content');
    var pollingContent = document.getElementById('polling-content');
    var refreshBtn = document.getElementById('refresh-btn');
    var pollTimer = document.getElementById('poll-timer');
    var isPolling = false;
    
    // Tombol refresh manual
    refreshBtn.addEventListener('click', function() {
        window.location.reload();
    });
    
    payButton.addEventListener('click', function () {
        payButton.disabled = true;
        btnText.textContent = 'Memuat...';
        btnIcon.className = 'loading-spinner';
        
        snap.pay('<?= clean($snapToken) ?>', {
            onSuccess: function(result){
                console.log('Success:', result);
                startPolling();
            },
            onPending: function(result){
                console.log('Pending:', result);
                alert('Pembayaran dalam proses. Silakan selesaikan pembayaran sesuai instruksi.');
                startPolling();
            },
            onError: function(result){
                console.log('Error:', result);
                payButton.disabled = false;
                btnText.textContent = 'Bayar Sekarang';
                btnIcon.className = 'fa-solid fa-wallet';
                alert('Pembayaran gagal: ' + (result.status_message || 'Terjadi kesalahan'));
            },
            onClose: function(){
                console.log('Popup ditutup');
                // Kalau popup ditutup, cek apakah sudah bayar
                startPolling();
            }
        });
    });
    
    // ==========================================
    // POLLING STATUS - AUTO REFRESH
    // ==========================================
    function startPolling() {
        if (isPolling) return;
        isPolling = true;
        
        // Sembunyikan tombol bayar, tampilkan polling UI
        payButton.classList.add('hidden');
        pendingContent.classList.add('hidden');
        pollingContent.classList.remove('hidden');
        
        let pollCount = 0;
        const maxPoll = 60; // 60 x 1 detik = 1 menit (lebih agresif)
        
        const interval = setInterval(function() {
            pollCount++;
            pollTimer.textContent = 'Mencoba ' + pollCount + '/' + maxPoll + ' detik';
            
            if (pollCount > maxPoll) {
                clearInterval(interval);
                isPolling = false;
                pollingContent.classList.add('hidden');
                refreshBtn.classList.remove('hidden');
                
                // Tampilkan tombol bayar lagi
                payButton.classList.remove('hidden');
                payButton.disabled = false;
                btnText.textContent = 'Coba Bayar Lagi';
                btnIcon.className = 'fa-solid fa-rotate';
                return;
            }
            
            fetch('cek_status_midtrans.php?order_id=<?= clean($orderId) ?>&_=' + Date.now())
                .then(res => res.json())
                .then(data => {
                    console.log('Poll #' + pollCount + ' status:', data.status);
                    
                    if (data.status !== 'pending') {
                        // STATUS BERUBAH! Reload halaman
                        clearInterval(interval);
                        window.location.reload();
                    }
                })
                .catch(err => {
                    console.error('Poll error:', err);
                });
        }, 1000); // Cek setiap 1 detik (lebih cepat)
    }
    
    // ==========================================
    // BACKGROUND POLLING (saat halaman dibuka)
    // ==========================================
    // Kalau user refresh halaman dan status masih pending,
    // cek apakah sebenarnya sudah berubah di background
    (function backgroundCheck() {
        fetch('cek_status_midtrans.php?order_id=<?= clean($orderId) ?>&_=' + Date.now())
            .then(res => res.json())
            .then(data => {
                if (data.status !== 'pending') {
                    window.location.reload();
                }
            });
    })();
</script>
<?php endif; ?>

</body>
</html>