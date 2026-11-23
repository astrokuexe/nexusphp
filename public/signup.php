<?php
require_once("../include/bittorrent.php");
dbconn();

$langid = intval($_GET['sitelanguage'] ?? 0);
if ($langid)
{
	$lang_folder = validlang($langid);
    $enabled = \App\Models\Language::listEnabled();
    if (!in_array($lang_folder, $enabled)) {
        nexus_redirect(getBaseUrl());
    }
	if(get_langfolder_cookie() != $lang_folder)
	{
		set_langfolder_cookie($lang_folder);
        nexus_redirect($_SERVER['REQUEST_URI']);
	}
}
require_once(get_langfile_path("signup.php", false, $CURLANGDIR));
cur_user_check ();
$type = is_string($_GET['type'] ?? null) ? $_GET['type'] : '';
$registrationMode = $type === 'apply' ? 'apply' : 'signup';
registration_mode_check($registrationMode);
$isPreRegisterEmailAndUsername = get_setting("system.is_invite_pre_email_and_username") == "yes";
if ($type == 'invite')
{
	registration_check();
	failedloginscheck ("Invite signup");
	$code = is_string($_GET["invitenumber"] ?? null) ? $_GET["invitenumber"] : '';
    if (empty($code)) {
        stderr($lang_signup['std_error'], "Require invitenumber");
    }

	$nuIP = getip();
	$dom = @gethostbyaddr($nuIP);
	if ($dom == $nuIP || @gethostbyname($dom) != $nuIP)
	$dom = "";
	else
	{
	$dom = strtoupper($dom);
	preg_match('/^(.+)\.([A-Z]{2,3})$/', $dom, $tldm);
	$dom = $tldm[2];
	}

	$sq = sprintf("SELECT * FROM invites WHERE valid = %s and hash ='%s'", \App\Models\Invite::VALID_YES, mysql_real_escape_string($code));
	$res = sql_query($sq) or sqlerr(__FILE__, __LINE__);
	$inv = mysql_fetch_assoc($res);
	if (!$inv)
		stderr($lang_signup['std_error'], $lang_signup['std_uninvited'], 0);
    if (\App\Models\Invite::hasExpired($inv['expired_at'] ?? null)) {
        stderr($lang_signup['std_error'], $lang_signup['std_uninvited'], 0);
    }
    $inviter = (int) $inv["inviter"];
	stdhead($lang_signup['head_invite_signup']);
}
else {
	registration_check('mode');
	failedloginscheck ("Signup");
	stdhead($lang_signup['head_signup']);
}

