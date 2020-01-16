<?php

// Disallow direct access to this file for security reasons.
if (!defined('IN_MYBB')) {
	die('Direct access to this file is not allowed.');
}

define('C_ACT', str_replace('.php', '', basename(__FILE__)));

if (!defined('IN_ADMINCP')) {
	$plugins->add_hook('global_start', 'activethreads_global_start');
}

function activethreads_global_start() {
	global $lang;

	// Load the language file so that the 'act_view_act_thr' message is available for the 'header_welcomeblock_guest' template
	// on every page.
	$lang->load(C_ACT);
}

function activethreads_info() {
	global $lang, $db, $mybb;

	if (!isset($lang->activethreads)) {
		$lang->load(C_ACT);
	}

	$ret = array(
		'name'          => $lang->act_name,
		'description'   => $lang->act_desc,
		'website'       => '',
		'author'        => 'Laird Shaw',
		'authorsite'    => '',
		'version'       => '1.2.0',
		// Constructed by converting each digit of 'version' above into two digits (zero-padded if necessary),
		// then concatenating them, then removing any leading zero(es) to avoid the value being interpreted as octal.
		'version_code'  => '10200',
		'guid'          => '',
		'codename'      => C_ACT,
		'compatibility' => '18*'
	);

	return $ret;
}

function activethreads_install() {
	global $mybb, $db, $lang, $cache;

	$lang->load(C_ACT);

	$res = $db->simple_select('settinggroups', 'MAX(disporder) as max_disporder');
	$disporder = $db->fetch_field($res, 'max_disporder') + 1;

	// Insert the plugin's settings into the database.
	$setting_group = array(
		'name'         => C_ACT.'_settings',
		'title'        => $db->escape_string($lang->act_name),
		'description'  => $db->escape_string($lang->act_desc),
		'disporder'    => intval($disporder),
		'isdefault'    => 0
	);
	$db->insert_query('settinggroups', $setting_group);

	act_update_create_settings();

	// Insert the plugin's templates into the database.
	$templateset = array(
		'prefix' => 'activethreads',
		'title' => 'Active Threads',
	);
	$db->insert_query('templategroups', $templateset);

	act_install_upgrade_common();

}

function activethreads_uninstall() {
	global $db, $cache;

	$db->delete_query('templates', "title LIKE 'activethreads_%'");
	$db->delete_query('templategroups', "prefix in ('activethreads')");

	$res = $db->simple_select('settinggroups', 'gid', "name = '".C_ACT."_settings'", array('limit' => 1));
	$group = $db->fetch_array($res);
	if (!empty($group['gid'])) {
		$db->delete_query('settinggroups', "gid='{$group['gid']}'");
		$db->delete_query('settings', "gid='{$group['gid']}'");
		rebuild_settings();
	}

	$lrs_plugins = $cache->read('lrs_plugins');
	unset($lrs_plugins[C_ACT]);
	$cache->update('lrs_plugins', $lrs_plugins);
}

function activethreads_is_installed() {
	global $db;

	$res = $db->simple_select('templates', '*', "title LIKE '".C_ACT."_%'");
	return ($db->affected_rows() > 0);
}

function act_upgrade() {
	global $db;

	// Update the master templates.
	act_install_upgrade_common();

	// Save existing values for the plugin's settings.
	$existing_setting_values = array();
	$res = $db->simple_select('settinggroups', 'gid', "name = '".C_ACT."_settings'", array('limit' => 1));
	$group = $db->fetch_array($res);
	if (!empty($group['gid'])) {
		$res = $db->simple_select('settings', 'value, name', "gid='{$group['gid']}'");
		while ($setting = $db->fetch_array($res)) {
			$existing_setting_values[$setting['name']] = $setting['value'];
		}
	}

	act_update_create_settings($existing_setting_values);
}


