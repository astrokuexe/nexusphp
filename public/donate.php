<?php
require "../include/bittorrent.php";
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    loggedinorreturn();

    $reportToken = $_POST['report_token'] ?? '';
    $tokenData = null;
    if (is_string($reportToken) && $reportToken !== '') {
        try {
            $tokenData = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($reportToken), true);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            $tokenData = null;
        }
    }

    if (
        !is_array($tokenData)
        || (int) ($tokenData['user_id'] ?? 0) !== (int) $CURUSER['id']
        || (int) ($tokenData['expires_at'] ?? 0) < time()
    ) {
        stderr($lang_donate['std_error'] ?? 'Error', 'The donation report form expired. Please reload the page and try again.');
    }

    $coinInput = $_POST['coin'] ?? null;
    $amountInput = $_POST['amount'] ?? null;
    $transactionIdInput = $_POST['transaction_id'] ?? null;
    if (!is_string($coinInput) || !is_string($amountInput) || !is_string($transactionIdInput)) {
        stderr($lang_donate['std_error'] ?? 'Error', 'Enter a valid coin, positive amount, and 64-character transaction ID.');
    }

    $coin = strtoupper(trim($coinInput));
    $amount = trim($amountInput);
    $transactionId = trim($transactionIdInput);
    if (!isset($wallets[$coin]) || !\App\Services\DonationReport::isValid($coin, $amount, $transactionId)) {
        stderr($lang_donate['std_error'] ?? 'Error', 'Enter a valid coin, positive amount, and 64-character transaction ID.');
    }

    if (
        get_user_class() < UC_MODERATOR
        && !empty($CURUSER['last_staffmsg'])
        && strtotime($CURUSER['last_staffmsg']) > TIMENOW - 60
    ) {
        $seconds = 60 - (TIMENOW - strtotime($CURUSER['last_staffmsg']));
        stderr($lang_donate['std_error'] ?? 'Error', "Please wait {$seconds} seconds before sending another staff message.");
    }

    $coinName = $wallets[$coin]['name'];
    $subject = "Donation report: {$coinName}";
    $message = "Donation report\n"
        . "Coin: {$coinName} ({$coin})\n"
        . "Amount: {$amount}\n"
        . "Transaction ID: " . strtolower($transactionId);
    \App\Models\StaffMessage::add((int) $CURUSER['id'], $subject, $message);
    sql_query("UPDATE users SET last_staffmsg = NOW() WHERE id = " . sqlesc($CURUSER['id'])) or sqlerr(__FILE__, __LINE__);
    $Cache->delete_value('staff_message_count');
    $Cache->delete_value('staff_new_message_count');
    clear_staff_message_cache();

    stderr($lang_donate['std_success'] ?? 'Success', 'Your report was sent to the staff for manual verification. Donations are not credited automatically.');
}

$custom = trim((string) \App\Models\Setting::getByName('misc.donation_custom'));
$qrImages = [];
foreach ($wallets as $coin => $wallet) {
    $path = \App\Models\Setting::getByName($wallet['setting']);
    if (is_string($path) && $path !== '' && \Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
        $qrImages[$coin] = \Illuminate\Support\Facades\Storage::disk('public')->url($path);
    }
}

$reportToken = '';
if (!empty($CURUSER)) {
    $reportToken = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
        'user_id' => (int) $CURUSER['id'],
        'expires_at' => time() + 1800,
    ]));
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

if (!empty($CURUSER)) {
    print('<h3>Report a donation</h3>');
    print('<p class="text">After sending a donation, submit its transaction ID for staff verification. Reports do not automatically credit your account.</p>');
    print('<form method="post" action="donate.php">');
    print('<input type="hidden" name="report_token" value="' . $escape($reportToken) . '">');
    print('<p><label>Coin <select name="coin" required><option value="XMR">Monero (XMR)</option><option value="LTC">Litecoin (LTC)</option></select></label></p>');
    print('<p><label>Amount <input name="amount" type="text" inputmode="decimal" maxlength="32" required></label></p>');
    print('<p><label>Transaction ID <input name="transaction_id" type="text" minlength="64" maxlength="64" pattern="[A-Fa-f0-9]{64}" required></label></p>');
    print('<p><button type="submit">Send donation report</button></p>');
    print('</form>');
} else {
    print('<p class="text">Log in to report a donation for staff verification.</p>');
}

end_main_frame();
stdfoot();
