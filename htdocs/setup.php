<?php
require_once __DIR__ . '/includes/db.php';

$sql = file_get_contents(__DIR__ . '/schema.sql');
foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
    $pdo->exec($stmt);
}

$count = $pdo->query("SELECT COUNT(*) c FROM users")->fetch()['c'];
if ($count == 0) {
    $hash = password_hash('0921', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, display_name) VALUES (?,?,?)");
    $stmt->execute(['omg', $hash, 'Ozi']);
    $stmt->execute(['cyd', $hash, 'Ceyda']);
    echo "<p>Kurulum tamamlandı: <b>omg</b> ve <b>cyd</b> kullanıcıları oluşturuldu (şifre: 0921).</p>";
} else {
    echo "<p>Kullanıcılar zaten mevcut, tekrar oluşturulmadı.</p>";
}
echo "<p><b>Önemli:</b> Bu dosyayı (setup.php) şimdi sil.</p>";