if ($type === 'apply') {
?>
<style>
.application-card {
    box-sizing: border-box;
    width: min(100%, 440px);
    margin: 0 auto;
    padding: 24px;
    border: 1px solid currentColor;
}
.application-card h1 {
    margin: 0 0 8px;
    color: inherit;
    text-align: center;
}
.application-intro {
    margin: 0 0 22px;
    color: inherit;
    line-height: 1.5;
    text-align: center;
}
.application-field {
    display: block;
    margin: 0 0 15px;
    color: inherit;
}
.application-field input,
.application-field textarea,
.profile-entry input {
    box-sizing: border-box;
    width: 100%;
    margin-top: 6px;
    padding: 10px;
}
.application-field input:focus,
.application-field textarea:focus,
.profile-entry input:focus {
    outline: auto;
}
.application-field textarea {
    min-height: 90px;
    resize: vertical;
}
.application-note {
    margin: 2px 0 15px;
    font-size: 11px;
    line-height: 1.45;
}
.application-requirements {
    margin: 0 0 15px;
    padding: 12px 14px;
    border: 1px solid currentColor;
    color: inherit;
    font-size: 12px;
}
.application-requirements ul {
    margin: 8px 0 0;
    padding-left: 18px;
    line-height: 1.55;
}
.profile-entry {
    min-width: 0;
    margin: 0 0 10px;
    padding: 10px;
    border: 1px solid currentColor;
}
.profile-entry legend {
    padding: 0 6px;
    color: inherit;
}
.profile-entry label {
    display: block;
    margin: 0 0 8px;
    color: inherit;
}
.profile-actions {
    display: flex;
    justify-content: space-between;
    margin: 6px 0 18px;
}
.profile-actions button {
    padding: 8px 10px;
}
.application-submit {
    width: 100%;
}
.application-back {
    display: block;
    margin-top: 14px;
    font-size: 12px;
    text-align: center;
}
</style>
<?php
$usernameInput = '<input type="text" name="wantusername" maxlength="12" autocomplete="username" required>';
$emailInput = '<input type="email" name="email" maxlength="80" autocomplete="email" required>';
?>
<section class="application-card" aria-labelledby="application-title">
    <h1 id="application-title">Membership Application</h1>
    <p class="application-intro">This is a private community. If you do not have an invite link, you can apply for membership below.</p>
    <form method="post" action="takesignup.php" id="application-form">
        <input type="hidden" name="type" value="apply">
        <label class="application-field">Desired Username
            <?php echo $usernameInput ?>
        </label>
        <label class="application-field"><?php echo htmlspecialchars($lang_signup['row_email_address'], ENT_QUOTES, 'UTF-8') ?>
            <?php echo $emailInput ?>
        </label>
        <?php if ($restrictemaildomain === 'yes') { ?>
        <p class="application-note"><?php echo $lang_signup['text_email_note'] . allowedemails() ?></p>
        <?php } ?>
        <label class="application-field">How did you hear about us and why do you want in?
            <textarea name="application_details" maxlength="2500" required></textarea>
        </label>
        <div class="application-field">Your Profiles From Other Trackers</div>
        <div class="application-requirements">
            <strong>Required:</strong>
            <ul>
                <li>Profiles from Private trackers only</li>
                <li>Accounts must be at least 90 days old</li>
                <li>At least 500 GB combined upload/download</li>
                <li>No hit and runs or other warnings</li>
                <li>Profiles must not be hidden or private</li>
                <li>Screenshots must be hosted publicly</li>
            </ul>
        </div>
        <div id="profile-list">
            <?php for ($profileIndex = 0; $profileIndex < 2; $profileIndex++) { ?>
            <fieldset class="profile-entry">
                <legend>Profile <?php echo $profileIndex + 1 ?></legend>
                <label>Link to screenshot of profile page
                    <input type="url" name="tracker_profiles[<?php echo $profileIndex ?>][screenshot]" maxlength="2048" placeholder="https://example.com/screenshot">
                </label>
                <label>Link to profile page
                    <input type="url" name="tracker_profiles[<?php echo $profileIndex ?>][profile]" maxlength="2048" placeholder="https://example.com/profile">
                </label>
            </fieldset>
            <?php } ?>
        </div>
        <div class="profile-actions">
            <button class="btn" type="button" id="add-profile">ADD PROFILE</button>
            <button class="btn" type="button" id="delete-profile">DELETE LAST</button>
        </div>
        <button class="btn application-submit" type="submit">Apply (PRESS ONLYONE)</button>
    </form>
    <a class="application-back" href="login.php">← Back to Login</a>
</section>
<template id="profile-template">
    <fieldset class="profile-entry">
        <legend></legend>
        <label>Link to screenshot of profile page
            <input type="url" maxlength="2048" placeholder="https://example.com/screenshot">
        </label>
        <label>Link to profile page
            <input type="url" maxlength="2048" placeholder="https://example.com/profile">
        </label>
    </fieldset>
</template>
<script>
(() => {
    const form = document.getElementById('application-form');
    const profileList = document.getElementById('profile-list');
    const profileTemplate = document.getElementById('profile-template');
    const renumberProfiles = () => {
        profileList.querySelectorAll('.profile-entry').forEach((entry, index) => {
            entry.querySelector('legend').textContent = `Profile ${index + 1}`;
            const inputs = entry.querySelectorAll('input');
            inputs[0].name = `tracker_profiles[${index}][screenshot]`;
            inputs[1].name = `tracker_profiles[${index}][profile]`;
        });
    };

    document.getElementById('add-profile').addEventListener('click', () => {
        if (profileList.children.length >= 10) {
            window.alert('You can add up to 10 tracker profiles.');
            return;
        }
        profileList.append(profileTemplate.content.firstElementChild.cloneNode(true));
        renumberProfiles();
    });
    document.getElementById('delete-profile').addEventListener('click', () => {
        profileList.lastElementChild?.remove();
        renumberProfiles();
    });
    form.addEventListener('submit', event => {
        const profiles = [...profileList.querySelectorAll('.profile-entry')].map(entry =>
            [...entry.querySelectorAll('input')].map(input => input.value.trim())
        );
        if (profiles.some(([screenshot, profile]) => Boolean(screenshot) !== Boolean(profile))) {
            event.preventDefault();
            window.alert('Please provide both links for each profile, or leave both fields blank.');
            return;
        }
        if (!profiles.some(([screenshot, profile]) => screenshot && profile)) {
            event.preventDefault();
            window.alert('Please add at least one tracker profile.');
        }
    });
})();
</script>
<?php
stdfoot();
exit;
}

$s = "<select name=\"sitelanguage\" onchange='submit()'>\n";

$langs = langlist("site_lang", true);

