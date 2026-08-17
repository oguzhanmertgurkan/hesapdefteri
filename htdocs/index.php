<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

// Ödeme kaydetme (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'settle') {
    $from = $_POST['from'];
    $to = $_POST['to'];
    $amount = (float)$_POST['amount'];
    $note = trim($_POST['note'] ?? '');
    if ($from !== $to && $amount > 0) {
        $stmt = $pdo->prepare("INSERT INTO settlements (settle_date, from_person, to_person, amount, note) VALUES (CURDATE(), ?, ?, ?, ?)");
        $stmt->execute([$from, $to, $amount, $note]);
    }
    header('Location: index.php');
    exit;
}

$curMonth = date('Y-m');

$stmt = $pdo->prepare("SELECT * FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m') = ?");
$stmt->execute([$curMonth]);
$monthExpenses = $stmt->fetchAll();
$monthTotal = array_sum(array_column($monthExpenses, 'amount'));

$allExpenses = $pdo->query("SELECT * FROM expenses")->fetchAll();
$net = 0;
foreach ($allExpenses as $e) {
    if ($e['paid_by'] === 'Ozi') $net += $e['owed_by_other'];
    else $net -= $e['owed_by_other'];
}
$settlements = $pdo->query("SELECT * FROM settlements ORDER BY settle_date DESC")->fetchAll();
foreach ($settlements as $s) {
    if ($s['from_person'] === 'Ceyda' && $s['to_person'] === 'Ozi') $net -= $s['amount'];
    if ($s['from_person'] === 'Ozi' && $s['to_person'] === 'Ceyda') $net += $s['amount'];
}

$positions = $pdo->query("SELECT * FROM investment_positions")->fetchAll();
$totalInvested = 0;
$totalCurrent = 0;
$typeTotals = [];
foreach ($positions as $p) {
    $es = $pdo->prepare("SELECT * FROM investment_entries WHERE position_id=?");
    $es->execute([$p['id']]);
    $entries = $es->fetchAll();
    [$inv, $cur, $ret] = calc_position_totals($entries);
    $totalInvested += $inv;
    $totalCurrent += $cur;
    $typeTotals[$p['type']] = ($typeTotals[$p['type']] ?? 0) + $cur;
}
$overallReturn = $totalInvested > 0 ? (($totalCurrent - $totalInvested) / $totalInvested * 100) : 0;

$months = [];
for ($i = 5; $i >= 0; $i--) {
    $months[] = date('Y-m', strtotime("-$i months"));
}
$trendData = [];
foreach ($months as $m) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) s FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=?");
    $stmt->execute([$m]);
    $trendData[] = (float)$stmt->fetch()['s'];
}
$trendLabels = array_map(fn($m) => date('M', strtotime($m . '-01')), $months);

$catTotals = [];
foreach ($monthExpenses as $e) {
    $catTotals[$e['category']] = ($catTotals[$e['category']] ?? 0) + $e['amount'];
}

$personTotals = ['Ozi' => 0, 'Ceyda' => 0];
foreach ($monthExpenses as $e) {
    $personTotals[$e['paid_by']] = ($personTotals[$e['paid_by']] ?? 0) + $e['amount'];
}

// ---- Maaş & Tasarruf Oranı (bu ay + son 6 ay trendi) ----
$salaryCurMonth = [];
$curMonthSalaryStmt = $pdo->prepare("SELECT * FROM salary_entries WHERE month=?");
$curMonthSalaryStmt->execute([$curMonth]);
foreach ($curMonthSalaryStmt->fetchAll() as $row) {
    $cStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) s FROM salary_contributions WHERE salary_entry_id=?");
    $cStmt->execute([$row['id']]);
    $contributed = (float)$cStmt->fetch()['s'];
    $salaryCurMonth[$row['person']] = [
        'salary' => $row['salary_amount'],
        'contributed' => $contributed,
        'rate' => $row['salary_amount'] > 0 ? $contributed / $row['salary_amount'] * 100 : 0,
    ];
}

$salaryRateByPerson = [];
foreach ($pdo->query("SELECT * FROM salary_entries")->fetchAll() as $se) {
    $cStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) s FROM salary_contributions WHERE salary_entry_id=?");
    $cStmt->execute([$se['id']]);
    $contributed = (float)$cStmt->fetch()['s'];
    $rate = $se['salary_amount'] > 0 ? $contributed / $se['salary_amount'] * 100 : 0;
    $salaryRateByPerson[$se['person']][$se['month']] = $rate;
}
$hasSalaryData = !empty($salaryRateByPerson);

