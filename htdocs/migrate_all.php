<?php
require_once __DIR__ . '/includes/db.php';

function col_exists($pdo, $table, $col) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return $stmt->fetch()['c'] > 0;
}

function table_exists($pdo, $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return $stmt->fetch()['c'] > 0;
}

echo "<h3>Geçiş kontrol ediliyor...</h3><ul>";

try {
    if (!col_exists($pdo, 'investment_entries', 'quantity')) {
        $pdo->exec("ALTER TABLE investment_entries ADD COLUMN quantity DECIMAL(14,4) NULL AFTER amount");
        echo "<li>✅ investment_entries.quantity eklendi.</li>";
    } else {
        echo "<li>— investment_entries.quantity zaten vardı.</li>";
    }

    if (!col_exists($pdo, 'investment_entries', 'price_per_unit')) {
        $pdo->exec("ALTER TABLE investment_entries ADD COLUMN price_per_unit DECIMAL(14,4) NULL AFTER quantity");
        echo "<li>✅ investment_entries.price_per_unit eklendi.</li>";
    } else {
        echo "<li>— investment_entries.price_per_unit zaten vardı.</li>";
    }

    if (!col_exists($pdo, 'investment_positions', 'ticker')) {
        $pdo->exec("ALTER TABLE investment_positions ADD COLUMN ticker VARCHAR(20) NULL AFTER type");
        echo "<li>✅ investment_positions.ticker eklendi.</li>";
    } else {
        echo "<li>— investment_positions.ticker zaten vardı.</li>";
    }

    if (!col_exists($pdo, 'investment_positions', 'exchange')) {
        $pdo->exec("ALTER TABLE investment_positions ADD COLUMN exchange VARCHAR(10) NOT NULL DEFAULT 'BIST' AFTER ticker");
        echo "<li>✅ investment_positions.exchange eklendi.</li>";
    } else {
        echo "<li>— investment_positions.exchange zaten vardı.</li>";
    }

    if (!table_exists($pdo, 'price_cache')) {
        $pdo->exec("CREATE TABLE price_cache (
            ticker VARCHAR(30) PRIMARY KEY,
            price DECIMAL(14,4) NOT NULL,
            fetched_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "<li>✅ price_cache tablosu oluşturuldu.</li>";
    } else {
        $pdo->exec("ALTER TABLE price_cache MODIFY ticker VARCHAR(30) NOT NULL");
        echo "<li>— price_cache zaten vardı (sütun genişliği kontrol edildi).</li>";
    }

    echo "</ul><p><b>Tüm geçişler tamamlandı.</b> Bu dosyayı (migrate_all.php) şimdi sil, sonra <a href='investments.php'>Birikim &amp; Yatırım sayfasına git</a>.</p>";
} catch (PDOException $e) {
    echo "</ul><p style='color:red;'><b>Hata:</b> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Bu ekran görüntüsünü Claude'a gönder.</p>";
}
