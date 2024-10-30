<?php
function kapost_byline_xmlrpc_version()
{
	return KAPOST_BYLINE_VERSION;
}



function kapost_byline_xmlrpc_new_post($args)
{
	global $wp_xmlrpc_server;

	// create a copy of the arguments and escape that
	// in order to avoid any potential double escape issues
	$_args = $args;
	$wp_xmlrpc_server->escape($_args);

	$username = $_args[1];
	$password = $_args[2];

	if(!$wp_xmlrpc_server->login($username, $password))
		return $wp_xmlrpc_server->error;

	if(!current_user_can('publish_posts'))
		return new IXR_Error(401, __('Sorry, you are not allowed to publish posts on this site.'));

	$data = apply_filters('kapost_byline_new_post_args', $args[3]);

	if($data instanceof IXR_Error)
		return $data;
	else
		$args[3] = $data;

	$wp_xmlrpc_server->escape($data);

	

	$post_id = $wp_xmlrpc_server->mw_newPost($args);

	if(is_string($post_id))
		kapost_byline_wpml_update_terms($post_id, $args);

	return apply_filters('kapost_byline_new_post', $post_id, $data);
}

function kapost_byline_xmlrpc_edit_post($args)
{
	global $wp_xmlrpc_server, $current_site;

	// create a copy of the arguments and escape that
	// in order to avoid any potential double escape issues
	$_args = $args;
	$wp_xmlrpc_server->escape($_args);

	$post_id	= intval($_args[0]);
	$username	= $_args[1];
	$password	= $_args[2];
	$publish	= $_args[4];

	if(!$wp_xmlrpc_server->login($username, $password))
		return $wp_xmlrpc_server->error;

	if(!current_user_can('edit_post', $post_id))
		return new IXR_Error(401, __('Sorry, you cannot edit this post.'));

	$post = get_post($post_id);
	if(!is_object($post) || !isset($post->ID))
		return new IXR_Error(404, __('Invalid post ID.'));

	$data = apply_filters('kapost_byline_edit_post_args', $args[3], $post_id);

	if($data instanceof IXR_Error)
		return $data;
	else
		$args[3] = $data;

	$wp_xmlrpc_server->escape($data);

	$blog_id = 1;
	if(function_exists('get_current_blog_id'))
		$blog_id = get_current_blog_id();
	elseif(is_object($current_site) && !empty($current_site->id))
		$blog_id = $current_site->id;

	

	if(in_array($post->post_type, array('post', 'page')))
	{
		$result = $wp_xmlrpc_server->mw_editPost($args);

		if($result === true)
			kapost_byline_wpml_update_terms($post_id, $args);

		return apply_filters('kapost_byline_edit_post', $result, $post_id, $data);
	}

	// to avoid double escaping the content structure in wp_editPost
	// point data to the original structure
	$_data = $args[3];

	$content_struct = array();
	$content_struct['post_type'] = $post->post_type;

	if($publish)
	{
		$content_struct['post_status'] = 'publish';

		// if the post is currently a draft we have to explicitly
		// reset the post_date, otherwise it will be set
		// to an invalid date in the past ...
		if(in_array($post->post_status, array('draft', 'auto-draft')))
		{
			$content_struct['post_date'] = '';
			$content_struct['post_date_gmt'] = '';
		}
	}
	else
	{
		$content_struct['post_status'] = 'draft';
	}

	if(isset($_data['title']))
		$content_struct['post_title'] = $_data['title'];

	if(isset($_data['description']))
		$content_struct['post_content'] = $_data['description'];

	if(isset($_data['custom_fields']))
		$content_struct['custom_fields'] = $_data['custom_fields'];

	if(isset($_data['mt_excerpt']))
		$content_struct['post_excerpt'] = $_data['mt_excerpt'];

	if(isset($_data['mt_keywords']) && !empty($_data['mt_keywords']))
		$content_struct['terms_names']['post_tag'] = kapost_byline_explode(',', $_data['mt_keywords']);

	// wp_editPost will create any inexistent categories, in order to stay consistent and match the
	// behavior of mw_editPost, we filter the categories on our own ...
	if(isset($_data['categories']) && !empty($_data['categories']) && is_array($_data['categories']))
		$content_struct['terms_names']['category'] = kapost_byline_xmlrpc_filter_categories($_data['categories']);

	$result = $wp_xmlrpc_server->wp_editPost(array($blog_id, $args[1], $args[2], $args[0], $content_struct));

	if($result === true)
		kapost_byline_wpml_update_terms($post_id, $args);

	return apply_filters('kapost_byline_edit_post', $result, $post_id, $data);
}

function kapost_byline_xmlrpc_filter_categories($categories)
{
	$filtered_categories = array();

	foreach($categories as $cat)
	{
		$cat_id = get_cat_ID($cat);

		if(!empty($cat_id))
			$filtered_categories[] = $cat;
	}

	return $filtered_categories;
}

function kapost_byline_xmlrpc_get_post($args)
{
	global $wp_xmlrpc_server;

	// create a copy of the arguments and escape that
	// in order to avoid any potential double escape issues
	$_args = $args;
	$wp_xmlrpc_server->escape($_args);

	$post_id  = intval($_args[0]);
	$username = $_args[1];
	$password = $_args[2];

	if(!$wp_xmlrpc_server->login($username, $password))
		return $wp_xmlrpc_server->error;

	if(!current_user_can('edit_post', $post_id))
		return new IXR_Error(401, __('Sorry, you cannot edit this post.'));

	$post = get_post($post_id);
	if(!is_object($post) || !isset($post->ID))
		return new IXR_Error(404, __('Invalid post ID.'));

	$result = $wp_xmlrpc_server->mw_getPost($args);

	return apply_filters('kapost_byline_get_post', $result, $post_id);
}

