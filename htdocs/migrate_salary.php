<?php
require_once __DIR__ . '/includes/db.php';

$pdo->exec("CREATE TABLE IF NOT EXISTS salary_entries (
  id INT AUTO_INCREMENT PRIMARY KEY,
  person VARCHAR(50) NOT NULL,
  month CHAR(7) NOT NULL,
  salary_amount DECIMAL(12,2) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY person_month (person, month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "<p>✅ salary_entries tablosu hazır.</p>";

$pdo->exec("CREATE TABLE IF NOT EXISTS salary_contributions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  salary_entry_id INT NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  entry_date DATE NOT NULL,
  note VARCHAR(255),
  FOREIGN KEY (salary_entry_id) REFERENCES salary_entries(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
echo "<p>✅ salary_contributions tablosu hazır.</p>";

echo "<p><b>Bitti.</b> Bu dosyayı (migrate_salary.php) şimdi sil.</p>";
