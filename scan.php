<?php
// =============================================================================
// scan.php - Etanimart AI Plant Doctor (FIXED v3)
// V3:
// 1. Validasi keyword_obat dibuat case-insensitive
//    (FUNGISIDA / fungisida / 'Fungisida ' tetap dikenali).
// 2. Tambah logging keyword mentah dari AI untuk mempermudah debug
//    pencarian produk rekomendasi.
// 3. Perbaiki struktur HTML panelHasil: dua </div> nyasar membuat
//    bagian produk keluar dari panel (tampil sebelum scan, tidak
//    ikut berganti panel).
// PERBAIKAN UTAMA:
// 1. ob_start() di baris paling awal -> cegah "headers already sent"
//    dan mencegah output stray (BOM/whitespace/notice) ikut terkirim.
// 2. Semua proses AJAX dibungkus try/catch(\Throwable) -> fatal error pun
//    tetap menghasilkan JSON, bukan halaman error HTML.
// 3. ob_clean() dipanggil tepat sebelum setiap sendJson() -> membuang
//    warning/notice yang mungkin sempat tercetak sebelum JSON dikirim.
// 4. display_errors dimatikan di production, tapi logging tetap jalan.
// =============================================================================

// --- WAJIB PALING ATAS, sebelum apapun yang bisa menghasilkan output ---
ob_start();

// Jangan tampilkan error ke output (biar tidak merusak JSON),
// tapi tetap catat ke log server untuk debugging.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

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
// (Fitur scan TIDAK wajib login - redirect ini hanya berlaku
//  untuk request halaman biasa, bukan untuk request AJAX)
// ==========================================
$isLoggedIn = isset($_SESSION['user_id']);
$userRole   = $isLoggedIn ? ($_SESSION['role'] ?? 'pembeli') : null;
$userName   = $isLoggedIn ? ($_SESSION['nama'] ?? 'Pengguna') : null;
$userFoto   = $isLoggedIn ? ($_SESSION['foto_profil'] ?? null) : null;

$isAjaxRequest = ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']));

// Redirect admin/penjual HANYA untuk request halaman biasa (GET),
// jangan pernah redirect saat request AJAX (POST + action).
if (!$isAjaxRequest && $isLoggedIn && in_array($userRole, ['admin', 'penjual'])) {
    ob_clean();
    header('Location: ' . ($userRole === 'admin' ? 'admin/admin_dashboard.php' : 'penjual/penjual_dashboard.php'));
    exit;
}

