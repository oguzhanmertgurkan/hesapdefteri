<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

$CATEGORIES = ["Market", "Gıda", "Kira", "Fatura", "Abonelik", "Ulaşım", "Sağlık", "Giyim", "Eğlence", "Eğitim", "Tatil", "Diğer"];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $date = $_POST['date'] ?: date('Y-m-d');
        $amount = (float)$_POST['amount'];
        $category = $_POST['category'];
        $person = $_POST['person'];
        $splitMode = $_POST['split_mode'];
        $desc = trim($_POST['desc'] ?? '');
        $paymentMethod = $_POST['payment_method'] ?? 'nakit';
        $creditCardId = ($paymentMethod === 'kredi_karti') ? (int)($_POST['credit_card_id'] ?? 0) : null;
        $owed = 0;
        if ($splitMode === 'esit') $owed = $amount / 2;
        elseif ($splitMode === 'ozel') $owed = min(max((float)($_POST['ozel_tutar'] ?? 0), 0), $amount);

        $cutoffDay = null;
        $dueDay = null;
        if ($creditCardId) {
            $cStmt = $pdo->prepare("SELECT cutoff_day, due_day FROM credit_cards WHERE id=?");
            $cStmt->execute([$creditCardId]);
            $cardRow = $cStmt->fetch();
            $cutoffDay = $cardRow['cutoff_day'] ?? null;
            $dueDay = $cardRow['due_day'] ?? null;
        }
        $budgetMonth = compute_budget_month($date, $paymentMethod, $cutoffDay, $dueDay);

        if ($amount > 0) {
            $stmt = $pdo->prepare("INSERT INTO expenses (expense_date, amount, category, description, paid_by, payment_method, credit_card_id, split_mode, owed_by_other, budget_month) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->execute([$date, $amount, $category, $desc, $person, $paymentMethod, $creditCardId ?: null, $splitMode, $owed, $budgetMonth]);
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
        $category = $_POST['category'] ?? null;
        if ($amount > 0) {
            if ($category) {
                $stmt = $pdo->prepare("UPDATE recurring_expenses SET amount=?, category=? WHERE id=?");
                $stmt->execute([$amount, $category, $id]);
                // Bu ay için zaten otomatik oluşmuş gider kaydı, oluşturulduğu andaki
                // (belki o zaman yanlış olan) kategoriyi taşımaya devam eder — şablon
                // düzeltildiğinde bu ayki kaydı da senkronize et ki grafiklerde eski
                // kategoriyle görünmeye devam etmesin. Geçmiş aylara dokunulmaz,
                // tutar bilerek senkronize edilmez (o zaten ayrı bir davranış).
                $syncStmt = $pdo->prepare("UPDATE expenses SET category=? WHERE recurring_id=? AND budget_month=?");
                $syncStmt->execute([$category, $id, date('Y-m')]);
            } else {
                $stmt = $pdo->prepare("UPDATE recurring_expenses SET amount=? WHERE id=?");
                $stmt->execute([$amount, $id]);
            }
        }
    } elseif ($action === 'delete_recurring') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM recurring_expenses WHERE id=?");
        $stmt->execute([$id]);

    } elseif ($action === 'add_card') {
        $name = trim($_POST['card_name']);
        $person = $_POST['card_person'];
        $cutoffDay = (int)($_POST['cutoff_day'] ?? 0);
        $dueDay = (int)($_POST['due_day'] ?? 0);
        if ($name && $cutoffDay >= 1 && $cutoffDay <= 28 && $dueDay >= 1 && $dueDay <= 28) {
            $stmt = $pdo->prepare("INSERT INTO credit_cards (person, name, cutoff_day, due_day) VALUES (?,?,?,?)");
            $stmt->execute([$person, $name, $cutoffDay, $dueDay]);
        }
    } elseif ($action === 'delete_card') {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("DELETE FROM credit_cards WHERE id=?");
        $stmt->execute([$id]);
    }
    header('Location: expenses.php');
    exit;
  } catch (Throwable $e) {
    http_response_code(200);
    echo "<div style='background:#1b2422;color:#eee7d8;font-family:monospace;padding:24px;'>";
    echo "<h2 style='color:#c0564b;'>Bir hata oluştu</h2>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p style='color:#b9b2a1;font-size:13px;'>" . htmlspecialchars($e->getFile()) . " (satır " . $e->getLine() . ")</p>";
    echo "<p><a href='expenses.php' style='color:#e0c25f;'>Giderler sayfasına dön</a></p>";
    echo "</div>";
    exit;
  }
}

try {
    run_recurring_generation($pdo);
} catch (Throwable $e) { /* geçiş henüz çalıştırılmamış olabilir, sayfa yine de açılsın */ }

