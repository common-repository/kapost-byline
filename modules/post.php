<?php
function kapost_byline_explode($separator, $string)
{
	return array_filter(array_map('trim', explode($separator, $string)));
}

function kapost_byline_custom_fields($raw_custom_fields)
{
	if(!is_array($raw_custom_fields))
		return array();

	$custom_fields = array();
	foreach($raw_custom_fields as $i => $cf)
	{
		$k = sanitize_text_field($cf['key']);
		$v = sanitize_text_field($cf['value']);
		$custom_fields[$k] = $v;
	}

	return $custom_fields;
}

function kapost_is_protected_meta($protected_fields, $field)
{
	if(!in_array($field, $protected_fields))
		return false;

	if(function_exists('is_protected_meta'))
		return is_protected_meta($field, 'post');

	return ($field[0] == '_');
}

function kapost_byline_protected_custom_fields($custom_fields)
{
	$protected_fields = array();

	if(isset($custom_fields['_kapost_protected']))
	{
		foreach(kapost_byline_explode('|', $custom_fields['_kapost_protected']) as $p)
		{
			list($prefix, $keywords) = kapost_byline_explode(':', $p);

			if(empty($keywords))
			{
				$protected_fields[] = "_${prefix}";
				continue;
			}

			foreach(kapost_byline_explode(',', $keywords) as $k)
				$protected_fields[] = "_${prefix}_${k}";
		}
	}

	$settings = kapost_byline_settings();
	if(isset($settings['whitelisted_protected_custom_fields']) && !empty($settings['whitelisted_protected_custom_fields']))
	{
		foreach(kapost_byline_explode(';', $settings['whitelisted_protected_custom_fields']) as $field)
			$protected_fields[] = $field;
	}

	$protected_fields = apply_filters('kapost_byline_protected_fields', $protected_fields);

	if(empty($protected_fields))
		return array();

	$pcf = array();
	foreach($custom_fields as $k => $v)
	{
		if(kapost_is_protected_meta($protected_fields, $k))
			$pcf[$k] = $v;
	}

	return $pcf;
}

function kapost_byline_update_array_custom_fields($id, $custom_fields)
{
	foreach($custom_fields as $k => $v)
	{
		if(strpos($k, '_kapost_array_') !== 0 || strpos($k, '_kapost_array_kapost_merged_') === 0)
			continue;

		$meta_key = str_replace('_kapost_array_', '', $k);
		if(empty($meta_key))
			continue;

		delete_post_meta($id, $meta_key);

		if(empty($v))
			continue;

		$meta_values = @json_decode(@base64_decode($v), true);
		if(!is_array($meta_values))
			continue;

		foreach($meta_values as $meta_value)
			add_post_meta($id, $meta_key, $meta_value);
	}
}

function kapost_byline_update_post_data($data, $custom_fields, $blog_id=0)
{
	// if this is a draft then clear the 'publish date' or set our own
	if(in_array($data['post_status'], array('draft', 'auto-draft')) && isset($custom_fields['kapost_publish_date']))
	{
		$post_date = $custom_fields['kapost_publish_date']; // UTC
		$data['post_date'] = get_date_from_gmt($post_date);
		$data['post_date_gmt'] = $post_date;
	}

	// set our custom type
	if(KAPOST_BYLINE_WP3 && isset($custom_fields['kapost_custom_type']))
	{
		$custom_type = $custom_fields['kapost_custom_type'];
		if(!empty($custom_type) && post_type_exists($custom_type))
			$data['post_type'] = $custom_type;
	}

	if(isset($custom_fields['kapost_parent_id']) && !empty($custom_fields['kapost_parent_id']))
		$data['post_parent'] = intval($custom_fields['kapost_parent_id']);

	if(isset($custom_fields['kapost_menu_order']) && !empty($custom_fields['kapost_menu_order']))
		$data['menu_order'] = intval($custom_fields['kapost_menu_order']);

	// exit early unless we have byline preview turned on
	if(isset($GLOBALS['KAPOST_BYLINE_PREVIEW']) && !isset($GLOBALS['KAPOST_BYLINE_PREVIEW_BYLINE']))
		return $data;

	// create user if necessary
	$uid = kapost_byline_create_user($custom_fields, $blog_id);

	// set our post author
	if($uid !== false && $data['post_author'] != $uid)
		$data['post_author'] = $uid;

	return $data;
}

