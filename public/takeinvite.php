<?php
require_once("../include/bittorrent.php");
dbconn();
require_once(get_langfile_path());
$currentLangTakeInvite = $lang_takeinvite;
require(get_langfile_path("takeinvite.php", false, "en"));
$lang_takeinvite = array_merge($lang_takeinvite, $currentLangTakeInvite);
loggedinorreturn();
$id = $CURUSER['id'];
$lockName = sprintf("takeinvite:%s", $id);
$lock = new \Nexus\Database\NexusLock($lockName, 10);
if (!$lock->get()) {
    $errMsg = nexus_trans("nexus.do_not_repeat");
    stderr($errMsg, $errMsg);
}
registration_check('invitesystem', true, false);
$userRep = new \App\Repositories\UserRepository();
try {
    $sendText = $userRep->getInviteBtnText($CURUSER['id']);
} catch (\Exception $exception) {
    stderr($lang_takeinvite['std_error'], $exception->getMessage());
}
function bark($msg) {
  global $lang_takeinvite;
  stdhead();
	stdmsg($lang_takeinvite['head_invitation_failed'], $msg);
  stdfoot();
  exit;
}
if (($_GET['type'] ?? '') == 'generate') {
    if ($_SERVER['REQUEST_METHOD'] != 'POST') {
        bark($lang_takeinvite['std_error']);
    }
    $csrfToken = $_POST['csrf_token'] ?? '';
    if (!is_string($csrfToken) || !hash_equals(csrf_token(), $csrfToken)) {
        bark($lang_takeinvite['std_error']);
    }
    $selectedHash = $_POST['hash'] ?? '';
    if (!is_string($selectedHash) || $selectedHash === '') {
        bark($lang_takeinvite['std_must_select_invite']);
    }

    $inviteHash = $selectedHash;
    try {
        if ($selectedHash === 'permanent') {
            $hashes = (new \App\Repositories\ToolRepository())->generateUniqueInviteHash([], 1, 1);
            $inviteHash = $hashes[0];
            $expiredAt = now()->addDays((int)$invite_timeout);
            \Illuminate\Support\Facades\DB::transaction(function () use ($id, $inviteHash, $expiredAt, $lang_takeinvite) {
                $user = \App\Models\User::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                if ($user->invites < 1) {
                    throw new \DomainException($lang_takeinvite['std_no_invite']);
                }
                $user->decrement('invites');
                \App\Models\Invite::query()->insert([
                    'inviter' => $id,
                    'invitee' => '',
                    'hash' => $inviteHash,
                    'time_invited' => now(),
                    'valid' => \App\Models\Invite::VALID_YES,
                    'expired_at' => $expiredAt,
                    'created_at' => now(),
                ]);
            });
        } else {
            \Illuminate\Support\Facades\DB::transaction(function () use ($id, $selectedHash, $lang_takeinvite) {
                $invite = \App\Models\Invite::query()
                    ->where('inviter', $id)
                    ->where('hash', $selectedHash)
                    ->where('invitee', '')
                    ->unissued()
                    ->lockForUpdate()
                    ->first();
                if (!$invite || $invite->valid != \App\Models\Invite::VALID_NO) {
                    throw new \DomainException($lang_takeinvite['hash_not_exists']);
                }
                if (!$invite->expired_at || \App\Models\Invite::hasExpired($invite->expired_at)) {
                    throw new \DomainException($lang_takeinvite['hash_expired']);
                }
                $invite->valid = \App\Models\Invite::VALID_YES;
                $invite->time_invited = now();
                $invite->save();
            });
        }
    } catch (\DomainException $exception) {
        $lock->release();
        bark($exception->getMessage());
    }
    $lock->release();
    header("Location: invite.php?id=".htmlspecialchars($id)."&menu=generated&generated=".rawurlencode($inviteHash));
    exit;
}

$email = unesc(htmlspecialchars(trim($_POST["email"])));
$email = safe_email($email);
$preRegisterUsername = $_POST['pre_register_username'] ?? '';
$isPreRegisterEmailAndUsername = get_setting("system.is_invite_pre_email_and_username") == "yes";
if (strlen($preRegisterUsername) > 12)
	bark($lang_takeinvite['std_username_too_long']);
if (!$email)
    bark($lang_takeinvite['std_must_enter_email']);
if (!check_email($email))
	bark($lang_takeinvite['std_invalid_email_address']);
if(EmailBanned($email))
    bark($lang_takeinvite['std_email_address_banned']);

if(!EmailAllowed($email))
    bark($lang_takeinvite['std_wrong_email_address_domains'].allowedemails());

$body = str_replace("<br />", "<br />", nl2br(trim(strip_tags($_POST["body"]))));
if(!$body)
	bark($lang_takeinvite['std_must_enter_personal_message']);

