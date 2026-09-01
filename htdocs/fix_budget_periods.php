<?php
require_once __DIR__ . '/includes/db.php';

// "Ay" bu uygulamada takvim ayı değil, MAAŞ DÖNEMİDİR (ayın 15'i - bir
// sonraki ayın 15'i). Bu kural artık nakit/banka giderlerinin bütçe ayı
// hesaplamasına da uygulanıyor (bkz. includes/functions.php,
// budget_period_for_date()) — daha önce bu hesaplama düz takvim ayını
// kullanıyordu. Bu script, o değişiklikten ÖNCE, ayın 15'inden ÖNCE
// girilmiş nakit/banka giderlerinin (o zaman yanlışlıkla takvim ayına
// göre kaydedilmiş) bütçe ayını doğru döneme (bir önceki aya) çeker.
// Kredi kartı harcamalarına dokunulmaz — onların bütçe ayı zaten ayrı,
// kartın kesim/son ödeme tarihine dayalı bir mantıkla hesaplanıyor.

$preview = $pdo->query("
    SELECT id, expense_date, description, amount, budget_month AS eski_ay,
        DATE_FORMAT(IF(DAY(expense_date) < 15, DATE_SUB(expense_date, INTERVAL 1 MONTH), expense_date), '%Y-%m') AS yeni_ay
    FROM expenses
    WHERE payment_method <> 'kredi_karti'
      AND budget_month <> DATE_FORMAT(IF(DAY(expense_date) < 15, DATE_SUB(expense_date, INTERVAL 1 MONTH), expense_date), '%Y-%m')
")->fetchAll();

if ($preview) {
    echo "<p>Aşağıdaki " . count($preview) . " kayıt güncellenecek:</p><ul>";
    foreach ($preview as $r) {
        echo "<li>#" . $r['id'] . " — " . htmlspecialchars($r['expense_date']) . " " . htmlspecialchars($r['description'] ?: '(açıklama yok)') .
             " (" . number_format((float)$r['amount'], 2, ',', '.') . "₺): " . htmlspecialchars($r['eski_ay']) . " → <b>" . htmlspecialchars($r['yeni_ay']) . "</b></li>";
    }
    echo "</ul>";

    $updated = $pdo->exec("
        UPDATE expenses
        SET budget_month = DATE_FORMAT(IF(DAY(expense_date) < 15, DATE_SUB(expense_date, INTERVAL 1 MONTH), expense_date), '%Y-%m')
        WHERE payment_method <> 'kredi_karti'
          AND budget_month <> DATE_FORMAT(IF(DAY(expense_date) < 15, DATE_SUB(expense_date, INTERVAL 1 MONTH), expense_date), '%Y-%m')
    ");
    echo "<p>✅ Güncellenen kayıt sayısı: <b>{$updated}</b></p>";
} else {
    echo "<p>Güncellenecek kayıt yok — tüm nakit/banka giderleri zaten doğru döneme kayıtlı.</p>";
}

echo "<p><b>Bitti.</b> Bu dosyayı (fix_budget_periods.php) şimdi sil, sonra <a href='index.php'>Özet sayfasına git</a> ve kontrol et.</p>";