$activeTab = 'dashboard';
$loadChart = true;
include __DIR__ . '/header.php';
?>

<div class="grid cols-4" style="margin-bottom:20px;">
  <div class="stat-card">
    <div class="lbl">Bu Ay Toplam Gider</div>
    <div class="val"><?= fmt($monthTotal) ?></div>
    <div class="sub"><?= count($monthExpenses) ?> kayıt · <?= date('F Y') ?></div>
  </div>
  <div class="stat-card">
    <div class="lbl">Toplam Birikim (Güncel Değer)</div>
    <div class="val"><?= fmt($totalCurrent) ?></div>
    <div class="sub"><?= count($positions) ?> aktif kalem</div>
  </div>
  <div class="stat-card">
    <div class="lbl">Ağırlıklı Getiri</div>
    <div class="val <?= $overallReturn >= 0 ? 'pos' : 'neg' ?>"><?= ($overallReturn >= 0 ? '+' : '') . number_format($overallReturn, 2) ?>%</div>
    <div class="sub">Yatırılan tutara göre</div>
  </div>
  <div class="stat-card">
    <div class="lbl">Aranızdaki Bakiye</div>
    <div class="val <?= abs($net) < 0.01 ? '' : 'neg' ?>"><?= fmt(abs($net)) ?></div>
    <div class="sub"><?= abs($net) < 0.01 ? 'Eşit' : ($net > 0 ? 'Ceyda → Ozi' : 'Ozi → Ceyda') ?></div>
  </div>
</div>

<div class="panel-box">
  <h3>Aranızdaki Bakiye</h3>
  <div class="balance-panel">
    <div class="balance-text">
      <?php if (abs($net) < 0.01): ?>
        Hesaplar eşit ✓
      <?php elseif ($net > 0): ?>
        <b>Ceyda</b>, <b>Ozi</b>'ye <?= fmt($net) ?> borçlu
      <?php else: ?>
        <b>Ozi</b>, <b>Ceyda</b>'ye <?= fmt(-$net) ?> borçlu
      <?php endif; ?>
    </div>
    <button class="form-toggle" onclick="document.getElementById('settleForm').classList.toggle('open')" style="margin:0;">Ödeme Kaydet</button>
  </div>
  <form method="post" class="form-card" id="settleForm" style="margin-top:14px;">
    <input type="hidden" name="action" value="settle">
    <div class="form-row">
      <div class="field"><label>Ödeyen</label><select name="from"><option>Ozi</option><option>Ceyda</option></select></div>
      <div class="field"><label>Alan</label><select name="to"><option>Ceyda</option><option>Ozi</option></select></div>
      <div class="field"><label>Tutar (₺)</label><input type="number" step="0.01" name="amount" required></div>
      <div class="field"><label>Not</label><input type="text" name="note"></div>
    </div>
    <div class="form-actions">
      <button class="btn-primary" type="submit">Kaydet</button>
      <button class="btn-ghost" type="button" onclick="document.getElementById('settleForm').classList.remove('open')">Vazgeç</button>
    </div>
  </form>
  <div class="settle-list">
    <?php foreach (array_slice($settlements, 0, 8) as $s): ?>
      <div class="settle-row"><span><?= $s['settle_date'] ?> — <?= htmlspecialchars($s['from_person']) ?> → <?= htmlspecialchars($s['to_person']) ?><?= $s['note'] ? ' (' . htmlspecialchars($s['note']) . ')' : '' ?></span><span class="mono"><?= fmt($s['amount']) ?></span></div>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel-box">
  <h3>Maaş &amp; Tasarruf Oranı (Bu Ay) <a href="salary.php" class="small-link" style="font-size:12px; margin-left:8px;">Detaylar →</a></h3>
  <div class="invest-stats" style="gap:36px;">
    <?php foreach (['Ozi', 'Ceyda'] as $person): ?>
      <?php if (isset($salaryCurMonth[$person])): $d = $salaryCurMonth[$person]; ?>
        <div class="mini">
          <?= $person ?>
          <span class="v" style="color:var(--gold-soft);">%<?= number_format($d['rate'], 1) ?></span>
          <div style="font-size:11.5px; color:var(--paper-dim); margin-top:4px;">Maaş: <?= fmt($d['salary']) ?> · Yatırılan: <?= fmt($d['contributed']) ?></div>
        </div>
      <?php else: ?>
        <div class="mini">
          <?= $person ?>
          <div style="font-size:12px; color:var(--paper-dim); margin-top:4px;">Bu ay için maaş girilmedi. <a href="salary.php" class="small-link">Gir →</a></div>
        </div>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>
  <?php if ($hasSalaryData): ?>
    <div class="chart-wrap" style="height:200px; margin-top:18px;"><canvas id="chartSalaryRate"></canvas></div>
  <?php endif; ?>
