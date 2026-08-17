<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$CATEGORIES = ["Gıda", "Kira / Fatura", "Ulaşım", "Sağlık", "Giyim", "Eğlence", "Eğitim", "Tatil", "Diğer"];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $date = $_POST['date'] ?: date('Y-m-d');
        $amount = (float)$_POST['amount'];
        $category = $_POST['category'];
        $person = $_POST['person'];
        $splitMode = $_POST['split_mode'];
        $desc = trim($_POST['desc'] ?? '');
        $owed = 0;
        if ($splitMode === 'esit') $owed = $amount / 2;
        elseif ($splitMode === 'ozel') $owed = min(max((float)($_POST['ozel_tutar'] ?? 0), 0), $amount);
        if ($amount > 0) {
            $stmt = $pdo->prepare("INSERT INTO expenses (expense_date, amount, category, description, paid_by, split_mode, owed_by_other) VALUES (?,?,?,?,?,?,?)");
            $stmt->execute([$date, $amount, $category, $desc, $person, $splitMode, $owed]);
        }
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM expenses WHERE id=?");
        $stmt->execute([$id]);
    }
    header('Location: expenses.php');
    exit;
}

$fCat = $_GET['category'] ?? '';
$fPerson = $_GET['person'] ?? '';
$fMonth = $_GET['month'] ?? '';
$where = [];
$params = [];
if ($fCat) { $where[] = "category=?"; $params[] = $fCat; }
if ($fPerson) { $where[] = "paid_by=?"; $params[] = $fPerson; }
if ($fMonth) { $where[] = "DATE_FORMAT(expense_date,'%Y-%m')=?"; $params[] = $fMonth; }
$sql = "SELECT * FROM expenses";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY expense_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

function split_label($e) {
    $other = $e['paid_by'] === 'Ozi' ? 'Ceyda' : 'Ozi';
    if ($e['split_mode'] === 'esit') return '50/50';
    if ($e['split_mode'] === 'tek') return 'Sadece ' . $e['paid_by'];
    return 'Özel: ' . fmt($e['owed_by_other']) . ' (' . $other . ')';
}

$activeTab = 'expenses';
include __DIR__ . '/header.php';
?>

<button class="form-toggle" onclick="document.getElementById('expenseForm').classList.toggle('open')">+ Yeni Gider Ekle</button>
<form method="post" class="form-card" id="expenseForm">
  <input type="hidden" name="action" value="add">
  <div class="form-row">
    <div class="field"><label>Tarih</label><input type="date" name="date" value="<?= date('Y-m-d') ?>"></div>
    <div class="field"><label>Tutar (₺)</label><input type="number" step="0.01" name="amount" required></div>
    <div class="field"><label>Kategori</label>
      <select name="category"><?php foreach ($CATEGORIES as $c): ?><option><?= $c ?></option><?php endforeach; ?></select>
    </div>
    <div class="field"><label>Ödeyen</label>
      <select name="person">
        <option value="Ozi" <?= current_user() === 'Ozi' ? 'selected' : '' ?>>Ozi</option>
        <option value="Ceyda" <?= current_user() === 'Ceyda' ? 'selected' : '' ?>>Ceyda</option>
      </select>
    </div>
    <div class="field"><label>Bölüşüm</label>
      <select name="split_mode" onchange="document.getElementById('ozelField').style.display = this.value==='ozel' ? 'block' : 'none'">
        <option value="esit">Yarı Yarıya (50/50)</option>
        <option value="tek">Sadece Ödeyene Ait</option>
        <option value="ozel">Özel Tutar</option>
      </select>
    </div>
    <div class="field" id="ozelField" style="display:none;"><label>Diğer Kişinin Payı (₺)</label><input type="number" step="0.01" name="ozel_tutar"></div>
  </div>
  <div class="field" style="margin-bottom:12px;"><label>Açıklama</label><textarea name="desc"></textarea></div>
  <div class="form-actions">
    <button class="btn-primary" type="submit">Kaydet</button>
    <button class="btn-ghost" type="button" onclick="document.getElementById('expenseForm').classList.remove('open')">Vazgeç</button>
  </div>
</form>

<div class="panel-box">
  <form method="get" class="filters">
    <select name="category" onchange="this.form.submit()">
      <option value="">Tüm Kategoriler</option>
      <?php foreach ($CATEGORIES as $c): ?><option <?= $fCat === $c ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?>
    </select>
    <select name="person" onchange="this.form.submit()">
      <option value="">Herkes</option>
      <option <?= $fPerson === 'Ozi' ? 'selected' : '' ?>>Ozi</option>
      <option <?= $fPerson === 'Ceyda' ? 'selected' : '' ?>>Ceyda</option>
    </select>
    <input type="month" name="month" value="<?= htmlspecialchars($fMonth) ?>" onchange="this.form.submit()">
    <a class="btn-ghost" href="expenses.php">Filtreleri Temizle</a>
  </form>
  <div style="overflow-x:auto;">
    <table>
      <thead><tr><th>Tarih</th><th>Kategori</th><th>Açıklama</th><th>Ödeyen</th><th>Bölüşüm</th><th style="text-align:right;">Tutar</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= $r['expense_date'] ?></td>
          <td><span class="cat-tag"><?= htmlspecialchars($r['category']) ?></span></td>
          <td><?= $r['description'] ? htmlspecialchars($r['description']) : '<span style="color:var(--paper-dim)">—</span>' ?></td>
          <td><span class="person-tag"><?= htmlspecialchars($r['paid_by']) ?></span></td>
          <td><span class="split-tag"><?= split_label($r) ?></span></td>
          <td class="amount"><?= fmt($r['amount']) ?></td>
          <td>
            <form method="post" onsubmit="return confirm('Bu gider kaydını silmek istediğine emin misin?')">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button class="del-btn" type="submit">✕</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if (!$rows): ?><div class="empty-state">Henüz gider kaydı yok. Yukarıdan ilk kaydı ekle.</div><?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