function act_update_create_settings($existing_setting_values = array()) {
	global $db, $lang;

	$lang->load(C_ACT);

	// Get the group ID for activethreads settings.
	$res = $db->simple_select('settinggroups', 'gid', "name = '".C_ACT."_settings'", array('limit' => 1));
	$row = $db->fetch_array($res);
	$gid = $row['gid'];

	// Delete existing activethreads settings, without deleting their group
	$db->delete_query('settings', "gid='{$gid}'");

	// The settings to (re)create in the database.
	$settings = array(
		'max_interval_in_secs' => array(
			'title'       => $lang->act_max_interval_in_secs_title,
			'description' => $lang->act_max_interval_in_secs_desc,
			'optionscode' => 'numeric',
			'value'       => '0'
		),
	);

	// (Re)create the settings, retaining the old values where they exist.
	$x = 1;
	foreach ($settings as $name => $setting) {
		$value = isset($existing_setting_values[C_ACT.'_'.$name]) ? $existing_setting_values[C_ACT.'_'.$name] : $setting['value'];
		$insert_settings = array(
			'name' => $db->escape_string(C_ACT.'_'.$name),
			'title' => $db->escape_string($setting['title']),
			'description' => $db->escape_string($setting['description']),
			'optionscode' => $db->escape_string($setting['optionscode']),
			'value' => $value,
			'disporder' => $x,
			'gid' => $gid,
			'isdefault' => 0
		);
		$db->insert_query('settings', $insert_settings);
		$x++;
	}

	rebuild_settings();
}