function kapost_byline_is_simple_field($k)
{
	// keys must be in this format: _simple_fields_fieldGroupID_1_fieldID_1_numInSet_0
	return preg_match('/^_simple_fields_fieldGroupID_[0-9]+_fieldID_[0-9]+_numInSet_[0-9]+$/', $k);
}

function kapost_byline_update_simple_fields($id, $custom_fields)
{
	global $wpdb;

	// remove any existing Simple Fields
	$wpdb->query("DELETE FROM $wpdb->postmeta WHERE post_id = $id AND meta_key LIKE '_simple_fields_fieldGroupID_%'");

	// store Simple Fields specific protected custom fields
	foreach($custom_fields as $k => $v)
	{
		// keys must be in this format: _simple_fields_fieldGroupID_1_fieldID_1_numInSet_0
		if(kapost_byline_is_simple_field($k))
		{
			$value = $custom_fields[$k];

			// is this an image?
			$matches = kapost_byline_validate_image_url($value);
			if(!empty($matches))
			{
				$image = kapost_byline_get_attachment_by_url($value);

				// if the image was found, set the ID
				if(!empty($image) && is_object($image))
					add_post_meta($id, $k, $image->ID);
			}
			else // default is text field/area
			{
				add_post_meta($id, $k, $value);
			}
		}
	}
}

function kapost_byline_update_post_image_fields($id, $custom_fields)
{
	foreach($custom_fields as $k => $v)
	{
		// skip simple fields because those are being handled differently
		if(kapost_byline_is_simple_field($k))
			continue;

		$value = $custom_fields[$k];

		// is this an image?
		$matches = kapost_byline_validate_image_url($value);
		if(empty($matches))
			continue;

		// find the image based on the URL
		$image = kapost_byline_get_attachment_by_url($value);
		if(empty($image) || !is_object($image))
			continue;

		delete_post_meta($id, $k);
		add_post_meta($id, $k, $image->ID);
	}
}

function kapost_byline_array_merged_deserialize($value)
{
	if(empty($value))
		return array();

	$values = @json_decode(@base64_decode($value), true);
	if(!is_array($values) || empty($values))
		return array();

	$deserialized = array();

	foreach($values as $v)
	{
		if(empty($v))
			continue;

		$deserialized = array_merge($deserialized, kapost_byline_explode(',', $v));
	}

	return $deserialized;
}

function kapost_byline_update_array_merged_custom_fields($id, $custom_fields)
{
	$taxonomies = array_keys(get_taxonomies(array(), 'names'));

	foreach($custom_fields as $k => $v)
	{
		if(strpos($k, '_kapost_array_kapost_merged_') !== 0)
			continue;

		$meta_key = str_replace('_kapost_array_kapost_merged_', '', $k);
		if(empty($meta_key))
			continue;

		$meta_values = kapost_byline_array_merged_deserialize($v);

		if(in_array($meta_key, $taxonomies))
		{
			if($meta_key === 'post_tag')
				wp_set_post_terms($id, $meta_values, $meta_key);
			else
				wp_set_object_terms($id, $meta_values, $meta_key);

			kapost_byline_taxonomy_set_primary_term($id, $meta_key, $meta_values);
			continue;
		}

		delete_post_meta($id, $meta_key);

		foreach($meta_values as $meta_value)
			add_post_meta($id, $meta_key, $meta_value);
	}
}

