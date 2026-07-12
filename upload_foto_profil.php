<?php
session_start();
require_once 'koneksi.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'pembeli') {
    header("Location: login.php");
    exit();
}

$userId = (int)$_SESSION['user_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['foto_profil'])) {
    $file = $_FILES['foto_profil'];
    
    $allowed = ['image/jpeg', 'image/png', 'image/gif'];
    $maxSize = 2 * 1024 * 1024;
    
    if (!in_array($file['type'], $allowed)) {
        $_SESSION['flash'] = ['msg' => 'Format file tidak didukung. Gunakan JPG, PNG, atau GIF.', 'type' => 'error'];
    } elseif ($file['size'] > $maxSize) {
        $_SESSION['flash'] = ['msg' => 'Ukuran file terlalu besar. Maksimal 2MB.', 'type' => 'error'];
    } elseif ($file['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['flash'] = ['msg' => 'Terjadi kesalahan saat upload.', 'type' => 'error'];
    } else {
        $uploadDir = 'uploads/profil/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // Hapus foto lama
        $stmt = $pdo->prepare("SELECT foto_profil FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $old = $stmt->fetchColumn();
        if ($old && file_exists($uploadDir . $old)) {
            unlink($uploadDir . $old);
        }
        
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'user_' . $userId . '_' . time() . '.' . $ext;
        $target = $uploadDir . $filename;
        
        if (move_uploaded_file($file['tmp_name'], $target)) {
            $stmt = $pdo->prepare("UPDATE users SET foto_profil = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$filename, $userId]);
            $_SESSION['flash'] = ['msg' => 'Foto profil berhasil diperbarui!', 'type' => 'success'];
        } else {
            $_SESSION['flash'] = ['msg' => 'Gagal menyimpan file.', 'type' => 'error'];
        }
    }
}

header("Location: profil.php");
exit();