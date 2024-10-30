<?php
function kapost_byline_update_user_bio($user_id, $custom_fields)
{
	$author = $custom_fields['kapost_author'];
	$profile= $custom_fields['kapost_author_profile'];
	$email	= $custom_fields['kapost_author_email'];

	if(isset($custom_fields['kapost_author_bio']))
		$bio = $custom_fields['kapost_author_bio'];
	else
		$bio = '';

	if(!empty($bio))
	{
		wp_update_user(array(
			'ID'			=> $user_id,
			'user_url'		=> $profile,
			'display_name'	=> $author,
			'description'	=> $bio
		));
	}
}

function kapost_byline_update_user_meta($user_id, $custom_fields)
{
	// whitelist the meta fields that an be updated this way
	$user_meta_fields = array('twitter', 'linkedin', 'google_plus', 'googleplus', 'facebook');

	foreach($user_meta_fields as $name)
	{
		$key = '_kapost_author_meta_' . $name;
		if(isset($custom_fields[$key]))
		{
			$value = esc_sql($custom_fields[$key]);
			update_user_meta($user_id, $name, $value);
		}
	}
}

function kapost_byline_update_user_photo($user_id, $custom_fields)
{
	$avatar_url = $custom_fields['kapost_author_avatar'];
	if(empty($avatar_url))
		return false;

	// check for the existance of the user-photo plugin
	if(!function_exists('userphoto_profile_update'))
		return false;

	// verify that the avatar is coming from Kapost and wasn't hijacked
	$matches = kapost_byline_extract_filename_from_s3_image_url($avatar_url);
	if(empty($matches))
		return false;

	$filename = $matches[2] . "." . $matches[3];

	// if the url matches the previous url don't do anything :)
	$old_avatar_url = get_user_meta($user_id, 'userphoto_original_image_file', true);
	if($old_avatar_url == $avatar_url)
		return false;

	// fetch the contents
	$avatar_contents = @file_get_contents($avatar_url);
	if($avatar_contents === FALSE)
		return false;

	// create a temporary and unique filename
	$tmp_name = tempnam('/tmp', 'kapost-userphoto-profile-update');

	// write the contents into the temporary file
	$tmp = @fopen($tmp_name, "w+");
	if(!$tmp)
		return false;

	fwrite($tmp, $avatar_contents);
	fclose($tmp);

	try
	{
		kapost_userphoto_profile_update($user_id, $tmp_name, $filename);
		update_usermeta($user_id, 'userphoto_original_image_file', $avatar_url);
	}
	catch(Exception $e)
	{
		// ignore any errors because there is nothing we can do about them
	}

	@unlink($tmp_name);
	return true;
}

