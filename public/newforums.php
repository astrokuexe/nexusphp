<?php
use Illuminate\Support\Facades\DB;

require_once("../include/bittorrent.php");
$legacyHook = $GLOBALS['hook'];
$legacyPlugin = $GLOBALS['plugin'];
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$GLOBALS['hook'] = $hook = $legacyHook;
$GLOBALS['plugin'] = $plugin = $legacyPlugin;
dbconn();
require_once(get_langfile_path('forums.php'));
loggedinorreturn();
parked();

if ($enableextforum === 'yes') {
    permissiondenied();
}

\Nexus\Database\RuntimeMigrations::ensureStandaloneForumTables();
\Nexus\Database\RuntimeMigrations::ensureStandaloneForumTorrentsTable();

$text = [
    'title' => 'Community Forums',
    'new_topic' => 'New Topic',
    'manage' => 'Manage Forums',
    'new_group' => 'Create Forum Group',
    'new_forum' => 'Create Forum',
    'group' => 'Group',
    'forum' => 'Forum',
    'topic' => 'Topic',
    'topics' => 'Topics',
    'posts' => 'Posts',
    'description' => 'Description',
    'name' => 'Name',
    'order' => 'Order',
    'read_class' => 'Minimum class to read',
    'write_class' => 'Minimum class to reply',
    'create_class' => 'Minimum class to create topics',
    'save' => 'Save',
    'delete' => 'Delete',
    'last_post' => 'Last Post',
    'no_groups' => 'No groups or forums have been created.',
    'no_topics' => 'There are no topics in this forum.',
    'empty' => 'This forum is empty.',
    'reply' => 'Reply to Topic',
    'submit' => 'Submit',
    'back' => 'Back to forums',
    'new_torrents' => 'New Torrents',
];
$escape = static fn ($value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$input = static function (string $name): string {
    $value = $_POST[$name] ?? '';
    return is_string($value) ? trim($value) : '';
};
$redirect = static function (string $url): void {
    header('Location: ' . $url);
    exit;
};
$canManage = user_can('forummanage');
$userClass = get_user_class();
$canPost = ($CURUSER['forumpost'] ?? 'no') === 'yes';
$csrfToken = nexus_csrf_token('standalone_forum', (string) $CURUSER['id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['_token'] ?? '';
    $token = is_string($token) ? $token : '';
    if ($token === '' || !hash_equals($csrfToken, $token)) {
        stderr('Forum error', 'Invalid request. Reload the page and try again.');
    }

    $postAction = $input('action');
    $deleteAction = $_POST['delete_action'] ?? '';
    if (is_string($deleteAction) && in_array($deleteAction, ['group_delete', 'forum_delete'], true)) {
        $postAction = $deleteAction;
    }
    if (str_starts_with($postAction, 'group_') || str_starts_with($postAction, 'forum_')) {
        if (!$canManage) {
            permissiondenied();
        }

        if ($postAction === 'group_create' || $postAction === 'group_save') {
            $groupId = (int) $input('id');
            $name = $input('name');
            $description = $input('description');
            $sort = max(0, min(65535, (int) $input('sort')));
            if ($name === '' || mb_strlen($name, 'UTF-8') > 60 || mb_strlen($description, 'UTF-8') > 255) {
                stderr('Forum error', 'The group name is required (maximum 60 characters); the description must be 255 characters or fewer.');
            }

            $data = ['name' => $name, 'description' => $description, 'sort' => $sort];
            if ($postAction === 'group_create') {
                DB::table('standalone_forum_groups')->insert($data);
            } else {
                DB::table('standalone_forum_groups')->where('id', $groupId)->update($data);
            }
            $redirect('newforums.php?action=manage');
        }

        if ($postAction === 'group_delete') {
            $groupId = (int) $input('id');
            DB::transaction(static function () use ($groupId): void {
                $forumIds = DB::table('standalone_forums')
                    ->where('group_id', $groupId)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
                if ($forumIds) {
                    $topicIds = DB::table('standalone_forum_topics')
                        ->whereIn('forum_id', $forumIds)
                        ->pluck('id')
                        ->all();
                    if ($topicIds) {
                        DB::table('standalone_forum_posts')->whereIn('topic_id', $topicIds)->delete();
                        DB::table('standalone_forum_topics')->whereIn('id', $topicIds)->delete();
                    }
                    DB::table('standalone_forum_torrents')->whereIn('forum_id', $forumIds)->delete();
                    DB::table('standalone_forums')->whereIn('id', $forumIds)->delete();
                }
                DB::table('standalone_forum_groups')->where('id', $groupId)->delete();
            });
            $redirect('newforums.php?action=manage');
        }

        if ($postAction === 'forum_create' || $postAction === 'forum_save') {
            $forumId = (int) $input('id');
            $groupId = (int) $input('group_id');
            $name = $input('name');
            $description = $input('description');
            $sort = max(0, min(65535, (int) $input('sort')));
            $maxClass = max(0, $userClass);
            $readClass = (int) $input('minclassread');
            $writeClass = (int) $input('minclasswrite');
            $createClass = (int) $input('minclasscreate');
            if (
                $name === ''
                || mb_strlen($name, 'UTF-8') > 60
                || mb_strlen($description, 'UTF-8') > 255
                || !DB::table('standalone_forum_groups')->where('id', $groupId)->exists()
                || min($readClass, $writeClass, $createClass) < 0
                || max($readClass, $writeClass, $createClass) > $maxClass
            ) {
                stderr('Forum error', 'The forum data is invalid. Select a group and make sure the permission classes are available.');
            }

            $data = [
                'group_id' => $groupId,
                'name' => $name,
                'description' => $description,
                'sort' => $sort,
                'minclassread' => $readClass,
                'minclasswrite' => $writeClass,
                'minclasscreate' => $createClass,
            ];
            if ($postAction === 'forum_create') {
                DB::table('standalone_forums')->insert($data);
            } else {
                DB::table('standalone_forums')->where('id', $forumId)->update($data);
            }
            $redirect('newforums.php?action=manage');
        }

        if ($postAction === 'forum_delete') {
            $forumId = (int) $input('id');
            DB::transaction(static function () use ($forumId): void {
                $topicIds = DB::table('standalone_forum_topics')
                    ->where('forum_id', $forumId)
                    ->pluck('id')
                    ->all();
                if ($topicIds) {
                    DB::table('standalone_forum_posts')->whereIn('topic_id', $topicIds)->delete();
                    DB::table('standalone_forum_topics')->whereIn('id', $topicIds)->delete();
                }
                DB::table('standalone_forum_torrents')->where('forum_id', $forumId)->delete();
                DB::table('standalone_forums')->where('id', $forumId)->delete();
            });
            $redirect('newforums.php?action=manage');
        }

        if (str_starts_with($postAction, 'group_') || str_starts_with($postAction, 'forum_')) {
            stderr('Forum error', 'Unknown management action.');
        }
    }

    if (!$canPost) {
        permissiondenied();
    }

    if ($postAction === 'create_topic') {
        $forumId = (int) $input('forum_id');
        $forum = DB::table('standalone_forums')->where('id', $forumId)->first();
        if (
            !$forum
            || $userClass < $forum->minclassread
            || $userClass < $forum->minclasswrite
            || $userClass < $forum->minclasscreate
        ) {
            permissiondenied();
        }

        $subject = $input('subject');
        $body = $input('body');
        if ($subject === '' || mb_strlen($subject, 'UTF-8') > 128 || $body === '') {
            stderr('Forum error', 'A subject is required (maximum 128 characters), and the topic body cannot be empty.');
        }
        if (!user_can('postmanage') && strtotime($CURUSER['last_post']) > (TIMENOW - 10)) {
            $seconds = max(1, 10 - (TIMENOW - strtotime($CURUSER['last_post'])));
            stderr('Forum error', 'Please wait ' . $seconds . ' seconds before posting again.', false);
        }

        $userId = (int) $CURUSER['id'];
        $now = date('Y-m-d H:i:s', TIMENOW);
        $topicId = DB::transaction(static function () use ($forumId, $userId, $subject, $body, $now): int {
            $topicId = DB::table('standalone_forum_topics')->insertGetId([
                'forum_id' => $forumId,
                'user_id' => $userId,
                'subject' => $subject,
                'created_at' => $now,
            ]);
            $postId = DB::table('standalone_forum_posts')->insertGetId([
                'topic_id' => $topicId,
                'user_id' => $userId,
                'body' => $body,
                'created_at' => $now,
            ]);
            DB::table('standalone_forum_topics')->where('id', $topicId)->update([
                'first_post_id' => $postId,
                'last_post_id' => $postId,
            ]);
            DB::table('standalone_forums')->where('id', $forumId)->increment('topiccount');
            DB::table('standalone_forums')->where('id', $forumId)->increment('postcount');
            return (int) $topicId;
        });
        KPS('+', $starttopic_bonus, $userId);
        DB::table('users')->where('id', $userId)->update(['last_post' => $now]);
        $redirect('newforums.php?action=viewtopic&topicid=' . $topicId);
    }

    if ($postAction === 'reply') {
        $topicId = (int) $input('topic_id');
        $topic = DB::table('standalone_forum_topics as t')
            ->join('standalone_forums as f', 'f.id', '=', 't.forum_id')
            ->where('t.id', $topicId)
            ->select('t.*', 'f.minclassread', 'f.minclasswrite')
            ->first();
        if (
            !$topic
            || $userClass < $topic->minclassread
            || $userClass < $topic->minclasswrite
            || $topic->locked
        ) {
            permissiondenied();
        }

        $body = $input('body');
        if ($body === '') {
            stderr('Forum error', 'The reply body cannot be empty.');
        }
        if (!user_can('postmanage') && strtotime($CURUSER['last_post']) > (TIMENOW - 10)) {
            $seconds = max(1, 10 - (TIMENOW - strtotime($CURUSER['last_post'])));
            stderr('Forum error', 'Please wait ' . $seconds . ' seconds before posting again.', false);
        }

        $userId = (int) $CURUSER['id'];
        $now = date('Y-m-d H:i:s', TIMENOW);
        DB::transaction(static function () use ($topicId, $topic, $userId, $body, $now): void {
            $postId = DB::table('standalone_forum_posts')->insertGetId([
                'topic_id' => $topicId,
                'user_id' => $userId,
                'body' => $body,
                'created_at' => $now,
            ]);
            DB::table('standalone_forum_topics')->where('id', $topicId)->update(['last_post_id' => $postId]);
            DB::table('standalone_forums')->where('id', $topic->forum_id)->increment('postcount');
        });
        KPS('+', $makepost_bonus, $userId);
        DB::table('users')->where('id', $userId)->update(['last_post' => $now]);
        $redirect('newforums.php?action=viewtopic&topicid=' . $topicId . '&page=last');
    }

    stderr('Forum error', 'Unknown forum action.');
}

$action = $_GET['action'] ?? 'index';
if (!is_string($action)) {
    $action = 'index';
}
$pageTitle = $text['title'];

if ($action === 'manage') {
    if (!$canManage) {
        permissiondenied();
    }

    $groups = DB::table('standalone_forum_groups')->orderBy('sort')->orderBy('id')->get();
    $forums = DB::table('standalone_forums as f')
        ->join('standalone_forum_groups as g', 'g.id', '=', 'f.group_id')
        ->select('f.*', 'g.name as group_name')
        ->orderBy('g.sort')->orderBy('f.sort')->orderBy('f.id')->get();
    $pageTitle = $text['manage'];
    stdhead($pageTitle);
    begin_main_frame();
?>
<p><a href="newforums.php"><?php echo $text['back'] ?></a></p>
<h2><?php echo $text['new_group'] ?></h2>
<form method="post" action="newforums.php?action=manage">
    <input type="hidden" name="_token" value="<?php echo $escape($csrfToken) ?>">
    <input type="hidden" name="action" value="group_create">
    <label><?php echo $text['name'] ?> <input name="name" maxlength="60" required></label>
    <label><?php echo $text['description'] ?> <input name="description" maxlength="255"></label>
    <label><?php echo $text['order'] ?> <input type="number" name="sort" min="0" max="65535" value="0"></label>
    <button class="btn" type="submit"><?php echo $text['save'] ?></button>
</form>

<h2><?php echo $text['new_forum'] ?></h2>
<?php if ($groups->isEmpty()) { ?>
<p><?php echo $text['empty'] ?> <?php echo $text['new_group'] ?>.</p>
<?php } else { ?>
<form method="post" action="newforums.php?action=manage">
    <input type="hidden" name="_token" value="<?php echo $escape($csrfToken) ?>">
    <input type="hidden" name="action" value="forum_create">
    <label><?php echo $text['group'] ?>
        <select name="group_id" required>
            <?php foreach ($groups as $group) { ?>
            <option value="<?php echo (int) $group->id ?>"><?php echo $escape($group->name) ?></option>
            <?php } ?>
        </select>
    </label>
    <label><?php echo $text['name'] ?> <input name="name" maxlength="60" required></label>
    <label><?php echo $text['description'] ?> <input name="description" maxlength="255"></label>
    <label><?php echo $text['order'] ?> <input type="number" name="sort" min="0" max="65535" value="0"></label>
    <?php foreach (['minclassread' => 'read_class', 'minclasswrite' => 'write_class', 'minclasscreate' => 'create_class'] as $field => $label) { ?>
    <label><?php echo $text[$label] ?>
        <select name="<?php echo $field ?>">
            <?php for ($class = 0; $class <= $userClass; $class++) { ?>
            <option value="<?php echo $class ?>"><?php echo $escape(get_user_class_name($class, false, false, true)) ?></option>
            <?php } ?>
        </select>
    </label>
    <?php } ?>
    <button class="btn" type="submit"><?php echo $text['save'] ?></button>
</form>
<?php } ?>

<h2><?php echo $text['group'] ?> &amp; <?php echo $text['forum'] ?></h2>
<?php foreach ($groups as $group) { ?>
<form method="post" action="newforums.php?action=manage" style="margin: 8px 0">
    <input type="hidden" name="_token" value="<?php echo $escape($csrfToken) ?>">
    <input type="hidden" name="action" value="group_save">
    <input type="hidden" name="id" value="<?php echo (int) $group->id ?>">
    <input name="name" maxlength="60" value="<?php echo $escape($group->name) ?>" required>
    <input name="description" maxlength="255" value="<?php echo $escape($group->description) ?>">
    <input type="number" name="sort" min="0" max="65535" value="<?php echo (int) $group->sort ?>">
    <button class="btn" type="submit"><?php echo $text['save'] ?></button>
    <button class="btn" type="submit" name="delete_action" value="group_delete"
        onclick="return confirm('Delete this group and every forum, topic, and post inside it?')"><?php echo $text['delete'] ?></button>
</form>
<?php } ?>

<?php foreach ($forums as $forum) { ?>
<form method="post" action="newforums.php?action=manage" style="margin: 8px 0">
    <input type="hidden" name="_token" value="<?php echo $escape($csrfToken) ?>">
    <input type="hidden" name="action" value="forum_save">
    <input type="hidden" name="id" value="<?php echo (int) $forum->id ?>">
    <select name="group_id">
        <?php foreach ($groups as $group) { ?>
        <option value="<?php echo (int) $group->id ?>" <?php echo $group->id == $forum->group_id ? 'selected' : '' ?>><?php echo $escape($group->name) ?></option>
        <?php } ?>
    </select>
    <input name="name" maxlength="60" value="<?php echo $escape($forum->name) ?>" required>
    <input name="description" maxlength="255" value="<?php echo $escape($forum->description) ?>">
    <input type="number" name="sort" min="0" max="65535" value="<?php echo (int) $forum->sort ?>">
    <?php foreach (['minclassread', 'minclasswrite', 'minclasscreate'] as $field) { ?>
    <select name="<?php echo $field ?>">
        <?php for ($class = 0; $class <= $userClass; $class++) { ?>
        <option value="<?php echo $class ?>" <?php echo $class == $forum->$field ? 'selected' : '' ?>><?php echo $escape(get_user_class_name($class, false, false, true)) ?></option>
        <?php } ?>
    </select>
    <?php } ?>
    <button class="btn" type="submit"><?php echo $text['save'] ?></button>
    <button class="btn" type="submit" name="delete_action" value="forum_delete"
        onclick="return confirm('Delete this forum and every topic and post inside it?')"><?php echo $text['delete'] ?></button>
</form>
<?php } ?>
<?php
    end_main_frame();
    stdfoot();
    exit;
}

if ($action === 'newtopic') {
    if (!$canPost) {
        permissiondenied();
    }
    $eligibleForums = DB::table('standalone_forums')
        ->where('minclassread', '<=', $userClass)
        ->where('minclasswrite', '<=', $userClass)
        ->where('minclasscreate', '<=', $userClass)
        ->orderBy('group_id')->orderBy('sort')->orderBy('id')->get();
    if ($eligibleForums->isEmpty()) {
        stderr('Forum error', 'There is no forum available for you to create topics in.');
    }
    $selectedForumId = filter_var($_GET['forumid'] ?? 0, FILTER_VALIDATE_INT);
    $selectedForumId = $selectedForumId === false ? 0 : $selectedForumId;
    $selectedForum = $eligibleForums->firstWhere('id', $selectedForumId);
    $pageTitle = $text['new_topic'];
    stdhead($pageTitle);
    begin_main_frame();
    if ($canManage) {
        echo '<p><a href="newforums.php?action=manage">' . $text['manage'] . '</a></p>';
    }
    if (!$selectedForum) {
?>
<form method="get" action="newforums.php" style="text-align: center">
    <input type="hidden" name="action" value="newtopic">
    <label for="forumid"><?php echo $text['forum'] ?></label>
    <select id="forumid" name="forumid" required>
        <?php foreach ($eligibleForums as $forum) { ?>
        <option value="<?php echo (int) $forum->id ?>"><?php echo $escape($forum->name) ?></option>
        <?php } ?>
    </select>
    <button class="btn" type="submit"><?php echo $lang_forums['submit_go'] ?></button>
</form>
<?php
    } else {
?>
<form id="compose" method="post" name="compose" action="newforums.php">
    <input type="hidden" name="_token" value="<?php echo $escape($csrfToken) ?>">
    <input type="hidden" name="action" value="create_topic">
    <input type="hidden" name="forum_id" value="<?php echo (int) $selectedForum->id ?>">
<?php
        begin_compose(
            htmlspecialchars($text['new_topic'] . ': ' . $selectedForum->name, ENT_QUOTES, 'UTF-8'),
            'new',
            '',
            true,
            '',
            128
        );
        end_compose();
?>
</form>
<?php
    }
    end_main_frame();
    stdfoot();
    exit;
}

if ($action === 'viewforum') {
    $forumId = (int) ($_GET['forumid'] ?? 0);
    $forum = DB::table('standalone_forums as f')
        ->join('standalone_forum_groups as g', 'g.id', '=', 'f.group_id')
        ->where('f.id', $forumId)
        ->select('f.*', 'g.name as group_name', 'g.id as group_id')
        ->first();
    if (!$forum || ($userClass < $forum->minclassread && !$canManage)) {
        permissiondenied();
    }
    $topics = DB::table('standalone_forum_topics as t')
        ->join('users as u', 'u.id', '=', 't.user_id')
        ->leftJoin('standalone_forum_posts as p', 'p.id', '=', 't.last_post_id')
        ->leftJoin('users as lp_user', 'lp_user.id', '=', 'p.user_id')
        ->where('t.forum_id', $forumId)
        ->select('t.*', 'u.username as author', 'lp_user.username as last_author', 'p.created_at as last_post_at')
        ->selectSub(DB::table('standalone_forum_posts as topic_posts')
            ->selectRaw('COUNT(*)')
            ->whereColumn('topic_posts.topic_id', 't.id'), 'topic_post_count')
        ->orderByDesc('t.last_post_id')
        ->get();
    $latestTorrentComments = DB::table('comments')
        ->select('torrent')
        ->selectRaw('MAX(id) AS id')
        ->where('torrent', '>', 0)
        ->groupBy('torrent');
    $forumTorrents = DB::table('standalone_forum_torrents as ft')
        ->join('torrents as t', 't.id', '=', 'ft.torrent_id')
        ->leftJoin('users as u', 'u.id', '=', 't.owner')
        ->leftJoinSub($latestTorrentComments, 'latest_torrent_comment', static function ($join): void {
            $join->on('latest_torrent_comment.torrent', '=', 't.id');
        })
        ->leftJoin('comments as last_comment', 'last_comment.id', '=', 'latest_torrent_comment.id')
        ->leftJoin('users as last_comment_user', 'last_comment_user.id', '=', 'last_comment.user')
        ->where('ft.forum_id', $forumId)
        ->where('t.visible', 'yes')
        ->where('t.banned', 'no')
        ->select(
            't.id',
            't.name',
            't.size',
            't.added',
            't.owner',
            't.anonymous',
            't.seeders',
            't.leechers',
            'u.username',
            'last_comment.user as last_reply_user_id',
            'last_comment.anonymous as last_reply_anonymous',
            'last_comment.added as last_reply_at',
            'last_comment_user.username as last_reply_username'
        )
        ->orderByDesc('ft.created_at')
        ->get();
    $canUploadToForum = ($CURUSER['uploadpos'] ?? 'no') === 'yes'
        && $userClass >= $forum->minclassread
        && $userClass >= $forum->minclasswrite
        && $userClass >= $forum->minclasscreate;
    $pageTitle = $forum->name;
    stdhead($pageTitle);
    begin_main_frame();
?>
<p>
    <a href="newforums.php"><?php echo $text['back'] ?></a> /
    <a href="newforums.php?groupid=<?php echo (int) $forum->group_id ?>"><?php echo $escape($forum->group_name) ?></a> /
    <a href="newforums.php?action=viewforum&amp;forumid=<?php echo (int) $forum->id ?>"><b><?php echo $escape($forum->name) ?></b></a>
</p>
<p><?php echo $escape($forum->description) ?></p>
<?php if ($canUploadToForum) { ?>
<p style="text-align: right; margin-bottom: 5px">
    <a class="btn" href="upload.php?forumid=<?php echo (int) $forum->id ?>"><?php echo $text['new_torrents'] ?></a>
</p>
<?php } ?>
<?php if ($forumTorrents->isNotEmpty()) { ?>
<table class="main" width="100%" cellspacing="0" cellpadding="5">
    <tr>
        <td class="colhead">Name</td>
        <td class="colhead" align="center">Size</td>
        <td class="colhead">Last Activity</td>
    </tr>
    <?php foreach ($forumTorrents as $torrent) { ?>
    <tr>
        <td class="rowfollow"><a href="details.php?id=<?php echo (int) $torrent->id ?>"><?php echo $escape($torrent->name) ?></a></td>
        <td class="rowfollow" align="center">
            <span style="color: green"><?php echo (int) $torrent->seeders ?></span>
            |
            <span style="color: red"><?php echo (int) $torrent->leechers ?></span>
            <br><span class="small"><?php echo mksize((int) $torrent->size) ?></span>
        </td>
        <td class="rowfollow">
            <?php if ($torrent->last_reply_user_id !== null) { ?>
                <?php echo $torrent->last_reply_anonymous === 'yes' ? 'Anonymous' : $escape($torrent->last_reply_username ?? '') ?>
                <?php if ($torrent->last_reply_at) { ?><br><span class="small"><?php echo gettime($torrent->last_reply_at) ?></span><?php } ?>
            <?php } else { ?>
                <?php echo $torrent->anonymous === 'yes' ? 'Anonymous' : $escape($torrent->username ?? '') ?>
                <?php if ($torrent->added) { ?><br><span class="small"><?php echo gettime($torrent->added) ?></span><?php } ?>
            <?php } ?>
        </td>
    </tr>
    <?php } ?>
</table>
<?php } ?>
<?php if ($topics->isEmpty() && $forumTorrents->isEmpty()) { ?>
<p><?php echo $text['no_topics'] ?></p>
<?php } elseif ($topics->isNotEmpty()) { ?>
<table class="main" width="100%" cellspacing="0" cellpadding="5">
    <tr><td class="colhead"><?php echo $text['topic'] ?></td><td class="colhead"><?php echo $text['posts'] ?></td><td class="colhead"><?php echo $text['last_post'] ?></td></tr>
    <?php foreach ($topics as $topic) { ?>
    <tr>
        <td class="rowfollow"><a href="newforums.php?action=viewtopic&amp;topicid=<?php echo (int) $topic->id ?>"><?php echo $escape($topic->subject) ?></a><br><span class="small"><?php echo $escape($topic->author) ?></span></td>
        <td class="rowfollow"><?php echo (int) $topic->topic_post_count ?></td>
        <td class="rowfollow"><?php echo $escape($topic->last_author ?? '') ?><?php if ($topic->last_post_at) { ?><br><span class="small"><?php echo $escape(gettime($topic->last_post_at)) ?></span><?php } ?></td>
    </tr>
    <?php } ?>
</table>
<?php } ?>
<?php
    end_main_frame();
    stdfoot();
    exit;
}

if ($action === 'viewtopic') {
    $topicId = (int) ($_GET['topicid'] ?? 0);
    $topic = DB::table('standalone_forum_topics as t')
        ->join('standalone_forums as f', 'f.id', '=', 't.forum_id')
        ->join('standalone_forum_groups as g', 'g.id', '=', 'f.group_id')
        ->where('t.id', $topicId)
        ->select('t.*', 'f.name as forum_name', 'f.minclassread', 'f.minclasswrite', 'g.name as group_name')
        ->first();
    if (!$topic || ($userClass < $topic->minclassread && !$canManage)) {
        permissiondenied();
    }

    $postCount = DB::table('standalone_forum_posts')->where('topic_id', $topicId)->count();
    $perPage = 20;
    $requestedPage = $_GET['page'] ?? '0';
    if (!is_string($requestedPage) && !is_int($requestedPage)) {
        $requestedPage = '0';
    }
    $page = $requestedPage === 'last'
        ? max(0, (int) ceil(max(1, $postCount) / $perPage) - 1)
        : max(0, (int) $requestedPage);
    $page = min($page, max(0, (int) ceil(max(1, $postCount) / $perPage) - 1));
    $posts = DB::table('standalone_forum_posts as p')
        ->join('users as u', 'u.id', '=', 'p.user_id')
        ->where('p.topic_id', $topicId)
        ->select('p.*', 'u.username')
        ->orderBy('p.id')
        ->offset($page * $perPage)
        ->limit($perPage)
        ->get();
    DB::table('standalone_forum_topics')->where('id', $topicId)->increment('views');
    $pageTitle = $topic->subject;
    stdhead($pageTitle);
    begin_main_frame();
?>
<p><a href="newforums.php"><?php echo $text['back'] ?></a> / <a href="newforums.php?action=viewforum&amp;forumid=<?php echo (int) $topic->forum_id ?>"><?php echo $escape($topic->group_name) ?> / <?php echo $escape($topic->forum_name) ?></a></p>
<h2><?php echo $escape($topic->subject) ?></h2>
<?php foreach ($posts as $post) { ?>
<table class="main" width="100%" cellspacing="0" cellpadding="5" style="margin: 10px 0">
    <tr><td class="colhead"><?php echo $escape($post->username) ?> <span class="small"><?php echo $escape(gettime($post->created_at)) ?></span></td></tr>
    <tr><td class="rowfollow"><?php echo format_comment($post->body) ?></td></tr>
</table>
<?php } ?>
<?php if ($postCount > $perPage) { ?>
<p style="text-align: center">
    <?php for ($pageNumber = 0; $pageNumber < (int) ceil($postCount / $perPage); $pageNumber++) { ?>
    <a href="newforums.php?action=viewtopic&amp;topicid=<?php echo $topicId ?>&amp;page=<?php echo $pageNumber ?>"><?php echo $pageNumber + 1 ?></a>
    <?php } ?>
</p>
<?php } ?>
<?php if ($canPost && $userClass >= $topic->minclasswrite && !$topic->locked) { ?>
<form id="compose" method="post" name="compose" action="newforums.php">
    <input type="hidden" name="_token" value="<?php echo $escape($csrfToken) ?>">
    <input type="hidden" name="action" value="reply">
    <input type="hidden" name="topic_id" value="<?php echo $topicId ?>">
    <?php begin_compose($text['reply'], 'reply', '', false); end_compose(); ?>
</form>
<?php } ?>
<?php
    end_main_frame();
    stdfoot();
    exit;
}

if ($action !== 'index') {
    httperr();
}

$selectedGroupId = null;
if (array_key_exists('groupid', $_GET)) {
    $selectedGroupId = filter_var($_GET['groupid'], FILTER_VALIDATE_INT);
    if ($selectedGroupId === false || $selectedGroupId < 1) {
        permissiondenied();
    }
}
$groupsQuery = DB::table('standalone_forum_groups');
if ($selectedGroupId !== null) {
    $groupsQuery->where('id', $selectedGroupId);
}
$groups = $groupsQuery->orderBy('sort')->orderBy('id')->get();
if ($selectedGroupId !== null && $groups->isEmpty()) {
    permissiondenied();
}
$pageTitle = $text['title'];
stdhead($pageTitle);
begin_main_frame();
?>
<p>
    <?php if ($selectedGroupId !== null) { ?><a href="newforums.php"><?php echo $text['back'] ?></a><?php } ?>
    <?php if ($canManage) { ?><a class="btn" href="newforums.php?action=manage"><?php echo $text['manage'] ?></a><?php } ?>
</p>
<?php if ($groups->isEmpty()) { ?>
<p><?php echo $text['no_groups'] ?></p>
<?php } ?>
<?php foreach ($groups as $group) {
    $groupForumsQuery = DB::table('standalone_forums')->where('group_id', $group->id);
    if (!$canManage) {
        $groupForumsQuery->where('minclassread', '<=', $userClass);
    }
    $groupForums = $groupForumsQuery->orderBy('sort')->orderBy('id')->get();
    if ($groupForums->isEmpty() && !$canManage) {
        if ($selectedGroupId !== null) {
            permissiondenied();
        }
        continue;
    }
?>
<h2 id="group-<?php echo (int) $group->id ?>"><?php echo $escape($group->name) ?></h2>
<?php if ($group->description !== '') { ?><p><?php echo $escape($group->description) ?></p><?php } ?>
<table class="main" width="100%" cellspacing="0" cellpadding="5">
    <?php foreach ($groupForums as $forum) { ?>
    <tr>
        <td class="rowfollow"><a href="newforums.php?action=viewforum&amp;forumid=<?php echo (int) $forum->id ?>"><?php echo $escape($forum->name) ?></a><?php if ($forum->description !== '') { ?><br><span class="small"><?php echo $escape($forum->description) ?></span><?php } ?></td>
    </tr>
    <?php } ?>
    <?php if ($groupForums->isEmpty()) { ?><tr><td class="rowfollow"><?php echo $text['empty'] ?></td></tr><?php } ?>
</table>
<?php } ?>
<?php
end_main_frame();
stdfoot();
