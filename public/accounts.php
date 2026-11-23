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
if ($type === 'invite') {
    \Nexus\Database\RuntimeMigrations::ensureInviteRegistrationRequestsTable();
}
$search = trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : '');
$escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
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
        || !in_array($decision, ['accept', 'accept_with_mail', 'deny'], true)
        || !is_array($tokenData)
        || (int) ($tokenData['user_id'] ?? 0) !== (int) $CURUSER['id']
        || (int) ($tokenData['request_id'] ?? 0) !== $requestId
        || ($tokenData['type'] ?? '') !== $type
        || (int) ($tokenData['expires_at'] ?? 0) < time()
    ) {
        stderr('Invalid request', 'The account decision could not be verified. Reload the page and try again.');
    }

    if ($decision === 'accept_with_mail') {
        $pendingRequest = \Nexus\Account\AccountApproval::getPendingRequest($type, $requestId);
        if (!$pendingRequest) {
            stderr('Request unavailable', 'This account request is no longer pending.');
        }

        $approvalToken = \Nexus\Account\AccountApproval::createToken($type, $requestId, $pendingRequest['email']);
        $approvalUrl = getSchemeAndHttpHost() . '/account-approval.php?token=' . rawurlencode($approvalToken);
        $username = htmlspecialchars($pendingRequest['username'], ENT_QUOTES, 'UTF-8');
        $requestDescription = $type === 'donation'
            ? 'your ' . htmlspecialchars((string) $pendingRequest['plan'], ENT_QUOTES, 'UTF-8') . ' VIP account request'
            : 'your account request';
        $emailBody = sprintf(
            '<p>Hello %s,</p><p>Staff is ready to approve %s. Open the link below and confirm to activate your account.</p><p><a href="%s">Review and approve account</a></p><p>This link expires in 7 days and can only be used while the request is pending.</p>',
            $username,
            $requestDescription,
            htmlspecialchars($approvalUrl, ENT_QUOTES, 'UTF-8')
        );
        $emailSent = sent_mail(
            $pendingRequest['email'],
            $SITENAME,
            $SITEEMAIL,
            $SITENAME . ' - Account approval',
            $emailBody,
            'account approval',
            false,
            false,
            ''
        );
        if ($emailSent !== true) {
            stderr('Email could not be sent', 'The approval link was not sent. Check the email configuration and try again.', false);
        }

        write_log("Account approval link sent for {$type} request {$requestId} by {$CURUSER['username']}.", 'mod');
        header('Location: accounts.php?type=' . rawurlencode($type) . '&q=' . rawurlencode($search) . '&mail_sent=1');
        exit;
    }

    if ($decision === 'deny') {
        if (!\Nexus\Account\AccountApproval::deny($type, $requestId)) {
            stderr('Request unavailable', 'This account request is no longer pending.');
        }
        write_log("Account request {$requestId} denied by {$CURUSER['username']}.", 'mod');
        header('Location: accounts.php?type=' . rawurlencode($type) . '&q=' . rawurlencode($search));
        exit;
    }

    if ($type === 'invite') {
        try {
            $review = \Illuminate\Support\Facades\DB::transaction(function () use ($requestId, $defaultclass_class, $invite_count) {
                $request = \Illuminate\Support\Facades\DB::table('invite_registration_requests')
                    ->where('id', $requestId)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();
                if (!$request) {
                    return null;
                }

                $invite = \Illuminate\Support\Facades\DB::table('invites')
                    ->where('id', $request->invite_id)
                    ->lockForUpdate()
                    ->first();
                if (!$invite) {
                    return null;
                }

                $usernameOrEmailExists = \Illuminate\Support\Facades\DB::table('users')
                    ->where('username', $request->username)
                    ->orWhere('email', $request->email)
                    ->exists();
                if ($usernameOrEmailExists) {
                    throw new \DomainException('The requested username or email is now in use.');
                }

                $now = now()->toDateTimeString();
                $userId = \Illuminate\Support\Facades\DB::table('users')->insertGetId([
                    'username' => $request->username,
                    'email' => $request->email,
                    'passhash' => $request->passhash,
                    'secret' => $request->secret,
                    'auth_key' => bin2hex(random_bytes(32)),
                    'editsecret' => '',
                    'status' => \App\Models\User::STATUS_CONFIRMED,
                    'class' => (int) $defaultclass_class,
                    'invites' => (int) $invite_count,
                    'invited_by' => (int) $invite->inviter,
                    'added' => $now,
                    'last_access' => $now,
                    'ip' => $request->ip,
                    'lang' => (int) $request->lang,
                    'stylesheet' => (int) $request->stylesheet,
                    'uploaded' => (int) getsize_int(100, 'G'),
                    'passkey' => md5($request->username . $now . $request->passhash),
                ]);

                \Illuminate\Support\Facades\DB::table('invites')
                    ->where('id', $invite->id)
                    ->update([
                        'valid' => \App\Models\Invite::VALID_NO,
                        'invitee_register_uid' => $userId,
                        'invitee_register_email' => $request->email,
                        'invitee_register_username' => $request->username,
                    ]);

                \Illuminate\Support\Facades\DB::table('invite_registration_requests')
                    ->where('id', $requestId)
                    ->update([
                        'status' => 'accepted',
                        'passhash' => '',
                        'secret' => '',
                        'updated_at' => now(),
                    ]);

                return [
                    'request' => $request,
                    'user_id' => $userId,
                    'inviter_id' => (int) $invite->inviter,
                ];
            });
        } catch (\DomainException $exception) {
            stderr('Cannot accept invite registration', htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'), false);
        }

        if (!$review) {
            stderr('Request unavailable', 'This invite registration is no longer pending.');
        }
        $userInfo = \App\Models\User::query()->findOrFail($review['user_id']);
        fire_event('user_created', $userInfo);

        $temporaryInviteCount = (int) get_setting('main.tmp_invite_count');
        if ($temporaryInviteCount > 0) {
            (new \App\Repositories\UserRepository())->addTemporaryInvite(null, $review['user_id'], 'increment', $temporaryInviteCount, 7);
        }

        \App\Models\Message::add([
            'sender' => 0,
            'receiver' => $review['user_id'],
            'subject' => \App\Models\Setting::getSiteName() . ' registration approved',
            'added' => now()->toDateTimeString(),
            'msg' => 'Your invite registration has been approved and your account is active.',
        ]);
        \App\Models\Message::add([
            'sender' => 0,
            'receiver' => $review['inviter_id'],
            'subject' => \App\Models\Setting::getSiteName() . ' invite registration approved',
            'added' => now()->toDateTimeString(),
            'msg' => $userInfo->username . ' has had their invite registration approved.',
        ]);
        $Cache->delete_value('user_' . $review['inviter_id'] . '_unread_message_count');
        $Cache->delete_value('user_' . $review['inviter_id'] . '_inbox_count');
        write_log("Invite registration request {$requestId} {$decision} by {$CURUSER['username']}.", 'mod');
        header('Location: accounts.php?type=' . rawurlencode($type) . '&q=' . rawurlencode($search));
        exit;
    }

    $request = \Illuminate\Support\Facades\DB::transaction(function () use ($requestId) {
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

        $userUpdate = [
            'status' => 'confirmed',
            'enabled' => 'yes',
            'editsecret' => '',
        ];
        if ($request->type === 'donation') {
            $uploadGigabytes = match ($request->plan) {
                '30days' => 50,
                'lifetime' => 500,
                default => null,
            };
            if ($uploadGigabytes === null) {
                return null;
            }
            $userUpdate['class'] = \App\Models\User::CLASS_VIP;
            $userUpdate['vip_added'] = 'yes';
            $userUpdate['vip_until'] = $request->plan === '30days' ? now()->addDays(30)->toDateTimeString() : null;
            $userUpdate['uploaded'] = (int) getsize_int($uploadGigabytes, 'G');
        } elseif ($request->type === 'apply') {
            $userUpdate['uploaded'] = (int) getsize_int(50, 'G');
        }
        \Illuminate\Support\Facades\DB::table('users')->where('id', $request->user_id)->update($userUpdate);

        \Illuminate\Support\Facades\DB::table('account_requests')
            ->where('id', $requestId)
            ->update([
                'status' => 'accepted',
                'updated_at' => now(),
            ]);

        return $request;
    });

    if (!$request) {
        stderr('Request unavailable', 'This account request is no longer pending.');
    }
    clear_user_cache((int) $request->user_id);
    clear_user_cache((int) $request->user_id);
    publish_model_event(\App\Enums\ModelEventEnum::USER_UPDATED, (int) $request->user_id);
    write_log("Account request {$requestId} accepted by {$CURUSER['username']}.", 'mod');
    header('Location: accounts.php?type=' . rawurlencode($type) . '&q=' . rawurlencode($search));
    exit;
}

