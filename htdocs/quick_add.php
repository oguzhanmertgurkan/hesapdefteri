<?php
// iPhone Kısayolları (Shortcuts) gibi araçlardan tek istekle hızlı gider
// eklemek için. Ana giriş sistemine (kullanıcı adı/şifre) dokunmuyor —
// sadece bu uç noktaya özel, kişiye bağlı gizli bir token ile çalışır.
// Bu token yalnızca YENİ GİDER EKLEYEBİLİR; veri okuyamaz ya da silemez.

require_once __DIR__ . '/includes/db.php';
header('Content-Type: text/plain; charset=utf-8');

$token = trim($_REQUEST['token'] ?? '');
$amount = (float)($_REQUEST['amount'] ?? 0);
$category = $_REQUEST['category'] ?? 'Market';
$desc = trim($_REQUEST['desc'] ?? '');

if (!$token) {
    http_response_code(400);
    echo "Hata: token eksik.";
    exit;
}
if ($amount <= 0) {
    http_response_code(400);
    echo "Hata: geçerli bir tutar gir.";
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE quick_token = ?");
$stmt->execute([$token]);
$user = $stmt->fetch();
if (!$user) {
    http_response_code(403);
    echo "Hata: geçersiz token.";
    exit;
}

$allowedCategories = ["Market", "Gıda", "Kira", "Fatura", "Abonelik", "Ulaşım", "Sağlık", "Giyim", "Eğlence", "Eğitim", "Tatil", "Diğer"];
if (!in_array($category, $allowedCategories, true)) {
    $category = 'Diğer';
}

$person = $user['display_name'];
$splitMode = 'esit';
$owed = $amount / 2;
$budgetMonth = date('Y-m');

$stmt = $pdo->prepare("INSERT INTO expenses (expense_date, amount, category, description, paid_by, payment_method, split_mode, owed_by_other, budget_month) VALUES (CURDATE(), ?, ?, ?, ?, 'nakit', ?, ?, ?)");
$stmt->execute([$amount, $category, $desc, $person, $splitMode, $owed, $budgetMonth]);

echo "Eklendi: " . number_format($amount, 2, ',', '.') . " TL - " . $category . " (" . $person . ")";