try {
    $recurringList = $pdo->query("SELECT * FROM recurring_expenses ORDER BY name")->fetchAll();
} catch (Throwable $e) { $recurringList = []; }
$recurringTotal = array_sum(array_column($recurringList, 'amount'));

try {
    $cardList = $pdo->query("SELECT * FROM credit_cards ORDER BY person, name")->fetchAll();
} catch (Throwable $e) { $cardList = []; }

$fCat = $_GET['category'] ?? '';
$fPerson = $_GET['person'] ?? '';
$fMonth = $_GET['month'] ?? '';
$where = ["recurring_id IS NULL"];
$params = [];
if ($fCat) { $where[] = "category=?"; $params[] = $fCat; }
if ($fPerson) { $where[] = "paid_by=?"; $params[] = $fPerson; }
if ($fMonth) { $where[] = "budget_month=?"; $params[] = $fMonth; }
$sql = "SELECT * FROM expenses";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);
$sql .= " ORDER BY expense_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$cardsById = [];
foreach ($cardList as $c) { $cardsById[$c['id']] = $c; }

function split_label($e) {
    $other = $e['paid_by'] === 'Ozi' ? 'Ceyda' : 'Ozi';
    if ($e['split_mode'] === 'esit') return '50/50';
    if ($e['split_mode'] === 'tek') return 'Sadece ' . $e['paid_by'];
    return 'Özel: ' . fmt($e['owed_by_other']) . ' (' . $other . ')';
}

$catLabel = $fCat ?: 'Tüm Kategoriler';
$personLabel = $fPerson ?: 'Herkes';
$monthLabel = $fMonth ? date('F Y', strtotime($fMonth . '-01')) : 'Tüm Zamanlar';

$activeTab = 'expenses';
include __DIR__ . '/header.php';
?>

<button class="form-toggle" onclick="document.getElementById('expenseForm').classList.toggle('open')">+ Yeni Gider Ekle</button>
<form method="post" class="form-card" id="expenseForm">
  <input type="hidden" name="action" value="add">
  <div class="form-row">
    <div class="field"><label>Tarih</label><input type="date" name="date" id="expDate" value="<?= date('Y-m-d') ?>" onchange="updateBudgetPreview()"></div>
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
    <div class="field"><label>Ödeme Yöntemi</label>
      <select name="payment_method" id="paymentMethod" onchange="document.getElementById('cardField').style.display = this.value==='kredi_karti' ? 'block' : 'none'; updateBudgetPreview();">
        <option value="nakit">Nakit / Banka Kartı</option>
        <option value="kredi_karti">Kredi Kartı</option>
      </select>
    </div>
    <div class="field" id="cardField" style="display:none;"><label>Kredi Kartı</label>
      <select name="credit_card_id" id="creditCardSelect" onchange="updateBudgetPreview()">
        <?php foreach ($cardList as $c): ?>
          <option value="<?= $c['id'] ?>" data-cutoff="<?= $c['cutoff_day'] ?>" data-due="<?= $c['due_day'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= htmlspecialchars($c['person']) ?>, kesim: <?= $c['cutoff_day'] ?>, son ödeme: <?= $c['due_day'] ?: '?' ?>)</option>
        <?php endforeach; ?>
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
  <?php if (!$cardList): ?>
    <p style="font-size:12px; color:var(--paper-dim); margin:-6px 0 12px 0;">Henüz kredi kartın yok — "Kredi Kartlarım" panelinden ekleyebilirsin.</p>
  <?php endif; ?>
  <p id="budgetPreview" style="font-size:12.5px; color:var(--gold-soft); margin:-6px 0 12px 0; display:none;"></p>
  <div class="field" style="margin-bottom:12px;"><label>Açıklama</label><textarea name="desc"></textarea></div>
  <div class="form-actions">
    <button class="btn-primary" type="submit">Kaydet</button>
    <button class="btn-ghost" type="button" onclick="document.getElementById('expenseForm').classList.remove('open')">Vazgeç</button>
  </div>
</form>

