<?php
// =============================================================================
// scan.php - Etanimart AI Plant Doctor (Modern)
// FIXED: Model vision deprecated (llama-3.2-11b-vision-preview) 
//        diganti ke meta-llama/llama-4-scout-17b-16e-instruct
// =============================================================================
session_start();

// Detect current page for active menu
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

require_once 'koneksi.php';




function clean($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

function getProfilePic($foto) {
    if (!empty($foto) && file_exists('uploads/profil/' . $foto)) {
        return 'uploads/profil/' . $foto;
    }
    return 'https://placehold.co/100x100/e2e8f0/94a3b8?text=' . urlencode(substr($foto ?? 'U', 0, 1));
}
// ==========================================
// CEK STATUS LOGIN & REDIRECT ROLE
// ==========================================
$isLoggedIn = isset($_SESSION['user_id']);
$userRole   = $isLoggedIn ? ($_SESSION['role'] ?? 'pembeli') : null;  // <-- SAMAIN key
$userName   = $isLoggedIn ? ($_SESSION['nama'] ?? 'Pengguna') : null;
$userFoto   = $isLoggedIn ? ($_SESSION['foto_profil'] ?? null) : null;

// Redirect admin/penjual
if ($isLoggedIn && in_array($userRole, ['admin', 'penjual'])) {
    header('Location: ' . ($userRole === 'admin' ? 'admin/admin_dashboard.php' : 'penjual/penjual_dashboard.php'));
    exit;
}

// ==========================================
// AMBIL DATA FRESH DARI DATABASE
// ==========================================
if ($isLoggedIn) {
    try {
        $stmtUser = $pdo->prepare("SELECT nama, foto_profil FROM users WHERE id = ?");
        $stmtUser->execute([$_SESSION['user_id']]);
        $userData = $stmtUser->fetch();
        
        if ($userData) {
            $userName = $userData['nama'];
            $userFoto = $userData['foto_profil'];
            // Update session juga biar konsisten
            $_SESSION['nama'] = $userData['nama'];
            $_SESSION['foto_profil'] = $userData['foto_profil'];
        }
    } catch (PDOException $e) {
        // silent fail
    }
}

// Hitung total item keranjang untuk badge
$totalKeranjang = 0;
if ($isLoggedIn && $userRole === 'pembeli') {
    try {
        $stmtCart = $pdo->prepare("SELECT COALESCE(SUM(jumlah), 0) as total FROM keranjang WHERE id_user = :idu");
        $stmtCart->execute(['idu' => ($_SESSION['user_id'] ?? 0)]);
        $totalKeranjang = (int)$stmtCart->fetchColumn();
    } catch (PDOException $e) {
        $totalKeranjang = 0;
    }
}



// ==========================================
// KONFIGURASI & SECURITY
// ==========================================
define('GROQ_API_KEY', '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');
define('GROQ_API_URL', 'https://api.groq.com/openai/v1/chat/completions');
define('MAX_IMAGE_SIZE', 3 * 1024 * 1024);




// ==========================================
// HELPER FUNCTIONS
// ==========================================
function sendJson($status, $data = [], $message = '') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $data));
    exit;
}

function callGroqAPI($payload) {
    $ch = curl_init(GROQ_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . GROQ_API_KEY
        ],
        CURLOPT_TIMEOUT => 60,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) return ['error' => 'cURL Error: ' . $error];
    if ($httpCode !== 200) {
        $decoded = json_decode($response, true);
        $errMsg = isset($decoded['error']['message']) ? $decoded['error']['message'] : $response;
        return ['error' => 'HTTP ' . $httpCode . ': ' . $errMsg];
    }

    return json_decode($response, true);
}

function extractJsonFromAI($text) {
    if (empty($text)) return null;
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/m', '', $text);
    $text = trim($text);

    if (preg_match('/\[[\s\S]*\]/', $text, $matches) || preg_match('/\{[\s\S]*\}/', $text, $matches)) {
        $text = $matches[0];
    }

    $decoded = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE) return $decoded;
    return null;
}

function resizeImageIfNeeded($sourcePath, $maxDimension = 1024) {
    $info = getimagesize($sourcePath);
    if (!$info) return file_get_contents($sourcePath);

    $width = $info[0];
    $height = $info[1];

    if ($width <= $maxDimension && $height <= $maxDimension) {
        return file_get_contents($sourcePath);
    }

    $ratio = min($maxDimension / $width, $maxDimension / $height);
    $newWidth = (int)($width * $ratio);
    $newHeight = (int)($height * $ratio);

    switch ($info[2]) {
        case IMAGETYPE_JPEG: $src = imagecreatefromjpeg($sourcePath); break;
        case IMAGETYPE_PNG: $src = imagecreatefrompng($sourcePath); break;
        case IMAGETYPE_WEBP: $src = imagecreatefromwebp($sourcePath); break;
        default: return file_get_contents($sourcePath);
    }

    if (!$src) return file_get_contents($sourcePath);

    $dst = imagecreatetruecolor($newWidth, $newHeight);

    if ($info[2] === IMAGETYPE_PNG) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

    ob_start();
    switch ($info[2]) {
        case IMAGETYPE_JPEG: imagejpeg($dst, null, 85); break;
        case IMAGETYPE_PNG: imagepng($dst, null, 6); break;
        case IMAGETYPE_WEBP: imagewebp($dst, null, 85); break;
    }
    $data = ob_get_clean();

    imagedestroy($src);
    imagedestroy($dst);

    return $data;
}

