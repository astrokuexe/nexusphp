<?php
require_once("../include/bittorrent.php");
header("Content-Type: text/html; charset=utf-8");
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !is_string($_POST['username'] ?? null)) {
    die();
}
$username = trim(unesc($_POST['username']));
if ($username === '') {
    die();
}
dbconn();
require_once(get_langfile_path("", false, get_langfolder_cookie()));
failedloginscheck ();
cur_user_check () ;
$ip = getip();
function bark($text = "")
{
  global $lang_takelogin;
  $text =  ($text == "" ? $lang_takelogin['std_login_fail_note'] : $text);
  stderr($lang_takelogin['std_login_fail'], $text,false);
}
if ($iv == "yes") {
    check_code($_POST['imagehash'] ?? null, $_POST['imagestring'] ?? null, 'login.php', true);
}
//同时支持新旧两种登录方式
$useChallengeResponse = \App\Models\Setting::getIsUseChallengeResponseAuthentication();
if ($useChallengeResponse) {
    if (!is_string($_POST['response'] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/i', $_POST['response'])) {
        failedlogins("Require response parameter.");
    }
} else {
    if (!is_string($_POST['password'] ?? null) || $_POST['password'] === '') {
        failedlogins("Require password parameter.");
    }
}

$challenge = null;
if ($useChallengeResponse) {
    $challenge = $_POST['challenge'] ?? null;
    if (!is_string($challenge) || !consume_auth_challenge($challenge, $username)) {
        failedlogins();
    }
}

$res = sql_query("SELECT id, passhash, secret, auth_key, enabled, status, two_step_secret, lang FROM users WHERE username = " . sqlesc($username));
$row = mysql_fetch_array($res);

if (!$row)
	failedlogins();
if ($row['status'] == 'pending')
	failedlogins();
if ($row["enabled"] == "no" && \App\Models\Setting::getSelfEnableBonus() <= 0) {
    failedlogins();
}

$log = "user: {$row['id']}, ip: $ip";
$update = [];

if (!empty($row['two_step_secret'])) {
    if (!is_string($_POST['two_step_code'] ?? null) || $_POST['two_step_code'] === '') {
        failedlogins();
    }
    $ga = new \PHPGangsta_GoogleAuthenticator();
    if (!$ga->verifyCode($row['two_step_secret'], $_POST['two_step_code'])) {
        failedlogins();
    }
}
if ($useChallengeResponse) {
    $log .= ", useChallengeResponse";
} else {
    $passwordHash = hash('sha256', $row['secret'] . hash('sha256', $_POST['password']));
    $log .= ", !useChallengeResponse";
    if (empty($row['auth_key'])) {
        //先使用旧的验证方式验证
        if (!hash_equals($row["passhash"], md5($row["secret"] . $_POST['password'] . $row["secret"]))) {
            do_log("$log, legacy password verification failed");
            login_failedlogins();
        }
        $log .= ", no auth_key, upgrade to challenge response";
        //自动升级为新的验证方式
        $update['passhash'] = $row['passhash'] = $passwordHash;
    }
    //后端自动生成挑战响应
    $challenge = mksecret();
    $_POST['response'] = hash_hmac('sha256', $passwordHash, $challenge);
}
$expectedResponse = hash_hmac('sha256', $row['passhash'], $challenge);
if (!hash_equals($expectedResponse, $_POST["response"])) {
    do_log("$log, password verification failed");
    login_failedlogins();
}
do_log("$log, login successful");
$userRep = new \App\Repositories\UserRepository();
$userRep->saveLoginLog($row['id'], $ip, 'Web', true);

//update user lang
$language = \App\Models\Language::query()->where("site_lang_folder", get_langfolder_cookie())->first();

if ($language && $language->id != $row["lang"]) {
    do_log(sprintf("update user: %s lang: %s => %s", $row["id"], $row["lang"], $language->id));
    $update["lang"] = $language->id;
}
if (empty($row['auth_key'])) {
    $row['auth_key'] = $update['auth_key'] = hash('sha256', mksecret(32));
}
if (!empty($update)) {
    \App\Models\User::query()->where("id", $row["id"])->update($update);
    clear_user_cache($row["id"]);
}

if (isset($_POST["logout"]) && $_POST["logout"] == "yes")
{
	logincookie($row["id"], $row['auth_key'],900);
}
else
{
    logincookie($row["id"], $row['auth_key']);
}

$returnto = $_POST['returnto'] ?? '';
if (!is_string($returnto)
    || strlen($returnto) > 2048
    || !preg_match('/\A[a-zA-Z0-9_.\/?&=%+#-]*\z/', $returnto)
    || str_starts_with($returnto, '//')
    || parse_url($returnto, PHP_URL_SCHEME) !== null
) {
    $returnto = '';
}
nexus_redirect($returnto !== '' ? $returnto : "index.php");
?>
