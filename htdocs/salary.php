<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$PEOPLE = ['Ozi', 'Ceyda'];
$curMonth = current_budget_period();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = $_POST['action'] ?? '';

    if ($action === 'set_salary') {
        $person = $_POST['person'];
        $month = $_POST['month'] ?: $curMonth;
        $amount = (float)($_POST['salary_amount'] ?? 0);
        if ($amount > 0 && in_array($person, $PEOPLE)) {
            $stmt = $pdo->prepare("INSERT INTO salary_entries (person, month, salary_amount) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE salary_amount = VALUES(salary_amount)");
            $stmt->execute([$person, $month, $amount]);
        }

    } elseif ($action === 'add_contribution') {
        $salaryEntryId = (int)$_POST['salary_entry_id'];
        $amount = (float)($_POST['amount'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        if ($amount > 0) {
            $stmt = $pdo->prepare("INSERT INTO salary_contributions (salary_entry_id, amount, entry_date, note) VALUES (?,?,CURDATE(),?)");
            $stmt->execute([$salaryEntryId, $amount, $note]);
        }

    } elseif ($action === 'delete_contribution') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM salary_contributions WHERE id=?");
        $stmt->execute([$id]);

    } elseif ($action === 'delete_salary') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM salary_entries WHERE id=?");
        $stmt->execute([$id]);

    } elseif ($action === 'add_income') {
        $person = $_POST['income_person'];
        $date = $_POST['income_date'] ?: date('Y-m-d');
        $source = trim($_POST['source'] ?? '');
        $amount = (float)($_POST['income_amount'] ?? 0);
        if ($amount > 0 && $source && in_array($person, $PEOPLE)) {
            $stmt = $pdo->prepare("INSERT INTO extra_income (person, income_date, source, amount) VALUES (?,?,?,?)");
            $stmt->execute([$person, $date, $source, $amount]);
        }

    } elseif ($action === 'delete_income') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM extra_income WHERE id=?");
        $stmt->execute([$id]);
    }
    header('Location: salary.php');
    exit;
  } catch (Throwable $e) {
    echo "<div style='background:#1b2422;color:#eee7d8;font-family:monospace;padding:24px;'>";
    echo "<h2 style='color:#c0564b;'>Bir hata oluştu</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p style='color:#b9b2a1;font-size:13px;'>" . htmlspecialchars($e->getFile()) . " (satır " . $e->getLine() . ")</p>";
    echo "<p><a href='salary.php' style='color:#e0c25f;'>Maaş &amp; Tasarruf sayfasına dön</a></p>";
    echo "</div>";
    exit;
  }
}

$entries = $pdo->query("SELECT * FROM salary_entries ORDER BY month DESC, person")->fetchAll();
foreach ($entries as &$e) {
    $stmt = $pdo->prepare("SELECT * FROM salary_contributions WHERE salary_entry_id=? ORDER BY entry_date DESC, id DESC");
    $stmt->execute([$e['id']]);
    $e['contributions'] = $stmt->fetchAll();
    $e['total_contributed'] = array_sum(array_column($e['contributions'], 'amount'));
    $e['rate'] = $e['salary_amount'] > 0 ? ($e['total_contributed'] / $e['salary_amount'] * 100) : 0;
}
unset($e);

$existingThisMonth = array_column(array_filter($entries, fn($e) => $e['month'] === $curMonth), 'person');
$missingPeople = array_diff($PEOPLE, $existingThisMonth);

$months = recent_budget_periods(6);
$rateByPersonMonth = [];
foreach ($entries as $e) {
    $rateByPersonMonth[$e['person']][$e['month']] = $e['rate'];
}

// ---- Bu ayın gider dağılımı: maaşın ne kadarı market / sabit gider / kişisel harcamaya gidiyor ----
$curMonthEntries = array_filter($entries, fn($e) => $e['month'] === $curMonth);
$combinedSalary = array_sum(array_column($curMonthEntries, 'salary_amount'));
$curMonthBuckets = get_expense_bucket_totals($pdo, $curMonth);

try {
    $incomeList = $pdo->query("SELECT * FROM extra_income ORDER BY income_date DESC, id DESC")->fetchAll();
} catch (Throwable $e) {
    $incomeList = [];
}
$incomeThisMonth = array_filter($incomeList, fn($i) => budget_period_for_date($i['income_date']) === $curMonth);
$incomeThisMonthByPerson = ['Ozi' => 0, 'Ceyda' => 0];
foreach ($incomeThisMonth as $i) { $incomeThisMonthByPerson[$i['person']] += (float)$i['amount']; }
$incomeThisMonthTotal = array_sum($incomeThisMonthByPerson);

$activeTab = 'salary';
$loadChart = true;
include __DIR__ . '/header.php';
?>

