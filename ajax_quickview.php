<?php
// =============================================================================
// ajax_quickview.php - Quick View Modal Content Loader
// =============================================================================
require_once 'koneksi.php';

if (!isset($_GET['id'])) {
    echo '<p class="text-gray-500 text-center py-8">Produk tidak ditemukan.</p>';
    exit;
}

$id = (int)$_GET['id'];
$stmt = $pdo->prepare("SELECT * FROM produk WHERE id = ?");
$stmt->execute([$id]);
$product = $stmt->fetch();

if (!$product) {
    echo '<p class="text-gray-500 text-center py-8">Produk tidak ditemukan.</p>';
    exit;
}

$images = json_decode($product['gambar'] ?? '[]', true);
$thumb = !empty($images[0]) ? 'uploads/' . $images[0] : 'https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image';

// Ambil semua gambar untuk gallery
$allImages = [];
if (is_array($images)) {
    foreach ($images as $img) {
        if (!empty($img)) $allImages[] = 'uploads/' . $img;
    }
}
if (empty($allImages)) $allImages[] = 'https://placehold.co/400x400/e2e8f0/94a3b8?text=No+Image';

function clean($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}
?>

<div class="flex flex-col md:flex-row gap-6">
    <!-- Image Gallery -->
    <div class="w-full md:w-2/5 space-y-3">
        <div class="relative aspect-square rounded-2xl overflow-hidden bg-gray-50 border border-gray-100">
            <img id="qvMainImage" src="<?= $allImages[0] ?>" alt="<?= clean($product['nama']) ?>" 
                 class="w-full h-full object-cover transition-transform duration-500 hover:scale-105">
        </div>
        <?php if (count($allImages) > 1): ?>
        <div class="flex gap-2 overflow-x-auto pb-1">
            <?php foreach ($allImages as $idx => $img): ?>
            <button onclick="document.getElementById('qvMainImage').src='<?= $img ?>'" 
                    class="w-16 h-16 rounded-xl overflow-hidden border-2 <?= $idx === 0 ? 'border-emerald-500' : 'border-gray-200 hover:border-emerald-300' ?> transition-all shrink-0">
                <img src="<?= $img ?>" class="w-full h-full object-cover">
            </button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Product Info -->
    <div class="flex-1 flex flex-col">
        <div class="flex items-center gap-2 mb-3">
            <span class="text-xs font-bold uppercase tracking-wider text-emerald-600 bg-emerald-50 px-2.5 py-1 rounded-md">
                <?= clean($product['kategori']) ?>
            </span>
            <?php if ($product['stok'] <= 0): ?>
                <span class="text-xs font-bold uppercase tracking-wider text-white bg-rose-500 px-2.5 py-1 rounded-md">Habis</span>
            <?php elseif ($product['stok'] <= 5): ?>
                <span class="text-xs font-bold uppercase tracking-wider text-white bg-yellow-500 px-2.5 py-1 rounded-md">Sisa <?= $product['stok'] ?></span>
            <?php else: ?>
                <span class="text-xs font-bold uppercase tracking-wider text-emerald-700 bg-emerald-100 px-2.5 py-1 rounded-md">Tersedia</span>
            <?php endif; ?>
        </div>
        
        <h2 class="text-2xl font-bold text-gray-900 mb-2 leading-tight"><?= clean($product['nama']) ?></h2>
        
        <p class="text-3xl font-bold text-emerald-600 font-mono mb-4">
            Rp <?= number_format($product['harga'], 0, ',', '.') ?>
        </p>
        
        <div class="flex items-center gap-4 mb-5 text-sm text-gray-500">
            <span class="flex items-center gap-1.5">
                <i class="fa-solid fa-box text-emerald-500"></i>
                Stok: <strong class="text-gray-700"><?= $product['stok'] ?> unit</strong>
            </span>
        </div>
        
        <?php if (!empty($product['deskripsi'])): ?>
            <div class="prose prose-sm text-gray-600 mb-6 max-h-40 overflow-y-auto pr-2 custom-scrollbar">
                <p class="text-sm leading-relaxed"><?= nl2br(clean($product['deskripsi'])) ?></p>
            </div>
        <?php else: ?>
            <p class="text-sm text-gray-400 italic mb-6">Tidak ada deskripsi produk.</p>
        <?php endif; ?>
        
        <div class="mt-auto pt-4 border-t border-gray-100 flex flex-col sm:flex-row gap-3">
            <a href="detail_produk.php?id=<?= $product['id'] ?>" 
               class="flex-1 inline-flex items-center justify-center gap-2 px-6 py-3 bg-emerald-600 text-white rounded-xl font-semibold hover:bg-emerald-700 transition-all hover:shadow-lg hover:shadow-emerald-200">
                <i class="fa-solid fa-arrow-right"></i>
                Lihat Detail Lengkap
            </a>
            <?php if ($product['stok'] > 0): ?>
            <button onclick="alert('Fitur keranjang belum tersedia')" 
                    class="inline-flex items-center justify-center gap-2 px-5 py-3 bg-gray-100 text-gray-700 rounded-xl font-semibold hover:bg-gray-200 transition-colors">
                <i class="fa-solid fa-cart-plus"></i>
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