function kapost_byline_update_hash_custom_fields($id, $custom_fields)
{
	$prefix = '_kapost_hash_';
	foreach($custom_fields as $k => $v)
	{
		// starts with?
		if(strpos($k, $prefix) === 0)
		{
			$kk = str_replace($prefix, '', $k);
			delete_post_meta($id, $kk);

			if(empty($v))
				continue;

			$vv = @json_decode(@base64_decode($v), true);
			if(is_array($vv))
				add_post_meta($id, $kk, $vv);
		}
	}
}

function kapost_byline_taxonomy_set_primary_term($post_id, $taxonomy, $terms)
{
	if($taxonomy == 'post_tag' || !class_exists('WPSEO_Primary_Term'))
		return;

	$term_id = 0;

	if(count($terms) > 1)
	{
		$term = $terms[0];

		if(is_int($term))
		{
			$term_id = $term;
		}
		elseif(is_object($term))
		{
			$term_id = $term->term_id;
		}
		else
		{
			$term = get_term_by('slug', $term, $taxonomy);
			if(is_object($term))
			{
				$term_id = $term->term_id;
			}
			else
			{
				$term = get_term_by('name', $term, $taxonomy);
				if(is_object($term))
					$term_id = $term->term_id;
			}
		}
	}

	$primary_term = new WPSEO_Primary_Term($taxonomy, $post_id);
	$primary_term->set_primary_term($term_id);
}

function kapost_byline_update_post_meta_data($id, $custom_fields)
{
	// set any "array" custom fields
	kapost_byline_update_array_custom_fields($id, $custom_fields);

	// set any "array merged" custom fields
	kapost_byline_update_array_merged_custom_fields($id, $custom_fields);

	// set any "hash" custom fields
	kapost_byline_update_hash_custom_fields($id, $custom_fields);

	// set our featured image
	if(isset($custom_fields['kapost_featured_image']))
	{
		// look up the image by URL which is the GUID (too bad there's NO wp_ specific method to do this, oh well!)
		$thumbnail = kapost_byline_get_attachment_by_url($custom_fields['kapost_featured_image']);

		// if the image was found, set it as the featured image for the current post
		if(!empty($thumbnail))
		{
			// We support 2.9 and up so let's do this the old fashioned way
			// >= 3.0.1 and up has "set_post_thumbnail" available which does this little piece of mockery for us ...
			update_post_meta($id, '_thumbnail_id', $thumbnail->ID);
		}

		delete_post_meta($id, 'kapost_featured_image');
	}

	// store our image custom fields as IDs instead of URLs
	$settings = kapost_byline_settings();
	if(isset($settings['image_custom_fields']) && $settings['image_custom_fields'] == 'on')
		kapost_byline_update_post_image_fields($id, $custom_fields);

	// store our protected custom field required by our analytics
	if(isset($custom_fields['_kapost_analytics_post_id']))
	{
		delete_post_meta($id, '_kapost_analytics');

		// join them into one for performance and speed
		$kapost_analytics = array();
		foreach($custom_fields as $k => $v)
		{
			// starts with?
			if(strpos($k, '_kapost_analytics_') === 0)
			{
				$kk = str_replace('_kapost_analytics_', '', $k);
				$kapost_analytics[$kk] = $v;
			}
		}

		add_post_meta($id, '_kapost_analytics', $kapost_analytics);
	}

	// store other implicitly 'allowed' protected custom fields
	foreach(kapost_byline_protected_custom_fields($custom_fields) as $k => $v)
	{
		delete_post_meta($id, $k);
		if(!empty($v)) add_post_meta($id, $k, $v);
	}

	// check and store protected custom fields used by Simple Fields
	if(defined('EASY_FIELDS_VERSION') || class_exists('simple_fields'))
		kapost_byline_update_simple_fields($id, $custom_fields);

	// match custom fields to custom taxonomies if appropriate
	$taxonomies = array_keys(get_taxonomies(array('_builtin' => false), 'names'));
	if(!empty($taxonomies))
	{
		foreach($custom_fields as $k => $v)
		{
			if(!in_array($k, $taxonomies))
				continue;

			delete_post_meta($id, $k);

			$terms = kapost_byline_explode(',', $v);

			kapost_byline_taxonomy_set_primary_term($id, $k, $terms);
			wp_set_object_terms($id, $terms, $k);
		}
	}

	if(class_exists('WPSEO_Primary_Term'))
		kapost_byline_taxonomy_set_primary_term($id, 'category', wp_get_object_terms($id, 'category'));

	if(isset($custom_fields['kapost_page_template']) && !empty($custom_fields['kapost_page_template']))
		update_post_meta($id, '_wp_page_template', $custom_fields['kapost_page_template']);

	kapost_byline_update_unix_timestamp_fields($id, $custom_fields, $settings);
}