foreach ($langs as $row)
{
	if ($row["site_lang_folder"] == get_langfolder_cookie()) $se = " selected"; else $se = "";
	$s .= "<option value=". $row["id"] . $se. ">" . htmlspecialchars($row["lang_name"]) . "</option>\n";
}
$s .= "\n</select>";
?>
<form method="get" action="signup.php">
<?php
if ($type == 'invite')
print('<input type="hidden" name="type" value="invite"><input type="hidden" name="invitenumber" value="' . htmlspecialchars($code, ENT_QUOTES, 'UTF-8') . '">');
print("<div align=right valign=top>".$lang_signup['text_select_lang']. $s . "</div>");
?>
</form>
<p>
<form method="post" action="takesignup.php" id="signup-form">
<?php
if ($type == 'invite') {
	print("<input type=\"hidden\" name=\"inviter\" value=\"".$inviter."\"><input type=hidden name=type value='invite'>");
}
?>
<table border="1" cellspacing="0" cellpadding="10">
<?php
print("<tr><td class=text align=center colspan=2>".$lang_signup['text_cookies_note']."</td></tr>");
$formInputStyle = 'style="width: min(100%, 320px); min-width: 180px; border: 1px solid gray; box-sizing: border-box"';
if ($isPreRegisterEmailAndUsername && !empty($inv["pre_register_username"])) {
    $usernameInput = sprintf('<input type="text" %s name="wantusername" value="%s" readonly autocomplete="username" />', $formInputStyle, htmlspecialchars($inv["pre_register_username"], ENT_QUOTES));
} else {
    $usernameInput = '<input type="text" ' . $formInputStyle . ' name="wantusername" autocomplete="username" />';
}

if ($isPreRegisterEmailAndUsername && !empty($inv["pre_register_email"])) {
    $emailInput = sprintf('<input type="email" %s name="email" value="%s" readonly autocomplete="email" />', $formInputStyle, htmlspecialchars($inv["pre_register_email"], ENT_QUOTES));
} else {
    $emailInput = '<input type="email" ' . $formInputStyle . ' name="email" autocomplete="email" />';
}

?>
<tr><td class=rowhead><?php echo $lang_signup['row_desired_username'] ?></td><td class=rowfollow align=left><?php echo $usernameInput?><br />
<font class=small><?php echo $lang_signup['text_allowed_characters'] ?></font></td></tr>
<tr><td class=rowhead><?php echo $lang_signup['row_pick_a_password'] ?></td><td class=rowfollow align=left><input type="password" <?php echo $formInputStyle; ?> class="wantpassword" autocomplete="new-password" /><br />
	<font class=small><?php echo $lang_signup['text_minimum_six_characters'] ?></font></td></tr>
<tr><td class=rowhead><?php echo $lang_signup['row_enter_password_again'] ?></td><td class=rowfollow align=left><input type="password" <?php echo $formInputStyle; ?> class="passagain" autocomplete="new-password" /></td></tr>
<?php
show_image_code ();
?>
<tr><td class=rowhead><?php echo $lang_signup['row_email_address'] ?></td><td class=rowfollow align=left><?php echo $emailInput?>
<table width=250 border=0 cellspacing=0 cellpadding=0><tr><td class=embedded><font class=small><?php echo ($restrictemaildomain == 'yes' ? $lang_signup['text_email_note'].allowedemails() : "") ?></td></tr>
</font></td></tr></table>
</td></tr>
<?php $countries = "<option value=\"8\">---- ".$lang_signup['select_none_selected']." ----</option>n";
$ct_r = sql_query("SELECT id,name FROM countries ORDER BY name") or die;
while ($ct_a = mysql_fetch_array($ct_r))
$countries .= "<option value=$ct_a[id]" . ($ct_a['id'] == 8 ? " selected" : "") . ">$ct_a[name]</option>n";
tr($lang_signup['row_country'], "<select name=country>n$countries</select>", 1);
//School select
if ($showschool == 'yes'){
$schools = "<option value=35>---- ".$lang_signup['select_none_selected']." ----</option>n";
$sc_r = sql_query("SELECT id,name FROM schools ORDER BY name") or die;
while ($sc_a = mysql_fetch_array($sc_r))
$schools .= "<option value=$sc_a[id]" . ($sc_a['id'] == 35 ? " selected" : "") . ">$sc_a[name]</option>n";
tr($lang_signup['row_school'], "<select name=school>$schools</select>", 1);
}
?>
<tr><td class=rowhead><?php echo $lang_signup['row_gender'] ?></td><td class=rowfollow align=left>
<input type=radio name=gender value=Male><?php echo $lang_signup['radio_male'] ?><input type=radio name=gender value=Female><?php echo $lang_signup['radio_female'] ?></td></tr>
<tr><td class=rowhead><?php echo $lang_signup['row_verification'] ?></td><td class=rowfollow align=left><input type=checkbox name=rulesverify value=yes><?php echo $lang_signup['checkbox_read_rules'] ?><br />
<input type=checkbox name=faqverify value=yes><?php echo $lang_signup['checkbox_read_faq'] ?> <br />
<input type=checkbox name=ageverify value=yes><?php echo $lang_signup['checkbox_age'] ?></td></tr>
<input type="hidden" name="hash" value="<?php echo htmlspecialchars($code ?? '', ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="wantpassword" />
<tr><td class=toolbox colspan="2" align="center"><font color=red><b><?php echo $lang_signup['text_all_fields_required'] ?></b><p></font>
<input id="submit-btn" type="button" value="<?php echo $lang_signup['submit_sign_up'] ?>" style="height: 25px">
</td></tr>
</table>
</form>
<p><a href="login.php">Already registered? Login</a></p>
<?php
render_password_hash_js("signup-form", "wantpassword", "wantpassword", true,"passagain", "wantusername");
stdfoot();
