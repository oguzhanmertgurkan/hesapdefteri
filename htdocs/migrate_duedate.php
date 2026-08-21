<?php
require_once __DIR__ . '/includes/db.php';

function col_exists_dd($pdo, $table, $col) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return $stmt->fetch()['c'] > 0;
}

if (!col_exists_dd($pdo, 'credit_cards', 'due_day')) {
    $pdo->exec("ALTER TABLE credit_cards ADD COLUMN due_day TINYINT NULL AFTER cutoff_day");
    echo "<p>✅ credit_cards.due_day eklendi.</p>";
} else {
    echo "<p>— credit_cards.due_day zaten vardı.</p>";
}

echo "<p><b>Bitti.</b> Bu dosyayı (migrate_duedate.php) şimdi sil, sonra <a href='expenses.php'>Giderler sayfasına git</a> ve kartlarının son ödeme gününü de gir.</p>";
