<?php
$fail = 0;
function ck($ok, $label, $extra = '') { global $fail; if (!$ok) $fail++; echo ($ok ? 'PASS  ' : 'FAIL  ') . $label . ($extra ? "  [$extra]" : '') . "\n"; }

echo "== interval ==\n";
update_option('amper_las_fast_updates', '');
ck(ALAS_Updater::check_interval() === 12 * HOUR_IN_SECONDS, 'default interval is 12h', ALAS_Updater::check_interval());
update_option('amper_las_fast_updates', 'yes');
ck(ALAS_Updater::check_interval() === 2 * MINUTE_IN_SECONDS, 'fast opt-in gives 2 min', ALAS_Updater::check_interval());
update_option('amper_las_fast_updates', '');
add_filter('amper_las_update_check_interval', function () { return 5; });
ck(ALAS_Updater::check_interval() === MINUTE_IN_SECONDS, 'filter is floored at 60s', ALAS_Updater::check_interval());
remove_all_filters('amper_las_update_check_interval');

echo "== remote headers (live GitHub API) ==\n";
ALAS_Updater::flush_cache();
$h = ALAS_Updater::remote_headers(true);
ck((bool) $h['version'], 'version read from the branch', $h['version']);
ck(version_compare($h['version'], '1.0.0', '>='), 'version looks like a version', $h['version']);
ck($h['requires_php'] !== '', 'Requires PHP carried through', $h['requires_php']);
ck($h['requires_plugins'] !== '', 'Requires Plugins carried through', $h['requires_plugins']);
ck(is_array(get_site_transient('amper_las_remote_version')), 'result cached in a transient');

echo "== transient shape ==\n";
$base = plugin_basename(AMPER_LAS_PLUGIN_FILE);
$t = ALAS_Updater::inject_update((object) array('response' => array(), 'no_update' => array()));
$remote = $h['version'];
if (version_compare($remote, AMPER_LAS_VERSION, '>')) {
    ck(isset($t->response[$base]), 'newer branch version offered as an update');
    $o = $t->response[$base];
} else {
    ck(isset($t->no_update[$base]), 'up-to-date announced in no_update (WP 5.5+ requirement)');
    ck(!isset($t->response[$base]), 'no phantom update offered');
    $o = $t->no_update[$base];
}
ck(isset($o->plugin) && $o->plugin === $base, 'plugin field matches basename', $o->plugin ?? '');
ck(isset($o->slug) && $o->slug === 'amper-live-assisted-sales', 'slug matches folder', $o->slug ?? '');
ck(!empty($o->package) && strpos($o->package, 'api.github.com') !== false, 'package is the GitHub zipball', $o->package ?? '');
ck(is_array($o->requires_plugins) && in_array('woocommerce', $o->requires_plugins, true), 'requires_plugins parsed to an array');

echo "== package reachable ==\n";
$r = wp_remote_head(ALAS_Updater::plugin_details(false, 'plugin_information', (object) array('slug' => 'amper-live-assisted-sales'))->download_link, array('timeout' => 20, 'redirection' => 5, 'headers' => array('User-Agent' => 'amper-las-updater')));
ck(!is_wp_error($r) && (int) wp_remote_retrieve_response_code($r) === 200, 'zipball downloads', is_wp_error($r) ? $r->get_error_message() : wp_remote_retrieve_response_code($r));

echo "== details screen ==\n";
$d = ALAS_Updater::plugin_details(false, 'plugin_information', (object) array('slug' => 'amper-live-assisted-sales'));
ck(is_object($d) && $d->slug === 'amper-live-assisted-sales', 'plugins_api answered locally (no wordpress.org 404)');
ck(ALAS_Updater::plugin_details('untouched', 'plugin_information', (object) array('slug' => 'woocommerce')) === 'untouched', 'other plugins left alone');

echo "== auto update switch ==\n";
ck(ALAS_Updater::force_auto_update(false, (object) array('plugin' => $base)) === true, 'our plugin auto-updates');
ck(ALAS_Updater::force_auto_update(false, (object) array('plugin' => 'woocommerce/woocommerce.php')) === false, 'other plugins keep their own setting');
add_filter('amper_las_auto_update', '__return_false');
ck(ALAS_Updater::force_auto_update(false, (object) array('plugin' => $base)) === false, 'opt-out filter honoured');
remove_all_filters('amper_las_auto_update');

echo "== cron ==\n";
ck((bool) wp_next_scheduled('amper_las_check_update'), 'update check is scheduled');
$e = wp_get_scheduled_event('amper_las_check_update');
ck($e && (int) $e->interval === ALAS_Updater::check_interval(), 'scheduled interval matches the setting', $e ? $e->interval : '-');

echo "\nRESULT: " . ($fail ? "$fail failed" : 'all passed') . "\n";
