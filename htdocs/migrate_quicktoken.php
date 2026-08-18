<?php
require_once __DIR__ . '/includes/db.php';

function col_exists_qt($pdo, $table, $col) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return $stmt->fetch()['c'] > 0;
}

if (!col_exists_qt($pdo, 'users', 'quick_token')) {
    $pdo->exec("ALTER TABLE users ADD COLUMN quick_token VARCHAR(40) NULL UNIQUE");
    echo "<p>✅ users.quick_token eklendi.</p>";
} else {
    echo "<p>— users.quick_token zaten vardı.</p>";
}

$users = $pdo->query("SELECT * FROM users")->fetchAll();
foreach ($users as $u) {
    if (empty($u['quick_token'])) {
        $token = bin2hex(random_bytes(20));
        $stmt = $pdo->prepare("UPDATE users SET quick_token=? WHERE id=?");
        $stmt->execute([$token, $u['id']]);
        $u['quick_token'] = $token;
    }
    echo "<p style='font-family:monospace;'><b>" . htmlspecialchars($u['display_name']) . "</b> (" . htmlspecialchars($u['username']) . ") token:<br>"
        . "<span style='background:#eee; padding:3px 8px; display:inline-block; margin-top:4px;'>" . htmlspecialchars($u['quick_token']) . "</span></p>";
}

echo "<p><b>Önemli:</b> Bu tokenleri not al ya da ekran görüntüsü al — her biri sadece ilgili kişinin kendi iPhone Kısayoluna girilecek, kimseyle paylaşma. Sonra bu dosyayı (migrate_quicktoken.php) sil.</p>";