// ==========================================
// AJAX HANDLER
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    $action = $_GET['action'];

    if ($action === 'scan_foto') {
        if (!isset($_FILES['foto_tanaman'])) {
            sendJson('error', [], 'Foto tanaman tidak ditemukan.');
        }

        if ($_FILES['foto_tanaman']['error'] !== UPLOAD_ERR_OK) {
            $uploadErrors = [
                UPLOAD_ERR_INI_SIZE => 'File terlalu besar (melebihi limit server).',
                UPLOAD_ERR_FORM_SIZE => 'File terlalu besar (melebihi limit form).',
                UPLOAD_ERR_PARTIAL => 'File hanya ter-upload sebagian.',
                UPLOAD_ERR_NO_FILE => 'Tidak ada file yang di-upload.'
            ];
            sendJson('error', [], $uploadErrors[$_FILES['foto_tanaman']['error']] ?? 'Upload gagal.');
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
        $foto = $_FILES['foto_tanaman'];

        if (!in_array($foto['type'], $allowedTypes)) {
            sendJson('error', [], 'Format file tidak didukung. Gunakan JPG, PNG, atau WEBP.');
        }

        if ($foto['size'] > 5 * 1024 * 1024) {
            sendJson('error', [], 'Ukuran file maksimal 5MB.');
        }

        $imageData = resizeImageIfNeeded($foto['tmp_name'], 1024);
        $fotoData = base64_encode($imageData);
        $fotoMime = $foto['type'];

        $prompt = 'Kamu adalah ahli patologi tanaman. Analisis foto ini dengan cermat. '
                . 'LIHAT foto dan identifikasi gejala spesifik yang TERLIHAT. '
                . 'Jika foto BUKAN tanaman atau tanaman SEHAT → kembalikan []. '
                . 'JANGAN tanya jenis tanaman. Fokus ke gejala yang ADA di foto. '
                . 'JANGAN diagnosis faktor lingkungan (kering, panas, banjir). '
                . 'Buat 5 pertanyaan yang SESUAI dengan gejala di foto ini. '
                . 'CONTOH: Jika foto menunjukkan bercak coklat bulat di daun dengan tepi kuning: '
                . '  - Q1: Apakah bercak memiliki tepi berwarna kuning/kemerahan? (Ya/Tidak/Sebagian) '
                . '  - Q2: Apakah pusat bercak berwarna coklat tua atau abu-abu? (Coklat tua/Abu-abu/Keduanya) '
                . '  - Q3: Apakah bercak terlihat berlubang di tengah? (Ya/Tidak/Beberapa) '
                . '  - Q4: Apakah gejala hanya pada daun tua atau juga daun muda? (Hanya tua/Hanya muda/Keduanya) '
                . '  - Q5: Apakah ada jamur berwarna putih/pink di permukaan bercak? (Ya/Tidak/Tidak yakin) '
                . 'CONTOH: Jika foto menunjukkan daun menguning dari ujung: '
                . '  - Q1: Apakah menguning dimulai dari ujung daun atau tepi? (Ujung/Tepi/Merata) '
                . '  - Q2: Apakah urat daun tetap hijau saat daun menguning? (Ya/Tidak/Sebagian) '
                . '  - Q3: Apakah ada bercak coklat sebelum daun menguning? (Ya/Tidak/Tidak yakin) '
                . '  - Q4: Apakah gejala pada satu tanaman atau banyak tanaman? (Satu/Beberapa/Seluruh ladang) '
                . '  - Q5: Apakah tanaman tampak layu di siang hari? (Ya/Tidak/Kadang) '
                . 'Setiap pertanyaan HARUS berbeda-beda sesuai foto. JANGAN pakai template yang sama. '
                . 'Opsi jawaban harus spesifik dan berbeda untuk setiap pertanyaan. '
                . 'Format: [{"key": "param_1", "teks_pertanyaan": "...", "opsi": ["A", "B", "C"]}, ...] '
                . 'Hanya JSON array, tanpa teks lain.';

        $payload = [
            "model" => "meta-llama/llama-4-scout-17b-16e-instruct",
            "messages" => [
                [
                    "role" => "user",
                    "content" => [
                        ["type" => "text", "text" => $prompt],
                        [
                            "type" => "image_url",
                            "image_url" => [
                                "url" => "data:" . $fotoMime . ";base64," . $fotoData
                            ]
                        ]
                    ]
                ]
            ],
            "temperature" => 0.2,
            "max_completion_tokens" => 2048,
            "response_format" => ["type" => "json_object"]
        ];

        $result = callGroqAPI($payload);

        if (isset($result['error'])) {
            sendJson('error', [], 'Gagal menghubungi AI: ' . $result['error']);
        }

        $textOut = $result['choices'][0]['message']['content'] ?? '[]';
        $pertanyaan_generasi_ai = extractJsonFromAI($textOut);

        if (!is_array($pertanyaan_generasi_ai)) {
            if (isset($pertanyaan_generasi_ai['pertanyaan_ai'])) {
                $pertanyaan_generasi_ai = $pertanyaan_generasi_ai['pertanyaan_ai'];
            } elseif (isset($pertanyaan_generasi_ai['questions'])) {
                $pertanyaan_generasi_ai = $pertanyaan_generasi_ai['questions'];
            } else {
                $pertanyaan_generasi_ai = [];
            }
        }

        if (empty($pertanyaan_generasi_ai) || !is_array($pertanyaan_generasi_ai)) {
            sendJson('error', [], 'AI gagal menghasilkan pertanyaan. Coba foto lain yang lebih jelas dan terang.');
        }

        $validPertanyaan = [];
        foreach ($pertanyaan_generasi_ai as $q) {
            if (isset($q['key']) && isset($q['teks_pertanyaan']) && is_array($q['opsi']) && count($q['opsi']) >= 2) {
                $validPertanyaan[] = $q;
            }
        }

        if (empty($validPertanyaan)) {
            sendJson('error', [], 'Format pertanyaan AI tidak valid. Coba lagi.');
        }

        $_SESSION['context_pertanyaan'] = json_encode($validPertanyaan);
        $_SESSION['foto_scanned'] = true;

        sendJson('success', ['pertanyaan_ai' => $validPertanyaan]);
    }

    if ($action === 'hitung_diagnosis') {
        if (empty($_SESSION['context_pertanyaan'])) {
            sendJson('error', [], 'Sesi habis. Silakan scan ulang foto tanaman.');
        }

        $jawaban_user = isset($_POST['jawaban']) && is_array($_POST['jawaban']) ? $_POST['jawaban'] : [];

        if (empty($jawaban_user)) {
            sendJson('error', [], 'Jawaban tidak boleh kosong.');
        }

        $context_pertanyaan = $_SESSION['context_pertanyaan'];
        $jawaban_string = json_encode($jawaban_user);

        $prompt_final = 'Kamu adalah ahli patologi tanaman. '
                      . 'Data konteks: ' . $context_pertanyaan . '. '
                      . 'Jawaban user: ' . $jawaban_string . '. '
                      . 'Tentukan diagnosis PENYAKIT TANAMAN spesifik dari gejala. '
                      . 'ATURAN KERAS: '
                      . '1. JANGAN diagnosis faktor lingkungan (kering, kekurangan air, panas, banjir). '
                      . '2. JANGAN diagnosis kerusakan mekanik (tertimpa, terpotong, terbakar). '
                      . '3. Hanya diagnosis penyakit biotik (jamur, bakteri, virus, hama, nematoda). '
                      . '4. Jika gejala tidak jelas atau bukan penyakit → gunakan "Penyakit Tidak Teridentifikasi". '
                      . '5. keyword_obat HARUS salah satu: Fungisida/Insektisida/Bakterisida/Nematisida. '
                      . '6. JANGAN gunakan keyword lain seperti Pupuk, Air, Hormon, dll. '
                      . 'Format JSON: '
                      . '{"penyakit": "Nama Penyakit", "deskripsi": "Penjelasan dan saran penanganan", "keyword_obat": "Fungisida/Insektisida/Bakterisida/Nematisida"} '
                      . 'Hanya JSON, tanpa teks lain.';

        $payload_final = [
            "model" => "meta-llama/llama-4-scout-17b-16e-instruct",
            "messages" => [
                ["role" => "user", "content" => $prompt_final]
            ],
            "temperature" => 0.2,
            "max_completion_tokens" => 2048,
            "response_format" => ["type" => "json_object"]
        ];

        $result_final = callGroqAPI($payload_final);

        if (isset($result_final['error'])) {
            sendJson('error', [], 'Gagal mendapatkan diagnosis: ' . $result_final['error']);
        }

        $textOutFinal = $result_final['choices'][0]['message']['content'] ?? '{}';
        $data_diagnosis = extractJsonFromAI($textOutFinal);

        if (empty($data_diagnosis) || !isset($data_diagnosis['penyakit'])) {
            sendJson('error', [], 'AI gagal merumuskan diagnosis. Silakan coba lagi.');
        }

        // Validasi diagnosis
        $penyakit = $data_diagnosis['penyakit'] ?? 'Penyakit Tidak Teridentifikasi';
        $keyword_obat = $data_diagnosis['keyword_obat'] ?? '';
        $validKeywords = ['Fungisida', 'Insektisida', 'Herbisida', 'Bakterisida', 'Nematisida'];
        $isValidDiagnosis = ($penyakit !== 'Penyakit Tidak Teridentifikasi') && in_array($keyword_obat, $validKeywords);

        $produk_rekomendasi = [];

        // Hanya cari produk kalau diagnosis valid
        if ($isValidDiagnosis) {
            try {
                $keyword = '%' . $keyword_obat . '%';
                $stmt = $pdo->prepare("
                    SELECT id, nama, kategori, harga, gambar 
                    FROM produk 
                    WHERE nama LIKE :kw OR deskripsi LIKE :kw2 OR kategori LIKE :kw3 
                    LIMIT 6
                ");
                $stmt->execute([
                    'kw' => $keyword, 
                    'kw2' => $keyword,
                    'kw3' => $keyword
                ]);
                $produk_rekomendasi = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log('DB Error: ' . $e->getMessage());
            }
        }

        unset($_SESSION['context_pertanyaan']);
        unset($_SESSION['foto_scanned']);

        sendJson('success', [
            'diagnosis' => [
                'penyakit' => $penyakit,
                'deskripsi' => $data_diagnosis['deskripsi'] ?? 'Tidak ada deskripsi penanganan.'
            ],
            'produk' => $produk_rekomendasi,
            'is_valid' => $isValidDiagnosis
        ]);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<link rel="icon" type="image/png" href="uploads/logo/tani.png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0, user-scalable=no">
    <title>Scan Tanaman - Etanimart</title>
    <meta name="description" content="Scan tanaman dengan AI untuk deteksi penyakit dan rekomendasi obat.">
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Plus Jakarta Sans', sans-serif; }
        html { scroll-behavior: smooth; }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f5f9; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }

        /* ===== NAVBAR ===== */
        .nav-link {
            position: relative;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: -4px;
            left: 0;
            width: 0;
            height: 2px;
            background: #10b981;
            transition: width 0.3s ease;
        }
        .nav-link:hover::after {
            width: 100%;
        }

        .btn-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.5);
        }

        .cat-pill {
            transition: all 0.3s ease;
        }
        .cat-pill:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -8px rgba(16, 185, 129, 0.3);
        }

        /* Scroll reveal */
        .reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s ease-out;
        }
        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }

        /* Scroll buttons */
        .scroll-btn {
            transition: all 0.3s ease;
        }
        .scroll-btn:hover {
            background: #10b981;
            color: white;
            border-color: #10b981;
        }

        

        /* ===== PANEL ===== */
        .panel-hidden { display: none !important; }
        .panel-visible { display: block !important; }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .fade-in { animation: fadeIn 0.6s ease-out forwards; }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .animate-slide-up {
            animation: slideUp 0.6s ease-out forwards;
        }

        .loading-pulse {
            animation: pulse 1.5s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(0.98); }
        }

        .drop-active {
            border-color: #10b981 !important;
            background-color: #ecfdf5 !important;
        }

        /* ===== CAMERA MODAL ===== */
        .camera-modal {
            display: none;
            position: fixed;
            inset: 0;
            z-index: 100;
            background: rgba(0, 0, 0, 0.9);
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .camera-modal.active {
            display: flex;
        }
        .camera-content {
            background: #1a1a2e;
            border-radius: 1.5rem;
            max-width: 640px;
            width: 100%;
            overflow: hidden;
            position: relative;
        }

        

        

        /* ===== SCAN ANIMATION ===== */
        @keyframes scanLine {
            0% { top: 0%; }
            50% { top: 100%; }
            100% { top: 0%; }
        }
        .scan-line {
            position: absolute;
            left: 10%;
            right: 10%;
            height: 2px;
            background: linear-gradient(90deg, transparent, #10b981, transparent);
            animation: scanLine 2s ease-in-out infinite;
            z-index: 10;
        }

        /* ===== PRODUCT CARD ===== */
        .product-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
        }
        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.15);
        }
        .product-card:hover .card-img {
            transform: scale(1.05);
        }
        .card-img {
            transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* ===== REVEAL ===== */
        .reveal {
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.6s ease-out;
        }
        .reveal.active {
            opacity: 1;
            transform: translateY(0);
        }

        .btn-primary {
    background: linear-gradient(135deg, #10b981, #0d9488);
    transition: all 0.3s ease;
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.4);
}

/* ===== BUTTON PRIMARY ===== */
.btn-primary {
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    transition: all 0.3s ease;
}
.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.5);
}

        /* ===== USER DROPDOWN ===== */
        .user-dropdown {
            position: relative;
        }
        .user-dropdown-menu {
            position: absolute;
            top: calc(100% + 8px);
            right: 0;
            min-width: 240px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 40px -10px rgba(0,0,0,0.15);
            border: 1px solid #e2e8f0;
            opacity: 0;
            visibility: hidden;
            transform: translateY(-10px) scale(0.95);
            transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 100;
            overflow: hidden;
        }
        .user-dropdown-menu.active {
            opacity: 1;
            visibility: visible;
            transform: translateY(0) scale(1);
        }
        .user-dropdown-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: #374151;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .user-dropdown-item:hover {
            background: #f0fdf4;
            color: #059669;
        }
        .user-dropdown-item i {
            width: 20px;
            text-align: center;
            color: #10b981;
        }
        .user-dropdown-divider {
            height: 1px;
            background: #e2e8f0;
            margin: 4px 12px;
        }

        /* ===== MOBILE MENU ===== */
        #mobile-menu {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: top;
        }
        #mobile-menu:not(.hidden) {
            animation: slideDown 0.3s ease-out;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Menu icon animation */
        #menuIcon {
            transition: transform 0.3s ease;
        }

        /* ===== HIDE DROP ZONE ON MOBILE ===== */
