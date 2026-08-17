<?php
require_once __DIR__ . '/includes/db.php';

function column_exists($pdo, $table, $col) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return $stmt->fetch()['c'] > 0;
}

if (!column_exists($pdo, 'investment_entries', 'quantity')) {
    $pdo->exec("ALTER TABLE investment_entries ADD COLUMN quantity DECIMAL(14,4) NULL AFTER amount");
    echo "<p>quantity sütunu eklendi.</p>";
} else {
    echo "<p>quantity sütunu zaten var.</p>";
}

if (!column_exists($pdo, 'investment_entries', 'price_per_unit')) {
    $pdo->exec("ALTER TABLE investment_entries ADD COLUMN price_per_unit DECIMAL(14,4) NULL AFTER quantity");
    echo "<p>price_per_unit sütunu eklendi.</p>";
} else {
    echo "<p>price_per_unit sütunu zaten var.</p>";
}

echo "<p><b>Bitti.</b> Bu dosyayı (migrate_stock.php) şimdi sil, sonra <a href='investments.php'>Birikim &amp; Yatırım sayfasına git</a>.</p>";
