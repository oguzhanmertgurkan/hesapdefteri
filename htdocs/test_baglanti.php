<?php
header('Content-Type: text/plain; charset=utf-8');

$url = 'https://query1.finance.yahoo.com/v8/finance/chart/THYAO.IS';
echo "Test URL: $url\n";
echo "Amaç: Bu sunucudan dışarıya (Yahoo Finance) bağlantı kurulabiliyor mu, kontrol ediyoruz.\n\n";

// 1) cURL ile dene
if (function_exists('curl_init')) {
    echo "== cURL testi ==\n";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $result = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo "HTTP Kod: $code\n";
    if ($err) echo "cURL Hatası: $err\n";
    if ($result) {
        echo "BAŞARILI. Yanıttan bir parça:\n" . substr($result, 0, 300) . "\n";
    } else {
        echo "Yanıt alınamadı.\n";
    }
    echo "\n";
} else {
    echo "== cURL bu sunucuda mevcut değil ==\n\n";
}

// 2) file_get_contents ile dene
echo "== file_get_contents testi ==\n";
$ctx = stream_context_create([
    'http' => ['header' => "User-Agent: Mozilla/5.0\r\n", 'timeout' => 10]
]);
$result2 = @file_get_contents($url, false, $ctx);
if ($result2 === false) {
    echo "BAŞARISIZ — dış bağlantı muhtemelen bu hostingde engelli.\n";
    $error = error_get_last();
    if ($error) echo "Hata mesajı: " . $error['message'] . "\n";
} else {
    echo "BAŞARILI. Yanıttan bir parça:\n" . substr($result2, 0, 300) . "\n";
}

echo "\n== Sonuç ==\n";
echo "Yukarıda en az bir 'BAŞARILI' görüyorsan, otomatik fiyat çekme bu hostingte mümkün demektir.\n";
echo "İkisi de 'BAŞARISIZ' ise, bu hosting dış bağlantılara kapalı demektir; alternatif bir yol konuşuruz.\n";
