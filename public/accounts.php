<?php
require_once("../include/bittorrent.php");
$legacyHook = $GLOBALS['hook'];
$legacyPlugin = $GLOBALS['plugin'];
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$GLOBALS['hook'] = $hook = $legacyHook;
$GLOBALS['plugin'] = $plugin = $legacyPlugin;
dbconn();
loggedinorreturn();
if (!user_can('staffmem')) {
    permissiondenied();
}
\Nexus\Database\RuntimeMigrations::ensureAccountRequestsTable();

$types = ['signup' => 'SignUp', 'apply' => 'Apply', 'donation' => 'Donations', 'invite' => 'Invite'];
$type = is_string($_GET['type'] ?? null) ? $_GET['type'] : 'signup';
if (!isset($types[$type])) {
    $type = 'signup';
}
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if ($type === 'invite') {
        stderr('Invalid request', 'Invite registrations cannot be accepted or denied from this page.');
    }
    $requestId = filter_var($_POST['request_id'] ?? null, FILTER_VALIDATE_INT);
    $decision = is_string($_POST['decision'] ?? null) ? $_POST['decision'] : '';
    $token = is_string($_POST['request_token'] ?? null) ? $_POST['request_token'] : '';
    $tokenData = null;
    if ($token !== '') {
        try {
            $tokenData = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($token), true);
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            $tokenData = null;
        }
    }
    if (
        !$requestId
        || !in_array($decision, ['accept', 'deny'], true)
        || !is_array($tokenData)
        || (int) ($tokenData['user_id'] ?? 0) !== (int) $CURUSER['id']
        || (int) ($tokenData['request_id'] ?? 0) !== $requestId
        || (int) ($tokenData['expires_at'] ?? 0) < time()
    ) {
        stderr('Invalid request', 'The account decision could not be verified. Reload the page and try again.');
    }

    $request = \Illuminate\Support\Facades\DB::transaction(function () use ($requestId, $decision) {
        $request = \Illuminate\Support\Facades\DB::table('account_requests')
            ->where('id', $requestId)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->first();
        if (!$request) {
            return null;
        }
        $user = \Illuminate\Support\Facades\DB::table('users')
            ->where('id', $request->user_id)
            ->lockForUpdate()
            ->first();
        if (!$user || $user->status !== 'pending') {
            return null;
        }

        if ($decision === 'accept') {
            $userUpdate = [
                'status' => 'confirmed',
                'enabled' => 'yes',
                'editsecret' => '',
            ];
            if ($request->type === 'donation') {
                $userUpdate['class'] = \App\Models\User::CLASS_VIP;
                $userUpdate['vip_added'] = 'yes';
                $userUpdate['vip_until'] = $request->plan === '30days' ? now()->addDays(30)->toDateTimeString() : null;
            }
            \Illuminate\Support\Facades\DB::table('users')->where('id', $request->user_id)->update($userUpdate);
        } else {
            \Illuminate\Support\Facades\DB::table('users')
                ->where('id', $request->user_id)
                ->update(['enabled' => 'no', 'editsecret' => '']);
        }

        \Illuminate\Support\Facades\DB::table('account_requests')
            ->where('id', $requestId)
            ->update([
                'status' => $decision === 'accept' ? 'accepted' : 'denied',
                'updated_at' => now(),
            ]);

        return $request;
    });

    if (!$request) {
        stderr('Request unavailable', 'This account request is no longer pending.');
    }
    clear_user_cache((int) $request->user_id);
    if ($decision === 'accept') {
        publish_model_event(\App\Enums\ModelEventEnum::USER_UPDATED, (int) $request->user_id);
    }
    write_log("Account request {$requestId} {$decision} by {$CURUSER['username']}.", 'mod');
    header('Location: accounts.php?type=' . rawurlencode($type) . '&q=' . rawurlencode($search));
    exit;
}