function kapost_byline_update_unix_timestamp_fields($id, $custom_fields, $settings)
{
	if(!isset($settings['unix_timestamp_custom_fields']) || empty($settings['unix_timestamp_custom_fields']))
		return;

	foreach(kapost_byline_explode(';', $settings['unix_timestamp_custom_fields']) as $field)
	{
		if(!isset($custom_fields[$field]) || empty($custom_fields[$field]))
			continue;

		delete_post_meta($id, $field);

		$timestamp = @strtotime($custom_fields[$field]);
		if(is_int($timestamp) && $timestamp > 0)
			add_post_meta($id, $field, $timestamp);
	}
}

function kapost_byline_get_xmlrpc_server()
{
	if(!defined('XMLRPC_REQUEST'))
		return false;

	global $wp_xmlrpc_server;
	if(empty($wp_xmlrpc_server))
		return false;

	$methods = array('metaWeblog.newPost', 'metaWeblog.editPost', 'kapost.newPost', 'kapost.editPost', 'kapost.getPreview');
	if(!in_array($wp_xmlrpc_server->message->methodName, $methods))
		return false;

	return $wp_xmlrpc_server;
}

function kapost_byline_on_insert_post_data($data, $postarr)
{
	$xmlrpc_server = kapost_byline_get_xmlrpc_server();
	if($xmlrpc_server == false)
		return $data;

	$message = $xmlrpc_server->message;
	$args = $message->params; // create a copy

	$xmlrpc_server->escape($args);

	$filter_args = array();
	$filter_args['post'] = $data;
	$filter_args['custom_fields'] = kapost_byline_custom_fields($args[3]['custom_fields']);
	$filter_args['data'] = $args[3];
	$filter_args['blog_id'] = intval($args[0]);

	$filter_args = apply_filters('kapost_byline_before_on_insert_post_data', $filter_args);

	$filter_args['post'] = kapost_byline_update_post_data($filter_args['post'],
														  $filter_args['custom_fields'],
														  $filter_args['blog_id']);

	$filter_args = apply_filters('kapost_byline_after_on_insert_post_data', $filter_args);

	return $filter_args['post'];
}

function kapost_byline_on_insert_post($post_id)
{
	$xmlrpc_server = kapost_byline_get_xmlrpc_server();
	if($xmlrpc_server == false)
		return false;

	$message = $xmlrpc_server->message;
	$args = $message->params; // create a copy
	$xmlrpc_server->escape($args);

	$filter_args = array();
	$filter_args['post_id'] = $post_id;
	$filter_args['custom_fields'] = kapost_byline_custom_fields($args[3]['custom_fields']);
	$filter_args['data'] = $args[3];

	$filter_args = apply_filters('kapost_byline_before_on_insert_post', $filter_args);

	kapost_byline_update_post_meta_data($filter_args['post_id'],
										$filter_args['custom_fields']);

	// we deliberately ignore the return value ; this is not an action in order to
	// maintain consistency with the rest of the filters
	$filter_args = apply_filters('kapost_byline_after_on_insert_post', $filter_args);
}

add_filter('wp_insert_post_data', 'kapost_byline_on_insert_post_data', '999', 2);
add_action('wp_insert_post', 'kapost_byline_on_insert_post');
?>
