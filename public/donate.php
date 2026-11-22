<?php
require "../include/bittorrent.php";
$legacyHook = $GLOBALS['hook'];
$legacyPlugin = $GLOBALS['plugin'];
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$GLOBALS['hook'] = $hook = $legacyHook;
$GLOBALS['plugin'] = $plugin = $legacyPlugin;
dbconn();
require_once(get_langfile_path());

if ($enabledonation != 'yes') {
    stderr($lang_donate['std_sorry'], $lang_donate['std_do_not_accept_donation']);
}

$wallets = [
    'XMR' => [
        'name' => 'Monero',
        'address' => '49Jjbyvf8ECY3bL72JGrcKaCuGQhW8msoe8sHdfrJtmU4CRVEgW5FMYGiSPmJ1WYUW4rf12MBMqsKCHYAH9fSb9rQziw5oL',
        'uri' => 'monero:',
        'setting' => 'misc.donation_monero_qr',
    ],
    'LTC' => [
        'name' => 'Litecoin',
        'address' => 'ltc1q48zfgvtc0f0sjkkw5ndvd5n4aqz0qansstx8ac',
        'uri' => 'litecoin:',
        'setting' => 'misc.donation_litecoin_qr',
    ],
];

$custom = trim((string) \App\Models\Setting::getByName('misc.donation_custom'));
$qrImages = [];
foreach ($wallets as $coin => $wallet) {
    $path = \App\Models\Setting::getByName($wallet['setting']);
    if (is_string($path) && $path !== '' && \Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
        $qrImages[$coin] = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    }
}
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

stdhead($lang_donate['head_donation']);
begin_main_frame();
print("<h2>" . $lang_donate['text_donate'] . "</h2>");
print("<div class=\"text\">" . $lang_donate['text_donation_note'] . "</div>");
if ($custom !== '') {
    print('<div class="text">' . format_comment($custom) . '</div>');
}

print('<div style="display:flex;flex-wrap:wrap;gap:24px;margin:20px 0">');
foreach ($wallets as $coin => $wallet) {
    print('<section class="text" style="flex:1;min-width:280px;padding:16px;border:1px solid #888">');
    print('<h3>' . $escape($wallet['name']) . '</h3>');
    if (isset($qrImages[$coin])) {
        print('<p><img src="' . $escape($qrImages[$coin]) . '" alt="' . $escape($wallet['name']) . ' QR code" style="max-width:220px;width:100%;height:auto"></p>');
    }
    print('<p><a href="' . $escape($wallet['uri'] . $wallet['address']) . '">' . $escape($wallet['address']) . '</a></p>');
    print('</section>');
}
print('</div>');

end_main_frame();
stdfoot();
