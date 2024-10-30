<?php
// we define our own _nonce() because we wanna support
// earlier versions of wordpress that do not have the
// nonce_user_logged_out filter.
function kapost_byline_create_nonce($action)
{
	$i = wp_nonce_tick();
	return substr(wp_hash($i . $action, 'nonce'), -12, 10);
}
function kapost_byline_verify_nonce($nonce, $action)
{
	$i = wp_nonce_tick();

	// Nonce generated 0-12 hours ago
	if(substr(wp_hash($i . $action, 'nonce'), -12, 10) === $nonce)
		return 1;

	return false;
}

function kapost_byline_is_preview_enabled()
{
	$settings = kapost_byline_settings();
	return (isset($settings['preview']) && $settings['preview'] == 'on');
}

function kapost_byline_is_preview_byline_enabled()
{
	$settings = kapost_byline_settings();
	return (isset($settings['preview_byline']) && $settings['preview_byline'] == 'on');
}

function kapost_byline_get_preview_permalink($post_id, $nonce=null)
{
	$preview_params = array('preview' => 'true');

	if($nonce != null)
		$preview_params['kn'] = $nonce;

	if(function_exists('wp_generate_uuid4'))
		$preview_params['ku'] = wp_generate_uuid4();

	$preview_permalink = set_url_scheme(get_permalink($post_id));
	$preview_permalink = apply_filters('preview_post_link', add_query_arg($preview_params, $preview_permalink));
	$preview_permalink = apply_filters('kapost_byline_get_preview_permalink', $preview_permalink, $post_id);

	return array('url' => $preview_permalink, 'id' => strval($post_id));
}

function kapost_byline_preview_nonce_action($post_id)
{
	return 'kapost_byline_get_preview' . strval($post_id);
}

function kapost_byline_get_preview($args)
{
	global $wp_xmlrpc_server;

	$GLOBALS['KAPOST_BYLINE_PREVIEW'] = true;

	if(kapost_byline_is_preview_byline_enabled())
		$GLOBALS['KAPOST_BYLINE_PREVIEW_BYLINE'] = true;

	// create a copy of the arguments and escape that
	// in order to avoid any potential double escape issues
	$_args = $args;
	$wp_xmlrpc_server->escape($_args);

	$username = $_args[1];
	$password = $_args[2];

	if(isset($_args[4]))
		$post_id = intval($_args[4]);
	else
		$post_id = 0;

	$post = null;

	if(!$user = $wp_xmlrpc_server->login($username, $password))
		return $wp_xmlrpc_server->error;

	$data = apply_filters('kapost_byline_get_preview_args', $args[3], $post_id);

	if($data instanceof IXR_Error)
		return $data;
	else
		$args[3] = $data;

	if($post_id)
	{
		if(!current_user_can('edit_post', $post_id))
			return new IXR_Error(401, __('Sorry, you cannot edit this post.'));

		$post = get_post($post_id);
		if(is_object($post) && isset($post->ID))
		{
			if($post->post_status == 'publish')
			{
				$permalink = kapost_byline_get_preview_permalink($post_id);
				return apply_filters('kapost_byline_get_preview', $permalink);
			}

			$tmp_args = $args;
			$tmp_args[0] = $tmp_args[4];
			$tmp_args[4] = false;

			$status = kapost_byline_xmlrpc_edit_post($tmp_args);

			if($status instanceof IXR_Error)
				return $status;
		}
		else
		{
			$post = null;
		}
	}

	if($post == null)
	{
		$tmp_args = $args;
		$tmp_args[4] = false;

		$post_id = kapost_byline_xmlrpc_new_post($tmp_args);

		if($post_id instanceof IXR_Error)
			return $post_id;
	}

	$nonce = kapost_byline_create_nonce(kapost_byline_preview_nonce_action($post_id));
	$permalink = kapost_byline_get_preview_permalink($post_id, $nonce);

	return apply_filters('kapost_byline_get_preview', $permalink);
}

function kapost_byline_preview_verify_params()
{
	if(isset($_GET['kn']) && isset($_GET['p']) && isset($_GET['preview']))
	   return true;

	return false;
}

function kapost_byline_preview()
{
	if(!kapost_byline_preview_verify_params())
		return;

	if(kapost_byline_verify_nonce($_GET['kn'], kapost_byline_preview_nonce_action($_GET['p'])))
	{
		add_filter('posts_results', 'kapost_byline_preview_filter');

		if(is_user_logged_in())
			add_filter('show_admin_bar', '__return_false');
	}
}

function kapost_byline_preview_filter($posts)
{
	$posts[0]->post_status = 'publish';
	return $posts;
}

if(kapost_byline_is_preview_enabled())
	add_action('init', 'kapost_byline_preview');
