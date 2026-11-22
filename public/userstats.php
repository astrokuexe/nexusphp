<?php
require "../include/bittorrent.php";
dbconn();
require_once(get_langfile_path('index.php'));
require_once(get_langfile_path('users.php'));
loggedinorreturn();
parked();

if (get_user_class() < UC_MODERATOR) {
	stderr("Error", "Permission denied.");
}

$type = $_GET['type'] ?? '';
$activeToday = sqlesc(date("Y-m-d H:i:s", TIMENOW - 86400));
$activeWeek = sqlesc(date("Y-m-d H:i:s", TIMENOW - 604800));
$classStats = [
	UC_PEASANT => get_user_class_name(UC_PEASANT, false, false, true),
	UC_USER => get_user_class_name(UC_USER, false, false, true),
	UC_POWER_USER => get_user_class_name(UC_POWER_USER, false, false, true),
	UC_ELITE_USER => get_user_class_name(UC_ELITE_USER, false, false, true),
	UC_CRAZY_USER => get_user_class_name(UC_CRAZY_USER, false, false, true),
	UC_INSANE_USER => get_user_class_name(UC_INSANE_USER, false, false, true),
	UC_VETERAN_USER => get_user_class_name(UC_VETERAN_USER, false, false, true),
	UC_EXTREME_USER => get_user_class_name(UC_EXTREME_USER, false, false, true),
	UC_ULTIMATE_USER => get_user_class_name(UC_ULTIMATE_USER, false, false, true),
	UC_NEXUS_MASTER => get_user_class_name(UC_NEXUS_MASTER, false, false, true),
];
$stats = [
	'active_today' => [$lang_index['row_users_active_today'], "last_access >= $activeToday"],
	'active_week' => [$lang_index['row_users_active_this_week'], "last_access >= $activeWeek"],
	'registered' => [$lang_index['row_registered_users'], '1=1'],
	'unconfirmed' => [$lang_index['row_unconfirmed_users'], "status='pending' AND enabled='yes'"],
	'vip' => [get_user_class_name(UC_VIP, false, false, true), 'class='.UC_VIP],
	'donors' => [$lang_index['row_donors'], "donor='yes'"],
	'warned' => [$lang_index['row_warned_users'], "warned='yes'"],
	'disabled' => [$lang_index['row_banned_users'], "enabled='no'"],
	'male' => [$lang_index['row_male_users'], "gender='Male'"],
	'female' => [$lang_index['row_female_users'], "gender='Female'"],
];
foreach ($classStats as $class => $className) {
	$stats['class_'.$class] = [$className, 'class='.$class];
}

if (!is_string($type) || !isset($stats[$type])) {
	stderr("Error", "Invalid user statistic.");
}

[$title, $where] = $stats[$type];
$count = get_row_count("users", "WHERE $where");
$perpage = 50;
list($pagertop, $pagerbottom, $limit) = pager($perpage, $count, "userstats.php?type=".rawurlencode($type)."&");
$result = sql_query("SELECT id, added, last_access, class FROM users WHERE $where ORDER BY username $limit") or sqlerr(__FILE__, __LINE__);

stdhead(strip_tags($title));
begin_main_frame();
begin_frame(strip_tags($title)." (".number_format($count).")", true);
print($pagertop);
print("<table border=\"1\" cellspacing=\"0\" cellpadding=\"5\">\n");
print("<tr><td class=\"colhead\">".$lang_users['col_user_name']."</td><td class=\"colhead\">".$lang_users['col_registered']."</td><td class=\"colhead\">".$lang_users['col_last_access']."</td><td class=\"colhead\">".$lang_users['col_class']."</td></tr>\n");
while ($user = mysql_fetch_assoc($result)) {
	print("<tr><td>".get_username($user['id'])."</td><td>".gettime($user['added'], true, false)."</td><td>".gettime($user['last_access'], true, false)."</td><td>".get_user_class_name($user['class'], false, true, true)."</td></tr>\n");
}
print("</table>\n");
print($pagerbottom);
end_frame();
end_main_frame();
stdfoot();
?>
