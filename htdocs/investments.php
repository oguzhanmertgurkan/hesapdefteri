<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name']);
        $type = $_POST['type'];
        $person = $_POST['person'];
        $ticker = $type === 'Hisse Senedi' ? strtoupper(trim($_POST['ticker'] ?? '')) : null;
        $exchange = $type === 'Hisse Senedi' ? ($_POST['exchange'] ?? 'BIST') : 'BIST';

        if ($type === 'Hisse Senedi') {
            $qty = (float)($_POST['quantity'] ?? 0);
            $priceInput = (float)($_POST['price_per_unit'] ?? 0);
            if ($exchange === 'US') {
                [$rate, $rateErr] = get_usdtry_rate($pdo);
                $amount = ($rate !== null) ? $qty * $priceInput * $rate : 0;
            } else {
                $amount = $qty * $priceInput;
            }
            $price = $priceInput;
        } else {
            $qty = null;
            $price = null;
            $amount = (float)($_POST['amount'] ?? 0);
        }

        if ($name && $amount > 0) {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO investment_positions (name,type,person,ticker,exchange) VALUES (?,?,?,?,?)");
            $stmt->execute([$name, $type, $person, $ticker, $exchange]);
            $posId = $pdo->lastInsertId();
            $stmt2 = $pdo->prepare("INSERT INTO investment_entries (position_id, entry_date, kind, amount, quantity, price_per_unit) VALUES (?,CURDATE(),'yatırım',?,?,?)");
            $stmt2->execute([$posId, $amount, $qty, $price]);
            $pdo->commit();
        }

    } elseif ($action === 'add_entry') {
        $posId = (int)$_POST['position_id'];
        $kind = $_POST['kind'];

        $posStmt = $pdo->prepare("SELECT type, exchange FROM investment_positions WHERE id=?");
        $posStmt->execute([$posId]);
        $pos = $posStmt->fetch();
        $posType = $pos['type'] ?? '';
        $exchange = $pos['exchange'] ?? 'BIST';

        if ($posType === 'Hisse Senedi') {
            if ($kind === 'değer güncelleme') {
                $priceInput = (float)($_POST['price_per_unit'] ?? 0);
                $qtyStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN kind='yatırım' THEN quantity WHEN kind='çekim' THEN -quantity ELSE 0 END),0) q FROM investment_entries WHERE position_id=?");
                $qtyStmt->execute([$posId]);
                $runningQty = (float)$qtyStmt->fetch()['q'];
                if ($exchange === 'US') {
                    [$rate, $rateErr] = get_usdtry_rate($pdo);
                    $amount = ($rate !== null) ? $runningQty * $priceInput * $rate : 0;
                } else {
                    $amount = $runningQty * $priceInput;
                }
                if ($priceInput > 0 && $runningQty > 0 && $amount > 0) {
                    $stmt = $pdo->prepare("INSERT INTO investment_entries (position_id, entry_date, kind, amount, quantity, price_per_unit) VALUES (?,CURDATE(),?,?,?,?)");
                    $stmt->execute([$posId, $kind, $amount, $runningQty, $priceInput]);
                }
            } else {
                $qty = (float)($_POST['quantity'] ?? 0);
                $priceInput = (float)($_POST['price_per_unit'] ?? 0);
                if ($exchange === 'US') {
                    [$rate, $rateErr] = get_usdtry_rate($pdo);
                    $amount = ($rate !== null) ? $qty * $priceInput * $rate : 0;
                } else {
                    $amount = $qty * $priceInput;
                }
                if ($qty > 0 && $priceInput > 0 && $amount > 0) {
                    $stmt = $pdo->prepare("INSERT INTO investment_entries (position_id, entry_date, kind, amount, quantity, price_per_unit) VALUES (?,CURDATE(),?,?,?,?)");
                    $stmt->execute([$posId, $kind, $amount, $qty, $priceInput]);
                }
            }
        } else {
            $amount = (float)($_POST['amount'] ?? 0);
            if ($amount > 0) {
                $stmt = $pdo->prepare("INSERT INTO investment_entries (position_id, entry_date, kind, amount) VALUES (?,CURDATE(),?,?)");
                $stmt->execute([$posId, $kind, $amount]);
            }
        }

    } elseif ($action === 'set_ticker') {
        $posId = (int)$_POST['position_id'];
        $ticker = strtoupper(trim($_POST['ticker'] ?? ''));
        $exchange = $_POST['exchange'] ?? 'BIST';
        $stmt = $pdo->prepare("UPDATE investment_positions SET ticker=?, exchange=? WHERE id=?");
        $stmt->execute([$ticker, $exchange, $posId]);

    } elseif ($action === 'refresh_price') {
        $posId = (int)$_POST['position_id'];
        $posStmt = $pdo->prepare("SELECT ticker, exchange FROM investment_positions WHERE id=?");
        $posStmt->execute([$posId]);
        $pos = $posStmt->fetch();
        $ticker = $pos['ticker'] ?? '';
        $exchange = $pos['exchange'] ?? 'BIST';
        if ($ticker) {
            $qtyStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN kind='yatırım' THEN quantity WHEN kind='çekim' THEN -quantity ELSE 0 END),0) q FROM investment_entries WHERE position_id=?");
            $qtyStmt->execute([$posId]);
            $runningQty = (float)$qtyStmt->fetch()['q'];
            [$price, $err] = get_cached_price($pdo, $ticker, $exchange);
            $rate = 1;
            if ($price !== null && $exchange === 'US') {
                [$rate, $rateErr] = get_usdtry_rate($pdo);
            }
            if ($price !== null && $runningQty > 0 && $rate !== null) {
                $amount = $runningQty * $price * $rate;
                $stmt = $pdo->prepare("INSERT INTO investment_entries (position_id, entry_date, kind, amount, quantity, price_per_unit) VALUES (?,CURDATE(),'değer güncelleme',?,?,?)");
                $stmt->execute([$posId, $amount, $runningQty, $price]);
                $_SESSION['flash'] = "Fiyat güncellendi: " . ($exchange === 'US' ? fmt_usd($price) : fmt($price)) . " (" . $ticker . ")";
            } else {
                $_SESSION['flash'] = "Fiyat güncellenemedi" . ($err ? ": $err" : '.');
            }
        }

    } elseif ($action === 'delete_position') {
        $posId = (int)$_POST['position_id'];
        $stmt = $pdo->prepare("DELETE FROM investment_positions WHERE id=?");
        $stmt->execute([$posId]);
    }
    header('Location: investments.php');
    exit;
}

