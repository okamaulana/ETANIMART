<?php
// =============================================================================
// syarat_ketentuan.php - Halaman Syarat dan Ketentuan
// =============================================================================
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Syarat dan Ketentuan - eTanimart</title>
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
                <i class="fa-solid fa-file-contract"></i>
            </div>
            <h1 class="text-2xl md:text-3xl font-bold text-gray-900">Syarat dan Ketentuan</h1>
            <p class="text-gray-500 mt-2">eTanimart — Terakhir diperbarui: 5 Juli 2026</p>
        </div>

        <!-- Content Card -->
        <div class="bg-white rounded-2xl border border-gray-100 shadow-sm overflow-hidden">
            <div class="p-6 md:p-8">

                <!-- Intro -->
                <div class="mb-8">
                    <p class="text-gray-600 leading-relaxed">
                        Dengan mendaftar, mengakses, atau menggunakan platform eTanimart, Anda menyetujui untuk terikat oleh syarat dan ketentuan berikut. Harap baca dengan saksama sebelum menggunakan layanan kami.
                    </p>
                </div>

                <!-- Section 1: Definisi -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">1</span>
                        Definisi
                    </h2>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li><strong>"Platform"</strong> berarti situs web dan/atau aplikasi seluler eTanimart.</li>
                        <li><strong>"Pengguna"</strong> berarti individu yang mendaftar dan menggunakan layanan eTanimart.</li>
                        <li><strong>"Penjual"</strong> berarti Pengguna yang mendaftarkan diri untuk menjual produk pertanian melalui Platform.</li>
                        <li><strong>"Pembeli"</strong> berarti Pengguna yang melakukan pembelian produk melalui Platform.</li>
                        <li><strong>"Kami"</strong> berarti pihak pengelola eTanimart.</li>
                    </ul>
                </div>

                <!-- Section 2: Pendaftaran Akun -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">2</span>
                        Pendaftaran Akun
                    </h2>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li>Pengguna wajib berusia minimal 18 tahun atau telah memiliki persetujuan dari orang tua/wali.</li>
                        <li>Informasi yang diberikan saat pendaftaran harus akurat, lengkap, dan terkini.</li>
                        <li>Setiap akun hanya boleh digunakan oleh satu individu. Dilarang membagikan kredensial akun kepada pihak lain.</li>
                        <li>Kami berhak menolak atau menangguhkan akun yang melanggar kebijakan kami.</li>
                        <li>Akun penjual memerlukan verifikasi admin sebelum dapat mulai berjualan.</li>
                    </ul>
                </div>

                <!-- Section 3: Kewajiban Penjual -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">3</span>
                        Kewajiban Penjual
                    </h2>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li>Penjual wajib menjual produk pertanian yang legal, aman dikonsumsi, dan sesuai dengan deskripsi yang diberikan.</li>
                        <li>Penjual bertanggung jawab penuh atas kualitas, keamanan, dan keaslian produk yang dijual.</li>
                        <li>Penjual wajib memproses dan mengirimkan pesanan sesuai waktu yang telah disepakati.</li>
                        <li>Penjual dilarang menjual produk yang dilarang oleh hukum, termasuk namun tidak terbatas pada: narkotika, pestisida ilegal, atau produk palsu.</li>
                        <li>Penjual wajib menyediakan informasi rekening bank yang valid untuk keperluan penarikan saldo.</li>
                        <li>Penjual setuju bahwa eTanimart dapat memotong biaya layanan/platform sesuai ketentuan yang berlaku.</li>
                    </ul>
                </div>

                <!-- Section 4: Kewajiban Pembeli -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">4</span>
                        Kewajiban Pembeli
                    </h2>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li>Pembeli wajib memberikan informasi pengiriman yang akurat dan lengkap.</li>
                        <li>Pembeli wajib melakukan pembayaran sesuai dengan total tagihan yang tertera.</li>
                        <li>Pembeli dilarang melakukan tindakan penipuan, chargeback tanpa alasan yang sah, atau manipulasi transaksi.</li>
                        <li>Pembeli setuju untuk memberikan ulasan yang jujur dan tidak menyesatkan terhadap produk yang dibeli.</li>
                    </ul>
                </div>

                <!-- Section 5: Pembayaran dan Saldo -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">5</span>
                        Pembayaran dan Saldo
                    </h2>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li>Semua transaksi pembayaran dilakukan melalui sistem yang disediakan oleh eTanimart.</li>
                        <li>Saldo penjual akan dicairkan ke rekening bank yang terdaftar sesuai jadwal penarikan yang telah ditentukan.</li>
                        <li>eTanimart berhak mengenakan biaya layanan/administrasi sesuai dengan kebijakan yang berlaku.</li>
                        <li>Penjual dapat mengajukan penarikan saldo dengan minimum nominal tertentu sesuai ketentuan platform.</li>
                    </ul>
                </div>

                <!-- Section 6: Pengembalian dan Sengketa -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">6</span>
                        Pengembalian dan Sengketa
                    </h2>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li>Pembeli dapat mengajukan pengembalian/refund dalam jangka waktu yang telah ditentukan jika produk tidak sesuai deskripsi atau rusak.</li>
                        <li>eTanimart akan bertindak sebagai mediator dalam penyelesaian sengketa antara pembeli dan penjual.</li>
                        <li>Keputusan eTanimart dalam penyelesaian sengketa bersifat final dan mengikat.</li>
                        <li>Penjual yang terbukti melakukan kesalahan berulang kali dapat dikenakan sanksi berupa pembekuan akun.</li>
                    </ul>
                </div>

                <!-- Section 7: Batasan Tanggung Jawab -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">7</span>
                        Batasan Tanggung Jawab
                    </h2>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li>eTanimart hanya menyediakan platform sebagai perantara antara penjual dan pembeli.</li>
                        <li>eTanimart tidak bertanggung jawab atas kualitas produk yang dijual oleh penjual.</li>
                        <li>eTanimart tidak bertanggung jawab atas kerugian akibat kelalaian pengguna dalam menggunakan layanan.</li>
                        <li>eTanimart tidak menjamin bahwa platform akan selalu tersedia tanpa gangguan teknis.</li>
                    </ul>
                </div>

                <!-- Section 8: Penangguhan dan Penghapusan Akun -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">8</span>
                        Penangguhan dan Penghapusan Akun
                    </h2>
                    <ul class="space-y-2 text-gray-600 text-sm md:text-base pl-11">
                        <li>Kami berhak menangguhkan atau menghapus akun pengguna yang melanggar syarat dan ketentuan ini.</li>
                        <li>Akun yang terindikasi melakukan penipuan, penjualan produk ilegal, atau perilaku merugikan lainnya akan ditindak tegas.</li>
                        <li>Pengguna yang akunnya dihapus masih bertanggung jawab atas kewajiban yang timbul sebelum penghapusan.</li>
                    </ul>
                </div>

                <!-- Section 9: Perubahan -->
                <div class="mb-8">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">9</span>
                        Perubahan Syarat dan Ketentuan
                    </h2>
                    <p class="text-gray-600 text-sm md:text-base pl-11 leading-relaxed">
                        Kami berhak mengubah syarat dan ketentuan ini sewaktu-waktu. Perubahan akan diumumkan melalui platform dan berlaku efektif sejak tanggal diumumkan. Pengguna yang terus menggunakan layanan setelah perubahan dianggap telah menyetujui syarat dan ketentuan yang baru.
                    </p>
                </div>

                <!-- Section 10: Hukum -->
                <div class="mb-6">
                    <h2 class="text-lg font-bold text-emerald-700 mb-4 flex items-center gap-3">
                        <span class="section-number">10</span>
                        Hukum yang Berlaku
                    </h2>
                    <p class="text-gray-600 text-sm md:text-base pl-11 leading-relaxed">
                        Syarat dan ketentuan ini diatur dan ditafsirkan sesuai dengan hukum yang berlaku di Republik Indonesia. Setiap sengketa yang timbul akan diselesaikan secara musyawarah terlebih dahulu. Jika tidak tercapai kesepakatan, sengketa akan diselesaikan melalui lembaga arbitrase atau pengadilan yang berwenang di Indonesia.
                    </p>
                </div>

                <!-- Contact Box -->
                <div class="bg-gray-50 border border-gray-200 rounded-xl p-5 mt-8">
                    <p class="text-gray-600 text-sm">
                        <strong>Hubungi Kami:</strong> Jika Anda memiliki pertanyaan mengenai syarat dan ketentuan ini, silakan hubungi kami melalui email 
                        <a href="mailto:support@etanimart.id" class="text-emerald-700 font-semibold hover:underline">support@etanimart.id</a> 
                        atau melalui fitur bantuan di dalam aplikasi.
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