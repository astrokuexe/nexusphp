<?php
require_once("../include/bittorrent.php");
dbconn();
require_once(get_langfile_path('forums.php'));
loggedinorreturn();
parked();

if ($enableextforum == 'yes') {
    permissiondenied();
}

$maxSubjectLength = 100;
$userClass = get_user_class();
$canCreateTopics = ($CURUSER['forumpost'] ?? 'no') === 'yes';
$forumResult = sql_query("SELECT id, name, minclassread, minclasswrite, minclasscreate FROM forums ORDER BY forid ASC, sort ASC") or sqlerr(__FILE__, __LINE__);
$eligibleForums = [];
while ($forum = mysql_fetch_assoc($forumResult)) {
    if (
        $canCreateTopics
        && $userClass >= $forum['minclassread']
        && $userClass >= $forum['minclasswrite']
        && $userClass >= $forum['minclasscreate']
    ) {
        $eligibleForums[(int) $forum['id']] = $forum;
    }
}

$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? ($_POST['action'] ?? '') : ($_GET['action'] ?? '');
if ($action === 'create') {
    $forumId = (int) ($_POST['forumid'] ?? 0);
    if (!isset($eligibleForums[$forumId])) {
        permissiondenied();
    }

    $subject = trim($_POST['subject'] ?? '');
    $body = trim($_POST['body'] ?? '');
    if ($subject === '') {
        stderr($lang_forums['std_error'], $lang_forums['std_must_enter_subject']);
    }
    if (strlen($subject) > $maxSubjectLength) {
        stderr($lang_forums['std_error'], $lang_forums['std_subject_limited']);
    }
    if ($body === '') {
        stderr($lang_forums['std_error'], $lang_forums['std_no_body_text']);
    }

    if (!user_can('postmanage') && strtotime($CURUSER['last_post']) > (TIMENOW - 10)) {
        $seconds = 10 - (TIMENOW - strtotime($CURUSER['last_post']));
        stderr(
            $lang_forums['std_error'],
            $lang_forums['std_post_flooding'] . $seconds . $lang_forums['std_seconds_before_making'],
            false
        );
    }

    $userId = (int) $CURUSER['id'];
    $date = date('Y-m-d H:i:s');
    KPS('+', $starttopic_bonus, $userId);
    sql_query(
        "INSERT INTO topics (userid, forumid, subject) VALUES ("
        . sqlesc($userId) . ", " . sqlesc($forumId) . ", " . sqlesc($subject) . ")"
    ) or sqlerr(__FILE__, __LINE__);
    $topicId = mysql_insert_id();
    if (!$topicId) {
        stderr($lang_forums['std_error'], $lang_forums['std_no_topic_id_returned']);
    }

    sql_query("UPDATE forums SET topiccount = topiccount + 1, postcount = postcount + 1 WHERE id = " . sqlesc($forumId)) or sqlerr(__FILE__, __LINE__);
    sql_query(
        "INSERT INTO posts (topicid, userid, added, body, ori_body) VALUES ("
        . sqlesc($topicId) . ", " . sqlesc($userId) . ", " . sqlesc($date) . ", " . sqlesc($body) . ", " . sqlesc($body) . ")"
    ) or sqlerr(__FILE__, __LINE__);
    $postId = mysql_insert_id();
    if (!$postId) {
        die($lang_forums['std_post_id_not_available']);
    }

    sql_query("UPDATE topics SET firstpost = " . sqlesc($postId) . ", lastpost = " . sqlesc($postId) . " WHERE id = " . sqlesc($topicId)) or sqlerr(__FILE__, __LINE__);
    sql_query("UPDATE users SET last_post = " . sqlesc($date) . " WHERE id = " . sqlesc($userId)) or sqlerr(__FILE__, __LINE__);

    $todayDate = date('Y-m-d', TIMENOW);
    $Cache->delete_value('forum_' . $forumId . '_post_' . $todayDate . '_count');
    $Cache->delete_value('today_' . $todayDate . '_posts_count');
    $Cache->delete_value('forum_' . $forumId . '_last_replied_topic_content');
    $Cache->delete_value('topic_' . $topicId . '_post_count');
    $Cache->delete_value('user_' . $userId . '_post_count');

    header("Location: " . get_protocol_prefix() . "$BASEURL/forums.php?action=viewtopic&topicid=$topicId&page=last#pid$postId");
    die;
}

$forumId = (int) ($_GET['forumid'] ?? 0);
if ($forumId > 0) {
    if (!isset($eligibleForums[$forumId])) {
        permissiondenied();
    }

    $forum = $eligibleForums[$forumId];
    $forumTitle = $lang_forums['text_new_topic_in']
        . ' <a href="forums.php?action=viewforum&amp;forumid=' . $forumId . '">'
        . htmlspecialchars($forum['name'], ENT_QUOTES, 'UTF-8')
        . '</a> ' . $lang_forums['text_forum'];

    stdhead($lang_forums['head_new_topic']);
    begin_main_frame();
?>
<form id="compose" method="post" name="compose" action="newforums.php">
    <input type="hidden" name="action" value="create" />
    <input type="hidden" name="forumid" value="<?php echo $forumId ?>" />
<?php
    begin_compose($forumTitle, 'new', '', true, '', $maxSubjectLength);
    end_compose();
?>
</form>
<?php
    end_main_frame();
    stdfoot();
    die;
}

if (empty($eligibleForums)) {
    stderr($lang_forums['std_error'], $lang_forums['text_unpermitted_starting_new_topics']);
}

stdhead($lang_forums['head_new_topic']);
begin_main_frame();
?>
<form method="get" action="newforums.php" style="text-align: center">
    <label for="forumid"><?php echo $lang_forums['text_forum'] ?></label>
    <select id="forumid" name="forumid" required>
        <?php foreach ($eligibleForums as $forum) { ?>
        <option value="<?php echo (int) $forum['id'] ?>"><?php echo htmlspecialchars($forum['name'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php } ?>
    </select>
    <input type="submit" class="btn" value="<?php echo $lang_forums['submit_go'] ?>" />
</form>
<?php
end_main_frame();
stdfoot();
