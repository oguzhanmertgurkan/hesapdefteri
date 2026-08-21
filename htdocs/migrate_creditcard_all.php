<?php
require_once __DIR__ . '/includes/db.php';

function col_exists_cc2($pdo, $table, $col) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
    $stmt->execute([$table, $col]);
    return $stmt->fetch()['c'] > 0;
}

function table_exists_cc2($pdo, $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?");
    $stmt->execute([$table]);
    return $stmt->fetch()['c'] > 0;
}

echo "<h3>Kredi kartı geçişi kontrol ediliyor...</h3><ul>";

try {
    if (!table_exists_cc2($pdo, 'credit_cards')) {
        $pdo->exec("CREATE TABLE credit_cards (
          id INT AUTO_INCREMENT PRIMARY KEY,
          person VARCHAR(50) NOT NULL,
          name VARCHAR(100) NOT NULL,
          cutoff_day TINYINT NOT NULL,
          due_day TINYINT NULL,
          created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        echo "<li>✅ credit_cards tablosu oluşturuldu (due_day dahil).</li>";
    } else {
        echo "<li>— credit_cards tablosu zaten vardı.</li>";
        if (!col_exists_cc2($pdo, 'credit_cards', 'due_day')) {
            $pdo->exec("ALTER TABLE credit_cards ADD COLUMN due_day TINYINT NULL AFTER cutoff_day");
            echo "<li>✅ credit_cards.due_day eklendi.</li>";
        } else {
            echo "<li>— credit_cards.due_day zaten vardı.</li>";
        }
    }

    if (!col_exists_cc2($pdo, 'expenses', 'payment_method')) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN payment_method VARCHAR(20) NOT NULL DEFAULT 'nakit' AFTER paid_by");
        echo "<li>✅ expenses.payment_method eklendi.</li>";
    } else {
        echo "<li>— expenses.payment_method zaten vardı.</li>";
    }

    if (!col_exists_cc2($pdo, 'expenses', 'credit_card_id')) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN credit_card_id INT NULL AFTER payment_method");
        echo "<li>✅ expenses.credit_card_id eklendi.</li>";
    } else {
        echo "<li>— expenses.credit_card_id zaten vardı.</li>";
    }

    if (!col_exists_cc2($pdo, 'expenses', 'budget_month')) {
        $pdo->exec("ALTER TABLE expenses ADD COLUMN budget_month CHAR(7) NULL AFTER credit_card_id");
        $pdo->exec("UPDATE expenses SET budget_month = DATE_FORMAT(expense_date, '%Y-%m') WHERE budget_month IS NULL");
        $pdo->exec("ALTER TABLE expenses MODIFY budget_month CHAR(7) NOT NULL");
        echo "<li>✅ expenses.budget_month eklendi ve mevcut kayıtlar dolduruldu.</li>";
    } else {
        echo "<li>— expenses.budget_month zaten vardı.</li>";
    }

    echo "</ul><p><b>Tüm geçişler tamamlandı.</b> Bu dosyayı (migrate_creditcard_all.php) şimdi sil, sonra <a href='expenses.php'>Giderler sayfasına git</a> ve Kredi Kartlarım panelinden kartlarını ekle.</p>";
} catch (PDOException $e) {
    echo "</ul><p style='color:red;'><b>Hata:</b> " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Bu ekran görüntüsünü Claude'a gönder.</p>";
}