<script>
const monthNames = ["Ocak","Şubat","Mart","Nisan","Mayıs","Haziran","Temmuz","Ağustos","Eylül","Ekim","Kasım","Aralık"];
function updateBudgetPreview(){
  const method = document.getElementById('paymentMethod').value;
  const preview = document.getElementById('budgetPreview');
  const dateVal = document.getElementById('expDate').value;
  if (!dateVal) { preview.style.display = 'none'; return; }
  const d = new Date(dateVal + 'T00:00:00');

  if (method === 'kredi_karti') {
    const sel = document.getElementById('creditCardSelect');
    const opt = sel.options[sel.selectedIndex];
    const cutoff = opt ? parseInt(opt.dataset.cutoff, 10) : null;
    const due = opt ? parseInt(opt.dataset.due, 10) : null;
    if (!cutoff) { preview.style.display = 'none'; return; }

    // 1) hangi kesim ayına giriyor
    let cutoffMonth = new Date(d.getFullYear(), d.getMonth(), 1);
    if (d.getDate() > cutoff) cutoffMonth.setMonth(cutoffMonth.getMonth() + 1);

    if (due) {
      // 2) son ödeme hangi aya denk geliyor
      let dueMonth = new Date(cutoffMonth.getFullYear(), cutoffMonth.getMonth(), 1);
      if (due <= cutoff) dueMonth.setMonth(dueMonth.getMonth() + 1);
      const lastDay = new Date(dueMonth.getFullYear(), dueMonth.getMonth() + 1, 0).getDate();
      let dueDate = new Date(dueMonth.getFullYear(), dueMonth.getMonth(), Math.min(due, lastDay));
      // 3) hafta sonu düzeltmesi
      const wd = dueDate.getDay(); // 0=Paz, 6=Cmt
      if (wd === 6) dueDate.setDate(dueDate.getDate() + 2);
      if (wd === 0) dueDate.setDate(dueDate.getDate() + 1);
      preview.textContent = "Bu harcama " + monthNames[dueDate.getMonth()] + " " + dueDate.getFullYear() + " bütçesine sayılacak (son ödeme: " + dueDate.getDate() + " " + monthNames[dueDate.getMonth()] + ").";
    } else {
      preview.textContent = "Bu harcama " + monthNames[cutoffMonth.getMonth()] + " " + cutoffMonth.getFullYear() + " bütçesine sayılacak (bu kart için son ödeme günü henüz girilmemiş, kesim ayı kullanılıyor).";
    }
    preview.style.display = 'block';
  } else {
    preview.textContent = "Bu harcama " + monthNames[d.getMonth()] + " " + d.getFullYear() + " bütçesine sayılacak.";
    preview.style.display = 'block';
  }
}
document.getElementById('expenseForm').addEventListener('transitionend', updateBudgetPreview);
document.querySelector('.form-toggle').addEventListener('click', () => setTimeout(updateBudgetPreview, 50));
</script>

