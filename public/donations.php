<?php
require_once("../include/bittorrent.php");
dbconn();
require_once(get_langfile_path());

$wallets = [
    'LTC' => [
        'name' => 'Litecoin (LTC)',
        'address' => 'ltc1q48zfgvtc0f0sjkkw5ndvd5n4aqz0qansstx8ac',
        'uri' => 'litecoin:',
    ],
    'XMR' => [
        'name' => 'Monero (XMR)',
        'address' => '49Jjbyvf8ECY3bL72JGrcKaCuGQhW8msoe8sHdfrJtmU4CRVEgW5FMYGiSPmJ1WYUW4rf12MBMqsKCHYAH9fSb9rQziw5oL',
        'uri' => 'monero:',
    ],
];
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
$orderResult = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'order') {
    failedloginscheck('Donation Signup');
    if ($iv === 'yes') {
        check_code($_POST['imagehash'] ?? null, $_POST['imagestring'] ?? null, 'donations.php');
    }
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $emailInput = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $plan = is_string($_POST['plan'] ?? null) ? $_POST['plan'] : '';
    if (!in_array($plan, ['30days', 'lifetime'], true)) {
        stderr('Invalid order', 'Choose a valid VIP plan.');
    }
    if (!validusername($username)) {
        stderr('Invalid username', 'Usernames must be 3-20 letters or numbers.');
    }
    if (mb_strlen($password) < 6 || mb_strlen($password) > 40) {
        stderr('Invalid password', 'Password must be between 6 and 40 characters.');
    }
    $email = safe_email(htmlspecialchars($emailInput, ENT_QUOTES, 'UTF-8'));
    if (!check_email($email) || !validemail($email)) {
        stderr('Invalid email', 'Enter a valid email address.');
    }
    if (EmailBanned($email) || !EmailAllowed($email)) {
        stderr('Invalid email', 'This email address cannot be used.');
    }
    if (
        \Illuminate\Support\Facades\DB::table('users')->where('username', $username)->exists()
        || \Illuminate\Support\Facades\DB::table('users')->where('email', $email)->exists()
    ) {
        stderr('Account unavailable', 'That username or email address is already in use.');
    }
    if ((int) \Illuminate\Support\Facades\DB::table('users')->count() >= (int) $maxusers) {
        stderr('Account limit reached', 'The site cannot accept additional accounts right now.');
    }

    $secret = mksecret();
    $passwordHash = hash('sha256', $secret . hash('sha256', $password));
    $authKey = hash('sha256', mksecret(32));
    $userId = \Illuminate\Support\Facades\DB::transaction(function () use ($username, $email, $plan, $secret, $passwordHash, $authKey) {
        $now = date('Y-m-d H:i:s');
        $userId = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
            'username' => $username,
            'passhash' => $passwordHash,
            'passkey' => md5($username . $now . $passwordHash),
            'secret' => $secret,
            'auth_key' => $authKey,
            'editsecret' => '',
            'email' => $email,
            'country' => 8,
            'gender' => 'N/A',
            'status' => 'pending',
            'class' => (int) get_setting('authority.defaultclass', \App\Models\User::CLASS_USER),
            'invites' => (int) get_setting('main.invite_count', 0),
            'added' => $now,
            'last_access' => $now,
            'lang' => (int) get_langid_from_langcookie(),
            'stylesheet' => (int) get_setting('main.defstylesheet', 1),
            'uploaded' => max(0, (int) get_setting('main.iniupload')),
        ]);
        \Illuminate\Support\Facades\DB::table('account_requests')->insert([
            'user_id' => $userId,
            'type' => 'donation',
            'plan' => $plan,
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $userId;
    });
    $userInfo = \App\Models\User::query()->find($userId, \App\Models\User::$commonFields);
    fire_event('user_created', $userInfo);
    $orderResult = ['username' => $username, 'plan' => $plan, 'user_id' => $userId];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'txid') {
    $username = trim(is_string($_POST['username'] ?? null) ? $_POST['username'] : '');
    $email = trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : '');
    $txid = trim(is_string($_POST['txid'] ?? null) ? $_POST['txid'] : '');
    if ($username === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/\A[a-fA-F0-9]{64}\z/', $txid)) {
        stderr('Invalid report', 'Enter your username, valid email address, and 64-character TXID.');
    }

    $reportAccepted = \Illuminate\Support\Facades\DB::transaction(function () use ($username, $email, $txid) {
        if (\Illuminate\Support\Facades\DB::table('account_requests')->where('txid', strtolower($txid))->exists()) {
            return false;
        }
        $request = \Illuminate\Support\Facades\DB::table('account_requests')
            ->join('users', 'users.id', '=', 'account_requests.user_id')
            ->where('account_requests.type', 'donation')
            ->where('account_requests.status', 'pending')
            ->where('users.username', $username)
            ->where('users.email', $email)
            ->select('account_requests.id')
            ->lockForUpdate()
            ->first();
        if (!$request) {
            return false;
        }
        $updated = \Illuminate\Support\Facades\DB::table('account_requests')
            ->where('id', $request->id)
            ->whereNull('txid')
            ->update(['txid' => strtolower($txid), 'updated_at' => now()]);

        return $updated === 1;
    });
    if (!$reportAccepted) {
        stderr('Report unavailable', 'No pending donation request was found for those details, or a TXID was already submitted.');
    }
    $reportSubmitted = true;
}