$autoCandidates = $pdo->query("SELECT * FROM investment_positions WHERE type='Hisse Senedi' AND ticker IS NOT NULL AND ticker <> ''")->fetchAll();
foreach ($autoCandidates as $sp) {
    $chk = $pdo->prepare("SELECT COUNT(*) c FROM investment_entries WHERE position_id=? AND entry_date=CURDATE()");
    $chk->execute([$sp['id']]);
    if ($chk->fetch()['c'] > 0) continue;

    $qtyStmt = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN kind='yatırım' THEN quantity WHEN kind='çekim' THEN -quantity ELSE 0 END),0) q FROM investment_entries WHERE position_id=?");
    $qtyStmt->execute([$sp['id']]);
    $runningQty = (float)$qtyStmt->fetch()['q'];
    if ($runningQty <= 0) continue;

    [$price, $err] = get_cached_price($pdo, $sp['ticker'], $sp['exchange']);
    if ($price === null) continue;

    $rate = 1;
    if ($sp['exchange'] === 'US') {
        [$rate, $rateErr] = get_usdtry_rate($pdo);
    }
    if ($rate === null) continue;

    $amount = $runningQty * $price * $rate;
    $ins = $pdo->prepare("INSERT INTO investment_entries (position_id, entry_date, kind, amount, quantity, price_per_unit) VALUES (?,CURDATE(),'değer güncelleme',?,?,?)");
    $ins->execute([$sp['id'], $amount, $runningQty, $price]);
}

