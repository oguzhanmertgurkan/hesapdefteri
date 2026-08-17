<?php
require_once __DIR__ . '/includes/db.php';

function col_exists_rec($pdo, $table, $col) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return $stmt->fetch()['c'] > 0;
}

$pdo->exec("CREATE TABLE IF NOT EXISTS recurring_expenses (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  category VARCHAR(50) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  person VARCHAR(50) NOT NULL,
  split_mode VARCHAR(20) NOT NULL DEFAULT 'esit',
  ozel_tutar DECIMAL(12,2) NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "<p>✅ recurring_expenses tablosu hazır.</p>";

if (!col_exists_rec($pdo, 'expenses', 'recurring_id')) {
    $pdo->exec("ALTER TABLE expenses ADD COLUMN recurring_id INT NULL AFTER owed_by_other");
    echo "<p>✅ expenses.recurring_id eklendi.</p>";
} else {
    echo "<p>— expenses.recurring_id zaten vardı.</p>";
}

echo "<p><b>Bitti.</b> Bu dosyayı (migrate_recurring.php) şimdi sil, sonra <a href='expenses.php'>Giderler sayfasına git</a>.</p>";
