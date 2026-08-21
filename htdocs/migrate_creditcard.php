<?php
require_once __DIR__ . '/includes/db.php';

function col_exists_cc($pdo, $table, $col) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return $stmt->fetch()['c'] > 0;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS credit_cards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person VARCHAR(50) NOT NULL,
  name VARCHAR(100) NOT NULL,
  cutoff_day TINYINT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "<p>✅ credit_cards tablosu hazır.</p>";

if (!col_exists_cc($pdo, 'expenses', 'payment_method')) {
    $pdo->exec("ALTER TABLE expenses ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'nakit' AFTER paid_by");
    echo "<p>✅ expenses.payment_method eklendi.</p>";
} else {
    echo "<p>— expenses.payment_method zaten vardı.</p>";
}

if (!col_exists_cc($pdo, 'expenses', 'credit_card_id')) {
    $pdo->exec("ALTER TABLE expenses ADD COLUMN credit_card_id INT NULL AFTER payment_method");
    echo "<p>✅ expenses.credit_card_id eklendi.</p>";
} else {
    echo "<p>— expenses.credit_card_id zaten vardı.</p>";
}

if (!col_exists_cc($pdo, 'expenses', 'budget_month')) {
    $pdo->exec("ALTER TABLE expenses ADD COLUMN budget_month CHAR(7) NULL AFTER credit_card_id");
    $pdo->exec("UPDATE expenses SET budget_month = DATE_FORMAT(expense_date, '%Y-%m') WHERE budget_month IS NULL");
    $pdo->exec("ALTER TABLE expenses MODIFY budget_month CHAR(7) NOT NULL");
    echo "<p>✅ expenses.budget_month eklendi ve mevcut kayıtlar dolduruldu (satın alma ayı ile aynı ay atandı).</p>";
} else {
    echo "<p>— expenses.budget_month zaten vardı.</p>";
}

echo "<p><b>Bitti.</b> Bu dosyayı (migrate_creditcard.php) şimdi sil, sonra <a href='expenses.php'>Giderler sayfasına git</a>.</p>";