if ($type === 'invite') {
    $query = \Illuminate\Support\Facades\DB::table('invites')
        ->join('users', 'users.id', '=', 'invites.invitee_register_uid')
        ->leftJoin('users as inviters', 'inviters.id', '=', 'invites.inviter')
        ->where('invites.invitee', '')
        ->whereNotNull('invites.invitee_register_uid');
    if ($search !== '') {
        $query->where(function ($builder) use ($search) {
            $builder->where('users.username', 'like', '%' . $search . '%')
                ->orWhere('users.email', 'like', '%' . $search . '%');
        });
    }
    $requests = $query->orderByDesc('invites.id')
        ->select([
            'invites.id',
            'invites.hash',
            'users.id as user_id',
            'users.username',
            'users.email',
            'inviters.id as inviter_id',
            'inviters.username as inviter_username',
        ])
        ->paginate(50)
        ->appends(['type' => $type, 'q' => $search]);
} else {
    $query = \Illuminate\Support\Facades\DB::table('account_requests')
        ->join('users', 'users.id', '=', 'account_requests.user_id')
        ->where('account_requests.type', $type)
        ->where('account_requests.status', 'pending');
    if ($search !== '') {
        $query->where(function ($builder) use ($search) {
            $builder->where('users.username', 'like', '%' . $search . '%')
                ->orWhere('users.email', 'like', '%' . $search . '%');
        });
    }
    $requests = $query->orderBy('account_requests.id')
        ->select([
            'account_requests.id',
            'account_requests.user_id',
            'account_requests.plan',
            'account_requests.details',
            'account_requests.txid',
            'account_requests.created_at',
            'users.username',
            'users.email',
        ])
        ->paginate(50)
        ->appends(['type' => $type, 'q' => $search]);
}

stdhead('Account');
begin_main_frame();
print('<h2>Account</h2>');
print('<p class="text">');
foreach ($types as $key => $label) {
    print(($key === $type ? '<b>' . $escape($label) . '</b>' : '<a href="accounts.php?type=' . $escape($key) . '">' . $escape($label) . '</a>') . ' | ');
}
print('</p>');
print('<form method="get" action="accounts.php" style="margin: 12px 0">');
print('<input type="hidden" name="type" value="' . $escape($type) . '">');
print('<label>Search username or email <input type="search" name="q" value="' . $escape($search) . '"></label> ');
print('<button class="btn" type="submit">Search</button></form>');

print('<table class="main" width="100%" border="1" cellspacing="0" cellpadding="5">');
print('<tr><th>Number</th><th>Username</th><th>Email</th>');
if ($type === 'invite') {
    print('<th>Invite Link</th><th>Invited By</th>');
} elseif ($type === 'apply') {
    print('<th>Details</th>');
} elseif ($type === 'donation') {
    print('<th>Plan</th><th>TXID</th>');
}
if ($type !== 'invite') {
    print('<th>Actions</th>');
}
print('</tr>');
$rowNumber = (int) $requests->firstItem();
foreach ($requests as $row) {
    $number = $type === 'invite' ? $rowNumber++ : (int) $row->id;
    print('<tr><td>' . sprintf('%03d', $number) . '</td>');
    print('<td><a href="userdetails.php?id=' . (int) $row->user_id . '">' . $escape($row->username) . '</a></td>');
    print('<td>' . $escape($row->email) . '</td>');
    if ($type === 'invite') {
        $inviteUrl = getSchemeAndHttpHost() . '/youinvited.php/' . rawurlencode($row->hash);
        print('<td><a href="' . $escape($inviteUrl) . '" target="_blank" rel="noopener">' . $escape($inviteUrl) . '</a></td>');
        $inviter = $row->inviter_id
            ? '<a href="userdetails.php?id=' . (int) $row->inviter_id . '">' . $escape((string) $row->inviter_username) . '</a>'
            : '-';
        print('<td>' . $inviter . '</td>');
    } elseif ($type === 'apply') {
        print('<td>' . nl2br($escape((string) $row->details)) . '</td>');
    } elseif ($type === 'donation') {
        $plan = $row->plan === '30days' ? '30 Days' : ($row->plan === 'lifetime' ? 'Lifetime' : '');
        print('<td>' . $escape($plan) . '</td><td>' . $escape((string) $row->txid) . '</td>');
    }
    if ($type === 'invite') {
        print('</tr>');
        continue;
    }
    $requestToken = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
        'user_id' => (int) $CURUSER['id'],
        'request_id' => (int) $row->id,
        'expires_at' => time() + 1800,
    ]));
    print('<td><form method="post" action="accounts.php?type=' . $escape($type) . '&amp;q=' . rawurlencode($search) . '" style="display:inline">');
    print('<input type="hidden" name="request_id" value="' . (int) $row->id . '">');
    print('<input type="hidden" name="request_token" value="' . $escape($requestToken) . '">');
    print('<button class="btn" type="submit" name="decision" value="accept">Accept</button> ');
    print('<button class="btn" type="submit" name="decision" value="deny">Denied</button></form></td></tr>');
}
if ($requests->isEmpty()) {
    $emptyMessage = $type === 'invite' ? 'No registrations from generated invite links found.' : 'No pending account requests found.';
    $columnCount = $type === 'invite' ? 5 : ($type === 'apply' ? 5 : ($type === 'donation' ? 6 : 4));
    print('<tr><td colspan="' . $columnCount . '" class="text">' . $escape($emptyMessage) . '</td></tr>');
}
print('</table>');
print($requests->links());
end_main_frame();
stdfoot();
