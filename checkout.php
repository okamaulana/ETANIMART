<?php
// =============================================================================
// checkout.php - Checkout dengan Midtrans Snap (FIXED + id_penjual)
// =============================================================================
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();
require_once 'koneksi.php';

// Auto delete pesanan cancelled > 24 jam
try {
    $pdo->query("DELETE FROM pesanan WHERE status = 'cancelled' AND updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
} catch (PDOException $e) {}

// Load Midtrans config
$midtransConfig = require 'config/midtrans.php';

// Cek login & role
// ✅ JADI INI:
// Cek login & role — FIX: support both user_role dan role
$userRole = $_SESSION['user_role'] ?? $_SESSION['role'] ?? '';
if (!isset($_SESSION['user_id']) || $userRole !== 'pembeli') {
    header("Location: login.php?redirect=checkout.php");
    exit();
}

$userId = (int)$_SESSION['user_id'];

// ==========================================
// AMBIL ITEM CHECKOUT
// ==========================================
$checkoutItems = [];
$totalHarga = 0;

if (isset($_SESSION['checkout_cart']) && !empty($_SESSION['checkout_cart'])) {
    $cartIds = array_map('intval', $_SESSION['checkout_cart']);
    $placeholders = implode(',', array_fill(0, count($cartIds), '?'));

    $stmt = $pdo->prepare("
        SELECT k.id as cart_id, k.jumlah,
               p.id as produk_id, p.nama, p.harga, p.gambar, p.stok, p.kategori,
               p.id_penjual, u.nama_toko
        FROM keranjang k
        JOIN produk p ON k.id_produk = p.id
        LEFT JOIN users u ON p.id_penjual = u.id
        WHERE k.id_user = ? AND k.id IN ($placeholders)
    ");
    $stmt->execute(array_merge([$userId], $cartIds));
    $checkoutItems = $stmt->fetchAll();
}

elseif (isset($_SESSION['checkout_direct']) && !empty($_SESSION['checkout_direct'])) {
    $direct = $_SESSION['checkout_direct'];
    $stmt = $pdo->prepare("
        SELECT p.id, p.nama, p.harga, p.gambar, p.stok, p.kategori,
               p.id_penjual, u.nama_toko
        FROM produk p
        LEFT JOIN users u ON p.id_penjual = u.id
        WHERE p.id = ?
    ");
    $stmt->execute([$direct['id_produk']]);
    $produk = $stmt->fetch();

    if ($produk) {
        $checkoutItems = [[
            'cart_id' => 0,
            'jumlah' => $direct['jumlah'],
            'produk_id' => $produk['id'],
            'nama' => $produk['nama'],
            'harga' => $produk['harga'],
            'gambar' => $produk['gambar'],
            'stok' => $produk['stok'],
            'kategori' => $produk['kategori'],
            'id_penjual' => $produk['id_penjual'],
            'nama_toko' => $produk['nama_toko']
        ]];
    }
}

if (empty($checkoutItems)) {
    header("Location: keranjang.php");
    exit();
}

// Validasi stok & hitung total
$stokError = [];
foreach ($checkoutItems as $item) {
    if ($item['jumlah'] > $item['stok']) {
        $stokError[] = clean($item['nama']) . ' (stok: ' . $item['stok'] . ', pesan: ' . $item['jumlah'] . ')';
    }
    $totalHarga += $item['harga'] * $item['jumlah'];
}

$stmtUser = $pdo->prepare("SELECT nama, no_telepon, alamat FROM users WHERE id = ?");
$stmtUser->execute([$userId]);
$userData = $stmtUser->fetch();

// ==========================================
// PROSES CHECKOUT (POST)
// ==========================================
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['checkout'])) {
    // Cek error stok dulu
    if (!empty($stokError)) {
        $error = 'Stok tidak mencukupi: ' . implode(', ', $stokError);
    } else {
        $nama = trim($_POST['nama_penerima'] ?? '');
        $telepon = trim($_POST['no_telepon'] ?? '');
        $alamat = trim($_POST['alamat'] ?? '');
        $catatan = trim($_POST['catatan'] ?? '');

        if (empty($nama) || empty($telepon) || empty($alamat)) {
            $error = 'Nama penerima, nomor telepon, dan alamat wajib diisi.';
        } else {
            $orderId = 'ETN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -5));

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    INSERT INTO pesanan (id_user, order_id, total_harga, status, nama_penerima, no_telepon, alamat, catatan)
                    VALUES (?, ?, ?, 'pending', ?, ?, ?, ?)
                ");
                $stmt->execute([$userId, $orderId, $totalHarga, $nama, $telepon, $alamat, $catatan]);
                $pesananId = $pdo->lastInsertId();

                // Simpan detail pesanan DENGAN id_penjual
                foreach ($checkoutItems as $item) {
                    $stmtDetail = $pdo->prepare("
                        INSERT INTO detail_pesanan (id_pesanan, id_produk, nama_produk, harga_satuan, jumlah, subtotal, id_penjual)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $subtotal = $item['harga'] * $item['jumlah'];
                    $stmtDetail->execute([
                        $pesananId, 
                        $item['produk_id'], 
                        $item['nama'], 
                        $item['harga'], 
                        $item['jumlah'], 
                        $subtotal,
                        $item['id_penjual'] ?? null  // <-- FIX: simpan id_penjual
                    ]);
                }

                $cartIds = array_filter(array_column($checkoutItems, 'cart_id'));
                if (!empty($cartIds)) {
                    $placeholders = implode(',', array_fill(0, count($cartIds), '?'));
                    $stmtDel = $pdo->prepare("DELETE FROM keranjang WHERE id_user = ? AND id IN ($placeholders)");
                    $stmtDel->execute(array_merge([$userId], $cartIds));
                }

                $pdo->commit();

                // ==========================================
                // Generate Snap Token dari Midtrans
                // ==========================================
                $autoloadPath = __DIR__ . '/vendor/autoload.php';
                if (!file_exists($autoloadPath)) {
                    throw new Exception("Midtrans library tidak ditemukan. Pastikan sudah install via composer.");
                }

                require_once $autoloadPath;

                if (!class_exists('Midtrans\Config')) {
                    throw new Exception("Class Midtrans tidak ditemukan. Periksa instalasi library.");
                }

                Midtrans\Config::$serverKey = $midtransConfig['server_key'];
                Midtrans\Config::$isProduction = $midtransConfig['is_production'];
                Midtrans\Config::$isSanitized = $midtransConfig['is_sanitized'];
                Midtrans\Config::$is3ds = $midtransConfig['is_3ds'];

                $params = [
                    'transaction_details' => [
                        'order_id' => $orderId,
                        'gross_amount' => (int)$totalHarga,
                    ],
                    'customer_details' => [
                        'first_name' => substr($nama, 0, 50),
                        'phone' => $telepon,
                    ],
                    'item_details' => array_map(function($item) {
                        return [
                            'id' => (string)$item['produk_id'],
                            'price' => (int)$item['harga'],
                            'quantity' => (int)$item['jumlah'],
                            'name' => substr($item['nama'], 0, 50),
                        ];
                    }, $checkoutItems),
                ];

                $snapToken = Midtrans\Snap::getSnapToken($params);

                if (empty($snapToken)) {
                    throw new Exception("Gagal mendapatkan Snap Token dari Midtrans.");
                }

                $stmt = $pdo->prepare("UPDATE pesanan SET snap_token = ? WHERE order_id = ?");
                $stmt->execute([$snapToken, $orderId]);

                unset($_SESSION['checkout_cart']);
                unset($_SESSION['checkout_direct']);

                header("Location: pembayaran.php?order_id=" . urlencode($orderId));
                exit();

            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Gagal memproses checkout: ' . $e->getMessage();
            }
        }
    }
}

function clean($str) { return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8'); }
function formatRupiah($angka) { return 'Rp ' . number_format((int)($angka ?? 0), 0, ',', '.'); }
function getThumb($gambarJson) {
    if (empty($gambarJson)) return 'https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image';
    $decoded = json_decode($gambarJson, true);
    if (is_array($decoded) && !empty($decoded[0])) return 'uploads/' . $decoded[0];
    return 'https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Checkout - Etanimart</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        .nav-link { position: relative; }
        .nav-link::after { content: ''; position: absolute; bottom: -4px; left: 0; width: 0; height: 2px; background: #10b981; transition: width 0.3s ease; }
        .nav-link:hover::after { width: 100%; }
        .btn-primary { background: linear-gradient(135deg, #10b981 0%, #059669 100%); transition: all 0.3s ease; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.5); }
        .btn-primary:disabled { opacity: 0.6; cursor: not-allowed; transform: none; box-shadow: none; }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased">

<nav class="bg-white border-b border-gray-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16 items-center">
        <a href="index.php" class="flex items-center gap-2 font-bold text-2xl text-white">
    <img src="uploads/logo/tani.png" 
         alt="Etanimart" 
         class="logo-img"
         style="height: 70px; width: auto; object-fit: contain;"
         onerror="this.style.display='none'">
</a>
            <div class="flex items-center gap-4 text-sm text-gray-500">
                <span class="flex items-center gap-2"><i class="fa-solid fa-cart-shopping text-emerald-500"></i> Checkout</span>
            </div>
        </div>
    </div>
</nav>

<main class="py-8 md:py-12">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">

            <div class="lg:col-span-2 space-y-6">
                <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
                    <i class="fa-solid fa-truck-fast text-emerald-500"></i> Informasi Pengiriman
                </h1>

                <?php if (!empty($stokError)): ?>
                <div class="p-4 bg-red-50 border border-red-200 rounded-2xl text-red-700 text-sm">
                    <div class="flex items-center gap-2 mb-2 font-semibold">
                        <i class="fa-solid fa-circle-exclamation"></i> Stok tidak mencukupi
                    </div>
                    <ul class="list-disc list-inside space-y-1">
                        <?php foreach ($stokError as $err): ?>
                        <li><?= $err ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                <div class="p-4 bg-red-50 border border-red-200 rounded-2xl text-red-700 text-sm font-medium flex items-center gap-2">
                    <i class="fa-solid fa-circle-exclamation"></i> <?= clean($error) ?>
                </div>
                <?php endif; ?>

                <form method="POST" action="" class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm space-y-5">
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Nama Penerima</label>
                        <input type="text" name="nama_penerima" value="<?= clean($userData['nama'] ?? '') ?>" required
                            class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all"
                            placeholder="Nama lengkap penerima">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Nomor Telepon</label>
                        <input type="tel" name="no_telepon" value="<?= clean($userData['no_telepon'] ?? '') ?>" required
                            class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all"
                            placeholder="0812-3456-7890">
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Alamat Lengkap</label>
                        <textarea name="alamat" rows="3" required
                            class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all resize-none"
                            placeholder="Jalan, RT/RW, Kelurahan, Kecamatan, Kota, Kode Pos"><?= clean($userData['alamat'] ?? '') ?></textarea>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Catatan (Opsional)</label>
                        <textarea name="catatan" rows="2"
                            class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm focus:outline-none focus:border-emerald-500 focus:ring-2 focus:ring-emerald-500/20 transition-all resize-none"
                            placeholder="Catatan khusus untuk pengiriman"></textarea>
                    </div>

                    <button type="submit" name="checkout" 
                        class="w-full btn-primary text-white font-bold py-4 rounded-2xl shadow-lg flex items-center justify-center gap-2"
                        <?= !empty($stokError) ? 'disabled' : '' ?>>
                        <i class="fa-solid fa-credit-card"></i> 
                        <?= !empty($stokError) ? 'Stok Tidak Mencukupi' : 'Lanjut ke Pembayaran' ?>
                    </button>
                </form>
            </div>

            <div class="lg:col-span-1">
                <div class="bg-white rounded-2xl border border-gray-100 p-6 shadow-sm sticky top-24">
                    <h2 class="text-lg font-bold text-gray-900 mb-5 flex items-center gap-2">
                        <i class="fa-solid fa-receipt text-emerald-500"></i> Ringkasan Pesanan
                    </h2>

                    <div class="space-y-4 mb-6 max-h-80 overflow-y-auto">
                        <?php foreach ($checkoutItems as $item): 
                            $thumb = getThumb($item['gambar']);
                        ?>
                        <div class="flex gap-3">
                            <img src="<?= $thumb ?>" class="w-16 h-16 rounded-lg object-cover bg-gray-50 border border-gray-100 shrink-0" onerror="this.src='https://placehold.co/100x100/e2e8f0/94a3b8?text=No+Image'">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-bold text-gray-900 line-clamp-2"><?= clean($item['nama']) ?></p>
                                <?php if (!empty($item['nama_toko'])): ?>
                                <p class="text-[10px] text-gray-400"><i class="fa-solid fa-store mr-1"></i><?= clean($item['nama_toko']) ?></p>
                                <?php endif; ?>
                                <p class="text-xs text-gray-500"><?= $item['jumlah'] ?> x <?= formatRupiah($item['harga']) ?></p>
                                <?php if ($item['jumlah'] > $item['stok']): ?>
                                <p class="text-[10px] text-red-500 font-medium"><i class="fa-solid fa-triangle-exclamation mr-1"></i>Stok: <?= $item['stok'] ?></p>
                                <?php endif; ?>
                            </div>
                            <p class="text-sm font-bold text-emerald-600 font-mono shrink-0">
                                <?= formatRupiah($item['harga'] * $item['jumlah']) ?>
                            </p>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="border-t border-gray-100 pt-4 space-y-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Subtotal</span>
                            <span class="font-semibold text-gray-900"><?= formatRupiah($totalHarga) ?></span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-gray-500">Ongkir</span>
                            <span class="font-semibold text-emerald-600">Gratis</span>
                        </div>
                        <div class="h-px bg-gray-100"></div>
                        <div class="flex justify-between">
                            <span class="text-gray-900 font-bold">Total Bayar</span>
                            <span class="text-xl font-bold text-emerald-600 font-mono"><?= formatRupiah($totalHarga) ?></span>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

</body>
</html>