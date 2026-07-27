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

echo "== cache ladder (mirrors core wp_update_plugins) ==\n";
$ttl = function () { $m = new ReflectionMethod('ALAS_Updater', 'cache_ttl'); $m->setAccessible(true); return $m->invoke(null); };
update_option('amper_las_fast_updates', '');
ck($ttl() === 12 * HOUR_IN_SECONDS, 'idle request caches for 12h', $ttl());
do_action('load-plugins.php');
ck($ttl() === HOUR_IN_SECONDS, 'Plugins screen drops it to 1h, like core', $ttl());
do_action('load-update-core.php');
ck($ttl() === MINUTE_IN_SECONDS, 'Updates screen drops it to 1 min, like core', $ttl());
update_option('amper_las_fast_updates', 'yes');
ck($ttl() === MINUTE_IN_SECONDS, 'fast mode never loosens the ladder', $ttl());
update_option('amper_las_fast_updates', '');

echo "== storefront never blocks on GitHub ==\n";
$may = new ReflectionMethod('ALAS_Updater', 'may_fetch');
$may->setAccessible(true);
ck($may->invoke(null) === true, 'admin / cron / wp-cli may fetch');
// Black box: with the cache empty, a storefront request must NOT populate it - a shopper's page
// load may never wait on GitHub. Asserted against the running store, not simulated.
ALAS_Updater::flush_cache();
// redirection => 0: the container reaches the store as http://wordpress/, which WordPress answers
// with its canonical redirect to the public host. A 301 still means PHP ran, so the guard was
// exercised - which is the whole point of the probe.
$r = wp_remote_get('http://wordpress/?amper_las_guard_probe=1', array('timeout' => 20, 'redirection' => 0));
$after = get_site_transient('amper_las_remote_version');
$code = is_wp_error($r) ? $r->get_error_message() : (int) wp_remote_retrieve_response_code($r);
ck(in_array($code, array(200, 301), true), 'storefront request executed PHP', $code);
ck($after === false, 'storefront request left the version cache untouched', var_export($after, true));

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
