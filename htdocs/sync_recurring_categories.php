<?php
require_once __DIR__ . '/includes/db.php';

// Sabit ödeme şablonlarının kategorisi (örn. Kira, önceden yanlışlıkla
// "Abonelik" idi) sonradan düzeltildiğinde, o düzeltmeden ÖNCE bu ay için
// zaten otomatik oluşmuş gider kaydı eski kategoriyi taşımaya devam ediyordu
// (bkz. run_recurring_generation() — şablon kategorisini oluşturma anında
// kopyalıyor, sonradan geri senkronize etmiyordu). Bu yüzden "Bu Ay Kategori
// Dağılımı" pasta grafiğinde Kira, Abonelik dilimine karışmış görünüyordu.
//
// Bu script, SADECE bu ay için, sabit ödemeye bağlı (recurring_id dolu) gider
// kayıtlarını, bağlı oldukları şablonun GÜNCEL kategorisiyle senkronize eder.
// Geçmiş aylara dokunmaz. update_recurring_amount artık şablon kategorisi
// değiştiğinde bunu otomatik yapıyor (bkz. expenses.php) — bu script sadece
// o koddan ÖNCE zaten oluşmuş uyumsuzlukları bir kerelik temizlemek için.

$curMonth = date('Y-m');
$stmt = $pdo->prepare("
    UPDATE expenses e
    JOIN recurring_expenses r ON e.recurring_id = r.id
    SET e.category = r.category
    WHERE e.category <> r.category
      AND e.budget_month = ?
");
$stmt->execute([$curMonth]);
$updated = $stmt->rowCount();

echo "<p>✅ {$curMonth} ayı için senkronize edilen kayıt sayısı: <b>{$updated}</b></p>";
echo "<p><b>Bitti.</b> Bu dosyayı (sync_recurring_categories.php) şimdi sil, sonra <a href='index.php'>Özet sayfasına git</a> ve pasta grafiği kontrol et.</p>";
