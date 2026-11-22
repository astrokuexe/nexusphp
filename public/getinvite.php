<?php
require_once("../include/bittorrent.php");
$legacyHook = $GLOBALS['hook'];
$legacyPlugin = $GLOBALS['plugin'];
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$GLOBALS['hook'] = $hook = $legacyHook;
$GLOBALS['plugin'] = $plugin = $legacyPlugin;
dbconn();
require_once(get_langfile_path("signup.php", false, $CURLANGDIR));
require_once(get_langfile_path("takesignup.php", false, $CURLANGDIR));
\Nexus\Database\RuntimeMigrations::ensureInviteRegistrationRequestsTable();

function showInviteRegistrationMessage(string $title, string $message): void
{
    stdhead($title);
    stdmsg($title, $message);
    stdfoot();
    exit;
}

if (($_GET['submitted'] ?? '') === '1') {
    showInviteRegistrationMessage('Registration submitted', 'Your invite registration is awaiting staff review.');
}

cur_user_check();
registration_check('invitesystem');
failedloginscheck('Invite Signup');

$inviteHash = $_GET['id'] ?? '';
if (!is_string($inviteHash) || strlen($inviteHash) !== 32 || !ctype_alnum($inviteHash)) {
    showInviteRegistrationMessage($lang_signup['std_error'], $lang_signup['std_uninvited']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $submittedHash = $_POST['invite_hash'] ?? '';
    if (!is_string($csrfToken) || !hash_equals(nexus_csrf_token('invite.registration', $inviteHash), $csrfToken)) {
        showInviteRegistrationMessage($lang_signup['std_error'], 'Invalid request. Please reload the invite link and try again.');
    }
    if (!is_string($submittedHash) || !hash_equals($inviteHash, $submittedHash)) {
        showInviteRegistrationMessage($lang_signup['std_error'], $lang_signup['std_uninvited']);
    }
    $username = trim(is_string($_POST['wantusername'] ?? null) ? $_POST['wantusername'] : '');
    $email = safe_email(trim(is_string($_POST['email'] ?? null) ? $_POST['email'] : ''));
    $clientPasswordHash = strtolower(trim(is_string($_POST['wantpassword'] ?? null) ? $_POST['wantpassword'] : ''));
    if (!validusername($username)) {
        showInviteRegistrationMessage($lang_signup['std_error'], $lang_takesignup['std_invalid_username']);
    }
    if (strlen($username) > 12) {
        showInviteRegistrationMessage($lang_signup['std_error'], $lang_takesignup['std_username_too_long']);
    }
    if (!check_email($email)) {
        showInviteRegistrationMessage($lang_signup['std_error'], $lang_takesignup['std_wrong_email_address_format']);
    }
    if (!EmailAllowed($email)) {
        showInviteRegistrationMessage($lang_signup['std_error'], $lang_takesignup['std_wrong_email_address_domains'] . allowedemails());
    }
    if (!preg_match('/\A[a-f0-9]{64}\z/', $clientPasswordHash)) {
        showInviteRegistrationMessage($lang_signup['std_error'], $lang_takesignup['std_blank_field']);
    }

    $secret = mksecret();
    $passhash = hash('sha256', $secret . $clientPasswordHash);
    try {
        \Illuminate\Support\Facades\DB::transaction(function () use ($inviteHash, $username, $email, $secret, $passhash, $lang_takesignup) {
            $invite = \App\Models\Invite::query()
                ->where('hash', $inviteHash)
                ->where('valid', \App\Models\Invite::VALID_YES)
                ->where('invitee', '')
                ->whereNotNull('time_invited')
                ->whereNull('invitee_register_uid')
                ->where('expired_at', '>', now())
                ->lockForUpdate()
                ->first();
            if (!$invite) {
                throw new \DomainException('This invite link is invalid, expired, or already submitted.');
            }

            if (\App\Models\User::query()->where('username', $username)->exists()) {
                throw new \DomainException($lang_takesignup['std_username_exists']);
            }
            if (\App\Models\User::query()->where('email', $email)->exists()) {
                throw new \DomainException($lang_takesignup['std_email_address'] . $email . $lang_takesignup['std_in_use']);
            }
            $duplicateRequest = \Illuminate\Support\Facades\DB::table('invite_registration_requests')
                ->where('status', 'pending')
                ->where(function ($query) use ($username, $email) {
                    $query->where('username', $username)->orWhere('email', $email);
                })
                ->exists();
            if ($duplicateRequest) {
                throw new \DomainException('A pending invite registration already uses this username or email address.');
            }

            \Illuminate\Support\Facades\DB::table('invite_registration_requests')->insert([
                'invite_id' => $invite->id,
                'username' => $username,
                'email' => $email,
                'passhash' => $passhash,
                'secret' => $secret,
                'lang' => get_langid_from_langcookie(),
                'stylesheet' => $GLOBALS['defcss'],
                'ip' => getip(),
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $invite->update([
                'valid' => \App\Models\Invite::VALID_NO,
                'invitee_register_email' => $email,
                'invitee_register_username' => $username,
            ]);
        });
    } catch (\DomainException $exception) {
        showInviteRegistrationMessage($lang_signup['std_error'], htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    header('Location: getinvite.php?submitted=1');
    exit;
}

$invite = \App\Models\Invite::query()
    ->where('hash', $inviteHash)
    ->where('valid', \App\Models\Invite::VALID_YES)
    ->where('invitee', '')
    ->whereNotNull('time_invited')
    ->whereNull('invitee_register_uid')
    ->where('expired_at', '>', now())
    ->first();
if (!$invite) {
    showInviteRegistrationMessage($lang_signup['std_error'], $lang_signup['std_uninvited']);
}

stdhead($lang_signup['head_invite_signup']);
$formInputStyle = 'style="width: min(100%, 320px); min-width: 180px; border: 1px solid gray; box-sizing: border-box"';
?>
<form method="post" action="getinvite.php?id=<?php echo rawurlencode($inviteHash) ?>" id="getinvite-form">
    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(nexus_csrf_token('invite.registration', $inviteHash), ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="invite_hash" value="<?php echo htmlspecialchars($inviteHash, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="wantpassword">
    <table border="1" cellspacing="0" cellpadding="10">
        <tr>
            <td class="rowhead"><?php echo $lang_signup['row_desired_username'] ?></td>
            <td class="rowfollow"><input type="text" name="wantusername" autocomplete="username" maxlength="12" required <?php echo $formInputStyle ?>></td>
        </tr>
        <tr>
            <td class="rowhead"><?php echo $lang_signup['row_email_address'] ?></td>
            <td class="rowfollow"><input type="email" name="email" autocomplete="email" maxlength="80" required <?php echo $formInputStyle ?>></td>
        </tr>
        <tr>
            <td class="rowhead"><?php echo $lang_signup['row_pick_a_password'] ?></td>
            <td class="rowfollow"><input type="password" class="wantpassword" autocomplete="new-password" minlength="6" maxlength="40" required <?php echo $formInputStyle ?>></td>
        </tr>
        <tr>
            <td class="toolbox" colspan="2" align="center">
                <input type="button" value="Send" class="btn">
            </td>
        </tr>
    </table>
</form>
<?php
render_password_hash_js('getinvite-form', 'wantpassword', 'wantpassword', true, 'password_confirmation', 'wantusername');
stdfoot();