// FIXME: had to copy all this from the user-photo plugin because of @move_uploaded_file()
function kapost_userphoto_profile_update($userID, $tmppath, $name)
{
	global $userphoto_validtypes;
	global $userphoto_validextensions;
	global $current_user;

	$userdata = get_userdata($userID);

	$error = false;

	$imageinfo = null;
	$thumbinfo = null;

	$userphoto_maximum_dimension = get_option( 'userphoto_maximum_dimension' );

	$imageinfo = getimagesize($tmppath);
	if(!$imageinfo || !$imageinfo[0] || !$imageinfo[1])
		return false;

	if($imageinfo[0] > $userphoto_maximum_dimension || $imageinfo[1] > $userphoto_maximum_dimension)
	{
		if(userphoto_resize_image($tmppath, null, $userphoto_maximum_dimension, $error))
			$imageinfo = getimagesize($tmppath);
	}

	if($error)
		return false;

	$upload_dir = wp_upload_dir();
	$dir = trailingslashit($upload_dir['basedir']) . 'userphoto';

	// FIXME: 0777 is probably not safe
	if(!file_exists($dir) && @!mkdir($dir, 0777))
		return false;

	$oldimagefile = basename($userdata->userphoto_image_file);
	$oldthumbfile = basename($userdata->userphoto_thumb_file);
	$imagefile = "$userID." . preg_replace('{^.+?\.(?=\w+$)}', '', strtolower($name));
	$imagepath = $dir . '/' . $imagefile;
	$thumbfile = preg_replace("/(?=\.\w+$)/", '.thumbnail', $imagefile);
	$thumbpath = $dir . '/' . $thumbfile;

	if(!copy($tmppath, $imagepath))
		return false;

	chmod($imagepath, 0666);

	#Generate thumbnail
	$userphoto_thumb_dimension = get_option( 'userphoto_thumb_dimension' );
	if(!($userphoto_thumb_dimension >= $imageinfo[0] && $userphoto_thumb_dimension >= $imageinfo[1]))
	{
		userphoto_resize_image($imagepath, $thumbpath, $userphoto_thumb_dimension, $error);
		if($error)
			return false;
	}
	else
	{
		copy($imagepath, $thumbpath);
		chmod($thumbpath, 0666);
	}

	$thumbinfo = getimagesize($thumbpath);

	// TODO: respect userphoto_level_moderated
	update_usermeta($userID, "userphoto_approvalstatus", USERPHOTO_APPROVED);

	update_usermeta($userID, "userphoto_image_file", $imagefile); //TODO: use userphoto_image
	update_usermeta($userID, "userphoto_image_width", $imageinfo[0]); //TODO: use userphoto_image_width
	update_usermeta($userID, "userphoto_image_height", $imageinfo[1]);
	update_usermeta($userID, "userphoto_thumb_file", $thumbfile);
	update_usermeta($userID, "userphoto_thumb_width", $thumbinfo[0]);
	update_usermeta($userID, "userphoto_thumb_height", $thumbinfo[1]);

	//Delete old thumbnail if it has a different filename (extension)
	if($oldimagefile != $imagefile)
		@unlink($dir . '/' . $oldimagefile);
	if($oldthumbfile != $thumbfile)
		@unlink($dir . '/' . $oldthumbfile);

	return true;
}

function kapost_byline_create_user($custom_fields, $blog_id=0)
{
	// TODO: check $current_user->has_cap('edit_users') ?

	$user_created = false;

	if(	empty($custom_fields['kapost_author_email']) ||
		empty($custom_fields['kapost_author_profile']) ||
		empty($custom_fields['kapost_author']) )
		return false;

	$author = $custom_fields['kapost_author'];
	$profile= $custom_fields['kapost_author_profile'];
	$email	= $custom_fields['kapost_author_email'];

	if(isset($custom_fields['kapost_author_bio']))
		$bio = $custom_fields['kapost_author_bio'];
	else
		$bio = '';

	$settings = kapost_byline_settings();

	require_once(ABSPATH . WPINC . '/registration.php');
	$uid = email_exists($email);
	if(!$uid && $settings['attr_create_user'] == 'on')
	{
		$c = 0;
		$user_name = $user_login = str_replace(" ","",strtolower($author));

		// FIXME: find a better way to do this
		// Assuming 1000 collisions is safe enough for now, but there must be
		// a better way to achieve this; the request will time out before
		// reaching 1000 anyway ...
		while(username_exists($user_name))
		{
			$user_name = "$user_login-$c";
			if(++$c == 1000) return false;
		}

		$new_user_data = array(
			'user_login'	=> esc_sql($user_name),
			'user_pass'		=> wp_generate_password(12, false),
			'user_email'	=> esc_sql($email),
			'user_url'		=> esc_sql($profile),
			'display_name'	=> esc_sql($author),
			'role'			=> 'contributor'
		);

		if(!empty($bio) && $settings['attr_update_user_bio'] == 'on')
			$new_user_data['description'] = $bio;

		$uid = wp_insert_user($new_user_data);
		$user_created = true;
	}
	else if($uid && $blog_id && KAPOST_BYLINE_MU && function_exists('is_user_member_of_blog') && !is_user_member_of_blog($uid, $blog_id))
	{
		$uid = false;
	}

	global $user_updated;
	if($uid && !isset($user_updated) && current_user_can('edit_user', $uid))
	{
		$user_updated = true;

		$k = 'attr_update_existing_';
		if($user_created)
			$k = 'attr_update_';

		if(!$user_created && $settings[$k . 'user_bio'] == 'on')
			kapost_byline_update_user_bio($uid, $custom_fields);

		if($settings[$k . 'user_meta'] == 'on')
			kapost_byline_update_user_meta($uid, $custom_fields);

		if($settings[$k . 'user_photo'] == 'on')
			kapost_byline_update_user_photo($uid, $custom_fields);
	}

	return ($uid) ? $uid : false;
}
?>