$positions = $pdo->query("SELECT * FROM investment_positions ORDER BY name")->fetchAll();
foreach ($positions as &$p) {
    $es = $pdo->prepare("SELECT * FROM investment_entries WHERE position_id=? ORDER BY entry_date DESC, id DESC");
    $es->execute([$p['id']]);
    $p['entries'] = $es->fetchAll();
    [$inv, $cur, $ret] = calc_position_totals($p['entries']);
    $p['invested'] = $inv;
    $p['current'] = $cur;
    $p['returnPct'] = $ret;
    if ($p['type'] === 'Hisse Senedi') {
        $runningQty = 0;
        foreach ($p['entries'] as $e) {
            if ($e['kind'] === 'yatırım') $runningQty += (float)$e['quantity'];
            elseif ($e['kind'] === 'çekim') $runningQty -= (float)$e['quantity'];
        }
        $p['runningQty'] = $runningQty;
    }
}
unset($p);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$activeTab = 'investments';
$loadChart = true;
include __DIR__ . '/header.php';
?>

<?php if ($flash): ?>
  <div class="panel-box" style="padding:14px 18px; margin-bottom:16px; border-color:var(--gold);"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>

<button class="form-toggle" onclick="document.getElementById('positionForm').classList.toggle('open')">+ Yeni Yatırım / Birikim Kalemi</button>
<form method="post" class="form-card" id="positionForm">
  <input type="hidden" name="action" value="create">
  <div class="form-row">
    <div class="field"><label>Kalem Adı</label><input type="text" name="name" placeholder="Örn. Ziraat Altın Hesabı" required></div>
    <div class="field"><label>Tür</label>
      <select name="type" id="posType" onchange="toggleCreateFields()">
        <option>Altın</option><option>Döviz</option><option>Hisse Senedi</option>
        <option>Fon</option><option>Mevduat</option><option>Kripto Para</option><option>Diğer</option>
      </select>
    </div>
    <div class="field"><label>Açan Kişi</label><select name="person"><option>Ozi</option><option>Ceyda</option></select></div>
    <div class="field" id="createAmountField"><label>İlk Yatırım Tutarı (₺)</label><input type="number" step="0.01" name="amount" id="createAmount" required></div>
    <div class="field" id="createExchangeField" style="display:none;"><label>Borsa</label>
      <select name="exchange" id="createExchange">
        <option value="BIST">BIST (Türkiye)</option>
        <option value="US">NASDAQ / NYSE (ABD)</option>
      </select>
    </div>
    <div class="field" id="createTickerField" style="display:none;"><label>Sembol</label><input type="text" name="ticker" id="createTicker" placeholder="Örn. THYAO ya da AAPL"></div>
    <div class="field" id="createQtyField" style="display:none;"><label>Adet</label><input type="number" step="0.0001" name="quantity" id="createQty"></div>
    <div class="field" id="createPriceField" style="display:none;"><label>Birim Fiyat</label><input type="number" step="0.0001" name="price_per_unit" id="createPrice"></div>
  </div>
  <div class="form-actions">
    <button class="btn-primary" type="submit">Oluştur</button>
    <button class="btn-ghost" type="button" onclick="document.getElementById('positionForm').classList.remove('open')">Vazgeç</button>
  </div>
</form>

<?php if (!$positions): ?>
  <div class="empty-state">Henüz bir yatırım/birikim kalemi yok. Yukarıdan ilk kalemi oluştur.</div>
<?php endif; ?>