if ($type === 'invite') {
    $applicationQuery = \Illuminate\Support\Facades\DB::table('invite_registration_requests as requests')
        ->join('invites', 'invites.id', '=', 'requests.invite_id')
        ->leftJoin('users as inviters', 'inviters.id', '=', 'invites.inviter')
        ->where('requests.status', 'pending');
    if ($search !== '') {
        $applicationQuery->where(function ($builder) use ($search) {
            $builder->where('requests.username', 'like', '%' . $search . '%')
                ->orWhere('requests.email', 'like', '%' . $search . '%');
        });
    }
    $applicationQuery->select([
            'requests.id',
            'invites.hash',
            'requests.username',
            'requests.email',
            'inviters.id as inviter_id',
            'inviters.username as inviter_username',
            \Illuminate\Support\Facades\DB::raw('NULL as user_id'),
            \Illuminate\Support\Facades\DB::raw('1 as reviewable'),
            'requests.created_at',
        ]);
    $legacyQuery = \Illuminate\Support\Facades\DB::table('invites')
        ->join('users', 'users.id', '=', 'invites.invitee_register_uid')
        ->leftJoin('users as inviters', 'inviters.id', '=', 'invites.inviter')
        ->where('invites.invitee', '')
        ->whereNotNull('invites.invitee_register_uid')
        ->whereNotExists(function ($query) {
            $query->select(\Illuminate\Support\Facades\DB::raw('1'))
                ->from('invite_registration_requests as reviewed_requests')
                ->whereColumn('reviewed_requests.invite_id', 'invites.id');
        });
    if ($search !== '') {
        $legacyQuery->where(function ($builder) use ($search) {
            $builder->where('users.username', 'like', '%' . $search . '%')
                ->orWhere('users.email', 'like', '%' . $search . '%');
        });
    }
    $legacyQuery->select([
        'invites.id',
        'invites.hash',
        'users.username',
        'users.email',
        'inviters.id as inviter_id',
        'inviters.username as inviter_username',
        'users.id as user_id',
        \Illuminate\Support\Facades\DB::raw('0 as reviewable'),
        'users.added as created_at',
    ]);
    $requests = \Illuminate\Support\Facades\DB::query()
        ->fromSub($applicationQuery->unionAll($legacyQuery), 'invite_requests')
        ->orderByDesc('created_at')
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
if (($_GET['mail_sent'] ?? '') === '1') {
    print('<p class="text">Approval email sent. The account will remain pending until the user confirms the link.</p>');
}
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
    print('<th>Invite Link</th><th>Invited By</th><th>Actions</th>');
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
    if ($type === 'invite') {
        if ($row->user_id) {
            print('<td><a href="userdetails.php?id=' . (int) $row->user_id . '">' . $escape($row->username) . '</a></td>');
        } else {
            print('<td>' . $escape($row->username) . '</td>');
        }
    } else {
        print('<td><a href="userdetails.php?id=' . (int) $row->user_id . '">' . $escape($row->username) . '</a></td>');
    }
    print('<td>' . $escape($row->email) . '</td>');
    if ($type === 'invite') {
        if ((int) $row->reviewable === 1) {
            $inviteUrl = getSchemeAndHttpHost() . '/getinvite.php?id=' . rawurlencode($row->hash);
            print('<td><a href="' . $escape($inviteUrl) . '" target="_blank" rel="noopener">' . $escape($inviteUrl) . '</a></td>');
        } else {
            print('<td>-</td>');
        }
        $inviter = $row->inviter_id
            ? '<a href="userdetails.php?id=' . (int) $row->inviter_id . '">' . $escape((string) $row->inviter_username) . '</a>'
            : '-';
        print('<td>' . $inviter . '</td>');
        if ((int) $row->reviewable === 1) {
            $requestToken = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
                'user_id' => (int) $CURUSER['id'],
                'request_id' => (int) $row->id,
                'type' => $type,
                'expires_at' => time() + 1800,
            ]));
            print('<td><form method="post" action="accounts.php?type=invite&amp;q=' . rawurlencode($search) . '" style="display:inline">');
            print('<input type="hidden" name="request_id" value="' . (int) $row->id . '">');
            print('<input type="hidden" name="request_token" value="' . $escape($requestToken) . '">');
            print('<button class="btn" type="submit" name="decision" value="accept">Accept</button> ');
            print('<button class="btn" type="submit" name="decision" value="accept_with_mail">Accept with Mail</button> ');
            print('<button class="btn" type="submit" name="decision" value="deny">Deny</button></form></td>');
        } else {
            print('<td>-</td>');
        }
    } elseif ($type === 'apply') {
        print('<td>' . nl2br($escape((string) $row->details)) . '</td>');
    } elseif ($type === 'donation') {
        $plan = $row->plan === '30days' ? '30 Days' : ($row->plan === 'lifetime' ? 'Lifetime' : '');
        print('<td>' . $escape($plan) . '</td><td>' . $escape((string) $row->txid) . '</td>');
    }
    if ($type !== 'invite') {
        $requestToken = \Illuminate\Support\Facades\Crypt::encryptString(json_encode([
            'user_id' => (int) $CURUSER['id'],
            'request_id' => (int) $row->id,
            'type' => $type,
            'expires_at' => time() + 1800,
        ]));
        print('<td><form method="post" action="accounts.php?type=' . $escape($type) . '&amp;q=' . rawurlencode($search) . '" style="display:inline">');
        print('<input type="hidden" name="request_id" value="' . (int) $row->id . '">');
        print('<input type="hidden" name="request_token" value="' . $escape($requestToken) . '">');
        print('<button class="btn" type="submit" name="decision" value="accept">Accept</button> ');
        print('<button class="btn" type="submit" name="decision" value="accept_with_mail">Accept with Mail</button> ');
        print('<button class="btn" type="submit" name="decision" value="deny">Deny</button></form></td>');
    }
    print('</tr>');
}
if ($requests->isEmpty()) {
    $emptyMessage = $type === 'invite' ? 'No pending invite registrations found.' : 'No pending account requests found.';
    $columnCount = $type === 'invite' ? 6 : ($type === 'apply' ? 5 : ($type === 'donation' ? 6 : 4));
    print('<tr><td colspan="' . $columnCount . '" class="text">' . $escape($emptyMessage) . '</td></tr>');
}
print('</table>');
print($requests->links());
end_main_frame();
stdfoot();
