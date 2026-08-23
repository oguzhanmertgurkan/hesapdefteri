<?php
require_once __DIR__ . '/includes/db.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS extra_income (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person VARCHAR(50) NOT NULL,
  income_date DATE NOT NULL,
  source VARCHAR(150) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "<p>✅ extra_income tablosu hazır.</p>";

echo "<p><b>Bitti.</b> Bu dosyayı (migrate_extraincome.php) şimdi sil, sonra <a href='salary.php'>Maaş &amp; Tasarruf sayfasına git</a>.</p>";
