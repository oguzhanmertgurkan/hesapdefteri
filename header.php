<?php
$currentUser = current_user();
?>
<!DOCTYPE html>
<html lang="tr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Hesap Defteri</title>
<link rel="stylesheet" href="assets/style.css">
<?php if (!empty($loadChart)): ?><script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script><?php endif; ?>
</head>
<body>
<div id="appshell">
  <header class="topbar">
    <div class="topbar-left">
      <h1>Hesap Defteri</h1>
      <span class="tag">Gider Bölüşümü &amp; Birikim Takibi</span>
    </div>
    <div class="topbar-right">
      <div class="whoami"><span class="init"><?= htmlspecialchars($currentUser[0]) ?></span><span><?= htmlspecialchars($currentUser) ?></span></div>
      <a class="switch-btn" href="logout.php">Çıkış yap</a>
    </div>
  </header>
  <nav class="tabs">
    <a href="index.php" class="tab-btn <?= $activeTab === 'dashboard' ? 'active' : '' ?>">Özet</a>
    <a href="expenses.php" class="tab-btn <?= $activeTab === 'expenses' ? 'active' : '' ?>">Giderler</a>
    <a href="investments.php" class="tab-btn <?= $activeTab === 'investments' ? 'active' : '' ?>">Birikim &amp; Yatırım</a>
    <a href="salary.php" class="tab-btn <?= $activeTab === 'salary' ? 'active' : '' ?>">Maaş &amp; Tasarruf</a>
  </nav>
  <main>