</div>

<div class="grid cols-2">
  <div class="panel-box">
    <h3>Son 6 Ay Gider Trendi</h3>
    <div class="chart-wrap"><canvas id="chartTrend"></canvas></div>
  </div>
  <div class="panel-box">
    <h3>Bu Ay Kategori Dağılımı</h3>
    <div class="chart-wrap"><canvas id="chartCat"></canvas></div>
  </div>
</div>
<div class="grid cols-2">
  <div class="panel-box">
    <h3>Yatırım Türü Dağılımı</h3>
    <div class="chart-wrap"><canvas id="chartInvest"></canvas></div>
  </div>
  <div class="panel-box">
    <h3>Kişi Bazında Bu Ay Ödenen</h3>
    <div class="chart-wrap"><canvas id="chartPerson"></canvas></div>
  </div>
</div>

<script>
Chart.defaults.color = '#b9b2a1';
Chart.defaults.borderColor = 'rgba(238,231,216,0.08)';
Chart.defaults.font.family = "'Inter', sans-serif";

new Chart(document.getElementById('chartTrend'), {
  type: 'bar',
  data: { labels: <?= json_encode($trendLabels) ?>, datasets: [{ data: <?= json_encode($trendData) ?>, backgroundColor: '#c9a227', borderRadius: 5, maxBarThickness: 38 }] },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
    scales: { y: { ticks: { callback: v => '₺' + v } }, x: { grid: { display: false } } } }
});

const catLabels = <?= json_encode(array_keys($catTotals)) ?>;
const catData = <?= json_encode(array_values($catTotals)) ?>;
new Chart(document.getElementById('chartCat'), {
  type: 'doughnut',
  data: { labels: catLabels.length ? catLabels : ['Veri yok'], datasets: [{ data: catLabels.length ? catData : [1],
    backgroundColor: catLabels.length ? ['#c9a227','#4fa381','#c0564b','#7a9cc6','#b98fd1','#e0c25f','#5fb0a3','#a1a49b','#d98a6b'] : ['#324039'],
    borderColor: '#1b2422', borderWidth: 2 }] },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 12, font: { size: 11 } } } } }
});

const typeLabels = <?= json_encode(array_keys($typeTotals)) ?>;
const typeData = <?= json_encode(array_values($typeTotals)) ?>;
new Chart(document.getElementById('chartInvest'), {
  type: 'doughnut',
  data: { labels: typeLabels.length ? typeLabels : ['Veri yok'], datasets: [{ data: typeLabels.length ? typeData : [1],
    backgroundColor: typeLabels.length ? ['#4fa381','#c9a227','#7a9cc6','#c0564b','#b98fd1','#e0c25f','#a1a49b'] : ['#324039'],
    borderColor: '#1b2422', borderWidth: 2 }] },
  options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 12, font: { size: 11 } } } } }
});

new Chart(document.getElementById('chartPerson'), {
  type: 'bar',
  data: { labels: ['Ozi', 'Ceyda'], datasets: [{ data: [<?= $personTotals['Ozi'] ?>, <?= $personTotals['Ceyda'] ?>], backgroundColor: ['#c9a227', '#4fa381'], borderRadius: 6, maxBarThickness: 60 }] },
  options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: { ticks: { callback: v => '₺' + v } }, y: { grid: { display: false } } } }
});

<?php if ($hasSalaryData): ?>
new Chart(document.getElementById('chartSalaryRate'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_map(fn($m) => date('M Y', strtotime($m . '-01')), $months)) ?>,
    datasets: [
      {
        label: 'Ozi',
        data: <?= json_encode(array_map(fn($m) => $salaryRateByPerson['Ozi'][$m] ?? null, $months)) ?>,
        borderColor: '#c9a227', backgroundColor: 'rgba(201,162,39,0.1)', spanGaps: true, tension: 0.25
      },
      {
        label: 'Ceyda',
        data: <?= json_encode(array_map(fn($m) => $salaryRateByPerson['Ceyda'][$m] ?? null, $months)) ?>,
        borderColor: '#4fa381', backgroundColor: 'rgba(79,163,129,0.1)', spanGaps: true, tension: 0.25
      }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, padding: 12, font: { size: 11 } } } },
    scales: { y: { ticks: { callback: v => v + '%' } } }
  }
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/footer.php'; ?>
