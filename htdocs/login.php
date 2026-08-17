<?php
require_once __DIR__ . '/includes/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['display_name'])) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();
    if ($user && password_verify($password, $user['password_hash'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['display_name'] = $user['display_name'];
        header('Location: index.php');
        exit;
    } else {
        $error = 'Kullanıcı adı veya şifre hatalı.';
    }
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hesap Defteri — Giriş</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div id="login">
  <div class="ledger-card">
    <p class="brand-eyebrow">Hesap Defteri</p>
    <h1>Giriş Yap</h1>
    <p class="sub">Ortak gider ve birikim defterinize kullanıcı adı ve şifrenizle girin.</p>
    <form method="post" class="auth-form open">
      <div class="field"><label>Kullanıcı Adı</label><input type="text" name="username" autocomplete="username" required></div>
      <div class="field"><label>Şifre</label><input type="password" name="password" autocomplete="current-password" required></div>
      <?php if ($error): ?><p class="auth-error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
      <button class="enter-btn" type="submit">Giriş Yap</button>
    </form>
  </div>
</div>
</body>
</html>