function act_install_upgrade_common() {
	global $mybb, $db, $lang, $cache;

	$templates = array(
		'activethreads_page'
			=> '<html>
<head>
<title>{$mybb->settings[\'bbname\']} - {$lang->act_act_recent_threads_title_short}</title>
{$headerinclude}
<style type="text/css">
table, td, th {
	text-align: center;
	border-spacing: 0;
}
</style>
</head>
<body>
{$header}
{$multipage}
{$results_html}
{$multipage}
<form method="get" action="activethreads.php">
<table class="tborder clear xxx">
	<thead>
		<tr>
			<th class="thead" colspan="5" title="{$act_set_period_of_interest_tooltip}">{$act_set_period_of_interest}</td>
		</tr>
		<tr>
			<th class="tcat">{$lang->act_num_days}</th>
			<th class="tcat">{$lang->act_num_hours}</th>
			<th class="tcat">{$lang->act_num_mins}</th>
			<th class="tcat" title="{$act_before_date_tooltip}">{$lang->act_before_date} [*]</th>
			<th class="tcat">{$lang->act_sort_by}</th>
		</tr>
	</thead>
	<tbody>
		<tr>
			<td><input type="text" name="days" value="$days" size="5" style="text-align: right;"/></td>
			<td><input type="text" name="hours" value="$hours" size="5" style="text-align: right;" /></td>
			<td><input type="text" name="mins" value="$mins" size="5" style="text-align: right;" /></td>
			<td><input type="text" name="date" value="$date" size="16" style="text-align: right;" title="{$act_before_date_tooltip}" /></td>
			<td>
				<input type="radio" name="order" value="ascending" id="sort.asc"{$asc_checked} /><label for ="sort.asc">{$lang->act_asc}</label><br />
				<input type="radio" name="order" value="descending" id="sort.desc"{$desc_checked} /><label for ="sort.desc">{$lang->act_desc}</label>
				<br />
				<select name="sort">
					<option value="num_posts"$num_posts_selected>{$lang->act_sort_by_num_posts}</option>
					<option value="min_dateline"$min_dateline_selected>{$lang->act_sort_by_earliest}</option>
					<option value="max_dateline"$max_dateline_selected>{$lang->act_sort_by_latest}</option>
				</select>
			</td>
		</tr>
	</tbody>
</table>
<p style="text-align: center">[*] {$lang->act_before_date_footnote}<br /><input type="submit" name="go" value="{$lang->act_go}" class="button" /></p>
</form>
{$footer}
</body>
</html>',
		'activethreads_result_row' => '
	<tr class="inline_row">
		<td class="$bgcolor forumdisplay_regular" style="text-align: left;">$thread_link<div class="smalltext"><span class="author">$thread_username_link</span> <span style="float: right;">$thread_date</span></div></td>
		<td class="$bgcolor">$num_posts_fmt</td>
		<td class="$bgcolor">$forum_links</td>
		<td class="$bgcolor" style="text-align: right;">$min_post_date_link<div class="smalltext"><span class="author">$min_post_username_link</span></div></td>
		<td class="$bgcolor" style="text-align: right;">$max_post_date_link<div class="smalltext"><span class="author">$max_post_username_link</span></div></td>
	</tr>',
		'activethreads_results' =>
'<table class="tborder clear">
<thead>
	<tr>
		<th class="thead" colspan="5">{$lang_act_recent_threads_title}</td>
	</tr>
	<tr>
		<th class="tcat" style="text-align: left;">{$lang->act_thread_author_start}</th>
		<th class="tcat">{$num_posts_heading}</th>
		<th class="tcat">{$lang->act_cont_forum}</th>
		<th class="tcat" style="text-align: right;">{$min_dateline_heading}</th>
		<th class="tcat" style="text-align: right;">{$max_dateline_heading}</th>
	</tr>
</thead>
<tbody>
{$result_rows}
</tbody>
</table>',
	);

	$info = activethreads_info();
	$plugin_version_code = $info['version_code'];
	// Left-pad the version code with any zero that we forbade in activethreads_info(),
	// where it would have been interpreted as octal.
	while (strlen($plugin_version_code) < 6) {
		$plugin_version_code = '0'.$plugin_version_code;
	}

	// Insert templates into the Master group (sid=-2) with a (string) version set to a value that
	// will compare greater than the current MyBB version_code. We set the version to this value so that
	// the SQL comparison "m.version > t.version" in the query to find updated templates
	// (in admin/modules/style/templates.php) is true for templates modified by the user:
	// MyBB sets the version for modified templates to the value of $mybb->version_code.
	$version = substr($mybb->version_code.'_'.$plugin_version_code, 0, 20);
	foreach ($templates as $template_title => $template_data) {
		$template_row = array(
			'title'    => $db->escape_string($template_title),
			'template' => $db->escape_string($template_data),
			'sid'      => '-2',
			'version'  => $version,
			'dateline' => TIME_NOW
		);

		$res = $db->simple_select('templates', 'tid', "sid='-2' AND title='".$db->escape_string($template_title)."'");
		$existing = $db->fetch_array($res);
		if ($existing['tid']) {
			unset($template_row['sid']);
			unset($template_row['title']);
			$db->update_query('templates', $template_row, "title='".$db->escape_string($template_title)."' AND sid='-2'");
		} else {
			$db->insert_query('templates', $template_row);
		}
	}
}

function activethreads_activate() {
	global $cache;

	$lrs_plugins = $cache->read('lrs_plugins');
	$info = activethreads_info();

	$old_version_code = $lrs_plugins[C_ACT]['version_code'];
	$new_version_code = $info['version_code'];

	// Perform necessary upgrades.
	if ($new_version_code > $old_version_code) {
		act_upgrade();
	}

	// Update the version in the permanent cache.
	$lrs_plugins[C_ACT] = array(
		'version'      => $info['version'     ],
		'version_code' => $info['version_code'],
	);
	$cache->update('lrs_plugins', $lrs_plugins);

	require_once MYBB_ROOT.'/inc/adminfunctions_templates.php';
	find_replace_templatesets('header_welcomeblock_guest', '(</script>)', '</script>
				<div class="lower">
					<div class="wrapper">
						<ul class="menu user_links">
							<li><a href="{$mybb->settings[\'bburl\']}/activethreads.php">{$lang->act_view_act_thr}</a></li>
							<li><a href="{$mybb->settings[\'bburl\']}/search.php?action=getdaily">{$lang->welcome_todaysposts}</a></li>		</ul>
					</div>
					<br class="clear" />
				</div>'
	);
	find_replace_templatesets('header_welcomeblock_member_search', '('.preg_quote('<li><a href="{$mybb->settings[\'bburl\']}/search.php?action=getnew">{$lang->welcome_newposts}</a></li>').')', '<li><a href="{$mybb->settings[\'bburl\']}/activethreads.php">{$lang->act_view_act_thr}</a></li>
<li><a href="{$mybb->settings[\'bburl\']}/search.php?action=getnew">{$lang->welcome_newposts}</a></li>');

}

function activethreads_deactivate() {
	require_once MYBB_ROOT.'/inc/adminfunctions_templates.php';
	find_replace_templatesets('header_welcomeblock_guest', '('.preg_quote('
				<div class="lower">
					<div class="wrapper">
						<ul class="menu user_links">
							<li><a href="{$mybb->settings[\'bburl\']}/activethreads.php">{$lang->act_view_act_thr}</a></li>
							<li><a href="{$mybb->settings[\'bburl\']}/search.php?action=getdaily">{$lang->welcome_todaysposts}</a></li>		</ul>
					</div>
					<br class="clear" />
				</div>').')', '', 0
	);
	find_replace_templatesets('header_welcomeblock_member_search', '('.preg_quote('<li><a href="{$mybb->settings[\'bburl\']}/activethreads.php">{$lang->act_view_act_thr}</a></li>
').')', '', 0);
}