<?php if ($missingPeople && is_after_payday()): ?>
  <div class="panel-box">
    <h3>Bu Ayın Maaşını Gir</h3>
    <?php foreach ($missingPeople as $person): ?>
      <form method="post" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; margin-bottom:10px;">
        <input type="hidden" name="action" value="set_salary">
        <input type="hidden" name="person" value="<?= $person ?>">
        <input type="hidden" name="month" value="<?= $curMonth ?>">
        <div class="field" style="margin:0;"><label><?= $person ?> — <?= fmt_budget_period($curMonth) ?> Maaşı (₺)</label><input type="number" step="0.01" name="salary_amount" required style="width:180px;"></div>
        <button class="btn-primary" type="submit">Kaydet</button>
      </form>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<button class="form-toggle" onclick="document.getElementById('pastMonthForm').classList.toggle('open')">+ Geçmiş Ay İçin Maaş Gir / Düzelt</button>
<form method="post" class="form-card" id="pastMonthForm">
  <input type="hidden" name="action" value="set_salary">
  <div class="form-row">
    <div class="field"><label>Kişi</label><select name="person"><option>Ozi</option><option>Ceyda</option></select></div>
    <div class="field"><label>Ay</label><input type="month" name="month" value="<?= $curMonth ?>"></div>
    <div class="field"><label>Maaş (₺)</label><input type="number" step="0.01" name="salary_amount" required></div>
  </div>
  <div class="form-actions">
    <button class="btn-primary" type="submit">Kaydet</button>
    <button class="btn-ghost" type="button" onclick="document.getElementById('pastMonthForm').classList.remove('open')">Vazgeç</button>
  </div>
</form>

<div class="panel-box">
  <h3>Bu Ayın Gider Dağılımı (Maaşa Göre)</h3>
  <?php if ($combinedSalary > 0): ?>
    <div class="invest-stats" style="gap:28px;">
      <div class="mini">Market
        <span class="v" style="color:var(--gold-soft);">%<?= number_format($curMonthBuckets['market'] / $combinedSalary * 100, 1) ?></span>
        <div style="font-size:11.5px; color:var(--paper-dim); margin-top:4px;"><?= fmt($curMonthBuckets['market']) ?></div>
      </div>
      <div class="mini">Sabit Giderler <span style="font-weight:400; color:var(--paper-dim);">(kira, abonelikler vb.)</span>
        <span class="v" style="color:var(--gold-soft);">%<?= number_format($curMonthBuckets['sabit'] / $combinedSalary * 100, 1) ?></span>
        <div style="font-size:11.5px; color:var(--paper-dim); margin-top:4px;"><?= fmt($curMonthBuckets['sabit']) ?></div>
      </div>
      <div class="mini">Kişisel Harcamalar
        <span class="v" style="color:var(--gold-soft);">%<?= number_format($curMonthBuckets['kisisel'] / $combinedSalary * 100, 1) ?></span>
        <div style="font-size:11.5px; color:var(--paper-dim); margin-top:4px;"><?= fmt($curMonthBuckets['kisisel']) ?></div>
      </div>
    </div>
    <p style="font-size:11.5px; color:var(--paper-dim); margin-top:14px;">Yüzdeler, bu ay için girilmiş toplam maaşa (<?= fmt($combinedSalary) ?>) göre hesaplanıyor. "Market" ve "Kira / Fatura" / "Abonelik" kategorileri otomatik ayrılıyor, geri kalan tüm kategoriler "kişisel harcama" sayılıyor.</p>
  <?php else: ?>
    <div class="empty-state">Bu ay için henüz maaş girilmediğinden oran hesaplanamıyor.</div>
  <?php endif; ?>
</div>

<div class="panel-box">
  <h3>Ek Gelirler <span style="font-size:12px; color:var(--paper-dim); font-weight:400;">(proje vb. düzensiz gelirler)</span></h3>
  <p style="font-size:12.5px; color:var(--paper-dim); margin:-6px 0 14px 0;">Bu Ay Toplam: <span class="mono" style="color:var(--gold-soft); font-weight:600; font-size:15px;"><?= fmt($incomeThisMonthTotal) ?></span>
    <span style="margin-left:14px;">Ozi: <?= fmt($incomeThisMonthByPerson['Ozi']) ?></span>
    <span style="margin-left:10px;">Ceyda: <?= fmt($incomeThisMonthByPerson['Ceyda']) ?></span>
  </p>

  <form method="post" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
    <input type="hidden" name="action" value="add_income">
    <select name="income_person" style="background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
      <option>Ozi</option><option>Ceyda</option>
    </select>
    <input type="date" name="income_date" value="<?= date('Y-m-d') ?>" style="background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
    <input type="text" name="source" placeholder="Kaynak (örn. Proje X)" required style="width:160px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
    <input type="number" step="0.01" name="income_amount" placeholder="₺ tutar" required style="width:120px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
    <button class="btn-primary" type="submit" style="padding:8px 16px;font-size:13px;">+ Ekle</button>
  </form>

  <?php if (!$incomeList): ?>
    <div class="empty-state" style="padding:20px;">Henüz ek gelir kaydı yok.</div>
  <?php else: ?>
    <div style="overflow-x:auto;">
      <table>
        <thead><tr><th>Tarih</th><th>Kaynak</th><th>Kişi</th><th style="text-align:right;">Tutar</th><th></th></tr></thead>
        <tbody>
        <?php foreach (array_slice($incomeList, 0, 20) as $i): ?>
          <tr>
            <td><?= $i['income_date'] ?></td>
            <td><?= htmlspecialchars($i['source']) ?></td>
            <td><span class="person-tag"><?= htmlspecialchars($i['person']) ?></span></td>
            <td class="amount"><?= fmt($i['amount']) ?></td>
            <td>
              <form method="post" onsubmit="return confirm('Bu ek gelir kaydını silmek istediğine emin misin?')">
                <input type="hidden" name="action" value="delete_income">
                <input type="hidden" name="id" value="<?= $i['id'] ?>">
                <button class="del-btn" type="submit">✕</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($incomeList) > 20): ?><p style="font-size:11.5px; color:var(--paper-dim); margin-top:10px;">Son 20 kayıt gösteriliyor (toplam <?= count($incomeList) ?>).</p><?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if (count($months) >= 2 && !empty($rateByPersonMonth)): ?>
