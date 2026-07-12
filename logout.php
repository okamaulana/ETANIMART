<?php
// 1. Inisialisasi atau hubungkan ke sesi yang sedang berjalan
session_start();

// 2. Kosongkan semua data yang tersimpan di dalam array $_SESSION
$_SESSION = array();

// 3. Hancurkan cookie sesi di browser jika ada (opsi opsional untuk keamanan ekstra)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), 
        '', 
        time() - 42000,
        $params["path"], 
        $params["domain"],
        $params["secure"], 
        $params["httponly"]
    );
}

// 4. Hancurkan data sesi di server secara total
session_destroy();

// 5. Alihkan pengguna kembali ke halaman login utama
header("Location: index.php");
exit();
?>