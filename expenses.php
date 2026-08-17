<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$CATEGORIES = ["Market", "Gıda", "Kira / Fatura", "Abonelik", "Ulaşım", "Sağlık", "Giyim", "Eğlence", "Eğitim", "Tatil", "Diğer"];

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

    } elseif ($action === 'add_recurring') {
        $name = trim($_POST['name']);
        $category = $_POST['category'];
        $amount = (float)($_POST['amount'] ?? 0);
        $person = $_POST['person'];
        $splitMode = $_POST['split_mode'];
        if ($name && $amount > 0) {
            $stmt = $pdo->prepare("INSERT INTO recurring_expenses (name, category, amount, person, split_mode) VALUES (?,?,?,?,?)");
            $stmt->execute([$name, $category, $amount, $person, $splitMode]);
        }
    } elseif ($action === 'update_recurring_amount') {
        $id = (int)$_POST['id'];
        $amount = (float)($_POST['amount'] ?? 0);
        if ($amount > 0) {
            $stmt = $pdo->prepare("UPDATE recurring_expenses SET amount=? WHERE id=?");
            $stmt->execute([$amount, $id]);
        }
    } elseif ($action === 'delete_recurring') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM recurring_expenses WHERE id=?");
        $stmt->execute([$id]);
    }
    header('Location: expenses.php');
    exit;
}

run_recurring_generation($pdo);

$recurringList = $pdo->query("SELECT * FROM recurring_expenses ORDER BY name")->fetchAll();

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
  <h3>Sabit Ödemeler / Abonelikler</h3>
  <p style="font-size:12.5px; color:var(--paper-dim); margin:-6px 0 16px 0; line-height:1.5;">
    Her ayın 15'inden itibaren, burada tanımlı aktif sabit ödemeler bir sonraki ay için otomatik olarak gider listesine eklenir.
    Fiyat değişirse (örn. zam gelirse) aşağıdan tutarı güncelle — o andan itibaren yeni tutar kullanılır, geçmiş kayıtlar değişmez.
  </p>

  <form method="post" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
    <input type="hidden" name="action" value="add_recurring">
    <input type="text" name="name" placeholder="Örn. Kira, Netflix, F1TV" required style="width:150px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
    <select name="category" style="background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
      <?php foreach ($CATEGORIES as $c): ?><option <?= $c === 'Abonelik' ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?>
    </select>
    <input type="number" step="0.01" name="amount" placeholder="₺ tutar" required style="width:110px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
    <select name="person" style="background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
      <option>Ozi</option><option>Ceyda</option>
    </select>
    <select name="split_mode" style="background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
      <option value="esit">Yarı Yarıya</option>
      <option value="tek">Sadece Ödeyene Ait</option>
    </select>
    <button class="btn-primary" type="submit" style="padding:8px 16px;font-size:13px;">+ Ekle</button>
  </form>

  <?php if (!$recurringList): ?>
    <div class="empty-state" style="padding:20px;">Henüz sabit ödeme tanımlanmadı.</div>
  <?php else: ?>
    <div style="overflow-x:auto;">
      <table>
        <thead><tr><th>Ad</th><th>Kategori</th><th>Ödeyen</th><th>Bölüşüm</th><th style="text-align:right;">Tutar</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recurringList as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><span class="cat-tag"><?= htmlspecialchars($r['category']) ?></span></td>
            <td><span class="person-tag"><?= htmlspecialchars($r['person']) ?></span></td>
            <td><span class="split-tag"><?= $r['split_mode'] === 'esit' ? '50/50' : 'Sadece ' . $r['person'] ?></span></td>
            <td class="amount">
              <form method="post" style="display:flex; gap:6px; justify-content:flex-end;">
                <input type="hidden" name="action" value="update_recurring_amount">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <input type="number" step="0.01" name="amount" value="<?= $r['amount'] ?>" style="width:90px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:5px 8px;font-size:12.5px;">
                <button class="btn-ghost" type="submit" style="padding:5px 10px;font-size:12px;">Güncelle</button>
              </form>
            </td>
            <td>
              <form method="post" onsubmit="return confirm('&quot;<?= htmlspecialchars($r['name']) ?>&quot; sabit ödemesini silmek istediğine emin misin? Geçmiş kayıtlar etkilenmez, sadece gelecekteki otomatik ekleme durur.')">
                <input type="hidden" name="action" value="delete_recurring">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <button class="del-btn" type="submit">✕</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

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
          <td>
            <?= $r['description'] ? htmlspecialchars($r['description']) : '<span style="color:var(--paper-dim)">—</span>' ?>
            <?php if ($r['recurring_id']): ?><span class="split-tag" style="margin-left:4px;">otomatik</span><?php endif; ?>
          </td>
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