@media (max-width: 640px) {
    #dropZone {
        display: none !important;
    }
    /* Tambahin divider juga di-hide karena gak relevan lagi */
    #panelUpload .flex.items-center.gap-4:has(.text-xs.text-gray-400.font-medium) {
        display: none;
    }
}
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased overflow-x-hidden">

   <!-- ==================== NAVBAR ==================== -->
<nav class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" id="navbar">
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
                <a href="index.php" class="nav-link <?= $currentPage === 'index' ? 'text-emerald-600' : 'text-gray-600' ?> hover:text-emerald-600 transition-colors">Beranda</a>
                <a href="scan.php" class="nav-link <?= $currentPage === 'scan' ? 'text-emerald-600' : 'text-gray-600' ?> hover:text-emerald-600 transition-colors flex items-center gap-1.5">
                    <i class="fa-solid fa-qrcode <?= $currentPage === 'scan' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Scan AI
                </a>
                <a href="produk.php" class="nav-link <?= $currentPage === 'produk' ? 'text-emerald-600' : 'text-gray-600' ?> hover:text-emerald-600 transition-colors">Katalog</a>
               
                <a href="index.php#tentang" class="nav-link text-gray-600 hover:text-emerald-600 transition-colors">Tentang</a>
            </div>

            <!-- Right Side Actions -->
            <div class="flex items-center gap-2 sm:gap-4">
                <?php if (!$isLoggedIn): ?>
                    <!-- BELUM LOGIN -->
                    <div class="hidden sm:flex items-center gap-3">
                        <a href="login.php" class="px-5 py-2.5 text-emerald-600 hover:text-emerald-700 font-semibold transition-colors rounded-xl hover:bg-emerald-50">
                            Masuk
                        </a>
                        <a href="register.php" class="btn-primary text-white px-5 py-2.5 rounded-xl font-semibold shadow-lg text-sm">
                            Daftar
                        </a>
                    </div>
                <?php else: ?>
                    <!-- SUDAH LOGIN (PEMBELI) -->
                    <!-- Cart Icon (Desktop only) -->
                    <a href="keranjang.php" class="hidden lg:flex relative p-2.5 text-gray-600 hover:text-emerald-600 transition-colors rounded-xl hover:bg-emerald-50">
                        <i class="fa-solid fa-cart-shopping text-lg"></i>
                        <span class="absolute -top-1 -right-1 w-5 h-5 bg-emerald-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center <?= $totalKeranjang > 0 ? '' : 'hidden' ?>"><?= $totalKeranjang ?></span>
                    </a>

                    <!-- User Profile Dropdown (Desktop only) -->
                    <div class="user-dropdown relative hidden lg:block">
                        <button class="flex items-center gap-2 sm:gap-3 pl-2 pr-1 sm:pr-2 py-1.5 rounded-full hover:bg-gray-100 transition-colors" onclick="toggleUserDropdown(event)">
                            <img src="<?= getProfilePic($userFoto) ?>" alt="<?= clean($userName) ?>" class="w-9 h-9 rounded-full object-cover border-2 border-emerald-200">
                            <div class="hidden sm:flex flex-col items-start">
                                <span class="text-sm font-bold text-gray-800 max-w-[100px] truncate leading-tight"><?= clean($userName) ?></span>
                                <span class="text-[10px] text-gray-400 font-medium leading-tight">Pembeli</span>
                            </div>
                            <i class="fa-solid fa-chevron-down text-xs text-gray-400 mr-1 transition-transform duration-200" id="userDropdownIcon"></i>
                        </button>
                        
                        <!-- Dropdown Menu -->
                        <div class="user-dropdown-menu" id="userDropdownMenu">
                            <!-- Mobile-only user info header -->
                            <div class="sm:hidden px-4 py-3 border-b border-gray-100 flex items-center gap-3">
                                <img src="<?= getProfilePic($userFoto) ?>" alt="<?= clean($userName) ?>" class="w-12 h-12 rounded-full object-cover border-2 border-emerald-200">
                                <div>
                                    <p class="text-sm font-bold text-gray-800"><?= clean($userName) ?></p>
                                    <p class="text-xs text-gray-500">Pembeli</p>
                                </div>
                            </div>
                            
                            <div class="hidden sm:block px-4 py-3 border-b border-gray-100">
                                <p class="text-sm font-bold text-gray-800"><?= clean($userName) ?></p>
                                <p class="text-xs text-gray-500">Pembeli</p>
                            </div>
                            
                            <a href="profil.php" class="user-dropdown-item">
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
                <?php endif; ?>

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

            <?php if ($isLoggedIn): ?>
            <!-- Mobile: User Profile Card (Top) -->
            <div class="bg-gradient-to-r from-emerald-50 to-teal-50 rounded-2xl p-4 mb-4 border border-emerald-100">
                <div class="flex items-center gap-4">
                    <img src="<?= getProfilePic($userFoto) ?>" alt="<?= clean($userName) ?>" class="w-14 h-14 rounded-full object-cover border-2 border-emerald-300 shadow-sm">
                    <div class="flex-1 min-w-0">
                        <p class="text-base font-bold text-gray-900 truncate"><?= clean($userName) ?></p>
                        <p class="text-xs text-emerald-600 font-medium flex items-center gap-1">
                            <i class="fa-solid fa-circle-check text-[10px]"></i> Pembeli
                        </p>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-2 mt-4">
                    <a href="profil.php" class="flex flex-col items-center gap-1 py-2 bg-white rounded-xl border border-emerald-100 hover:border-emerald-300 transition-colors">
                        <i class="fa-solid fa-user text-emerald-600 text-sm"></i>
                        <span class="text-[10px] font-semibold text-gray-600">Profil</span>
                    </a>
                    <a href="pesanan.php" class="flex flex-col items-center gap-1 py-2 bg-white rounded-xl border border-emerald-100 hover:border-emerald-300 transition-colors">
                        <i class="fa-solid fa-bag-shopping text-emerald-600 text-sm"></i>
                        <span class="text-[10px] font-semibold text-gray-600">Pesanan</span>
                    </a>
                    <a href="keranjang.php" class="flex flex-col items-center gap-1 py-2 bg-white rounded-xl border border-emerald-100 hover:border-emerald-300 transition-colors relative">
                        <i class="fa-solid fa-cart-shopping text-emerald-600 text-sm"></i>
                        <span class="text-[10px] font-semibold text-gray-600">Keranjang</span>
                        <span class="absolute -top-1 -right-1 w-5 h-5 bg-emerald-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center <?= $totalKeranjang > 0 ? '' : 'hidden' ?>"><?= $totalKeranjang ?></span>
                    </a>
                </div>
            </div>
            <?php endif; ?>

            <!-- Navigation Links -->
            <div class="space-y-1">
                <p class="px-4 py-2 text-xs font-bold text-gray-400 uppercase tracking-wider">Menu</p>
                <a href="index.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium transition-all <?= $currentPage === 'index' ? 'text-emerald-700 bg-emerald-50 border border-emerald-100' : 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-600' ?>">
                    <i class="fa-solid fa-house w-5 text-center <?= $currentPage === 'index' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Beranda
                </a>
                <a href="scan.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium transition-all <?= $currentPage === 'scan' ? 'text-emerald-700 bg-emerald-50 border border-emerald-100' : 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-600' ?>">
                    <i class="fa-solid fa-qrcode w-5 text-center <?= $currentPage === 'scan' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Scan AI
                </a>
                <a href="produk.php" class="flex items-center gap-3 py-3 px-4 rounded-xl font-medium transition-all <?= $currentPage === 'produk' ? 'text-emerald-700 bg-emerald-50 border border-emerald-100' : 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-600' ?>">
                    <i class="fa-solid fa-shop w-5 text-center <?= $currentPage === 'produk' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Katalog Produk
                </a>
               
                <a href="index.php#tentang" class="flex items-center gap-3 py-3 px-4 rounded-xl text-gray-700 hover:bg-emerald-50 hover:text-emerald-600 font-medium transition-all">
                    <i class="fa-solid fa-circle-info w-5 text-center text-emerald-500"></i> Tentang
                </a>
            </div>

            <?php if (!$isLoggedIn): ?>
            <!-- Guest: Auth Buttons -->
            <div class="pt-4 mt-4 border-t border-gray-100">
                <div class="grid grid-cols-2 gap-3">
                    <a href="login.php" class="text-center py-3 text-emerald-600 border-2 border-emerald-600 rounded-xl font-semibold hover:bg-emerald-50 transition-all">
                        Masuk
                    </a>
                    <a href="register.php" class="text-center py-3 btn-primary text-white rounded-xl font-semibold shadow-md">
                        Daftar
                    </a>
                </div>
            </div>
            <?php else: ?>
            <!-- Logged In: Logout -->
            <div class="pt-4 mt-4 border-t border-gray-100">
                <a href="logout.php" class="flex items-center gap-3 py-3 px-4 rounded-xl text-red-600 hover:bg-red-50 font-medium transition-all">
                    <i class="fa-solid fa-right-from-bracket w-5 text-center"></i> Keluar Akun
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</nav>

    <!-- ==================== HERO ==================== -->
    <div class="bg-gradient-to-br from-emerald-90 to-teal-100 text-black pt-32 pb-12 md:pb-16 relative overflow-hidden">
        <div class="absolute inset-0 opacity-10">
            <div class="absolute top-0 right-0 w-96 h-96 bg-white rounded-full -translate-y-1/2 translate-x-1/3"></div>
            <div class="absolute bottom-0 left-0 w-64 h-64 bg-white rounded-full translate-y-1/2 -translate-x-1/3"></div>
        </div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
            <div class="flex items-center gap-2 mb-3">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 bg-white/10 backdrop-blur rounded-full text-xs font-medium">
                    <span class="w-1.5 h-1.5 bg-emerald-300 rounded-full animate-pulse"></span>
                    AI Detection System v2.0
                </span>
            </div>
            <h1 class="text-3xl md:text-4xl font-bold mb-3">Diagnosis Dokter Tanaman AI</h1>
            <p class="text-black-100 text-sm md:text-base max-w-xl">
                Ambil foto gejala pada daun atau batang tanaman, AI akan menganalisis dan memberikan rekomendasi obat tepat sasaran.
            </p>
        </div>
    </div>

    <!-- ==================== MAIN CONTENT ==================== -->
    <main class="py-8 md:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

            <!-- ===== PANEL 1: UPLOAD FOTO ===== -->
            <div id="panelUpload" class="bg-white rounded-2xl border border-gray-100 p-6 md:p-8 shadow-sm space-y-6 fade-in">

                <!-- Upload Methods -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <!-- Camera Button -->
                    <button onclick="openCamera()" class="group relative bg-gradient-to-br from-emerald-50 to-teal-50 border-2 border-emerald-200 hover:border-emerald-400 rounded-2xl p-6 text-center transition-all hover:shadow-lg hover:-translate-y-1">
                        <div class="w-14 h-14 bg-emerald-100 group-hover:bg-emerald-600 rounded-2xl flex items-center justify-center mx-auto mb-3 transition-all">
                            <i class="fa-solid fa-camera text-2xl text-emerald-600 group-hover:text-white transition-colors"></i>
                        </div>
                        <h3 class="font-bold text-gray-900 text-sm">Ambil Foto Kamera</h3>
                        <p class="text-xs text-gray-500 mt-1">Langsung dari kamera perangkat</p>
                    </button>

                    <!-- Upload Button -->
                    <div class="group relative bg-gray-50 border-2 border-gray-200 hover:border-emerald-300 rounded-2xl p-6 text-center transition-all hover:shadow-lg hover:-translate-y-1 cursor-pointer"
                         onclick="document.getElementById('foto_tanaman').click()">
                        <div class="w-14 h-14 bg-gray-100 group-hover:bg-emerald-100 rounded-2xl flex items-center justify-center mx-auto mb-3 transition-all">
                            <i class="fa-solid fa-cloud-arrow-up text-2xl text-gray-400 group-hover:text-emerald-600 transition-colors"></i>
                        </div>
                        <h3 class="font-bold text-gray-900 text-sm">Upload dari Galeri</h3>
                        <p class="text-xs text-gray-500 mt-1">Pilih file dari perangkat</p>
                        <input type="file" id="foto_tanaman" accept="image/jpeg,image/png,image/jpg,image/webp" 
                            class="hidden" onchange="eksekusiScanAwal(this)">
                    </div>
                </div>

                <!-- Or Divider -->
                <div class="flex items-center gap-4">
                    <div class="flex-1 h-px bg-gray-200"></div>
                    <span class="text-xs text-gray-400 font-medium">atau drop file di sini</span>
                    <div class="flex-1 h-px bg-gray-200"></div>
                </div>

                <!-- Drop Zone -->
                <div id="dropZone" class="relative border-2 border-dashed border-gray-300 hover:border-emerald-400 bg-gray-50 hover:bg-emerald-50/50 rounded-2xl p-8 transition-all text-center h-48 flex flex-col items-center justify-center group cursor-pointer"
                     onclick="document.getElementById('foto_tanaman').click()">
                    <div id="loadingStatus" class="space-y-3 pointer-events-none">
                        <i class="fa-solid fa-images text-4xl text-gray-300 group-hover:text-emerald-400 transition-colors"></i>
                        <p class="font-semibold text-gray-600 text-sm">Drop foto di sini</p>
                        <p class="text-xs text-gray-400">JPG, PNG, WEBP (Maks. 5MB)</p>
                    </div>
                </div>

                <!-- Preview foto yang diupload -->
                <div id="previewContainer" class="hidden rounded-2xl overflow-hidden border border-gray-200 shadow-sm relative">
                    <img id="previewImage" src="" alt="Preview" class="w-full h-64 object-cover">
                    <button onclick="resetScan()" class="absolute top-3 right-3 w-8 h-8 bg-white/90 backdrop-blur rounded-full flex items-center justify-center text-gray-600 hover:text-red-500 transition-colors shadow-sm">
                        <i class="fa-solid fa-times text-sm"></i>
                    </button>
                </div>

                <!-- Tips -->
                <div class="bg-blue-50 border border-blue-100 rounded-xl p-4 flex gap-3 items-start">
                    <div class="w-8 h-8 bg-blue-100 rounded-lg flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-lightbulb text-blue-600 text-sm"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-bold text-blue-900">Tips Foto yang Bagus</h4>
                        <ul class="text-xs text-blue-700 mt-1 space-y-1">
                            <li>• Pastikan pencahayaan cukup terang</li>
                            <li>• Fokus pada daun/batang yang sakit</li>
                            <li>• Hindari bayangan yang menutupi gejala</li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- ===== PANEL 2: KUESIONER AI ===== -->
            <div id="panelKuesioner" class="panel-hidden bg-white rounded-2xl border border-gray-100 p-6 md:p-8 shadow-sm space-y-6 mt-6 fade-in">
                <div class="bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-100 rounded-2xl p-5 flex gap-4 items-start">
                    <div class="w-12 h-12 bg-blue-100 rounded-xl flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-robot text-xl text-blue-600"></i>
                    </div>
                    <div>
                        <h4 class="text-sm font-bold text-blue-900">Pertanyaan Konsultasi dari AI</h4>
                        <p class="text-xs text-blue-700 mt-1 leading-relaxed">
                            AI berhasil mendeteksi gejala visual. Jawab pertanyaan buatan AI berikut agar rekomendasi resep obat lebih tepat sasaran.
                        </p>
                    </div>
                </div>

                <form id="formKuesionerAI" onsubmit="eksekusiDiagnosisFinal(event)" class="space-y-6">
                    <div id="boxPertanyaanDinamis" class="space-y-4">
                        <!-- Pertanyaan akan di-render di sini oleh JavaScript -->
                    </div>

                    <!-- Error message container -->
                    <div id="kuesionerError" class="hidden bg-red-50 border border-red-200 rounded-xl p-4 text-sm text-red-700 flex items-center gap-2">
                        <i class="fa-solid fa-circle-exclamation"></i>
                        <span id="kuesionerErrorText"></span>
                    </div>

                    <div class="flex gap-3 pt-2">
                        <button type="button" onclick="resetScan()" 
                            class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-3.5 rounded-xl transition-colors text-center flex items-center justify-center gap-2 text-sm">
                            <i class="fa-solid fa-rotate-left"></i> Scan Ulang
                        </button>
                        <button type="submit" id="btnSubmitKuesioner" 
                            class="flex-[2] bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-semibold py-3.5 rounded-xl shadow-lg shadow-emerald-200 transition-all text-center flex items-center justify-center gap-2 text-sm">
                            <span>Kirim Jawaban & Lihat Obat</span>
                            <i class="fa-solid fa-arrow-right"></i>
                        </button>
                    </div>
                </form>
            </div>

            <!-- ===== PANEL 3: HASIL DIAGNOSIS ===== -->
            <div id="panelHasil" class="panel-hidden space-y-6 mt-6 fade-in">
                <!-- Diagnosis Card -->
                <div class="bg-gray-900 text-white p-6 rounded-2xl border border-gray-800 shadow-lg relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-emerald-500/10 rounded-full -translate-y-1/2 translate-x-1/2"></div>
                    <div class="relative z-10">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="text-xs font-bold tracking-widest text-emerald-400 uppercase bg-emerald-950/50 px-3 py-1 rounded-lg border border-emerald-800">
                                <i class="fa-solid fa-stethoscope mr-1"></i> Hasil Analisis AI
                            </span>
                        </div>
                        <h3 id="txtNamaPenyakit" class="text-2xl font-bold text-white">Memuat...</h3>
                        <div class="h-px bg-gray-700 my-4"></div>
                        <p id="txtDeskripsiSolusi" class="text-sm text-gray-400 leading-relaxed">Sedang menganalisis...</p>
                    </div>
                </div>

                <!-- Produk Rekomendasi -->
                <div class="space-y-4">
                    <h4 class="text-sm font-bold text-gray-800 flex items-center gap-2">
                        <i class="fa-solid fa-prescription-bottle-medical text-emerald-600"></i> 
                        Obat & Solusi Tersedia di Etanimart
                    </h4>
                    <div id="boxKatalogProduk" class="grid grid-cols-1 sm:grid-cols-2 gap-3.5">
                        <!-- Produk akan di-render di sini oleh JavaScript -->
                    </div>

                    <!-- Kalau gak ada produk -->
                    <div id="noProdukMessage" class="hidden bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
                        <i class="fa-solid fa-triangle-exclamation text-yellow-500 text-2xl mb-2"></i>
                        <p class="text-sm text-yellow-700">
                            Produk obat belum tersedia di katalog. Hubungi admin untuk informasi lebih lanjut.
                        </p>
                    </div>
                </div>

                <!-- Tombol Scan Ulang -->
                <div class="text-center pt-4">
                    <button onclick="resetScan()" 
                        class="bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-700 hover:to-teal-700 text-white font-semibold py-3.5 px-8 rounded-xl shadow-lg shadow-emerald-200 transition-all inline-flex items-center gap-2">
                        <i class="fa-solid fa-camera"></i> Scan Tanaman Lain
                    </button>
                </div>
            </div>

        </div>
    </main>

    <!-- ==================== CAMERA MODAL ==================== -->
    <div id="cameraModal" class="camera-modal" onclick="closeCamera(event)">
        <div class="camera-content" onclick="event.stopPropagation()">
            <!-- Header -->
            <div class="p-4 border-b border-gray-800 flex items-center justify-between">
                <h3 class="font-bold text-white text-sm flex items-center gap-2">
                    <i class="fa-solid fa-camera text-emerald-400"></i>
                    Ambil Foto
                </h3>
                <button onclick="closeCamera()" class="w-8 h-8 bg-gray-800 hover:bg-gray-700 rounded-lg flex items-center justify-center text-gray-400 hover:text-white transition-colors">
                    <i class="fa-solid fa-times text-sm"></i>
                </button>
            </div>

            <!-- Video Preview -->
            <div class="relative bg-black aspect-[3/4] sm:aspect-video">
                <video id="cameraVideo" autoplay playsinline class="w-full h-full object-cover"></video>

                <!-- Scan overlay -->
                <div class="absolute inset-0 pointer-events-none">
                    <div class="absolute inset-8 border-2 border-dashed border-emerald-400/50 rounded-2xl">
                        <div class="scan-line"></div>
                    </div>
                    <div class="absolute bottom-4 left-0 right-0 text-center">
                        <p class="text-white/70 text-xs font-medium bg-black/50 inline-block px-3 py-1 rounded-full">
                            Arahkan kamera ke daun/batang yang sakit
                        </p>
                    </div>
                </div>
            </div>

            <!-- Controls -->
            <div class="p-4 bg-gray-900 flex items-center justify-center gap-6">
                <button onclick="switchCamera()" class="w-12 h-12 bg-gray-800 hover:bg-gray-700 rounded-full flex items-center justify-center text-gray-400 hover:text-white transition-colors" title="Ganti Kamera">
                    <i class="fa-solid fa-rotate"></i>
                </button>
                <button onclick="takePhoto()" class="w-16 h-16 bg-white rounded-full flex items-center justify-center shadow-lg hover:scale-105 transition-transform">
                    <div class="w-12 h-12 bg-emerald-500 rounded-full border-4 border-gray-900"></div>
                </button>
                <button onclick="closeCamera()" class="w-12 h-12 bg-gray-800 hover:bg-gray-700 rounded-full flex items-center justify-center text-gray-400 hover:text-white transition-colors" title="Batal">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Hidden canvas for photo capture -->
    <canvas id="photoCanvas" class="hidden"></canvas>

    <!-- ==================== FOOTER (SAMA SEPERTI INDEX) ==================== -->
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
                        <li><a href="index.php" class="hover:text-emerald-500 transition-colors">Beranda</a></li>
                        <li><a href="scan.php" class="hover:text-emerald-500 transition-colors">Scan AI</a></li>
                        <li><a href="produk.php" class="hover:text-emerald-500 transition-colors">Katalog Produk</a></li>
                        <li><a href="index.php#tentang" class="hover:text-emerald-500 transition-colors">Tentang Kami</a></li>
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
                    &copy; <?= date('Y') ?> Etanimart Project. All Rights Reserved.
                </p>
                <div class="flex gap-6 text-xs text-gray-600">
                    <a href="#" class="hover:text-emerald-500 transition-colors">Kebijakan Privasi</a>
                    <a href="#" class="hover:text-emerald-500 transition-colors">Syarat & Ketentuan</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- ==================== JAVASCRIPT ==================== -->
    <script>