stdhead('Donations');
begin_main_frame();
print('<h2>VIP account donation</h2>');
print('<p class="text">30 Days &mdash; 30 USD<br>Lifetime &mdash; 250 USD</p>');

if ($orderResult !== null) {
    print('<p class="text">Account request recorded for <b>' . $escape($orderResult['username']) . '</b>. No email verification is required; staff must approve the account after checking your payment.</p>');
    print('<h3>Send payment to one of these addresses</h3>');
    print('<p class="text">Selected plan: ' . ($orderResult['plan'] === '30days' ? '30 Days (30 USD)' : 'Lifetime (250 USD)') . '</p>');
    foreach ($wallets as $wallet) {
        print('<p class="text"><b>' . $escape($wallet['name']) . ':</b> <a href="' . $escape($wallet['uri'] . $wallet['address']) . '">' . $escape($wallet['address']) . '</a></p>');
    }
    print('<p><a href="login.php">Back to login</a></p>');
} elseif (!empty($reportSubmitted)) {
    print('<p class="text">Your TXID has been sent to staff for review.</p>');
    print('<p><a href="login.php">Back to login</a></p>');
} elseif (isset($_GET['report'])) {
    print('<h3>Already donated? Send your TXID</h3>');
    print('<form method="post" action="donations.php?report=1">');
    print('<input type="hidden" name="action" value="txid">');
    print('<p><label>Username <input type="text" name="username" required autocomplete="username"></label></p>');
    print('<p><label>Email <input type="email" name="email" required autocomplete="email"></label></p>');
    print('<p><label>TXID <input type="text" name="txid" minlength="64" maxlength="64" pattern="[A-Fa-f0-9]{64}" required></label></p>');
    print('<p><button class="btn" type="submit">Send</button></p></form>');
} else {
    print('<p class="text">Create a pending account request. Payment addresses will appear after you order; account access requires staff approval.</p>');
    print('<form method="post" action="donations.php">');
    print('<input type="hidden" name="action" value="order">');
    print('<table border="0" cellpadding="5">');
    print('<tr><td class="rowhead">Username</td><td><input type="text" name="username" required minlength="3" maxlength="20" autocomplete="username"></td></tr>');
    print('<tr><td class="rowhead">Password</td><td><input type="password" name="password" required minlength="6" maxlength="40" autocomplete="new-password"></td></tr>');
    print('<tr><td class="rowhead">Email</td><td><input type="email" name="email" required autocomplete="email"></td></tr>');
    show_image_code();
    print('<tr><td>30 Days &mdash; 30 USD</td><td><button class="btn" type="submit" name="plan" value="30days">Order</button></td></tr>');
    print('<tr><td>Lifetime &mdash; 250 USD</td><td><button class="btn" type="submit" name="plan" value="lifetime">Order</button></td></tr>');
    print('</table></form>');
}
end_main_frame();
stdfoot();
