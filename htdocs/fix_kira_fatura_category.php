<?php
require_once __DIR__ . '/includes/db.php';

// Bu script, "Kira" ve "Fatura" ayrı kategoriye bölünmeden önce oluşmuş,
// hâlâ eski birleşik "Kira / Fatura" etiketini taşıyan kayıtları düzeltir.
// Doğal Gaz / Elektrik / Su sabit ödeme şablonları "Fatura" olarak güncellendi,
// ama o güncellemeden ÖNCE otomatik oluşmuş gider kayıtları eski etiketle
// kalmaya devam ediyordu (örn. Özet sayfasındaki pasta grafikte ayrı,
// hatalı görünen "Kira / Fatura" dilimi). Bu script o kayıtları "Fatura"
// olarak günceller. Kira ile ilgisi olmayan, sadece fatura amaçlı kullanılan
// eski etiket olduğu için "Fatura" hedef kategori olarak seçildi.

$updatedExpenses = $pdo->exec("UPDATE expenses SET category = 'Fatura' WHERE category = 'Kira / Fatura'");
$updatedRecurring = $pdo->exec("UPDATE recurring_expenses SET category = 'Fatura' WHERE category = 'Kira / Fatura'");

echo "<p>✅ expenses tablosunda güncellenen kayıt sayısı: <b>{$updatedExpenses}</b></p>";
echo "<p>✅ recurring_expenses tablosunda güncellenen kayıt sayısı: <b>{$updatedRecurring}</b></p>";
echo "<p><b>Bitti.</b> Bu dosyayı (fix_kira_fatura_category.php) şimdi sil, sonra <a href='index.php'>Özet sayfasına git</a> ve pasta grafiği kontrol et.</p>";
