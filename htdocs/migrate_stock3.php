<?php
require_once __DIR__ . '/includes/db.php';

function column_exists3($pdo, $table, $col) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return $stmt->fetch()['c'] > 0;
}

if (!column_exists3($pdo, 'investment_positions', 'exchange')) {
    $pdo->exec("ALTER TABLE investment_positions ADD COLUMN exchange VARCHAR(10) NOT NULL DEFAULT 'BIST' AFTER ticker");
    echo "<p>exchange sütunu eklendi (mevcut hisseler otomatik olarak BIST kabul edildi).</p>";
} else {
    echo "<p>exchange sütunu zaten var.</p>";
}

$pdo->exec("ALTER TABLE price_cache MODIFY ticker VARCHAR(30) NOT NULL");
echo "<p>price_cache tablosu genişletildi.</p>";

echo "<p><b>Bitti.</b> Bu dosyayı (migrate_stock3.php) şimdi sil, sonra <a href='investments.php'>Birikim &amp; Yatırım sayfasına git</a>.</p>";