if ($isPreRegisterEmailAndUsername) {
    if (empty($preRegisterUsername)) {
        bark(nexus_trans("invite.require_pre_register_username"));
    }
    if (!validusername($preRegisterUsername)) {
        bark(nexus_trans("user.username_invalid", ["username" => $preRegisterUsername]));
    }
    $exists = \App\Models\User::query()->where('username', $preRegisterUsername)->exists();
    if ($exists) {
        bark(nexus_trans("user.username_already_exists", ["username" => $preRegisterUsername]));
    }
}


// check if email addy is already in use
$a = (@mysql_fetch_row(@sql_query("select count(*) from users where email=".sqlesc($email))));
if ($a[0] != 0)
  bark($lang_takeinvite['std_email_address'].htmlspecialchars($email).$lang_takeinvite['std_is_in_use']);
$b = (@mysql_fetch_row(@sql_query("select count(*) from invites where invitee=".sqlesc($email))));
if ($b[0] != 0)
  bark($lang_takeinvite['std_invitation_already_sent_to'].htmlspecialchars($email).$lang_takeinvite['std_await_user_registeration']);

$ret = sql_query("SELECT username FROM users WHERE id = ".sqlesc($id)) or sqlerr();
$arr = mysql_fetch_assoc($ret);

if (empty($_POST['hash'])) {
    bark($lang_takeinvite['std_must_select_invite']);
}
if ($_POST['hash'] == 'permanent') {
    $hash  = md5(mt_rand(1,10000).$CURUSER['username'].TIMENOW.$CURUSER['passhash']);
} else {
    $hashRecord = \App\Models\Invite::query()->where('inviter', $CURUSER['id'])->where('hash', $_POST['hash'])->first();
    if (!$hashRecord) {
        bark($lang_takeinvite['hash_not_exists']);
    }
    if ($hashRecord->invitee != '' || !\App\Models\Invite::isUnissued($hashRecord->time_invited)) {
        bark('hash '.$lang_takeinvite['std_is_in_use']);
    }
    if ($hashRecord->valid != \App\Models\Invite::VALID_NO)
        bark($lang_takeinvite['hash_not_exists']);
    if (!$hashRecord->expired_at || \App\Models\Invite::hasExpired($hashRecord->expired_at)) {
        bark($lang_takeinvite['hash_expired']);
    }
    $hash = $_POST['hash'];
}

$title = $SITENAME.$lang_takeinvite['mail_tilte'];

$signupUrl = getSchemeAndHttpHost() . "/signup.php?type=invite&invitenumber=$hash";
$siteName = \App\Models\Setting::getSiteName();
$mailTwo = sprintf($lang_takeinvite['mail_two'], $siteName, $siteName);
$mailFour = sprintf($lang_takeinvite['mail_four'], $siteName);
$mailSix = sprintf($lang_takeinvite['mail_six'], $REPORTMAIL, $siteName);
$message = <<<EOD
{$lang_takeinvite['mail_one']}{$arr['username']}{$mailTwo}
<b><a href="javascript:void(null)" onclick="window.open($signupUrl)">{$lang_takeinvite['mail_here']}</a></b><br />
$signupUrl
<br />{$lang_takeinvite['mail_three']}$invite_timeout{$mailFour}{$arr['username']}{$lang_takeinvite['mail_five']}<br />
$body
<br /><br />{$mailSix}
EOD;

$sendResult = sent_mail($email,$SITENAME,$SITEEMAIL,$title,$message,"invitesignup",false,false,'');
//this email is sent only when someone give out an invitation
if ($sendResult === true) {
    if (isset($hashRecord)) {
        $update = [
            'invitee' => $email,
            'time_invited' => now(),
            'valid' => 1,
        ];
        if ($isPreRegisterEmailAndUsername) {
            $update["pre_register_email"] = $email;
            $update["pre_register_username"] = $preRegisterUsername;
        }
        $hashRecord->update($update);
    } else {
        $insert = [
            "inviter" => $id,
            "invitee" => $email,
            "hash" => $hash,
            "time_invited" => now()->toDateTimeString(),
            "expired_at" => now()->addDays((int)$invite_timeout),
        ];
        if ($isPreRegisterEmailAndUsername) {
            $insert["pre_register_email"] = $email;
            $insert["pre_register_username"] = $preRegisterUsername;
        }
        \App\Models\Invite::query()->insert($insert);
//        sql_query("INSERT INTO invites (inviter, invitee, hash, time_invited) VALUES ('".mysql_real_escape_string($id)."', '".mysql_real_escape_string($email)."', '".mysql_real_escape_string($hash)."', " . sqlesc(date("Y-m-d H:i:s")) . ")");
        sql_query("UPDATE users SET invites = invites - 1 WHERE id = ".mysql_real_escape_string($id)) or sqlerr(__FILE__, __LINE__);
    }
}
$lock->release();
header("Location: invite.php?id=".htmlspecialchars($id)."&sent=1");
?>



