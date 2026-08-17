<?php
function fmt($n) {
    return '₺' . number_format((float)$n, 2, ',', '.');
}

function fmt_usd($n) {
    return '$' . number_format((float)$n, 2, '.', ',');
}

function fmt_qty($q) {
    $s = rtrim(rtrim(number_format((float)$q, 4, ',', '.'), '0'), ',');
    return $s === '' ? '0' : $s;
}

// entries: array of ['entry_date'=>..,'kind'=>..,'amount'=>..]
// returns [invested, current, returnPct]  — amount her zaman ₺ cinsindendir
function calc_position_totals($entries) {
    $invested = 0;
    $lastValue = null;
    usort($entries, fn($a, $b) => strcmp($a['entry_date'], $b['entry_date']));
    foreach ($entries as $e) {
        if ($e['kind'] === 'yatırım') {
            $invested += $e['amount'];
        } elseif ($e['kind'] === 'çekim') {
            $invested -= $e['amount'];
        } elseif ($e['kind'] === 'değer güncelleme') {
            $lastValue = $e['amount'];
        }
    }
    $current = $lastValue !== null ? $lastValue : $invested;
    $returnPct = $invested > 0 ? (($current - $invested) / $invested * 100) : 0;
    return [$invested, $current, $returnPct];
}

// Kronolojik "değer kontrol noktaları" listesi: her kontrolde bir önceki
// kontrole göre yüzde kaç değiştiğini hesaplar (günlük/dönemsel getiri).
function calc_position_timeline($entries) {
    usort($entries, fn($a, $b) => strcmp($a['entry_date'], $b['entry_date']));
    $points = [];
    $investedRunning = 0;
    foreach ($entries as $e) {
        if ($e['kind'] === 'yatırım') {
            $investedRunning += $e['amount'];
            if (empty($points)) {
                $points[] = ['date' => $e['entry_date'], 'value' => $investedRunning, 'pct' => null];
            }
        } elseif ($e['kind'] === 'çekim') {
            $investedRunning -= $e['amount'];
        } elseif ($e['kind'] === 'değer güncelleme') {
            $prev = end($points);
            $pct = ($prev && $prev['value'] > 0) ? (($e['amount'] - $prev['value']) / $prev['value'] * 100) : null;
            $points[] = ['date' => $e['entry_date'], 'value' => $e['amount'], 'pct' => $pct];
        }
    }
    return $points;
}

// Yahoo Finance'ten ham bir sembol için anlık fiyat çeker (borsa eki eklemeden).
function fetch_yahoo_symbol($symbol) {
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol);
    $price = null;
    $err = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $result = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($result && $code === 200) {
            $data = json_decode($result, true);
            $price = $data['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
        } else {
            $err = "Bağlantı hatası (HTTP $code)";
        }
    } else {
        $ctx = stream_context_create(['http' => ['header' => "User-Agent: Mozilla/5.0\r\n", 'timeout' => 8]]);
        $result = @file_get_contents($url, false, $ctx);
        if ($result) {
            $data = json_decode($result, true);
            $price = $data['chart']['result'][0]['meta']['regularMarketPrice'] ?? null;
        } else {
            $err = 'Bağlantı başarısız';
        }
    }
    if ($price === null && !$err) $err = 'Sembol bulunamadı (' . $symbol . ')';
    return [$price !== null ? (float)$price : null, $price !== null ? null : $err];
}

function yahoo_symbol_for($ticker, $exchange) {
    $ticker = strtoupper(trim($ticker));
    return $exchange === 'US' ? $ticker : $ticker . '.IS';
}

function fetch_yahoo_price($ticker, $exchange = 'BIST') {
    return fetch_yahoo_symbol(yahoo_symbol_for($ticker, $exchange));
}

