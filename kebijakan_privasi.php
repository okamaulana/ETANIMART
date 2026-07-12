<?php
// =============================================================================
// kebijakan_privasi.php - Halaman Kebijakan Privasi
// =============================================================================
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Kebijakan Privasi - eTanimart</title>
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
        .section-number {
            width: 28px;
            height: 28px;
            background: #d1fae5;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            color: #059669;
            font-weight: 700;
            flex-shrink: 0;
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
                     alt="eTanimart" 
                     class="logo-img"
                     style="height: 70px; width: auto; object-fit: contain;"
                     onerror="this.style.display='none'">
            </a>
            <div class="flex items-center gap-4 text-sm">
                <a href="register_penjual.php" class="font-semibold text-emerald-600 hover:text-emerald-700 transition-colors">
                    <i class="fa-solid fa-arrow-left mr-1"></i> Kembali
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
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <h1 class="text-2xl md:text-3xl font-bold text-gray-900">Kebijakan Privasi</h1>
            <p class="text-gray-500 mt-2">eTanimart — Terakhir diperbarui: 5 Juli 2026</p>
        </div>

        <!-- Content Card -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-6 md:p-8">

                <!-- Intro -->
                <div class="mb-8">
                    <p class="text-gray-600 leading-relaxed">
                        eTanimart menghargai privasi Anda. Kebijakan Privasi ini menjelaskan bagaimana kami mengumpulkan, menggunakan, menyimpan, dan melindungi informasi pribadi Anda saat menggunakan platform kami. Dengan menggunakan eTanimart, Anda menyetujui praktik yang dijelaskan dalam kebijakan ini.
                    </p>
                </div>

                <!-- Section 1: Informasi yang Dikumpulkan -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">1</span>
                        Informasi yang Kami Kumpulkan
                    </h2>
                    <p class="text-gray-600 text-sm md:text-base mb-3 pl-11">Kami mengumpulkan informasi berikut dari pengguna:</p>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li><strong>Informasi Pribadi:</strong> Nama lengkap, alamat email, nomor telepon, alamat lengkap, dan informasi identitas lainnya yang Anda berikan saat pendaftaran.</li>
                        <li><strong>Informasi Toko:</strong> Nama toko, deskripsi toko, foto profil, dan foto toko (khusus penjual).</li>
                        <li><strong>Informasi Keuangan:</strong> Nama bank, nomor rekening, dan atas nama rekening (khusus penjual untuk keperluan pencairan saldo).</li>
                        <li><strong>Informasi Transaksi:</strong> Riwayat pembelian, penjualan, saldo, dan detail pembayaran.</li>
                        <li><strong>Informasi Perangkat:</strong> Alamat IP, jenis browser, sistem operasi, dan data log akses.</li>
                        <li><strong>Informasi Lainnya:</strong> Ulasan, pesan, dan komunikasi yang Anda kirimkan melalui platform.</li>
                    </ul>
                </div>

                <!-- Section 2: Cara Menggunakan Informasi -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">2</span>
                        Cara Kami Menggunakan Informasi Anda
                    </h2>
                    <p class="text-gray-600 text-sm md:text-base mb-3 pl-11">Informasi yang kami kumpulkan digunakan untuk:</p>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li>Memproses pendaftaran akun dan verifikasi identitas pengguna.</li>
                        <li>Memfasilitasi transaksi jual beli antara penjual dan pembeli.</li>
                        <li>Memproses pembayaran dan pencairan saldo penjual.</li>
                        <li>Mengirimkan notifikasi terkait pesanan, promosi, dan pembaruan layanan.</li>
                        <li>Menyediakan layanan pelanggan dan menangani keluhan atau sengketa.</li>
                        <li>Meningkatkan kualitas platform dan pengalaman pengguna.</li>
                        <li>Mencegah penipuan, penyalahgunaan, dan aktivitas ilegal di platform.</li>
                        <li>Memenuhi kewajiban hukum dan peraturan yang berlaku.</li>
                    </ul>
                </div>

                <!-- Section 3: Penyimpanan dan Keamanan -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">3</span>
                        Penyimpanan dan Keamanan Data
                    </h2>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li>Kami menyimpan data Anda di server yang dilindungi dengan standar keamanan industri.</li>
                        <li>Password pengguna dienkripsi menggunakan algoritma hashing (bcrypt) dan tidak dapat dibaca oleh pihak manapun.</li>
                        <li>Kami menggunakan protokol HTTPS/SSL untuk mengamankan transmisi data antara browser dan server.</li>
                        <li>Akses ke data pribadi dibatasi hanya untuk staf yang berwenang dan memerlukannya untuk operasional.</li>
                        <li>Kami secara rutin meninjau dan memperbarui sistem keamanan kami.</li>
                        <li>Meskipun kami berupaya keras melindungi data Anda, tidak ada sistem yang sepenuhnya kebal terhadap risiko keamanan.</li>
                    </ul>
                </div>

                <!-- Section 4: Pembagian ke Pihak Ketiga -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">4</span>
                        Pembagian Informasi kepada Pihak Ketiga
                    </h2>
                    <p class="text-gray-600 text-sm md:text-base mb-3 pl-11">Kami tidak menjual, menyewakan, atau memperdagangkan informasi pribadi Anda. Namun, kami dapat membagikan informasi dalam kondisi berikut:</p>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li><strong>Penjual dan Pembeli:</strong> Informasi kontak dan alamat pengiriman dibagikan antara pihak yang terlibat dalam transaksi.</li>
                        <li><strong>Penyedia Layanan Pembayaran:</strong> Informasi rekening bank dibagikan dengan mitra pembayaran untuk keperluan transaksi.</li>
                        <li><strong>Jasa Pengiriman:</strong> Informasi alamat pengiriman dibagikan dengan kurir atau jasa logistik.</li>
                        <li><strong>Kewajiban Hukum:</strong> Kami dapat mengungkapkan informasi jika diwajibkan oleh hukum, perintah pengadilan, atau permintaan resmi dari pihak berwenang.</li>
                        <li><strong>Perlindungan Hak:</strong> Kami dapat mengungkapkan informasi untuk melindungi hak, properti, atau keamanan eTanimart, pengguna, atau publik.</li>
                    </ul>
                </div>

                <!-- Section 5: Hak Pengguna -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">5</span>
                        Hak Pengguna
                    </h2>
                    <p class="text-gray-600 text-sm md:text-base mb-3 pl-11">Sebagai pengguna, Anda memiliki hak berikut terhadap data pribadi Anda:</p>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li><strong>Hak Akses:</strong> Anda dapat meminta salinan data pribadi yang kami miliki tentang Anda.</li>
                        <li><strong>Hak Koreksi:</strong> Anda dapat memperbarui atau memperbaiki informasi yang tidak akurat melalui pengaturan akun.</li>
                        <li><strong>Hak Penghapusan:</strong> Anda dapat meminta penghapusan akun dan data pribadi Anda, dengan catatan bahwa beberapa data mungkin perlu disimpan untuk keperluan hukum atau transaksi yang sedang berlangsung.</li>
                        <li><strong>Hak Pembatasan:</strong> Anda dapat meminta pembatasan penggunaan data Anda dalam kondisi tertentu.</li>
                        <li><strong>Hak Keberatan:</strong> Anda dapat menolak penggunaan data Anda untuk tujuan pemasaran langsung.</li>
                    </ul>
                </div>

                <!-- Section 6: Cookie -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">6</span>
                        Cookie dan Teknologi Pelacakan
                    </h2>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li>Kami menggunakan cookie dan teknologi serupa untuk meningkatkan pengalaman pengguna, menganalisis lalu lintas, dan mempersonalisasi konten.</li>
                        <li>Cookie sesi digunakan untuk menjaga status login Anda selama sesi aktif.</li>
                        <li>Anda dapat mengatur browser untuk menolak cookie, namun beberapa fitur platform mungkin tidak berfungsi optimal.</li>
                        <li>Kami tidak menggunakan cookie untuk melacak aktivitas Anda di luar platform eTanimart.</li>
                    </ul>
                </div>

                <!-- Section 7: Retensi Data -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">7</span>
                        Retensi Data
                    </h2>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li>Kami menyimpan data pribadi Anda selama akun Anda aktif atau selama diperlukan untuk menyediakan layanan.</li>
                        <li>Setelah penghapusan akun, beberapa data mungkin tetap disimpan dalam bentuk anonim untuk keperluan analitik dan kepatuhan hukum.</li>
                        <li>Data transaksi disimpan sesuai dengan periode retensi yang diwajibkan oleh peraturan perpajakan dan peraturan yang berlaku di Indonesia.</li>
                    </ul>
                </div>

                <!-- Section 8: Privasi Anak-anak -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">8</span>
                        Privasi Anak-anak
                    </h2>
                    <p class="text-gray-600 text-sm md:text-base pl-11 leading-relaxed">
                        Platform eTanimart tidak ditujukan untuk anak-anak di bawah usia 18 tahun. Kami tidak secara sadar mengumpulkan informasi pribadi dari anak-anak. Jika Anda mengetahui bahwa anak di bawah usia 18 tahun telah memberikan informasi pribadi kepada kami, silakan hubungi kami segera agar kami dapat menghapus informasi tersebut.
                    </p>
                </div>

                <!-- Section 9: Perubahan -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">9</span>
                        Perubahan Kebijakan Privasi
                    </h2>
                    <p class="text-gray-600 text-sm md:text-base pl-11 leading-relaxed">
                        Kami dapat memperbarui Kebijakan Privasi ini sewaktu-waktu. Perubahan akan diumumkan melalui platform dan tanggal pembaruan terakhir akan dicantumkan di bagian atas halaman ini. Pengguna yang terus menggunakan layanan setelah perubahan dianggap telah menyetujui kebijakan yang baru. Kami mendorong Anda untuk secara berkala meninjau kebijakan ini.
                    </p>
                </div>

                <!-- Section 10: Hubungi Kami -->
                <div class="mb-6">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">10</span>
                        Hubungi Kami
                    </h2>
                    <p class="text-gray-600 text-sm md:text-base mb-3 pl-11">Jika Anda memiliki pertanyaan, kekhawatiran, atau permintaan terkait Kebijakan Privasi ini, silakan hubungi kami melalui:</p>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li><strong>Email:</strong> <a href="mailto:privacy@etanimart.id" class="text-emerald-700 font-semibold hover:underline">privacy@etanimart.id</a></li>
                        <li><strong>Telepon:</strong> 0800-1234-5678 (Senin–Jumat, 08.00–17.00 WIB)</li>
                        <li><strong>Alamat:</strong> Jl. Pertanian No. 123, Jakarta Selatan, Indonesia</li>
                    </ul>
                </div>

                <!-- Note Box -->
                <div class="bg-gray-50 border border-gray-200 rounded-xl p-5 mt-8">
                    <p class="text-gray-600 text-sm">
                        <i class="fa-solid fa-circle-info text-emerald-600 mr-2"></i>
                        <strong>Catatan:</strong> Dengan menggunakan platform eTanimart, Anda mengakui bahwa Anda telah membaca, memahami, dan menyetujui Kebijakan Privasi ini beserta Syarat dan Ketentuan yang berlaku.
                    </p>
                </div>

            </div>
        </div>

        <!-- Back Button -->
        <div class="text-center mt-8">
            <a href="register_penjual.php" class="inline-flex items-center gap-2 btn-primary text-white font-bold py-3 px-8 rounded-2xl shadow-lg">
                <i class="fa-solid fa-arrow-left"></i> Kembali ke Pendaftaran
            </a>
        </div>

    </div>
</main>

</body>
</html>