<?php foreach ($positions as $p): $cls = $p['returnPct'] >= 0 ? 'pos' : 'neg'; $timeline = calc_position_timeline($p['entries']); $isStock = $p['type'] === 'Hisse Senedi'; $isUS = $isStock && $p['exchange'] === 'US'; ?>
  <div class="invest-card">
    <div class="invest-head">
      <div class="invest-title">
        <h4><?= htmlspecialchars($p['name']) ?></h4>
        <span class="type-tag"><?= htmlspecialchars($p['type']) ?></span>
        <?php if ($isStock && $p['ticker']): ?>
          <span class="split-tag"><?= htmlspecialchars($p['ticker']) ?><?= $isUS ? '' : '.IS' ?> · <?= $isUS ? 'NASDAQ/NYSE' : 'BIST' ?></span>
        <?php endif; ?>
        <span class="person-tag"><?= htmlspecialchars($p['person']) ?></span>
      </div>
      <span class="return-badge <?= $cls ?>"><?= ($p['returnPct'] >= 0 ? '+' : '') . number_format($p['returnPct'], 2) ?>% (toplam)</span>
    </div>
    <div class="invest-stats">
      <?php if ($isStock): ?>
        <div class="mini">Elde Tutulan Adet<span class="v"><?= fmt_qty($p['runningQty']) ?></span></div>
      <?php endif; ?>
      <div class="mini">Yatırılan<span class="v"><?= fmt($p['invested']) ?></span></div>
      <div class="mini">Güncel Değer<span class="v"><?= fmt($p['current']) ?></span></div>
      <div class="mini">Kâr/Zarar<span class="v" style="color:<?= $p['current'] - $p['invested'] >= 0 ? 'var(--teal)' : 'var(--brick)' ?>"><?= fmt($p['current'] - $p['invested']) ?></span></div>
    </div>

    <?php if ($isStock && !$p['ticker']): ?>
      <form method="post" style="display:flex; gap:8px; align-items:center; margin-bottom:12px; background:var(--surface-2); padding:10px; border-radius:8px; flex-wrap:wrap;">
        <input type="hidden" name="action" value="set_ticker">
        <input type="hidden" name="position_id" value="<?= $p['id'] ?>">
        <span class="person-tag" style="white-space:nowrap;">Otomatik fiyat için:</span>
        <select name="exchange" style="background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:6px 8px;font-size:12.5px;">
          <option value="BIST">BIST</option>
          <option value="US">NASDAQ/NYSE</option>
        </select>
        <input type="text" name="ticker" placeholder="Örn. THYAO / AAPL" style="width:110px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:6px 8px;font-size:12.5px;">
        <button class="btn-primary" type="submit" style="padding:6px 12px;font-size:12.5px;">Kaydet</button>
      </form>
    <?php elseif ($isStock && $p['ticker']): ?>
      <form method="post" style="display:inline-block; margin-bottom:10px;">
        <input type="hidden" name="action" value="refresh_price">
        <input type="hidden" name="position_id" value="<?= $p['id'] ?>">
        <button class="btn-ghost" type="submit" style="padding:7px 14px;font-size:12.5px;">↻ Şimdi Güncelle</button>
      </form>
    <?php endif; ?>

    <?php if (count($timeline) >= 2): ?>
      <div class="chart-wrap" style="height:140px; margin-bottom:14px;"><canvas id="spark-<?= $p['id'] ?>"></canvas></div>
      <script>
        new Chart(document.getElementById('spark-<?= $p['id'] ?>'), {
          type: 'line',
          data: {
            labels: <?= json_encode(array_map(fn($pt) => $pt['date'], $timeline)) ?>,
            datasets: [{
              data: <?= json_encode(array_map(fn($pt) => $pt['value'], $timeline)) ?>,
              borderColor: '#c9a227', backgroundColor: 'rgba(201,162,39,0.12)',
              fill: true, tension: 0.25, pointRadius: 3, pointBackgroundColor: '#c9a227'
            }]
          },
          options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { ticks: { callback: v => '₺' + v } }, x: { grid: { display: false } } }
          }
        });
      </script>
    <?php endif; ?>

    <?php if ($isStock): ?>
      <form method="post" class="entry-form" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:6px;">
        <input type="hidden" name="action" value="add_entry">
        <input type="hidden" name="position_id" value="<?= $p['id'] ?>">
        <select name="kind" id="kind-<?= $p['id'] ?>" onchange="toggleStockEntry(<?= $p['id'] ?>)" style="background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:7px 8px;font-size:12.5px;">
          <option value="yatırım">Yatırım Ekle (Hisse Al)</option>
          <option value="değer güncelleme">Fiyat Güncelle (Elle)</option>
          <option value="çekim">Çekim Yap (Hisse Sat)</option>
        </select>
        <input type="number" name="quantity" step="0.0001" placeholder="Adet" id="qty-<?= $p['id'] ?>" style="width:100px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:7px 8px;font-size:12.5px;">
        <input type="number" name="price_per_unit" step="0.0001" placeholder="<?= $isUS ? 'Birim Fiyat $' : 'Birim Fiyat ₺' ?>" style="width:130px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:7px 8px;font-size:12.5px;">
        <button class="btn-primary" type="submit" style="padding:7px 14px;font-size:12.5px;">Ekle</button>
      </form>
      <script>
        function toggleStockEntry(id){
          document.getElementById('qty-'+id).style.display =
            document.getElementById('kind-'+id).value === 'değer güncelleme' ? 'none' : 'inline-block';
        }
      </script>
    <?php else: ?>
      <form method="post" class="entry-form" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:6px;">
        <input type="hidden" name="action" value="add_entry">
        <input type="hidden" name="position_id" value="<?= $p['id'] ?>">
        <select name="kind" style="background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:7px 8px;font-size:12.5px;">
          <option value="yatırım">Yatırım Ekle</option>
          <option value="değer güncelleme">Değer Güncelle</option>
          <option value="çekim">Çekim Yap</option>
        </select>
        <input type="number" name="amount" step="0.01" placeholder="₺ tutar" style="width:120px;background:var(--ink);border:1px solid var(--line);color:var(--paper);border-radius:6px;padding:7px 8px;font-size:12.5px;">
        <button class="btn-primary" type="submit" style="padding:7px 14px;font-size:12.5px;">Ekle</button>
      </form>
    <?php endif; ?>

    <form method="post" onsubmit="return confirm('&quot;<?= htmlspecialchars($p['name']) ?>&quot; kalemini tüm hareketleriyle birlikte silmek istediğine emin misin?')" style="display:inline;">
      <input type="hidden" name="action" value="delete_position">
      <input type="hidden" name="position_id" value="<?= $p['id'] ?>">
      <button class="del-btn" type="submit">✕ Kalemi Sil</button>
    </form>

    <details class="entry-list" style="margin-top:10px;">
      <summary class="small-link" style="cursor:pointer;">Hareketleri göster (<?= count($p['entries']) ?>)</summary>
      <?php
      $pctByKey = [];
      foreach ($timeline as $pt) { $pctByKey[$pt['date'] . '|' . $pt['value']] = $pt['pct']; }
      foreach ($p['entries'] as $e):
        $key = $e['entry_date'] . '|' . $e['amount'];
        $pct = ($e['kind'] === 'değer güncelleme' && isset($pctByKey[$key])) ? $pctByKey[$key] : null;
        if ($isStock && $e['quantity'] !== null) {
            $priceStr = $isUS ? fmt_usd($e['price_per_unit']) : fmt($e['price_per_unit']);
            $detail = fmt_qty($e['quantity']) . ' adet @ ' . $priceStr . ' = ' . fmt($e['amount']);
        } else {
            $detail = fmt($e['amount']);
        }
      ?>
        <div class="entry-row">
          <span class="et"><?= $e['entry_date'] ?> — <?= $e['kind'] ?></span>
          <span>
            <span class="mono"><?= $detail ?></span>
            <?php if ($pct !== null): ?>
              <span class="mono" style="color:<?= $pct >= 0 ? 'var(--teal)' : 'var(--brick)' ?>; margin-left:8px;"><?= ($pct >= 0 ? '+' : '') . number_format($pct, 2) ?>%</span>
            <?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </details>
  </div>
<?php endforeach; ?>

<script>
function toggleCreateFields(){
  var isStock = document.getElementById('posType').value === 'Hisse Senedi';
  document.getElementById('createAmountField').style.display = isStock ? 'none' : 'block';
  document.getElementById('createQtyField').style.display = isStock ? 'block' : 'none';
  document.getElementById('createPriceField').style.display = isStock ? 'block' : 'none';
  document.getElementById('createTickerField').style.display = isStock ? 'block' : 'none';
  document.getElementById('createExchangeField').style.display = isStock ? 'block' : 'none';
  document.getElementById('createAmount').required = !isStock;
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