function get_cached_value($pdo, $cacheKey, $fetchFn, $maxAgeMinutes = 15) {
    $stmt = $pdo->prepare("SELECT price, fetched_at FROM price_cache WHERE ticker=?");
    $stmt->execute([$cacheKey]);
    $row = $stmt->fetch();
    if ($row && (time() - strtotime($row['fetched_at'])) / 60 < $maxAgeMinutes) {
        return [(float)$row['price'], null];
    }
    [$price, $err] = $fetchFn();
    if ($price !== null) {
        $stmt = $pdo->prepare("REPLACE INTO price_cache (ticker, price, fetched_at) VALUES (?,?,NOW())");
        $stmt->execute([$cacheKey, $price]);
        return [$price, null];
    }
    if ($row) return [(float)$row['price'], 'Güncel veri alınamadı, önbellekteki son değer kullanıldı.'];
    return [null, $err];
}

function get_cached_price($pdo, $ticker, $exchange = 'BIST', $maxAgeMinutes = 15) {
    $ticker = strtoupper(trim($ticker));
    if (!$ticker) return [null, 'Sembol tanımlı değil'];
    $cacheKey = $exchange . ':' . $ticker;
    return get_cached_value($pdo, $cacheKey, function () use ($ticker, $exchange) {
        return fetch_yahoo_price($ticker, $exchange);
    }, $maxAgeMinutes);
}

function get_usdtry_rate($pdo, $maxAgeMinutes = 15) {
    return get_cached_value($pdo, 'USDTRY', function () {
        return fetch_yahoo_symbol('TRY=X');
    }, $maxAgeMinutes);
}

// ---------------------------------------------------------------
// Sabit ödemeler (abonelikler, kira vb.) ve harcama grupları
// ---------------------------------------------------------------

// Her kategori hangi "kova"ya (market / sabit / kişisel) ait?
// Burada olmayan her kategori otomatik olarak 'kisisel' sayılır.
function category_group($category) {
    $map = [
        'Market' => 'market',
        'Kira / Fatura' => 'sabit',
        'Abonelik' => 'sabit',
    ];
    return $map[$category] ?? 'kisisel';
}

// Aktif sabit ödemeleri, bu ay için (eksikse) ve ayın 15'inden itibaren
// bir sonraki ay için de (eksikse) otomatik olarak gider tablosuna ekler.
function run_recurring_generation($pdo) {
    $templates = $pdo->query("SELECT * FROM recurring_expenses WHERE active = 1")->fetchAll();
    if (!$templates) return;

    $targetMonths = [date('Y-m')];
    if ((int)date('j') >= 15) {
        $targetMonths[] = date('Y-m', strtotime('+1 month'));
    }

    foreach ($targetMonths as $targetMonth) {
        $targetDate = $targetMonth . '-01';
        foreach ($templates as $t) {
            $chk = $pdo->prepare("SELECT COUNT(*) c FROM expenses WHERE recurring_id=? AND DATE_FORMAT(expense_date,'%Y-%m')=?");
            $chk->execute([$t['id'], $targetMonth]);
            if ($chk->fetch()['c'] > 0) continue;

            $amount = (float)$t['amount'];
            $owed = 0;
            if ($t['split_mode'] === 'esit') $owed = $amount / 2;
            elseif ($t['split_mode'] === 'ozel') $owed = min(max((float)$t['ozel_tutar'], 0), $amount);

            $ins = $pdo->prepare("INSERT INTO expenses (expense_date, amount, category, description, paid_by, split_mode, owed_by_other, recurring_id) VALUES (?,?,?,?,?,?,?,?)");
            $ins->execute([$targetDate, $amount, $t['category'], $t['name'] . ' (sabit ödeme)', $t['person'], $t['split_mode'], $owed, $t['id']]);
        }
    }
}

// Belirli bir ay (YYYY-MM) için market / sabit / kişisel harcama toplamlarını döner.
function get_expense_bucket_totals($pdo, $month) {
    $stmt = $pdo->prepare("SELECT category, SUM(amount) s FROM expenses WHERE DATE_FORMAT(expense_date,'%Y-%m')=? GROUP BY category");
    $stmt->execute([$month]);
    $totals = ['market' => 0, 'sabit' => 0, 'kisisel' => 0];
    foreach ($stmt->fetchAll() as $row) {
        $totals[category_group($row['category'])] += (float)$row['s'];
    }
    return $totals;
}