function kapost_byline_xmlrpc_new_media_Object($args)
{
	global $wpdb, $wp_xmlrpc_server;

	// create a copy of the arguments and escape that
	// in order to avoid any potential double escape issues
	$_args = $args;
	$wp_xmlrpc_server->escape($_args);

	$username	= $_args[1];
	$password	= $_args[2];
	$data		= $_args[3];

	if(!$wp_xmlrpc_server->login($username, $password))
		return $wp_xmlrpc_server->error;

	if(!current_user_can('upload_files'))
		return new IXR_Error(401, __('You are not allowed to upload files to this site.'));

	$data = apply_filters('kapost_byline_new_media_object_args', $args[3]);

	if($data instanceof IXR_Error)
		return $data;
	else
		$args[3] = $data;

	$wp_xmlrpc_server->escape($data);

	if(is_array($data) && isset($data['overwrite']) && $data['overwrite'] && isset($data['name']) && !empty($data['name']))
	{
		$attachment = $wpdb->get_row($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND post_title = %s LIMIT 1", $data['name']));

		if(!empty($attachment))
			wp_delete_attachment($attachment->ID);

		// do not pass overwrite to mw_newMediaObject down below because that has
		// a slightly different and unwanted behaviour in this case
		unset($args[3]['overwrite']);
	}

	$image = $wp_xmlrpc_server->mw_newMediaObject($args);
	if(!is_array($image) || empty($image['url']))
		return $image;

	$attachment = kapost_byline_get_attachment_by_url($image['url']);

	if(empty($attachment))
		return $image;

	$update_attachment = false;

	if(isset($data['description']))
	{
		$attachment->post_content = sanitize_text_field($data['description']);
		$update_attachment = true;
	}

	if(isset($data['title']))
	{
		$attachment->post_title	= sanitize_text_field($data['title']);
		$update_attachment = true;
	}

	if(isset($data['caption']))
	{
		$attachment->post_excerpt = sanitize_text_field($data['caption']);
		$update_attachment = true;
	}

	if($update_attachment)
		wp_update_post($attachment);

	if(isset($data['alt']))
		add_post_meta($attachment->ID, '_wp_attachment_image_alt', sanitize_text_field($data['alt']));

	if(!isset($image['id']))
		$image['id'] = $attachment->ID;

	return apply_filters('kapost_byline_new_media_object', $image, $data);
}

function kapost_byline_xmlrpc_get_permalink($args)
{
	global $wp_xmlrpc_server;
	$wp_xmlrpc_server->escape($args);

	$post_id	= intval($args[0]);
	$username	= $args[1];
	$password	= $args[2];

	if(!$wp_xmlrpc_server->login($username, $password))
		return $wp_xmlrpc_server->error;

	if(!current_user_can('edit_post', $post_id))
		return new IXR_Error(401, __('Sorry, you cannot edit this post.'));

	$post = get_post($post_id);
	if(!is_object($post) || !isset($post->ID))
		return new IXR_Error(401, __('Sorry, you cannot edit this post.'));

	// play nice with the utterly broken No Category Parents plugin :) (sigh)
	if(function_exists('myfilter_category') || function_exists('my_insert_rewrite_rules'))
	{
		remove_filter('pre_post_link'       ,'filter_category');
		remove_filter('user_trailingslashit','myfilter_category');
		remove_filter('category_link'       ,'filter_category_link');
		remove_filter('rewrite_rules_array' ,'my_insert_rewrite_rules');
		remove_filter('query_vars'          ,'my_insert_query_vars');
	}

	list($permalink, $post_name) = get_sample_permalink($post->ID);
	$permalink = str_replace(array('%postname%', '%pagename%'), $post_name, $permalink);

	if(strpos($permalink, "%") !== false) // make sure it doesn't contain %day%, etc.
		$permalink = get_permalink($post);

	return apply_filters('kapost_byline_get_permalink', $permalink, $post_id);
}

function kapost_byline_xmlrpc_wck_is_installed()
{
    return defined('WCK_PLUGIN_DIR');
}

function kapost_byline_xmlrpc_get_users_blogs($args)
{
	global $wp_xmlrpc_server;

	if(function_exists('is_multisite') && is_multisite())
	{
		array_shift($args);
		return $wp_xmlrpc_server->wp_getUsersBlogs($args);
	}

	return $wp_xmlrpc_server->blogger_getUsersBlogs($args);
}

function kapost_byline_xmlrpc($methods)
{
	$methods['kapost.version'] = 'kapost_byline_xmlrpc_version';
	$methods['kapost.newPost'] = 'kapost_byline_xmlrpc_new_post';
	$methods['kapost.editPost'] = 'kapost_byline_xmlrpc_edit_post';
	$methods['kapost.getPost'] = 'kapost_byline_xmlrpc_get_post';
	$methods['kapost.newMediaObject'] = 'kapost_byline_xmlrpc_new_media_object';
	$methods['kapost.getPermalink']	= 'kapost_byline_xmlrpc_get_permalink';
	$methods['kapost.wckIsInstalled'] = 'kapost_byline_xmlrpc_wck_is_installed';

	if(kapost_byline_is_preview_enabled())
		$methods['kapost.getPreview'] = 'kapost_byline_get_preview';

	if(!isset($methods['kapost.getUsersBlogs']))
		$methods['kapost.getUsersBlogs'] = 'kapost_byline_xmlrpc_get_users_blogs';
	return $methods;
}
add_filter('xmlrpc_methods', 'kapost_byline_xmlrpc');
?>