<div class="panel-box">
  <h3>Tasarruf Oranı Trendi (Son 6 Ay)</h3>
  <div class="chart-wrap"><canvas id="chartRate"></canvas></div>
</div>
<script>
new Chart(document.getElementById('chartRate'), {
  type: 'line',
  data: {
    labels: <?= json_encode(array_map(fn($m) => date('M Y', strtotime($m . '-01')), $months)) ?>,
    datasets: [
      {
        label: 'Ozi',
        data: <?= json_encode(array_map(fn($m) => $rateByPersonMonth['Ozi'][$m] ?? null, $months)) ?>,
        borderColor: '#c9a227', backgroundColor: 'rgba(201,162,39,0.1)', spanGaps: true, tension: 0.25
      },
      {
        label: 'Ceyda',
        data: <?= json_encode(array_map(fn($m) => $rateByPersonMonth['Ceyda'][$m] ?? null, $months)) ?>,
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
</script>
<?php endif; ?>

<?php if (!$entries): ?>
  <div class="empty-state">Henüz maaş kaydı yok. Yukarıdan bu ayın maaşını gir.</div>
<?php endif; ?>

<?php foreach ($entries as $e): ?>
  <div class="invest-card">
    <div class="invest-head">
      <div class="invest-title">
        <h4><?= date('F Y', strtotime($e['month'] . '-01')) ?></h4>
        <span class="person-tag"><?= htmlspecialchars($e['person']) ?></span>
      </div>
      <span class="return-badge pos">%<?= number_format($e['rate'], 1) ?> yatırıma ayrıldı</span>
    </div>
    <div class="invest-stats">
      <div class="mini">Maaş<span class="v"><?= fmt($e['salary_amount']) ?></span></div>
      <div class="mini">Yatırıma Ayrılan<span class="v"><?= fmt($e['total_contributed']) ?></span></div>
      <div class="mini">Kalan<span class="v"><?= fmt($e['salary_amount'] - $e['total_contributed']) ?></span></div>
    </div>
    <form method="post" class="entry-form" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:6px;">
      <input type="hidden" name="action" value="add_contribution">
      <input type="hidden" name="salary_entry_id" value="<?= $e['id'] ?>">
      <input type="number" name="amount" step="0.01" placeholder="₺ tutar" style="width:120px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:7px 8px;font-size:12.5px;">
      <input type="text" name="note" placeholder="Not (isteğe bağlı)" style="width:160px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:7px 8px;font-size:12.5px;">
      <button class="btn-primary" type="submit" style="padding:7px 14px;font-size:12.5px;">+ Yatırıma Ekle</button>
    </form>
    <form method="post" onsubmit="return confirm('Bu ay kaydını (ve tüm yatırım eklemelerini) silmek istediğine emin misin?')" style="display:inline;">
      <input type="hidden" name="action" value="delete_salary">
      <input type="hidden" name="id" value="<?= $e['id'] ?>">
      <button class="del-btn" type="submit">✕ Ay Kaydını Sil</button>
    </form>
    <?php if ($e['contributions']): ?>
    <details class="entry-list" style="margin-top:10px;">
      <summary class="small-link" style="cursor:pointer;">Eklemeleri göster (<?= count($e['contributions']) ?>)</summary>
      <?php foreach ($e['contributions'] as $c): ?>
        <div class="entry-row">
          <span class="et"><?= $c['entry_date'] ?><?= $c['note'] ? ' — ' . htmlspecialchars($c['note']) : '' ?></span>
          <span>
            <span class="mono"><?= fmt($c['amount']) ?></span>
            <form method="post" style="display:inline;" onsubmit="return confirm('Bu eklemeyi silmek istediğine emin misin?')">
              <input type="hidden" name="action" value="delete_contribution">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <button class="del-btn" type="submit" style="font-size:12px;">✕</button>
            </form>
          </span>
        </div>
      <?php endforeach; ?>
    </details>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php include __DIR__ . '/footer.php'; ?>
