<?php

return [
    'menu' => 'H&R',
    'staff_title' => 'Hit and Run review',
    'staff_empty' => 'No active H&R records found.',
    'staff_user' => 'User',
    'staff_torrent' => 'Torrent',
    'staff_status' => 'Status',
    'staff_seed_time' => 'Seed time required',
    'staff_inspect_time' => 'Time left to inspect',
    'staff_created_at' => 'Created',
    'staff_actions' => 'Actions',
    'action_suspend' => 'Suspend',
    'action_delete' => 'Delete H&R',
    'action_allow' => 'Allow / Pardon',
    'action_confirm_suspend' => 'Suspend this user account?',
    'action_confirm_delete' => 'Delete this H&R record?',
    'action_confirm_allow' => 'Pardon this H&R record?',
    'action_success' => 'H&R action completed.',
    'error' => 'H&R action error',
    'invalid_request' => 'Invalid request.',
    'permission_denied' => 'You are not allowed to perform this action.',

    'status_' . \App\Models\HitAndRun::STATUS_INSPECTING => 'Inspecting',
    'status_' . \App\Models\HitAndRun::STATUS_REACHED => 'Reached',
    'status_' . \App\Models\HitAndRun::STATUS_UNREACHED => 'Unreached',
    'status_' . \App\Models\HitAndRun::STATUS_PARDONED => 'Pardoned',

    'mode_' . \App\Models\HitAndRun::MODE_DISABLED => 'Disabled',
    'mode_' . \App\Models\HitAndRun::MODE_MANUAL => 'Manual',
    'mode_' . \App\Models\HitAndRun::MODE_GLOBAL => 'Global',

    'reached_by_seed_time_comment' => 'Up to：:now，seed time: :seed_time Hour(s) reached :seed_time_minimum Hour(s)',
    'reached_by_leech_time_comment' => 'Up to：:now，leech time: :leech_time Hour(s) reached :leech_time_minimum Hour(s)',
    'reached_by_share_ratio_comment' => "Up to：:now \nseed time: :seed_time Hour(s) Unreached :seed_time_minimum Hour(s) \nShare ratio: :share_ratio reached standard：:ignore_when_ratio_reach",
    'reached_by_special_user_class_comment' => "Your user class: :user_class_text or donated user, ignore this H&R",
    'reached_message_subject' => 'H&R(ID: :hit_and_run_id) reached!',
    'reached_message_content' => 'Congratulation! The torrent: :torrent_name(ID: :torrent_id) you download at: :completed_at has reach the requirement.',

    'unreached_comment' => "Up to：:now \nseed time： :seed_time Hour(s) Unreached the requirement：:seed_time_minimum Hour(s) \nshare ratio：:share_ratio unreached the requirement：:ignore_when_ratio_reach too",
    'unreached_message_subject' => 'H&R(ID: :hit_and_run_id) unreached!',
    'unreached_message_content' => 'The torrent :torrent_name(ID: :torrent_id) you downloaded on :completed_at: did not reached! Please note that accumulating a certain number of H&R your account will be disabled.',

    'unreached_disable_comment' => 'H&R quantity reached the upper limit and account was disabled by the system',
    'unreached_disable_message_content' => 'Your account has been disabled because the cumulative H&R count has been reached the system limit: :ban_user_when_counts_reach',

    'bonus_cancel_comment' => 'spend :bonus canceled',
    'remove_confirm_msg' => 'Eliminate an H&R by deducting :bonus bonus, OK?',
];