<div class="panel-box">
  <h3>Kredi Kartlarım</h3>
  <p style="font-size:12.5px; color:var(--paper-dim); margin:-6px 0 14px 0; line-height:1.5;">
    Her kart için hesap kesim gününü ve son ödeme gününü tanımla. Sistem harcamanın hangi ayın "son ödeme"sine düştüğünü (yani paranın gerçekten hangi ay hesaptan çıkacağını) otomatik hesaplar — hafta sonuna denk gelen son ödemeler otomatik olarak bir sonraki pazartesiye kayar.
  </p>
  <form method="post" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:16px;">
    <input type="hidden" name="action" value="add_card">
    <input type="text" name="card_name" placeholder="Örn. Axess, Ozi Vakıf" required style="width:150px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
    <select name="card_person" style="background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
      <option>Ozi</option><option>Ceyda</option><option>Ortak</option>
    </select>
    <input type="number" name="cutoff_day" min="1" max="28" placeholder="Kesim günü (1-28)" required style="width:150px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
    <input type="number" name="due_day" min="1" max="28" placeholder="Son ödeme günü (1-28)" required style="width:160px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:8px 10px;font-size:13px;">
    <button class="btn-primary" type="submit" style="padding:8px 16px;font-size:13px;">+ Ekle</button>
  </form>
  <?php if (!$cardList): ?>
    <div class="empty-state" style="padding:20px;">Henüz kart tanımlanmadı.</div>
  <?php else: ?>
    <div style="overflow-x:auto;">
      <table>
        <thead><tr><th>Kart</th><th>Kişi</th><th>Kesim Günü</th><th>Son Ödeme Günü</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($cardList as $c): ?>
          <tr>
            <td><?= htmlspecialchars($c['name']) ?></td>
            <td><span class="person-tag"><?= htmlspecialchars($c['person']) ?></span></td>
            <td><?= $c['cutoff_day'] ?></td>
            <td><?= $c['due_day'] ?: '—' ?></td>
            <td>
              <form method="post" onsubmit="return confirm('&quot;<?= htmlspecialchars($c['name']) ?>&quot; kartını silmek istediğine emin misin? Geçmiş kayıtlar etkilenmez.')">
                <input type="hidden" name="action" value="delete_card">
                <input type="hidden" name="id" value="<?= $c['id'] ?>">
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
  <h3>Sabit Ödemeler / Abonelikler</h3>
  <?php if ($recurringList): ?>
    <p style="font-size:13.5px; color:var(--paper-dim); margin:-6px 0 12px 0;">Aylık Toplam: <span class="mono" style="color:var(--gold-soft); font-weight:600; font-size:15px;"><?= fmt($recurringTotal) ?></span> <span style="font-size:12px;">(<?= count($recurringList) ?> kalem)</span></p>
  <?php endif; ?>
  <p style="font-size:12.5px; color:var(--paper-dim); margin:-6px 0 16px 0; line-height:1.5;">
    Her ayın 15'inden itibaren, burada tanımlı aktif sabit ödemeler o ay için otomatik olarak gider listesine eklenir.
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
        <thead><tr><th>Ad</th><th>Ödeyen</th><th>Bölüşüm</th><th style="text-align:right;">Kategori / Tutar</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($recurringList as $r): ?>
          <tr>
            <td><?= htmlspecialchars($r['name']) ?></td>
            <td><span class="person-tag"><?= htmlspecialchars($r['person']) ?></span></td>
            <td><span class="split-tag"><?= $r['split_mode'] === 'esit' ? '50/50' : 'Sadece ' . $r['person'] ?></span></td>
            <td class="amount">
              <form method="post" style="display:flex; gap:6px; justify-content:flex-end; flex-wrap:wrap;">
                <input type="hidden" name="action" value="update_recurring_amount">
                <input type="hidden" name="id" value="<?= $r['id'] ?>">
                <?php $isLegacyCat = !in_array($r['category'], $CATEGORIES, true); ?>
                <select name="category" style="background:var(--ink);border:1px solid var(--line);color:<?= $isLegacyCat ? '#e0736b' : 'var(--paper)' ?>;border-radius:6px;padding:5px 6px;font-size:12px;" <?= $isLegacyCat ? 'title="Bu, artık kullanılmayan eski bir kategori. Listeden doğru kategoriyi seçip Güncelle\'ye basmadan önce dikkatli ol."' : '' ?>>
                  <?php if ($isLegacyCat): ?><option value="<?= htmlspecialchars($r['category']) ?>" selected>⚠ <?= htmlspecialchars($r['category']) ?> (eski)</option><?php endif; ?>
                  <?php foreach ($CATEGORIES as $c): ?><option <?= $c === $r['category'] ? 'selected' : '' ?>><?= $c ?></option><?php endforeach; ?>
                </select>
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
  <h3>Gider Kayıtları</h3>
  <p style="font-size:12px; color:var(--paper-dim); margin:-6px 0 14px 0;">Sabit ödemeler burada listelenmez, onları yukarıdaki "Sabit Ödemeler / Abonelikler" panelinden yönetebilirsin. Ay filtresi, kredi kartı harcamalarında <b>hesap kesim gününe göre hesaplanan bütçe ayını</b> baz alır — satın alma tarihini değil.</p>
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
  <p style="font-size:12px; color:var(--paper-dim); margin:0 0 14px 0;">
    Gösterilen: <span class="cat-tag"><?= htmlspecialchars($catLabel) ?></span>
    <span class="person-tag" style="margin-left:6px;"><?= htmlspecialchars($personLabel) ?></span>
    <span class="split-tag" style="margin-left:6px;"><?= htmlspecialchars($monthLabel) ?></span>
  </p>
  <div style="overflow-x:auto;">
    <table>
      <thead><tr><th>Tarih</th><th>Kategori</th><th>Açıklama</th><th>Ödeme</th><th>Ödeyen</th><th>Bölüşüm</th><th style="text-align:right;">Tutar</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <?php
        $purchaseMonth = substr($r['expense_date'], 0, 7);
        $shifted = $r['budget_month'] !== $purchaseMonth;
        $card = $r['credit_card_id'] ? ($cardsById[$r['credit_card_id']] ?? null) : null;
        ?>
        <tr>
          <td>
            <?= $r['recurring_id'] ? '<span style="color:var(--paper-dim);">—</span>' : $r['expense_date'] ?>
            <?php if ($shifted): ?><br><span class="split-tag" style="margin-top:4px; display:inline-block;">→ <?= date('M Y', strtotime($r['budget_month'] . '-01')) ?>'a sayılıyor</span><?php endif; ?>
          </td>
          <td><span class="cat-tag"><?= htmlspecialchars($r['category']) ?></span></td>
          <td>
            <?= $r['description'] ? htmlspecialchars($r['description']) : '<span style="color:var(--paper-dim)">—</span>' ?>
            <?php if ($r['recurring_id']): ?><span class="split-tag" style="margin-left:4px;">sabit</span><?php endif; ?>
          </td>
          <td>
            <?php if ($r['payment_method'] === 'kredi_karti'): ?>
              <span class="type-tag"><?= $card ? htmlspecialchars($card['name']) : 'Kredi Kartı' ?></span>
            <?php else: ?>
              <span class="person-tag">Nakit/Banka</span>
            <?php endif; ?>
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
