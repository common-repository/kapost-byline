<?php
/*
	Plugin Name: Kapost
	Plugin URI: http://www.kapost.com/
	Description: Kapost
	Version: 2.2.9
	Author: Kapost
	Author URI: http://www.kapost.com
*/
define('KAPOST_BYLINE_VERSION', '2.2.9');
define('KAPOST_BYLINE_ANALYTICS_URL', '//analytics.kapost.com');
define('KAPOST_BYLINE_WP3', (get_bloginfo('version') >= 3.0));
define('KAPOST_BYLINE_WP3DOT4', (get_bloginfo('version') >= 3.4));
define('KAPOST_BYLINE_FILENAME', __FILE__);
define('KAPOST_BYLINE_BASEPATH', dirname(__FILE__));
define('KAPOST_BYLINE_BASENAME', plugin_basename(__FILE__));
define('KAPOST_BYLINE_DIRNAME', str_replace(basename(__FILE__), '', plugin_basename(__FILE__)));
define('KAPOST_BYLINE_DEFAULT_SETTINGS_KEY', 'kapost_byline_settings');
define('KAPOST_BYLINE_MU', (function_exists('is_multisite') && is_multisite()));
define('KAPOST_BYLINE_EMAIL', 'plugin@kapost.com');

function kapost_byline_bootstrap($mods)
{
	foreach($mods as $mod)
		require_once(KAPOST_BYLINE_BASEPATH . '/modules/' . $mod);
}
kapost_byline_bootstrap(array('install.php',
							  'settings.php',
							  'image.php',
							  'user.php',
							  'post.php',
							  'jwplayer.php',
							  'analytics.php',
							  'xmlrpc.php',
							  'xmlrpc-preview.php',
							  'wpml.php'));
?>