// ==========================================
// AMBIL DATA FRESH DARI DATABASE (hanya kalau login)
// ==========================================
if ($isLoggedIn) {
    try {
        $stmtUser = $pdo->prepare("SELECT nama, foto_profil FROM users WHERE id = ?");
        $stmtUser->execute([$_SESSION['user_id']]);
        $userData = $stmtUser->fetch();

        if ($userData) {
            $userName = $userData['nama'];
            $userFoto = $userData['foto_profil'];
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
// Simpan API key di environment variable OPENAI_API_KEY.
// Untuk testing lokal, fallback string dapat diisi sementara, tetapi jangan
// commit/upload API key ke GitHub atau file yang bisa diakses publik.
define('OPENAI_API_KEY', '');
define('OPENAI_API_URL', 'https://api.openai.com/v1/responses');
define('OPENAI_MODEL', 'gpt-5.6-luna');
define('MAX_IMAGE_SIZE', 3 * 1024 * 1024);

error_log('Scan.php initialized', 0);

// ==========================================
// HELPER FUNCTIONS
// ==========================================
function sendJson($status, $data = [], $message = '') {
    if (ob_get_length()) {
        ob_clean();
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['status' => $status, 'message' => $message], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Panggil OpenAI Responses API dengan dukungan teks + gambar.
 * Output diambil dari seluruh output_text agar kompatibel dengan beberapa bentuk response.
 */
function callOpenAIAPI($prompt, $imageData = null, $mimeType = null, $useJsonInstruction = true) {
    $content = [];

    if ($useJsonInstruction) {
        $prompt .= "\n\nPENTING: Jawab HANYA dengan satu objek JSON valid. Jangan gunakan markdown, jangan gunakan ``` dan jangan menambahkan kalimat di luar JSON.";
    }

    $content[] = [
        'type' => 'input_text',
        'text' => $prompt
    ];

    if ($imageData !== null) {
        $content[] = [
            'type' => 'input_image',
            'image_url' => 'data:' . ($mimeType ?: 'image/jpeg') . ';base64,' . base64_encode($imageData),
            'detail' => 'high'
        ];
    }

    $payload = [
        'model' => OPENAI_MODEL,
        'input' => [
            [
                'role' => 'user',
                'content' => $content
            ]
        ],
        'max_output_tokens' => 3000
    ];

    $ch = curl_init(OPENAI_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENAI_API_KEY
        ],
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    error_log('OpenAI API Response Code: ' . $httpCode, 0);
    error_log('OpenAI API Response: ' . substr($response ?: '', 0, 1000), 0);

    if ($error) {
        return ['error' => 'cURL Error: ' . $error];
    }

    $decoded = json_decode($response, true);
    if ($httpCode < 200 || $httpCode >= 300) {
        $errMsg = $decoded['error']['message'] ?? ($decoded['error'] ?? $response);
        if (!is_string($errMsg)) $errMsg = json_encode($errMsg, JSON_UNESCAPED_UNICODE);
        return ['error' => 'HTTP ' . $httpCode . ': ' . $errMsg];
    }

    if (!is_array($decoded)) {
        return ['error' => 'Respons OpenAI tidak valid.'];
    }

    // Normalisasi response menjadi bentuk choices agar kode lama tetap kompatibel.
    $text = '';
    if (isset($decoded['output_text']) && is_string($decoded['output_text'])) {
        $text = $decoded['output_text'];
    } elseif (isset($decoded['output']) && is_array($decoded['output'])) {
        foreach ($decoded['output'] as $item) {
            if (!isset($item['content']) || !is_array($item['content'])) continue;
            foreach ($item['content'] as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }
        }
    }

    if ($text === '') {
        return ['error' => 'OpenAI tidak mengembalikan teks hasil.'];
    }

    return [
        'choices' => [
            ['message' => ['content' => $text]]
        ],
        'raw' => $decoded
    ];
}

function extractJsonFromAI($text) {
    if (empty($text)) return null;

    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
    $text = preg_replace('/\s*```$/m', '', $text);
    $text = trim($text);

    $decoded = json_decode($text, true);
    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;

    // Cari objek JSON pertama/terluar jika model menyertakan sedikit teks tambahan.
    $firstObj = strpos($text, '{');
    $lastObj = strrpos($text, '}');
    if ($firstObj !== false && $lastObj !== false && $lastObj > $firstObj) {
        $candidate = substr($text, $firstObj, $lastObj - $firstObj + 1);
        $decoded = json_decode($candidate, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
    }

    $firstArr = strpos($text, '[');
    $lastArr = strrpos($text, ']');
    if ($firstArr !== false && $lastArr !== false && $lastArr > $firstArr) {
        $candidate = substr($text, $firstArr, $lastArr - $firstArr + 1);
        $decoded = json_decode($candidate, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) return $decoded;
    }

    error_log('JSON Parse Error: ' . json_last_error_msg() . ' | Text: ' . substr($text, 0, 500), 0);
    return null;
}

function resizeImageIfNeeded($sourcePath, $maxDimension = 1024) {
    $info = @getimagesize($sourcePath);
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
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($sourcePath); break;
        case IMAGETYPE_PNG: $src = @imagecreatefrompng($sourcePath); break;
        case IMAGETYPE_WEBP: $src = @imagecreatefromwebp($sourcePath); break;
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

function generateFallbackPertanyaan() {
    return [
        [
            'key' => 'param_1',
            'teks_pertanyaan' => 'Bagaimana bentuk gejala utama pada daun?',
            'opsi' => ['Bercak bulat', 'Garis memanjang', 'Warna menguning', 'Berlubang']
        ],
        [
            'key' => 'param_2',
            'teks_pertanyaan' => 'Apakah ada tepung putih atau jamur terlihat?',
            'opsi' => ['Ya', 'Tidak', 'Tidak yakin']
        ],
        [
            'key' => 'param_3',
            'teks_pertanyaan' => 'Berapa lama gejala muncul?',
            'opsi' => ['Kurang dari 1 minggu', '1-2 minggu', 'Lebih dari 2 minggu']
        ],
        [
            'key' => 'param_4',
            'teks_pertanyaan' => 'Apakah gejala cepat menyebar ke daun lain?',
            'opsi' => ['Ya, sangat cepat', 'Lumayan cepat', 'Lambat', 'Belum menyebar']
        ],
        [
            'key' => 'param_5',
            'teks_pertanyaan' => 'Kondisi tanah dan cuaca saat ini?',
            'opsi' => ['Sangat basah', 'Lembab', 'Agak kering', 'Kering']
        ]
    ];
}

// ==========================================
// AJAX HANDLER
// Dibungkus try/catch(\Throwable) supaya fatal error apapun
// (misal: koneksi DB putus, GD error, dll) tetap mengembalikan JSON,
// bukan halaman error HTML dari server.
// ==========================================
if ($isAjaxRequest) {
    try {
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
            $fotoMime = $foto['type'];

            $prompt = 'Kamu adalah AI vision untuk Etanimart dan harus membuat pertanyaan konsultasi YANG SPESIFIK TERHADAP FOTO INI. '
                    . 'Analisis FOTO TERLEBIH DAHULU sebelum membuat pertanyaan. Sistem berlaku untuk tanaman apa pun: pangan, sayuran, buah, perkebunan, tanaman hias, dan lainnya. '
                    . 'Identifikasi tanaman atau kelompok tanaman yang paling mungkin, bagian tanaman yang terlihat, serta gejala visual yang benar-benar tampak. '
                    . 'Perhatikan warna, bentuk dan tepi bercak, lubang, perubahan tekstur, keriting, layu, busuk, lapisan jamur, serangga/telur, pola penyebaran, dan kondisi bagian tanaman lain yang terlihat. '
                    . 'JANGAN membuat pertanyaan generik. Setiap pertanyaan harus berasal dari sesuatu yang terlihat pada foto atau merupakan informasi lanjutan yang paling berguna untuk membedakan 2-3 kemungkinan penyebab dari gejala foto tersebut. '
                    . 'JANGAN menanyakan hal yang jawabannya sudah pasti terlihat di foto. '
                    . 'Buat tepat 5 pertanyaan. Setiap pertanyaan harus mempunyai 3-4 opsi yang jelas dan saling membedakan. '
                    . 'Jika tanaman belum dapat diidentifikasi sampai spesies, gunakan nama umum/kelompok yang paling masuk akal dan jangan mengarang. '
                    . 'Jika foto bukan tanaman, kembalikan pertanyaan_ai kosong. '
                    . 'JSON WAJIB: {"tanaman_terdeteksi":"...","jenis_tanaman":"...","bagian_terlihat":"...","gejala_terlihat":"...","kemungkinan_gangguan":["...","..."],"pertanyaan_ai":[{"key":"p1","teks_pertanyaan":"...","alasan":"...","opsi":["...","...","..."]},{"key":"p2","teks_pertanyaan":"...","alasan":"...","opsi":["...","...","..."]},{"key":"p3","teks_pertanyaan":"...","alasan":"...","opsi":["...","...","..."]},{"key":"p4","teks_pertanyaan":"...","alasan":"...","opsi":["...","...","..."]},{"key":"p5","teks_pertanyaan":"...","alasan":"...","opsi":["...","...","..."]}]}';

            $result = callOpenAIAPI($prompt, $imageData, $fotoMime, true);

            if (isset($result['error'])) {
                error_log('OpenAI Error: ' . $result['error'], 0);
                sendJson('error', [], 'AI gagal menganalisis foto untuk membuat pertanyaan. Periksa API key, billing, dan koneksi server lalu coba lagi.');
            }

            $textOut = $result['choices'][0]['message']['content'] ?? '';
            $pertanyaan_generasi_ai = extractJsonFromAI($textOut);

            if (!is_array($pertanyaan_generasi_ai)) {
                error_log('Pertanyaan JSON tidak valid: ' . substr($textOut, 0, 1000), 0);
                sendJson('error', [], 'AI mengembalikan format pertanyaan yang tidak valid. Silakan scan ulang foto.');
            }

            $validPertanyaan = [];
            if (isset($pertanyaan_generasi_ai['pertanyaan_ai']) && is_array($pertanyaan_generasi_ai['pertanyaan_ai'])) {
                foreach ($pertanyaan_generasi_ai['pertanyaan_ai'] as $q) {
                    if (isset($q['key'], $q['teks_pertanyaan']) && is_array($q['opsi'] ?? null) && count($q['opsi']) >= 3) {
                        $validPertanyaan[] = [
                            'key' => (string)$q['key'],
                            'teks_pertanyaan' => (string)$q['teks_pertanyaan'],
                            'opsi' => array_values(array_map('strval', $q['opsi']))
                        ];
                    }
                }
            }

            if (count($validPertanyaan) < 5) {
                error_log('AI menghasilkan kurang dari 5 pertanyaan spesifik.', 0);
                sendJson('error', [], 'AI belum menghasilkan 5 pertanyaan yang sesuai dengan foto. Silakan scan ulang dengan foto tanaman yang lebih jelas.');
            }

            $_SESSION['context_pertanyaan'] = json_encode([
                'tanaman_terdeteksi' => $pertanyaan_generasi_ai['tanaman_terdeteksi'] ?? 'Tanaman',
                'gejala_terlihat' => $pertanyaan_generasi_ai['gejala_terlihat'] ?? '',
                'pertanyaan' => $validPertanyaan
            ], JSON_UNESCAPED_UNICODE);
            $_SESSION['foto_scanned'] = true;
            $_SESSION['use_fallback'] = false;
            // Foto asli disimpan agar diagnosis akhir juga dapat melihat gambar.
            $_SESSION['foto_base64'] = base64_encode($imageData);
            $_SESSION['foto_mime'] = $fotoMime;

            sendJson('success', [
                'pertanyaan_ai' => $validPertanyaan,
                'tanaman_terdeteksi' => $pertanyaan_generasi_ai['tanaman_terdeteksi'] ?? 'Tanaman',
                'gejala_terlihat' => $pertanyaan_generasi_ai['gejala_terlihat'] ?? ''
            ]);
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
            $jawaban_string = json_encode($jawaban_user, JSON_UNESCAPED_UNICODE);

            // Diagnosis akhir WAJIB melihat foto asli + gejala + jawaban pengguna.
            $finalImageData = null;
            $finalMime = $_SESSION['foto_mime'] ?? 'image/jpeg';
            if (!empty($_SESSION['foto_base64'])) {
                $finalImageData = base64_decode($_SESSION['foto_base64'], true);
            }

            $prompt_final = 'Kamu adalah AI ahli diagnosis penyakit tanaman untuk Etanimart. '
                          . 'Gunakan FOTO ASLI sebagai sumber utama, lalu gabungkan hasil pengamatan awal, pertanyaan yang dibuat berdasarkan foto, dan jawaban pengguna. '
                          . 'Sistem harus menangani tanaman apa pun. Identifikasi tanaman secara bertahap: nama umum, nama ilmiah jika cukup yakin, dan jenis/kelompok tanaman. '
                          . 'Tentukan satu penyakit atau gangguan biotik yang PALING MUNGKIN. Jika bukti belum cukup kuat, gunakan awalan "Kemungkinan " dan turunkan tingkat keyakinan, tetapi tetap berikan diagnosis yang paling sesuai dengan bukti. Jangan mengarang kepastian. '
                          . 'Analisis warna, bentuk dan tepi lesi, tekstur, lubang, keriting, layu, busuk, jamur, hama, pola penyebaran, serta bagian tanaman yang terkena. '
                          . 'Jangan menjadikan kondisi lingkungan atau kerusakan mekanik sebagai penyakit; jika faktor tersebut relevan, masukkan sebagai penyebab/pemicu, bukan nama penyakit. '
                          . 'Konteks analisis awal: ' . $context_pertanyaan . '. '
                          . 'Jawaban pengguna: ' . $jawaban_string . '. '
                          . 'Pisahkan isi setiap field dan JANGAN mengulang informasi antar-field. Field deskripsi HANYA berisi ringkasan singkat tentang kondisi/penyakit dan bagaimana kondisi tersebut tampak secara umum; jangan memasukkan gejala_pendukung, penyebab, solusi, pencegahan, bahan aktif, atau rekomendasi produk ke dalam deskripsi. Field gejala_pendukung HANYA menjelaskan bukti gejala dari foto/jawaban. Field penyebab HANYA menjelaskan penyebab atau pemicu yang paling mungkin. Field solusi HANYA berisi langkah penanganan. Field pencegahan HANYA berisi langkah pencegahan. Solusi dan pencegahan harus ditulis sebagai daftar bernomor, satu langkah per baris, misalnya: 1. Langkah pertama\n2. Langkah kedua\n3. Langkah ketiga. Setelah diagnosis selesai, berikan kata kunci produk yang dapat dipakai backend untuk mencari produk nyata di katalog Etanimart. Jangan mengarang nama merek/produk. '
                          . 'JSON WAJIB dengan semua field berikut: '
                          . '{"tanaman":"...","nama_ilmiah":"...","jenis_tanaman":"...","penyakit":"Kemungkinan ...","tingkat_keyakinan":75,"bagian_terkena":"...","gejala_pendukung":"...","penyebab":"...","deskripsi":"...","solusi":"...","pencegahan":"...","jenis_pengendali":"Fungisida/Insektisida/Bakterisida/Nematisida/Non-kimia","bahan_aktif":"...","kata_kunci_produk":["...","..."],"keyword_obat":"Fungisida/Insektisida/Bakterisida/Nematisida"}. '
                          . 'Jika penyakit tidak pasti, pilih kemungkinan yang paling masuk akal berdasarkan bukti dan turunkan tingkat_keyakinan. TINGKAT_KEYAKINAN WAJIB berupa angka 1-100, JANGAN pernah mengirim 0, null, atau string. Gunakan 80-95 bila ciri visual dan jawaban sangat konsisten, 60-79 bila cukup mendukung, 40-59 bila masih ada beberapa kemungkinan. Jangan pernah mengisi nama produk fiktif. Hanya JSON.';

            // Percobaan 1: diagnosis dengan foto + JSON mode.
            $result_final = callOpenAIAPI($prompt_final, $finalImageData, $finalMime, true);
            $data_diagnosis = null;

            if (!isset($result_final['error'])) {
                $textOutFinal = $result_final['choices'][0]['message']['content'] ?? '';
                $data_diagnosis = extractJsonFromAI($textOutFinal);
                if (!is_array($data_diagnosis)) {
                    error_log('Diagnosis JSON pertama tidak valid: ' . substr($textOutFinal, 0, 500), 0);
                }
            } else {
                error_log('Diagnosis vision error: ' . $result_final['error'], 0);
            }

            // Percobaan 2: ulangi dengan prompt JSON yang lebih ketat jika parsing gagal.
            if (empty($data_diagnosis) || empty($data_diagnosis['penyakit'])) {
                $retryPrompt = $prompt_final
                    . ' PENTING: keluarkan SATU objek JSON valid saja. Jangan gunakan markdown, jangan gunakan ```.';

                $retry = callOpenAIAPI($retryPrompt, $finalImageData, $finalMime, false);

                if (!isset($retry['error'])) {
                    $retryText = $retry['choices'][0]['message']['content'] ?? '';
                    $retryData = extractJsonFromAI($retryText);
                    if (is_array($retryData) && !empty($retryData['penyakit'])) {
                        $data_diagnosis = $retryData;
                    } else {
                        error_log('Diagnosis retry vision JSON tidak valid: ' . substr($retryText, 0, 500), 0);
                    }
                } else {
                    error_log('Diagnosis retry vision error: ' . $retry['error'], 0);
                }
            }

            // Percobaan 3: jika masalah berasal dari gambar/session, lakukan diagnosis
            // berbasis hasil analisis awal + jawaban. Jadi user tidak mentok error.
            if (empty($data_diagnosis) || empty($data_diagnosis['penyakit'])) {
                $textOnlyPrompt = 'Kamu adalah AI diagnosis tanaman Etanimart. Gunakan hasil analisis visual awal yang tersimpan dan jawaban pengguna untuk menghasilkan diagnosis paling mungkin. '
                    . 'Jangan mengarang nama tanaman/penyakit jika tidak didukung; gunakan awalan "Kemungkinan " bila perlu. '
                    . 'Kembalikan JSON lengkap dengan tanaman, nama_ilmiah, jenis_tanaman, penyakit, tingkat_keyakinan (angka 1-100, jangan 0), bagian_terkena, gejala_pendukung, penyebab, deskripsi, solusi, pencegahan, jenis_pengendali, bahan_aktif, kata_kunci_produk, dan keyword_obat. '
                    . 'Konteks analisis awal: ' . $context_pertanyaan . '. Jawaban pengguna: ' . $jawaban_string . '.';

                $textOnly = callOpenAIAPI($textOnlyPrompt, null, null, true);

                if (!isset($textOnly['error'])) {
                    $textOnlyContent = $textOnly['choices'][0]['message']['content'] ?? '';
                    $textOnlyData = extractJsonFromAI($textOnlyContent);
                    if (is_array($textOnlyData) && !empty($textOnlyData['penyakit'])) {
                        $data_diagnosis = $textOnlyData;
                    } else {
                        error_log('Diagnosis text-only JSON tidak valid: ' . substr($textOnlyContent, 0, 500), 0);
                    }
                } else {
                    error_log('Diagnosis text-only error: ' . $textOnly['error'], 0);
                }
            }

            // Jangan hapus session jika gagal. User masih bisa mencoba submit lagi.
            if (empty($data_diagnosis) || empty($data_diagnosis['penyakit'])) {
                error_log('Diagnosis AI gagal setelah 3 percobaan.', 0);
                sendJson('error', [], 'AI belum berhasil membaca hasil diagnosis. Coba tekan tombol diagnosis sekali lagi; foto dan jawaban Anda masih tersimpan.');
            }

            $penyakit = trim((string)($data_diagnosis['penyakit'] ?? ''));
            $keyword_obat = trim((string)($data_diagnosis['keyword_obat'] ?? ($data_diagnosis['jenis_pengendali'] ?? 'Fungisida')));
            // Debug: lihat jawaban mentah AI di error log hosting.
            error_log('keyword_obat mentah AI: ' . var_export($data_diagnosis['keyword_obat'] ?? null, true)
                . ' | jenis_pengendali: ' . var_export($data_diagnosis['jenis_pengendali'] ?? null, true));

            // Sanitasi jika model tetap mengirim frasa terlarang.
            $forbidden = [
                'Penyakit Tidak Teridentifikasi',
                'Kemungkinan Penyakit Tanaman',
                'Tidak diketahui',
                'Tidak dapat menentukan diagnosis'
            ];
            foreach ($forbidden as $bad) {
                if (stripos($penyakit, $bad) !== false) {
                    $penyakit = 'Kemungkinan Infeksi Tanaman';
                    break;
                }
            }

            if ($penyakit === '') {
                $penyakit = 'Kemungkinan Infeksi Tanaman';
            }

            // Normalisasi keyword: AI kadang mengirim 'FUNGISIDA', 'fungisida',
            // atau 'Fungisida ' (ada spasi). Semuanya dipetakan ke bentuk baku.
            $validKeywords = ['Fungisida', 'Insektisida', 'Herbisida', 'Bakterisida', 'Nematisida'];
            $normalizedKeyword = ucfirst(strtolower(trim($keyword_obat)));
            if (!in_array($normalizedKeyword, $validKeywords, true)) {
                $normalizedKeyword = 'Fungisida';
            }
            $keyword_obat = $normalizedKeyword;

            $tanaman = trim((string)($data_diagnosis['tanaman'] ?? 'Tanaman'));
            $nama_ilmiah = trim((string)($data_diagnosis['nama_ilmiah'] ?? ''));
            $jenis_tanaman = trim((string)($data_diagnosis['jenis_tanaman'] ?? ''));
            $bagian_terkena = trim((string)($data_diagnosis['bagian_terkena'] ?? ''));
            $penyebab = trim((string)($data_diagnosis['penyebab'] ?? ''));
            $solusi = trim((string)($data_diagnosis['solusi'] ?? ''));
            $jenis_pengendali = trim((string)($data_diagnosis['jenis_pengendali'] ?? $keyword_obat));
            $bahan_aktif = trim((string)($data_diagnosis['bahan_aktif'] ?? ''));
            $kata_kunci_produk = $data_diagnosis['kata_kunci_produk'] ?? [];
            if (!is_array($kata_kunci_produk)) $kata_kunci_produk = [$kata_kunci_produk];
            $kata_kunci_produk = array_values(array_filter(array_map('trim', array_map('strval', $kata_kunci_produk))));

            $deskripsi = trim((string)($data_diagnosis['deskripsi'] ?? 'Analisis berdasarkan foto, gejala, dan jawaban pengguna.'));
            if ($solusi === '') $solusi = 'Pisahkan bagian yang terserang, lakukan sanitasi, dan pantau perkembangan gejala.';
            $pencegahan = trim((string)($data_diagnosis['pencegahan'] ?? 'Lakukan sanitasi tanaman dan pantau perkembangan gejala.'));
            $gejala_pendukung = trim((string)($data_diagnosis['gejala_pendukung'] ?? 'Diagnosis dipilih berdasarkan pola gejala pada foto dan jawaban pengguna.'));
            $tingkat_keyakinan = (int)($data_diagnosis['tingkat_keyakinan'] ?? 0);
            // Model diwajibkan mengirim 1-100. Jika respons lama/invalid masih mengirim 0,
            // gunakan nilai aman agar UI tidak menampilkan keyakinan 0% untuk diagnosis yang dipilih.
            if ($tingkat_keyakinan <= 0) {
                $tingkat_keyakinan = 50;
                error_log('tingkat_keyakinan kosong/0; fallback ke 50.', 0);
            }
            $tingkat_keyakinan = max(1, min(100, $tingkat_keyakinan));

            // Jangan gabungkan field lain ke dalam deskripsi.
            // Setiap bagian ditampilkan pada kolomnya sendiri agar tidak berulang.
            // AI diminta membuat deskripsi sebagai ringkasan singkat tentang kondisi tanaman/penyakit saja.
            $deskripsi = preg_replace('/\s+/', ' ', $deskripsi);
            $deskripsi = trim($deskripsi);

            $validKeywords = ['Fungisida', 'Insektisida', 'Herbisida', 'Bakterisida', 'Nematisida'];
            $isValidDiagnosis = in_array($keyword_obat, $validKeywords, true);

            $produk_rekomendasi = [];

            if ($isValidDiagnosis) {
                try {
                    /*
                     * ==========================================================
                     * PENCARIAN PRODUK REKOMENDASI v2 - AI MATCHING
                     * ==========================================================
                     * 1. PHP ambil 20-30 produk kandidat dari DB (pre-filter
                     *    berdasarkan kategori + nama tanaman/penyakit/bahan aktif)
                     *    supaya tidak kirim ratusan produk ke AI (hemat token).
                     * 2. Daftar produk dikirim ke AI bersama hasil diagnosis.
                     *    AI membaca nama + deskripsi tiap produk lalu memilih
                     *    maksimal 6 produk yang PALING RELEVAN untuk tanaman
                     *    dan penyakit hasil diagnosis (bukan cuma cocok
                     *    kategori, tapi benar-benar cocok fungsi produknya).
                     * 3. Jika AI matching gagal/kosong, fallback ke pencarian
                     *    SQL lama agar tampilan tidak kosong.
                     */

                    // ---- STEP 1: PRE-FILTER KANDIDAT DARI DATABASE ----
                    $keywordLower = strtolower(trim($keyword_obat));

                    $kategoriAliases = [
                        'fungisida'   => ['fungisida', 'obat jamur', 'fungi'],
                        'insektisida' => ['insektisida', 'obat hama', 'insect'],
                        'herbisida'   => ['herbisida', 'obat gulma', 'herbicide'],
                        'bakterisida' => ['bakterisida', 'obat bakteri', 'bakteri'],
                        'nematisida'  => ['nematisida', 'obat nematoda', 'nematoda']
                    ];

                    $kategoriTerms = $kategoriAliases[$keywordLower] ?? [$keyword_obat];
                    $kategoriConditions = [];
                    $kategoriParams = [];

                    foreach ($kategoriTerms as $i => $term) {
                        $param = ':kat' . $i;
                        $kategoriConditions[] = "LOWER(COALESCE(kategori, '')) LIKE $param";
                        $kategoriParams[$param] = '%' . strtolower($term) . '%';
                    }

                    // Keyword dari diagnosis untuk mempersempit kandidat.
                    $extraTerms = array_values(array_unique(array_filter([
                        strtolower($tanaman),
                        strtolower($penyakit),
                        strtolower($bahan_aktif),
                        ...array_map('strtolower', $kata_kunci_produk)
                    ], function ($v) {
                        return strlen((string)$v) >= 3;
                    })));

                    $extraConditions = [];
                    $extraParams = [];
                    foreach ($extraTerms as $i => $term) {
                        $param = ':ext' . $i;
                        $extraConditions[] = "(
                            LOWER(COALESCE(nama, '')) LIKE $param
                            OR LOWER(COALESCE(deskripsi, '')) LIKE $param
                        )";
                        $extraParams[$param] = '%' . $term . '%';
                    }

                    $whereParts = [];
                    $allParams = [];

                    if (!empty($kategoriConditions)) {
                        $whereParts[] = '(' . implode(' OR ', $kategoriConditions) . ')';
                        $allParams = array_merge($allParams, $kategoriParams);
                    }
                    if (!empty($extraConditions)) {
                        $whereParts[] = '(' . implode(' OR ', $extraConditions) . ')';
                        $allParams = array_merge($allParams, $extraParams);
                    }

                    $candidates = [];
                    if (!empty($whereParts)) {
                        $sql = "SELECT id, nama, kategori, harga, gambar, deskripsi
                                FROM produk
                                WHERE " . implode(' OR ', $whereParts) . "
                                ORDER BY id DESC
                                LIMIT 30";
                        $stmtCand = $pdo->prepare($sql);
                        $stmtCand->execute($allParams);
                        $candidates = $stmtCand->fetchAll(PDO::FETCH_ASSOC);
                    }

                    error_log('Kandidat produk untuk AI matching: ' . count($candidates), 0);

                    // ---- STEP 2: AI MATCHING ----
                    // AI membaca nama + deskripsi produk, lalu memilih yang
                    // paling relevan dengan hasil diagnosis (tanaman + penyakit).
                    if (count($candidates) > 0) {
                        $productListText = "";
                        foreach ($candidates as $c) {
                            $desc = substr((string)($c['deskripsi'] ?? ''), 0, 250);
                            $productListText .= "[ID: {$c['id']}] Nama: {$c['nama']} | Kategori: {$c['kategori']} | Deskripsi: {$desc}\n";
                        }

                        $kataKunciStr = implode(', ', $kata_kunci_produk);

                        $matchPrompt = "Kamu adalah ahli rekomendasi produk pertanian untuk Etanimart.\n\n"
                            . "DIAGNOSIS:\n"
                            . "- Tanaman: {$tanaman}\n"
                            . "- Penyakit/Gangguan: {$penyakit}\n"
                            . "- Bagian terkena: {$bagian_terkena}\n"
                            . "- Bahan aktif yang direkomendasikan: {$bahan_aktif}\n"
                            . "- Jenis pengendali: {$keyword_obat}\n"
                            . "- Kata kunci produk: {$kataKunciStr}\n\n"
                            . "DAFTAR PRODUK YANG TERSEDIA:\n{$productListText}\n"
                            . "TUGAS:\n"
                            . "Pilih MAKSIMAL 6 produk yang PALING RELEVAN dan TEPAT SASARAN untuk diagnosis di atas.\n"
                            . "Pertimbangkan:\n"
                            . "1. Kategori produk harus sesuai jenis pengendali ({$keyword_obat}).\n"
                            . "2. Produk harus cocok untuk tanaman {$tanaman} dan penyakit {$penyakit}.\n"
                            . "3. Prioritaskan produk yang bahan aktifnya cocok dengan '{$bahan_aktif}'.\n"
                            . "4. Jangan pilih produk yang jelas untuk tanaman lain (contoh: jangan pilih produk untuk 'padi' kalau tanamannya cabai).\n"
                            . "5. Baca nama dan deskripsi produk untuk menentukan produk tersebut sebenarnya untuk tanaman/penyakit apa.\n\n"
                            . "Jawab HANYA dengan array JSON berisi ID produk yang dipilih, contoh: [2, 5, 8].\n"
                            . "Jika tidak ada yang relevan, jawab: []";

                        $matchResult = callOpenAIAPI($matchPrompt, null, null, false);

                        if (!isset($matchResult['error'])) {
                            $matchText = trim($matchResult['choices'][0]['message']['content'] ?? '');
                            preg_match('/\[[\d\s,]*\]/', $matchText, $matches);

                            if (!empty($matches)) {
                                $selectedIds = json_decode($matches[0], true);
                                if (is_array($selectedIds) && count($selectedIds) > 0) {
                                    // Ambil produk sesuai urutan pilihan AI.
                                    $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
                                    $stmtFinal = $pdo->prepare("
                                        SELECT id, nama, kategori, harga, gambar
                                        FROM produk
                                        WHERE id IN ($placeholders)
                                    ");
                                    $stmtFinal->execute(array_map('intval', $selectedIds));
                                    $fetched = $stmtFinal->fetchAll(PDO::FETCH_ASSOC);

                                    $idMap = [];
                                    foreach ($fetched as $row) {
                                        $idMap[$row['id']] = $row;
                                    }

                                    foreach ($selectedIds as $sid) {
                                        $sid = (int)$sid;
                                        if (isset($idMap[$sid])) {
                                            $produk_rekomendasi[] = $idMap[$sid];
                                            if (count($produk_rekomendasi) >= 6) break;
                                        }
                                    }

                                    error_log('AI matching memilih produk ID: ' . implode(',', $selectedIds), 0);
                                }
                            } else {
                                error_log('AI matching tidak mengembalikan array JSON. Respons: ' . substr($matchText, 0, 300), 0);
                            }
                        } else {
                            error_log('AI matching error: ' . $matchResult['error'], 0);
                        }
                    }

                    // ---- STEP 3: FALLBACK SQL (kalau AI matching kosong/gagal) ----
                    if (empty($produk_rekomendasi)) {
                        $kategoriTerms = $kategoriAliases[$keywordLower] ?? [$keyword_obat];
                        $kategoriConditions = [];
                        $kategoriParams = [];

                        foreach ($kategoriTerms as $i => $term) {
                            $param = ':katfb' . $i;
                            $kategoriConditions[] = "LOWER(COALESCE(kategori, '')) LIKE $param";
                            $kategoriParams[$param] = '%' . strtolower($term) . '%';
                        }

                        $stmtKategori = $pdo->prepare("
                            SELECT id, nama, kategori, harga, gambar
                            FROM produk
                            WHERE (" . implode(' OR ', $kategoriConditions) . ")
                            ORDER BY id DESC
                            LIMIT 6
                        ");
                        $stmtKategori->execute($kategoriParams);
                        $produk_rekomendasi = $stmtKategori->fetchAll(PDO::FETCH_ASSOC);

                        if (count($produk_rekomendasi) < 6) {
                            $searchTerms = array_values(array_unique(array_filter(array_merge(
                                $kata_kunci_produk,
                                [$bahan_aktif, $jenis_pengendali, $keyword_obat]
                            ))));

                            $searchTerms = array_slice($searchTerms, 0, 8);
                            $conditions = [];
                            $params = [];

                            foreach ($searchTerms as $i => $term) {
                                $term = trim((string)$term);
                                if ($term === '') continue;

                                $param = ':kwfb' . $i;
                                $conditions[] = "(
                                    LOWER(COALESCE(nama, '')) LIKE $param
                                    OR LOWER(COALESCE(deskripsi, '')) LIKE $param
                                    OR LOWER(COALESCE(kategori, '')) LIKE $param
                                )";
                                $params[$param] = '%' . strtolower($term) . '%';
                            }

                            if (!empty($conditions)) {
                                $existingIds = array_map('intval', array_column($produk_rekomendasi, 'id'));
                                $notInSql = '';

                                if (!empty($existingIds)) {
                                    $idPlaceholders = [];
                                    foreach ($existingIds as $i => $id) {
                                        $ph = ':exfb' . $i;
                                        $idPlaceholders[] = $ph;
                                        $params[$ph] = $id;
                                    }
                                    $notInSql = ' AND id NOT IN (' . implode(',', $idPlaceholders) . ')';
                                }

                                $sisa = 6 - count($produk_rekomendasi);

                                $stmtTambahan = $pdo->prepare("
                                    SELECT id, nama, kategori, harga, gambar
                                    FROM produk
                                    WHERE (" . implode(' OR ', $conditions) . ")
                                    $notInSql
                                    ORDER BY id DESC
                                    LIMIT $sisa
                                ");
                                $stmtTambahan->execute($params);
                                $tambahan = $stmtTambahan->fetchAll(PDO::FETCH_ASSOC);

                                $produk_rekomendasi = array_merge($produk_rekomendasi, $tambahan);
                            }
                        }
                    }

                    error_log(
                        'Produk rekomendasi akhir: keyword=' . $keyword_obat .
                        ' | tanaman=' . $tanaman .
                        ' | jumlah=' . count($produk_rekomendasi),
                        0
                    );

                } catch (PDOException $e) {
                    error_log('DB Error rekomendasi produk: ' . $e->getMessage(), 0);
                } catch (\Throwable $e) {
                    error_log('AI Matching Error: ' . $e->getMessage(), 0);
                }
            }

            unset($_SESSION['context_pertanyaan']);
            unset($_SESSION['foto_scanned']);
            unset($_SESSION['foto_base64']);
            unset($_SESSION['foto_mime']);

            sendJson('success', [
                'diagnosis' => [
                    'tanaman' => $tanaman,
                    'nama_ilmiah' => $nama_ilmiah,
                    'jenis_tanaman' => $jenis_tanaman,
                    'penyakit' => $penyakit,
                    'tingkat_keyakinan' => $tingkat_keyakinan,
                    'bagian_terkena' => $bagian_terkena,
                    'gejala_pendukung' => $gejala_pendukung,
                    'penyebab' => $penyebab,
                    'deskripsi' => $deskripsi,
                    'solusi' => $solusi,
                    'pencegahan' => $pencegahan,
                    'jenis_pengendali' => $jenis_pengendali,
                    'bahan_aktif' => $bahan_aktif,
                    'kata_kunci_produk' => $kata_kunci_produk,
                    'keyword_obat' => $keyword_obat
                ],
                'produk' => $produk_rekomendasi,
                'is_valid' => $isValidDiagnosis
            ]);
        }

        // Action tidak dikenali
        sendJson('error', [], 'Aksi tidak dikenali.');

    } catch (\Throwable $e) {
        error_log('FATAL in scan.php AJAX: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine(), 0);
        sendJson('error', [], 'Terjadi kesalahan pada server. Silakan coba lagi.');
    }
}

// Buang buffer sebelum mulai render halaman HTML normal
// (mencegah whitespace/BOM tak sengaja tercampur ke output halaman)
ob_end_clean();
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

        .nav-link { position: relative; }
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
        .nav-link:hover::after { width: 100%; }

        .btn-primary {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px -10px rgba(16, 185, 129, 0.5);
        }

        .cat-pill { transition: all 0.3s ease; }
        .cat-pill:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px -8px rgba(16, 185, 129, 0.3);
        }

        .reveal {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.8s ease-out;
        }
        .reveal.active { opacity: 1; transform: translateY(0); }

        .scroll-btn { transition: all 0.3s ease; }
        .scroll-btn:hover {
            background: #10b981;
            color: white;
            border-color: #10b981;
        }

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
        .animate-slide-up { animation: slideUp 0.6s ease-out forwards; }

        .loading-pulse { animation: pulse 1.5s ease-in-out infinite; }
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.6; transform: scale(0.98); }
        }

        .drop-active {
            border-color: #10b981 !important;
            background-color: #ecfdf5 !important;
        }

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
        .camera-modal.active { display: flex; }
        .camera-content {
            background: #1a1a2e;
            border-radius: 1.5rem;
            max-width: 640px;
            width: 100%;
            overflow: hidden;
            position: relative;
        }

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

        .ai-info-card {
            border: 1px solid #e5e7eb;
            border-radius: 1rem;
            padding: 1rem;
            background: #f8fafc;
        }
        .ai-info-card h5 {
            font-size: .75rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: .04em;
            margin-bottom: .35rem;
        }
        .ai-info-card p {
            font-size: .875rem;
            line-height: 1.65;
            color: #334155;
            white-space: normal;
        }
        .ai-result-text {
            font-size: .875rem;
            line-height: 1.8;
            color: #334155;
        }
        .ai-result-text .ai-step {
            display: block;
            margin: .35rem 0;
            padding-left: .15rem;
        }
        .ai-result-text .ai-step-number {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 1.55rem;
            height: 1.55rem;
            margin-right: .45rem;
            border-radius: 9999px;
            background: #d1fae5;
            color: #047857;
            font-size: .72rem;
            font-weight: 800;
            vertical-align: middle;
        }
        .questionnaire-photo {
            max-height: 360px;
            object-fit: contain;
            background: #0f172a;
        }

        .product-card {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            background: white;
        }
        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.15);
        }
        .product-card:hover .card-img { transform: scale(1.05); }
        .card-img { transition: transform 0.5s cubic-bezier(0.4, 0, 0.2, 1); }

        .btn-primary {
            background: linear-gradient(135deg, #10b981, #0d9488);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(16, 185, 129, 0.4);
        }

        .user-dropdown { position: relative; }
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
        .user-dropdown-item:hover { background: #f0fdf4; color: #059669; }
        .user-dropdown-item i { width: 20px; text-align: center; color: #10b981; }
        .user-dropdown-divider { height: 1px; background: #e2e8f0; margin: 4px 12px; }

        #mobile-menu {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            transform-origin: top;
        }
        #mobile-menu:not(.hidden) { animation: slideDown 0.3s ease-out; }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        #menuIcon { transition: transform 0.3s ease; }

        @media (max-width: 640px) {
            #dropZone { display: none !important; }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 antialiased overflow-x-hidden">

   <!-- ==================== NAVBAR ==================== -->
<nav class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" id="navbar">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-20 items-center">
            <a href="index.php" class="flex items-center gap-2 font-bold text-2xl text-white">
    <img src="uploads/logo/tani.png"
         alt="Etanimart"
         class="logo-img"
         style="height: 70px; width: auto; object-fit: contain;"
         onerror="this.style.display='none'">
</a>

            <div class="hidden lg:flex items-center space-x-8 font-medium">
                <a href="index.php" class="nav-link <?= $currentPage === 'index' ? 'text-emerald-600' : 'text-gray-600' ?> hover:text-emerald-600 transition-colors">Beranda</a>
                <a href="scan.php" class="nav-link <?= $currentPage === 'scan' ? 'text-emerald-600' : 'text-gray-600' ?> hover:text-emerald-600 transition-colors flex items-center gap-1.5">
                    <i class="fa-solid fa-qrcode <?= $currentPage === 'scan' ? 'text-emerald-600' : 'text-emerald-500' ?>"></i> Scan AI
                </a>
                <a href="produk.php" class="nav-link <?= $currentPage === 'produk' ? 'text-emerald-600' : 'text-gray-600' ?> hover:text-emerald-600 transition-colors">Katalog</a>
                <a href="index.php#tentang" class="nav-link text-gray-600 hover:text-emerald-600 transition-colors">Tentang</a>
            </div>

            <div class="flex items-center gap-2 sm:gap-4">
                <?php if (!$isLoggedIn): ?>
                    <div class="hidden sm:flex items-center gap-3">
                        <a href="login.php" class="px-5 py-2.5 text-emerald-600 hover:text-emerald-700 font-semibold transition-colors rounded-xl hover:bg-emerald-50">
                            Masuk
                        </a>
                        <a href="register.php" class="btn-primary text-white px-5 py-2.5 rounded-xl font-semibold shadow-lg text-sm">
                            Daftar
                        </a>
                    </div>
                <?php else: ?>
                    <a href="keranjang.php" class="hidden lg:flex relative p-2.5 text-gray-600 hover:text-emerald-600 transition-colors rounded-xl hover:bg-emerald-50">
                        <i class="fa-solid fa-cart-shopping text-lg"></i>
                        <span class="absolute -top-1 -right-1 w-5 h-5 bg-emerald-500 text-white text-[10px] font-bold rounded-full flex items-center justify-center <?= $totalKeranjang > 0 ? '' : 'hidden' ?>"><?= $totalKeranjang ?></span>
                    </a>

                    <div class="user-dropdown relative hidden lg:block">
                        <button class="flex items-center gap-2 sm:gap-3 pl-2 pr-1 sm:pr-2 py-1.5 rounded-full hover:bg-gray-100 transition-colors" onclick="toggleUserDropdown(event)">
                            <img src="<?= getProfilePic($userFoto) ?>" alt="<?= clean($userName) ?>" class="w-9 h-9 rounded-full object-cover border-2 border-emerald-200">
                            <div class="hidden sm:flex flex-col items-start">
                                <span class="text-sm font-bold text-gray-800 max-w-[100px] truncate leading-tight"><?= clean($userName) ?></span>
                                <span class="text-[10px] text-gray-400 font-medium leading-tight">Pembeli</span>
                            </div>
                            <i class="fa-solid fa-chevron-down text-xs text-gray-400 mr-1 transition-transform duration-200" id="userDropdownIcon"></i>
                        </button>

                        <div class="user-dropdown-menu" id="userDropdownMenu">
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

                <button id="btn-menu" class="lg:hidden p-2.5 text-gray-600 hover:text-emerald-600 hover:bg-emerald-50 transition-all rounded-xl">
                    <i class="fa-solid fa-bars text-xl" id="menuIcon"></i>
                </button>
            </div>
        </div>
    </div>

    <div id="mobile-menu" class="hidden lg:hidden bg-white/95 backdrop-blur-xl border-t border-gray-100 shadow-xl max-h-[85vh] overflow-y-auto">
        <div class="max-w-7xl mx-auto px-4 py-4 space-y-1">

            <?php if ($isLoggedIn): ?>
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
                <span class="inline-flex items-center  gap-1.5 px-3 py-1 bg-white/10 backdrop-blur rounded-full text-xs font-medium"></span>
            </div>
            <h1 class="text-3xl md:text-4xl font-bold mb-3">Diagnosis Dokter Tanaman AI</h1>
            <p class="text-black-100 text-sm md:text-base max-w-xl">
                Ambil foto gejala pada daun atau batang tanaman, AI akan menganalisis dan memberikan rekomendasi obat tepat sasaran.
            </p>
            <a href="Panduan_Penggunaan_eTaniMart.pdf" download
               class="mt-4 inline-flex items-center gap-2 bg-emerald-600 hover:bg-emerald-700 text-white text-sm font-semibold px-5 py-2.5 rounded-xl shadow-lg shadow-emerald-200 transition-all hover:-translate-y-0.5">
                <i class="fa-solid fa-file-arrow-down"></i> Download Panduan (PDF)
            </a>
        </div>
    </div>

    <!-- ==================== MAIN CONTENT ==================== -->
    <main class="py-8 md:py-12">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">

            <!-- ===== PANEL 1: UPLOAD FOTO ===== -->
            <div id="panelUpload" class="bg-white rounded-2xl border border-gray-100 p-6 md:p-8 shadow-sm space-y-6 fade-in">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <button onclick="openCamera()" class="group relative bg-gradient-to-br from-emerald-50 to-teal-50 border-2 border-emerald-200 hover:border-emerald-400 rounded-2xl p-6 text-center transition-all hover:shadow-lg hover:-translate-y-1">
                        <div class="w-14 h-14 bg-emerald-100 group-hover:bg-emerald-600 rounded-2xl flex items-center justify-center mx-auto mb-3 transition-all">
                            <i class="fa-solid fa-camera text-2xl text-emerald-600 group-hover:text-white transition-colors"></i>
                        </div>
                        <h3 class="font-bold text-gray-900 text-sm">Ambil Foto Kamera</h3>
                        <p class="text-xs text-gray-500 mt-1">Langsung dari kamera perangkat</p>
                    </button>

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

                <div class="flex items-center gap-4">
                    <div class="flex-1 h-px bg-gray-200"></div>
                    <span class="text-xs text-gray-400 font-medium">atau drop file di sini</span>
                    <div class="flex-1 h-px bg-gray-200"></div>
                </div>

                <div id="dropZone" class="relative border-2 border-dashed border-gray-300 hover:border-emerald-400 bg-gray-50 hover:bg-emerald-50/50 rounded-2xl p-8 transition-all text-center h-48 flex flex-col items-center justify-center group cursor-pointer"
                     onclick="document.getElementById('foto_tanaman').click()">
                    <div id="loadingStatus" class="space-y-3 pointer-events-none">
                        <i class="fa-solid fa-images text-4xl text-gray-300 group-hover:text-emerald-400 transition-colors"></i>
                        <p class="font-semibold text-gray-600 text-sm">Drop foto di sini</p>
                        <p class="text-xs text-gray-400">JPG, PNG, WEBP (Maks. 5MB)</p>
                    </div>
                </div>

                <div id="previewContainer" class="hidden rounded-2xl overflow-hidden border border-gray-200 shadow-sm relative">
                    <img id="previewImage" src="" alt="Preview" class="w-full h-64 object-cover">
                    <button onclick="resetScan()" class="absolute top-3 right-3 w-8 h-8 bg-white/90 backdrop-blur rounded-full flex items-center justify-center text-gray-600 hover:text-red-500 transition-colors shadow-sm">
                        <i class="fa-solid fa-times text-sm"></i>
                    </button>
                </div>

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

                <div id="kuesionerFotoCard" class="rounded-2xl overflow-hidden border border-gray-200 bg-gray-50 shadow-sm">
                    <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between bg-white">
                        <div>
                            <h4 class="text-sm font-bold text-gray-800 flex items-center gap-2">
                                <i class="fa-solid fa-image text-emerald-600"></i> Foto yang Dianalisis
                            </h4>
                            <p class="text-xs text-gray-500 mt-0.5">Gunakan foto ini sebagai acuan saat menjawab pertanyaan.</p>
                        </div>
                        <span class="text-[10px] font-semibold text-emerald-700 bg-emerald-50 px-2 py-1 rounded-full">AI Vision</span>
                    </div>
                    <div class="p-3 bg-gray-900">
                        <img id="kuesionerFoto" src="" alt="Foto tanaman yang dianalisis" class="questionnaire-photo w-full rounded-xl">
                    </div>
                </div>

                <div id="hasilPengamatanAwal" class="hidden bg-emerald-50 border border-emerald-100 rounded-2xl p-4">
                    <div class="flex items-start gap-3">
                        <div class="w-9 h-9 bg-emerald-100 rounded-xl flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-magnifying-glass text-emerald-600"></i>
                        </div>
                        <div class="min-w-0">
                            <h4 class="text-sm font-bold text-emerald-900">Pengamatan Awal AI</h4>
                            <p id="txtPengamatanTanaman" class="text-xs text-emerald-800 mt-1"></p>
                            <p id="txtPengamatanGejala" class="text-xs text-emerald-800 mt-1 leading-relaxed"></p>
                        </div>
                    </div>
                </div>

                <form id="formKuesionerAI" onsubmit="eksekusiDiagnosisFinal(event)" class="space-y-6">
                    <div id="boxPertanyaanDinamis" class="space-y-4"></div>

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
                <div class="bg-gray-900 text-white p-6 rounded-2xl border border-gray-800 shadow-lg relative overflow-hidden">
                    <div class="absolute top-0 right-0 w-32 h-32 bg-emerald-500/10 rounded-full -translate-y-1/2 translate-x-1/2"></div>
                    <div class="relative z-10">
                        <div class="flex items-center gap-2 mb-4">
                            <span class="text-xs font-bold tracking-widest text-emerald-400 uppercase bg-emerald-950/50 px-3 py-1 rounded-lg border border-emerald-800">
                                <i class="fa-solid fa-stethoscope mr-1"></i> Hasil Analisis AI
                            </span>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 id="txtNamaPenyakit" class="text-2xl font-bold text-white">Memuat...</h3>
                            <span id="txtTanaman" class="hidden text-xs font-semibold bg-white/10 text-emerald-300 px-3 py-1 rounded-full border border-white/10"></span>
                            <span id="txtKeyakinan" class="hidden text-xs font-semibold bg-emerald-500/10 text-emerald-300 px-3 py-1 rounded-full border border-emerald-500/20"></span>
                        </div>
                        <div class="h-px bg-gray-700 my-4"></div>
                        <p id="txtGejalaPendukung" class="hidden text-sm text-gray-300 leading-relaxed mb-3"></p>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div id="cardIdentitasTanaman" class="ai-info-card">
                        <h5><i class="fa-solid fa-seedling mr-1 text-emerald-600"></i> Identifikasi Tanaman</h5>
                        <p id="txtIdentitasTanaman">-</p>
                    </div>
                    <div class="ai-info-card">
                        <h5><i class="fa-solid fa-location-dot mr-1 text-emerald-600"></i> Bagian Terkena</h5>
                        <p id="txtBagianTerkena">-</p>
                    </div>
                    <div class="ai-info-card md:col-span-2">
                        <h5><i class="fa-solid fa-circle-info mr-1 text-emerald-600"></i> Deskripsi</h5>
                        <div id="txtDeskripsiSolusi" class="ai-result-text">Sedang menganalisis...</div>
                    </div>
                    <div class="ai-info-card md:col-span-2">
                        <h5><i class="fa-solid fa-virus mr-1 text-emerald-600"></i> Penyebab yang Mungkin</h5>
                        <div id="txtPenyebab" class="ai-result-text">-</div>
                    </div>
                    <div class="ai-info-card md:col-span-2">
                        <h5><i class="fa-solid fa-screwdriver-wrench mr-1 text-emerald-600"></i> Solusi Penanganan</h5>
                        <div id="txtSolusi" class="ai-result-text">-</div>
                    </div>
                    <div class="ai-info-card md:col-span-2">
                        <h5><i class="fa-solid fa-shield-heart mr-1 text-emerald-600"></i> Pencegahan</h5>
                        <div id="txtPencegahan" class="ai-result-text">-</div>
                    </div>
                </div>

                <div class="space-y-4">
                    <h4 class="max-w-3xl mx-auto text-sm font-bold text-gray-800 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-prescription-bottle-medical text-emerald-600"></i>
                        Obat & Solusi Tersedia di Etanimart
                    </h4>
                    <div id="boxKatalogProduk" class="max-w-3xl mx-auto grid grid-cols-1 sm:grid-cols-2 gap-3.5"></div>

                    <div id="noProdukMessage" class="hidden bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
                        <i class="fa-solid fa-triangle-exclamation text-yellow-500 text-2xl mb-2"></i>
                        <p class="text-sm text-yellow-700">
                            Produk untuk penanganan ini belum tersedia di katalog Etanimart. Solusi dari AI tetap tersedia sebagai panduan penanganan.
                        </p>
                    </div>
                </div>

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
            <div class="p-4 border-b border-gray-800 flex items-center justify-between">
                <h3 class="font-bold text-white text-sm flex items-center gap-2">
                    <i class="fa-solid fa-camera text-emerald-400"></i>
                    Ambil Foto
                </h3>
                <button onclick="closeCamera()" class="w-8 h-8 bg-gray-800 hover:bg-gray-700 rounded-lg flex items-center justify-center text-gray-400 hover:text-white transition-colors">
                    <i class="fa-solid fa-times text-sm"></i>
                </button>
            </div>

            <div class="relative bg-black aspect-[3/4] sm:aspect-video">
                <video id="cameraVideo" autoplay playsinline class="w-full h-full object-cover"></video>

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

    <canvas id="photoCanvas" class="hidden"></canvas>

    <!-- ==================== FOOTER ==================== -->
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
        let isProcessing = false;
        let currentStream = null;
        let facingMode = 'environment';

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
            const kFoto = document.getElementById('kuesionerFoto');
            if (kFoto) kFoto.src = '';
            const pengamatan = document.getElementById('hasilPengamatanAwal');
            if (pengamatan) pengamatan.classList.add('hidden');
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

        // ===== Helper: parsing respons fetch dengan aman =====
        // Kalau server ternyata mengembalikan HTML (mis. error 500 dari hosting),
        // ini akan menampilkan pesan yang jelas alih-alih "Unexpected token '<'".
        async function parseJsonSafe(res) {
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Respons bukan JSON valid:', text.substring(0, 300));
                throw new Error('Server mengembalikan respons tidak valid (status ' + res.status + '). Coba lagi dalam beberapa saat.');
            }
        }

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
                alert('Tidak dapat mengakses kamera. Pastikan izin kamera sudah diberikan.');
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
                facingMode = facingMode === 'environment' ? 'user' : 'environment';
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
            fetch("scan.php?action=scan_foto", { method: "POST", body: formData })
            .then(async res => {
                const data = await parseJsonSafe(res);
                isProcessing = false;
                if (data.status === 'success' && data.pertanyaan_ai && data.pertanyaan_ai.length > 0) {
                    const fotoSrc = document.getElementById('previewImage').src;
                    const kFoto = document.getElementById('kuesionerFoto');
                    if (kFoto && fotoSrc) kFoto.src = fotoSrc;

                    const pengamatan = document.getElementById('hasilPengamatanAwal');
                    const txtTanamanAwal = document.getElementById('txtPengamatanTanaman');
                    const txtGejalaAwal = document.getElementById('txtPengamatanGejala');
                    if (data.tanaman_terdeteksi || data.gejala_terlihat) {
                        txtTanamanAwal.textContent = data.tanaman_terdeteksi ? 'Tanaman terdeteksi: ' + data.tanaman_terdeteksi : '';
                        txtGejalaAwal.textContent = data.gejala_terlihat ? 'Gejala yang terlihat: ' + data.gejala_terlihat : '';
                        pengamatan.classList.remove('hidden');
                    }

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
                if (!q.key || !q.teks_pertanyaan || !Array.isArray(q.opsi)) return;
                let templateOpsi = '';
                q.opsi.forEach((opt, optIdx) => {
                    const optId = `opt_${idx}_${optIdx}`;
                    templateOpsi += `
                        <label class="border p-3 rounded-xl bg-white flex items-center gap-3 cursor-pointer border-gray-200 hover:border-emerald-400 hover:bg-emerald-50 transition-all text-sm font-medium group">
                            <input type="radio" name="jawaban[${q.key}]" value="${opt}" required class="accent-emerald-600 w-4 h-4 cursor-pointer" id="${optId}">
                            <span class="group-hover:text-emerald-700">${opt}</span>
                        </label>
                    `;
                });
                const html = `
                    <div class="space-y-3 bg-gray-50 p-5 rounded-2xl border border-gray-100">
                        <div class="flex items-start gap-3">
                            <span class="bg-emerald-100 text-emerald-700 text-xs font-bold px-3 py-1 rounded-full shrink-0 mt-0.5">${idx + 1}</span>
                            <label class="block text-sm font-semibold text-gray-800 leading-relaxed">${q.teks_pertanyaan}</label>
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
            fetch("scan.php?action=hitung_diagnosis", { method: "POST", body: formData })
            .then(async res => {
                const data = await parseJsonSafe(res);
                isProcessing = false;
                btn.disabled = false;
                btn.innerHTML = `<span>Kirim Jawaban & Lihat Obat</span><i class="fa-solid fa-arrow-right"></i>`;
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
                btn.innerHTML = `<span>Kirim Jawaban & Lihat Obat</span><i class="fa-solid fa-arrow-right"></i>`;
                showError('Error: ' + err.message);
                console.error('Error:', err);
            });
        }

        function renderHasil(data) {
            // ==========================================
            // HASIL ANALISIS AI SELALU DITAMPILKAN
            // Produk adalah tambahan, bukan syarat diagnosis.
            // ==========================================
            const diagnosis = data.diagnosis || {};
            const namaPenyakit = diagnosis.penyakit || 'Hasil Analisis Tanaman';
            const deskripsi = diagnosis.deskripsi || 'Solusi penanganan tersedia berdasarkan hasil analisis AI.';

            document.getElementById('txtNamaPenyakit').textContent = namaPenyakit;
            document.getElementById('txtDeskripsiSolusi').innerHTML = formatAIText(deskripsi);

            const tanamanEl = document.getElementById('txtTanaman');
            const keyakinanEl = document.getElementById('txtKeyakinan');
            const gejalaEl = document.getElementById('txtGejalaPendukung');

            if (diagnosis.tanaman) {
                tanamanEl.textContent = '🌿 ' + diagnosis.tanaman;
                tanamanEl.classList.remove('hidden');
            } else {
                tanamanEl.classList.add('hidden');
            }

            const keyakinan = Math.max(1, Math.min(100, Number(diagnosis.tingkat_keyakinan) || 1));
            keyakinanEl.textContent = 'Keyakinan AI: ' + keyakinan + '%';
            keyakinanEl.classList.remove('hidden');

            if (diagnosis.gejala_pendukung) {
                gejalaEl.innerHTML = '<strong class="text-emerald-300">Gejala yang mendukung:</strong><br>' + formatAIText(diagnosis.gejala_pendukung);
                gejalaEl.classList.remove('hidden');
            } else {
                gejalaEl.classList.add('hidden');
            }

            document.getElementById('txtIdentitasTanaman').innerHTML = formatAIText(
                [diagnosis.tanaman, diagnosis.nama_ilmiah ? '(' + diagnosis.nama_ilmiah + ')' : '', diagnosis.jenis_tanaman ? '• ' + diagnosis.jenis_tanaman : ''].filter(Boolean).join(' ') || '-'
            );
            document.getElementById('txtBagianTerkena').innerHTML = formatAIText(diagnosis.bagian_terkena || '-');
            document.getElementById('txtPenyebab').innerHTML = formatAIText(diagnosis.penyebab || '-');
            document.getElementById('txtSolusi').innerHTML = formatAIText(diagnosis.solusi || '-');
            document.getElementById('txtPencegahan').innerHTML = formatAIText(diagnosis.pencegahan || '-');

            const boxKatalog = document.getElementById('boxKatalogProduk');
            const noProdukMsg = document.getElementById('noProdukMessage');

            boxKatalog.innerHTML = '';
            noProdukMsg.classList.add('hidden');

            // ==========================================
            // PRODUK ETANIMART
            // ==========================================
            if (Array.isArray(data.produk) && data.produk.length > 0) {
                data.produk.forEach(p => {
                    let listGambar = [];

                    try {
                        listGambar = JSON.parse(p.gambar || '[]');
                    } catch(e) {
                        if (p.gambar && p.gambar.includes(',')) {
                            listGambar = p.gambar.split(',').map(s => s.trim());
                        } else if (p.gambar) {
                            listGambar = [p.gambar];
                        }
                    }

                    let coverFoto = 'https://placehold.co/150?text=No+Image';
                    if (Array.isArray(listGambar) && listGambar.length > 0 && listGambar[0]) {
                        coverFoto = `uploads/${listGambar[0]}`;
                    }

                    const hargaFormatted = parseInt(p.harga || 0).toLocaleString('id-ID');

                    const produkHTML = `
                        <div class="product-card bg-white border border-gray-100 p-3.5 rounded-2xl flex gap-4 items-center shadow-sm hover:shadow-md transition-all group">
                            <div class="w-16 h-16 rounded-xl overflow-hidden bg-gray-50 border border-gray-100 shrink-0">
                                <img src="${escapeHtml(coverFoto)}"
                                     onerror="this.src='https://placehold.co/150?text=No+Image'"
                                     class="card-img w-full h-full object-cover"
                                     alt="${escapeHtml(p.nama || 'Produk Etanimart')}">
                            </div>
                            <div class="flex-grow min-w-0">
                                <span class="text-[10px] bg-emerald-50 text-emerald-700 font-bold px-2 py-0.5 rounded-sm uppercase tracking-wide">${escapeHtml(p.kategori || 'Umum')}</span>
                                <h5 class="text-xs font-bold text-gray-900 mt-1 truncate">${escapeHtml(p.nama || 'Produk')}</h5>
                                <p class="text-xs font-semibold text-emerald-600 mt-0.5">Rp ${hargaFormatted}</p>
                            </div>
                            <a href="detail_produk.php?id=${encodeURIComponent(p.id)}"
                               class="p-2.5 bg-gray-50 hover:bg-emerald-50 text-gray-400 hover:text-emerald-600 rounded-xl transition-all shrink-0">
                                <i class="fa-solid fa-chevron-right text-xs"></i>
                            </a>
                        </div>
                    `;

                    boxKatalog.insertAdjacentHTML('beforeend', produkHTML);
                });
            } else {
                // ==========================================
                // TIDAK ADA PRODUK -> SOLUSI AI TETAP ADA
                // ==========================================
                noProdukMsg.classList.remove('hidden');
                noProdukMsg.innerHTML = `
                    <div class="flex items-start gap-3 text-left">
                        <div class="w-10 h-10 rounded-xl bg-amber-100 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-store-slash text-amber-600"></i>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-gray-800">
                                Produk terkait belum tersedia di Etanimart
                            </p>
                            <p class="text-xs text-gray-600 mt-1 leading-relaxed">
                                Saat ini belum ada produk yang sesuai dengan rekomendasi AI di katalog Etanimart.
                            </p>
                            <p class="text-xs text-emerald-700 mt-2 font-medium">
                                <i class="fa-solid fa-circle-check mr-1"></i>
                                Solusi dan penanganan dari AI tetap tersedia sebagai panduan.
                            </p>
                        </div>
                    </div>
                `;
            }

            setTimeout(() => {
                document.getElementById('panelHasil').scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }

        function formatAIText(value) {
            const text = value == null ? '' : String(value).trim();
            if (!text) return '-';

            // Normalisasi daftar agar langkah 1, 2, 3 selalu turun ke baris berikutnya,
            // termasuk jika AI mengirim semuanya dalam satu paragraf.
            const normalized = text
                .replace(/\r\n/g, '\n')
                .replace(/\r/g, '\n')
                .replace(/\s+(?=(?:\d+)[.)]\s)/g, '\n')
                .replace(/\s+(?=[•\-*]\s)/g, '\n');

            return normalized.split('\n').map(line => {
                const trimmed = line.trim();
                if (!trimmed) return '';

                const numbered = trimmed.match(/^(\d+)[.)]\s*(.*)$/);
                if (numbered) {
                    return `<div class="ai-step"><span class="ai-step-number">${escapeHtml(numbered[1])}</span>${escapeHtml(numbered[2])}</div>`;
                }

                const bullet = trimmed.match(/^[•\-*]\s*(.*)$/);
                if (bullet) {
                    return `<div class="ai-step">• ${escapeHtml(bullet[1])}</div>`;
                }

                return `<div class="mb-2 last:mb-0">${escapeHtml(trimmed)}</div>`;
            }).join('');
        }

        function escapeHtml(value) {
            const div = document.createElement('div');
            div.textContent = value == null ? '' : String(value);
            return div.innerHTML;
        }

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

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeCamera();
                mobileMenu.classList.add('hidden');
            }
        });

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
            link.addEventListener('click', () => {
                closeMobileMenu();
            });
        });
        document.addEventListener('click', (e) => {
            if (!mobileMenu.contains(e.target) && !btnMenu.contains(e.target)) {
                closeMobileMenu();
            }
        });

        const navbar = document.getElementById('navbar');
        window.addEventListener('scroll', () => {
            if (window.scrollY > 50) {
                navbar.classList.add('bg-white/95', 'backdrop-blur-xl', 'shadow-sm');
            } else {
                navbar.classList.remove('bg-white/95', 'backdrop-blur-xl', 'shadow-sm');
            }
        });

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
                closeCamera();
            }
        });
    </script>
</body>
</html>