// ===== AUTO-START DARI FLOATING BUTTON =====
document.addEventListener('DOMContentLoaded', () => {
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('autostart') === '1') {
        const fotoBase64 = sessionStorage.getItem('scan_foto_temp');
        if (fotoBase64) {
            // Convert base64 ke file
            fetch(fotoBase64)
                .then(res => res.blob())
                .then(blob => {
                    const file = new File([blob], 'camera-photo.jpg', { type: 'image/jpeg' });
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    
                    const fileInput = document.getElementById('foto_tanaman');
                    fileInput.files = dataTransfer.files;
                    
                    // Trigger preview & scan
                    eksekusiScanAwal(fileInput);
                    
                    // Hapus dari sessionStorage
                    sessionStorage.removeItem('scan_foto_temp');
                });
        }
    }
});


        // ===== STATE MANAGEMENT =====
        let isProcessing = false;
        let currentStream = null;
        let facingMode = 'environment'; // 'environment' = back camera, 'user' = front camera

       

        // ===== PANEL HELPER =====
        function showPanel(panelId) {
            ['panelUpload', 'panelKuesioner', 'panelHasil'].forEach(id => {
                const el = document.getElementById(id);
                el.classList.add('panel-hidden');
                el.classList.remove('panel-visible', 'fade-in');
            });
            const panel = document.getElementById(panelId);
            panel.classList.remove('panel-hidden');
            panel.classList.add('panel-visible', 'fade-in');
        }

        function resetScan() {
            if (isProcessing) return;

            document.getElementById('foto_tanaman').value = '';
            document.getElementById('previewContainer').classList.add('hidden');
            document.getElementById('previewImage').src = '';
            document.getElementById('boxPertanyaanDinamis').innerHTML = '';
            document.getElementById('kuesionerError').classList.add('hidden');
            document.getElementById('boxKatalogProduk').innerHTML = '';
            document.getElementById('noProdukMessage').classList.add('hidden');

            document.getElementById('loadingStatus').innerHTML = `
                <i class="fa-solid fa-images text-4xl text-gray-300 group-hover:text-emerald-400 transition-colors"></i>
                <p class="font-semibold text-gray-600 text-sm">Drop foto di sini</p>
                <p class="text-xs text-gray-400">JPG, PNG, WEBP (Maks. 5MB)</p>
            `;

            showPanel('panelUpload');
        }

        function showError(message, isAlert = false) {
            if (isAlert) {
                alert(message);
            } else {
                const errorBox = document.getElementById('kuesionerError');
                document.getElementById('kuesionerErrorText').textContent = message;
                errorBox.classList.remove('hidden');
            }
        }

        // ===== CAMERA FUNCTIONS =====
        async function openCamera() {
            const modal = document.getElementById('cameraModal');
            const video = document.getElementById('cameraVideo');

            try {
                currentStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: facingMode, width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: false
                });
                video.srcObject = currentStream;
                modal.classList.add('active');
                document.body.style.overflow = 'hidden';
            } catch (err) {
                console.error('Camera error:', err);
                alert('Tidak dapat mengakses kamera. Pastikan izin kamera sudah diberikan, atau gunakan upload file.');
            }
        }

        function closeCamera(event) {
            if (event && event.target !== event.currentTarget) return;

            const modal = document.getElementById('cameraModal');
            const video = document.getElementById('cameraVideo');

            modal.classList.remove('active');
            document.body.style.overflow = '';

            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
                currentStream = null;
            }
            video.srcObject = null;
        }

        async function switchCamera() {
            facingMode = facingMode === 'environment' ? 'user' : 'environment';

            if (currentStream) {
                currentStream.getTracks().forEach(track => track.stop());
            }

            try {
                const video = document.getElementById('cameraVideo');
                currentStream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: facingMode, width: { ideal: 1280 }, height: { ideal: 720 } },
                    audio: false
                });
                video.srcObject = currentStream;
            } catch (err) {
                console.error('Switch camera error:', err);
                facingMode = facingMode === 'environment' ? 'user' : 'environment'; // revert
                alert('Gagal mengganti kamera.');
            }
        }

        function takePhoto() {
            const video = document.getElementById('cameraVideo');
            const canvas = document.getElementById('photoCanvas');

            if (!video.videoWidth) {
                alert('Kamera belum siap. Tunggu sebentar.');
                return;
            }

            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            const ctx = canvas.getContext('2d');
            ctx.drawImage(video, 0, 0);

            // Convert to blob and create file
            canvas.toBlob((blob) => {
                const file = new File([blob], 'camera-photo.jpg', { type: 'image/jpeg' });
                const dataTransfer = new DataTransfer();
                dataTransfer.items.add(file);

                const fileInput = document.getElementById('foto_tanaman');
                fileInput.files = dataTransfer.files;

                closeCamera();
                eksekusiScanAwal(fileInput);
            }, 'image/jpeg', 0.9);
        }

        // ===== 1. KIRIM FOTO → AI GENERATE PERTANYAAN =====
        function eksekusiScanAwal(input) {
            if (!input.files || !input.files[0]) return;
            if (isProcessing) return;

            const file = input.files[0];

            const allowedTypes = ['image/jpeg', 'image/png', 'image/jpg', 'image/webp'];
            if (!allowedTypes.includes(file.type)) {
                alert('Format file tidak didukung. Gunakan JPG, PNG, atau WEBP.');
                input.value = '';
                return;
            }

            if (file.size > 5 * 1024 * 1024) {
                alert('Ukuran file maksimal 5MB.');
                input.value = '';
                return;
            }

            isProcessing = true;

            const reader = new FileReader();
            reader.onload = function(e) {
                document.getElementById('previewImage').src = e.target.result;
                document.getElementById('previewContainer').classList.remove('hidden');
            };
            reader.readAsDataURL(file);

            document.getElementById('loadingStatus').innerHTML = `
                <i class="fa-solid fa-circle-notch fa-spin text-4xl text-emerald-600 loading-pulse"></i>
                <p class="font-semibold text-emerald-700 text-sm">AI sedang menganalisis gejala foto...</p>
                <p class="text-xs text-gray-500">Mohon tunggu sebentar</p>
            `;

            let formData = new FormData();
            formData.append("foto_tanaman", file);

            fetch("scan.php?action=scan_foto", { 
                method: "POST", 
                body: formData 
            })
            .then(res => {
                if (!res.ok) throw new Error('Network response was not ok: ' + res.status);
                return res.json();
            })
            .then(data => {
                isProcessing = false;

                if (data.status === 'success' && data.pertanyaan_ai && data.pertanyaan_ai.length > 0) {
                    renderPertanyaan(data.pertanyaan_ai);
                    showPanel('panelKuesioner');
                } else {
                    throw new Error(data.message || 'Gagal mendapatkan pertanyaan dari AI');
                }
            })
            .catch(err => {
                isProcessing = false;
                console.error('Error:', err);
                alert('Error: ' + err.message);

                document.getElementById('loadingStatus').innerHTML = `
                    <i class="fa-solid fa-triangle-exclamation text-4xl text-red-500"></i>
                    <p class="font-semibold text-red-700 text-sm">Gagal menganalisis</p>
                    <p class="text-xs text-gray-500">Coba foto lain yang lebih jelas</p>
                `;
            });
        }

        function renderPertanyaan(pertanyaanList) {
            const boxPertanyaan = document.getElementById('boxPertanyaanDinamis');
            boxPertanyaan.innerHTML = '';

            pertanyaanList.forEach((q, idx) => {
                if (!q.key || !q.teks_pertanyaan || !Array.isArray(q.opsi)) {
                    console.warn('Pertanyaan invalid:', q);
                    return;
                }

                let templateOpsi = '';
                q.opsi.forEach((opt, optIdx) => {
                    const optId = `opt_${idx}_${optIdx}`;
                    templateOpsi += `
                        <label class="border p-3 rounded-xl bg-white flex items-center gap-3 cursor-pointer border-gray-200 hover:border-emerald-400 hover:bg-emerald-50 transition-all text-sm font-medium group">
                            <input type="radio" name="jawaban[${q.key}]" value="${opt}" required 
                                class="accent-emerald-600 w-4 h-4 cursor-pointer"
                                id="${optId}">
                            <span class="group-hover:text-emerald-700">${opt}</span>
                        </label>
                    `;
                });

                const html = `
                    <div class="space-y-3 bg-gray-50 p-5 rounded-2xl border border-gray-100">
                        <div class="flex items-start gap-3">
                            <span class="bg-emerald-100 text-emerald-700 text-xs font-bold px-3 py-1 rounded-full shrink-0 mt-0.5">
                                ${idx + 1}
                            </span>
                            <label class="block text-sm font-semibold text-gray-800 leading-relaxed">
                                ${q.teks_pertanyaan}
                            </label>
                        </div>
                        <div class="grid grid-cols-1 gap-2 pl-10">${templateOpsi}</div>
                    </div>
                `;

                boxPertanyaan.insertAdjacentHTML('beforeend', html);
            });

            setTimeout(() => {
                document.getElementById('panelKuesioner').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }

        // ===== 2. KIRIM JAWABAN → DIAGNOSIS FINAL =====
        function eksekusiDiagnosisFinal(event) {
            event.preventDefault();
            if (isProcessing) return;

            const btn = document.getElementById('btnSubmitKuesioner');
            const form = document.getElementById('formKuesionerAI');
            const errorBox = document.getElementById('kuesionerError');

            const formData = new FormData(form);
            const jawabanKeys = [...formData.keys()].filter(k => k.startsWith('jawaban['));
            const totalPertanyaan = document.querySelectorAll('#boxPertanyaanDinamis > div').length;

            if (jawabanKeys.length < totalPertanyaan) {
                showError('Jawab semua pertanyaan terlebih dahulu!');
                return;
            }

            errorBox.classList.add('hidden');
            isProcessing = true;

            btn.disabled = true;
            btn.innerHTML = `
                <i class="fa-solid fa-circle-notch fa-spin"></i>
                <span>AI sedang merumuskan solusi...</span>
            `;

            fetch("scan.php?action=hitung_diagnosis", { 
                method: "POST", 
                body: formData 
            })
            .then(res => {
                if (!res.ok) throw new Error('Network response was not ok: ' + res.status);
                return res.json();
            })
            .then(data => {
                isProcessing = false;
                btn.disabled = false;
                btn.innerHTML = `
                    <span>Kirim Jawaban & Lihat Obat</span>
                    <i class="fa-solid fa-arrow-right"></i>
                `;

                if (data.status === 'success') {
                    renderHasil(data);
                    showPanel('panelHasil');
                } else {
                    throw new Error(data.message || 'Gagal mendapatkan diagnosis');
                }
            })
            .catch(err => {
                isProcessing = false;
                btn.disabled = false;
                btn.innerHTML = `
                    <span>Kirim Jawaban & Lihat Obat</span>
                    <i class="fa-solid fa-arrow-right"></i>
                `;

                showError('Error: ' + err.message);
                console.error('Error:', err);
            });
        }

        function renderHasil(data) {
            document.getElementById('txtNamaPenyakit').textContent = data.diagnosis.penyakit;
            document.getElementById('txtDeskripsiSolusi').textContent = data.diagnosis.deskripsi;

            const boxKatalog = document.getElementById('boxKatalogProduk');
            const noProdukMsg = document.getElementById('noProdukMessage');
            boxKatalog.innerHTML = '';

            // Kalau diagnosis nggak valid, jangan tampilin produk
            if (data.is_valid === false) {
                noProdukMsg.classList.remove('hidden');
                noProdukMsg.innerHTML = `
                    <i class="fa-solid fa-circle-info text-blue-500 text-2xl mb-2"></i>
                    <p class="text-sm text-blue-700">
                        Penyakit tidak teridentifikasi dengan jelas. Tidak ada rekomendasi obat spesifik.
                        <br>Silakan scan ulang dengan foto yang lebih jelas.
                    </p>
                `;
                return;
            }

            if (!data.produk || data.produk.length === 0) {
                noProdukMsg.classList.remove('hidden');
                noProdukMsg.innerHTML = `
                    <i class="fa-solid fa-triangle-exclamation text-yellow-500 text-2xl mb-2"></i>
                    <p class="text-sm text-yellow-700">
                        Produk obat belum tersedia di katalog. Hubungi admin untuk informasi lebih lanjut.
                    </p>
                `;
            } else {
                noProdukMsg.classList.add('hidden');

                data.produk.forEach(p => {
                    let listGambar = [];
                    try { 
                        listGambar = JSON.parse(p.gambar); 
                    } catch(e) {
                        if (p.gambar && p.gambar.includes(',')) {
                            listGambar = p.gambar.split(',').map(s => s.trim());
                        } else if (p.gambar) {
                            listGambar = [p.gambar];
                        }
                    }

                    let coverFoto = (listGambar && listGambar.length > 0) 
                        ? `uploads/${listGambar[0]}` 
                        : 'https://placehold.co/150?text=No+Image';

                    const hargaFormatted = parseInt(p.harga || 0).toLocaleString('id-ID');

                    const produkHTML = `
                        <div class="product-card bg-white border border-gray-100 p-3.5 rounded-2xl flex gap-4 items-center shadow-sm hover:shadow-md transition-all group">
                            <div class="w-16 h-16 rounded-xl overflow-hidden bg-gray-50 border border-gray-100 shrink-0">
                                <img src="${coverFoto}" 
                                    onerror="this.src='https://placehold.co/150?text=No+Image'" 
                                    class="card-img w-full h-full object-cover">
                            </div>
                            <div class="flex-grow min-w-0">
                                <span class="text-[10px] bg-emerald-50 text-emerald-700 font-bold px-2 py-0.5 rounded-sm uppercase tracking-wide">
                                    ${p.kategori || 'Umum'}
                                </span>
                                <h5 class="text-xs font-bold text-gray-900 mt-1 truncate">${p.nama}</h5>
                                <p class="text-xs font-semibold text-emerald-600 mt-0.5">Rp ${hargaFormatted}</p>
                            </div>
                            <a href="detail_produk.php?id=${p.id}" 
                                class="p-2.5 bg-gray-50 hover:bg-emerald-50 text-gray-400 hover:text-emerald-600 rounded-xl transition-all shrink-0">
                                <i class="fa-solid fa-chevron-right text-xs"></i>
                            </a>
                        </div>
                    `;

                    boxKatalog.insertAdjacentHTML('beforeend', produkHTML);
                });
            }

            setTimeout(() => {
                document.getElementById('panelHasil').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }

        // ===== DRAG & DROP SUPPORT =====
        const dropZone = document.getElementById('dropZone');

        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.classList.add('drop-active');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropZone.addEventListener(eventName, () => {
                dropZone.classList.remove('drop-active');
            });
        });

        dropZone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const files = dt.files;
            if (files.length > 0) {
                document.getElementById('foto_tanaman').files = files;
                eksekusiScanAwal(document.getElementById('foto_tanaman'));
            }
        });

        // ===== CLOSE MODAL ON ESCAPE =====
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeCamera();
                mobileMenu.classList.add('hidden');
            }
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

        // Close mobile menu when clicking a link
        document.querySelectorAll('#mobile-menu a').forEach(link => {
            link.addEventListener('click', () => {
                closeMobileMenu();
            });
        });

        // Close mobile menu when clicking outside
        document.addEventListener('click', (e) => {
            if (!mobileMenu.contains(e.target) && !btnMenu.contains(e.target)) {
                closeMobileMenu();
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

        // ===== USER DROPDOWN =====
        function toggleUserDropdown(e) {
            e.stopPropagation();
            const menu = document.getElementById('userDropdownMenu');
            const icon = document.getElementById('userDropdownIcon');
            const isActive = menu.classList.contains('active');
            
            // Close all other dropdowns first
            document.querySelectorAll('.user-dropdown-menu').forEach(m => m.classList.remove('active'));
            document.querySelectorAll('#userDropdownIcon').forEach(i => i.style.transform = 'rotate(0deg)');
            
            if (!isActive) {
                menu.classList.add('active');
                icon.style.transform = 'rotate(180deg)';
            }
        }

        // Close dropdown when clicking outside
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

        // Close dropdown on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.user-dropdown-menu').forEach(m => m.classList.remove('active'));
                document.querySelectorAll('#userDropdownIcon').forEach(i => i.style.transform = 'rotate(0deg)');
                closeMobileMenu();
                closeCamera();
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
    </script>
</body>
</html>