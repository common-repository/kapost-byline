<?php
function kapost_byline_activate()
{
	update_option('enable_xmlrpc', 1);
}
register_activation_hook(KAPOST_BYLINE_FILENAME, 'kapost_byline_activate');
?>
