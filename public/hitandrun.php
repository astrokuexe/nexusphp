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

if (get_user_class() < UC_MODERATOR) {
    permissiondenied();
}

$activeStatuses = [
    \App\Models\HitAndRun::STATUS_INSPECTING,
    \App\Models\HitAndRun::STATUS_UNREACHED,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string) ($_POST['_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        stderr(nexus_trans('hr.error'), nexus_trans('hr.invalid_request'));
    }

    $record = \App\Models\HitAndRun::query()
        ->with('user')
        ->whereIn('status', $activeStatuses)
        ->findOrFail((int) ($_POST['record_id'] ?? 0));
    $operator = \App\Models\User::query()->findOrFail((int) $CURUSER['id']);
    $repository = new \App\Repositories\HitAndRunRepository();

    switch ($_POST['action'] ?? '') {
        case 'allow':
            if (!in_array((int) $record->status, \App\Models\HitAndRun::CAN_PARDON_STATUS, true)) {
                stderr(nexus_trans('hr.error'), nexus_trans('hr.permission_denied'));
            }
            $repository->pardon($record->id, $operator);
            break;
        case 'delete':
            $repository->delete($record->id);
            break;
        case 'suspend':
            $targetUser = $record->user;
            if (
                !$targetUser
                || $targetUser->id == $operator->id
                || $targetUser->enabled !== \App\Models\User::ENABLED_YES
                || $operator->class <= $targetUser->class
                || $operator->class < (int) get_setting('authority.prfmanage')
            ) {
                stderr(nexus_trans('hr.error'), nexus_trans('hr.permission_denied'));
            }
            (new \App\Repositories\UserRepository())->disableUser(
                $operator,
                $targetUser->id,
                sprintf('Hit and Run record #%d', $record->id)
            );
            break;
        default:
            stderr(nexus_trans('hr.error'), nexus_trans('hr.invalid_request'));
    }

    header('Location: hitandrun.php?updated=1');
    die;
}

stdhead(nexus_trans('hr.staff_title'));
begin_main_frame();
begin_frame(nexus_trans('hr.staff_title'));

if (isset($_GET['updated'])) {
    print('<p class="success">' . htmlspecialchars(nexus_trans('hr.action_success')) . '</p>');
}

$query = \App\Models\HitAndRun::query()
    ->with(['user', 'torrent'])
    ->whereIn('status', $activeStatuses)
    ->whereHas('user')
    ->orderByDesc('id');
$records = $query->paginate(30);
$token = csrf_token();
$renderAction = function ($record, string $action, string $label, string $confirmation) use ($token): string {
    return sprintf(
        '<form method="post" action="hitandrun.php" style="display:inline" data-confirm="%s" onsubmit="return confirm(this.dataset.confirm)">' .
        '<input type="hidden" name="_token" value="%s" />' .
        '<input type="hidden" name="record_id" value="%d" />' .
        '<input type="hidden" name="action" value="%s" />' .
        '<button type="submit" class="btn">%s</button></form> ',
        htmlspecialchars($confirmation, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($token, ENT_QUOTES, 'UTF-8'),
        (int) $record->id,
        htmlspecialchars($action, ENT_QUOTES, 'UTF-8'),
        htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
    );
};

if ($records->isEmpty()) {
    print('<p>' . htmlspecialchars(nexus_trans('hr.staff_empty')) . '</p>');
} else {
    print('<table width="100%" border="1" cellspacing="0" cellpadding="5">');
    print('<tr>' .
        '<td class="colhead">' . htmlspecialchars(nexus_trans('hr.staff_user')) . '</td>' .
        '<td class="colhead">' . htmlspecialchars(nexus_trans('hr.staff_torrent')) . '</td>' .
        '<td class="colhead">' . htmlspecialchars(nexus_trans('hr.staff_status')) . '</td>' .
        '<td class="colhead">' . htmlspecialchars(nexus_trans('hr.staff_seed_time')) . '</td>' .
        '<td class="colhead">' . htmlspecialchars(nexus_trans('hr.staff_inspect_time')) . '</td>' .
        '<td class="colhead">' . htmlspecialchars(nexus_trans('hr.staff_created_at')) . '</td>' .
        '<td class="colhead">' . htmlspecialchars(nexus_trans('hr.staff_actions')) . '</td>' .
        '</tr>');

    foreach ($records as $record) {
        $targetUser = $record->user;
        $username = $targetUser ? get_username($targetUser->id) : htmlspecialchars((string) $record->uid);
        $torrentName = $record->torrent
            ? '<a href="details.php?id=' . (int) $record->torrent_id . '">' . htmlspecialchars($record->torrent->name) . '</a>'
            : htmlspecialchars((string) $record->torrent_id);
        $actions = '';
        if (in_array((int) $record->status, \App\Models\HitAndRun::CAN_PARDON_STATUS, true)) {
            $actions .= $renderAction($record, 'allow', nexus_trans('hr.action_allow'), nexus_trans('hr.action_confirm_allow'));
        }
        $actions .= $renderAction($record, 'delete', nexus_trans('hr.action_delete'), nexus_trans('hr.action_confirm_delete'));
        if (
            $targetUser
            && $targetUser->enabled === \App\Models\User::ENABLED_YES
            && $targetUser->id != $CURUSER['id']
            && $CURUSER['class'] > $targetUser->class
            && $CURUSER['class'] >= (int) get_setting('authority.prfmanage')
        ) {
            $actions .= $renderAction($record, 'suspend', nexus_trans('hr.action_suspend'), nexus_trans('hr.action_confirm_suspend'));
        }

        print('<tr>' .
            '<td><a href="userdetails.php?id=' . (int) $record->uid . '">' . $username . '</a></td>' .
            '<td>' . $torrentName . '</td>' .
            '<td>' . htmlspecialchars($record->statusText) . '</td>' .
            '<td>' . htmlspecialchars($record->seedTimeRequired) . '</td>' .
            '<td>' . htmlspecialchars($record->inspectTimeLeft) . '</td>' .
            '<td>' . htmlspecialchars((string) $record->created_at) . '</td>' .
            '<td>' . $actions . '</td>' .
            '</tr>');
    }
    print('</table>');
    print($records->links());
}

end_frame();
end_main_frame();
stdfoot();
