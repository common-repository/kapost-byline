<?php
function kapost_byline_validate_image_url($url)
{
	$result = apply_filters('kapost_byline_valid_image_url', $url);
	if($result != $url) return $result;

	return preg_match('/^https?:\/\/.*?\/.*?\.(jpg|png|jpeg|bmp|gif)$/', $url);
}

function kapost_byline_extract_filename_from_s3_image_url($url)
{
	$matches = array();

	$re = '/^https:\/\/(.*?)\.s3\.amazonaws\.com\/uploads\/user\/avatar\/.*?\/(.*?)\.(jpg|jpeg|png|bmp|gif)$/';
	if(!preg_match($re, $url, $matches))
		return array();

	return $matches;
}

function kapost_byline_is_s3_cloudfront_installed()
{
	global $aws_meta;
	return isset($aws_meta['amazon-s3-and-cloudfront']);
}

function kapost_byline_get_attachment_by_guid($op, $guid)
{
	global $wpdb;
	return $wpdb->get_row($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type = 'attachment' AND guid " . $op . " %s LIMIT 1", $guid));
}

function kapost_byline_get_attachment_by_url($url)
{
	global $as3cf;

	$op = '=';
	$guid = $url;

	if(!empty($as3cf) && class_exists('AS3CF_S3_To_Local'))
	{
		if(!class_exists('KapostBylineMediaLiteCompatHelper'))
			kapost_byline_bootstrap(array('media-lite-compat-helper.php'));

		try
		{
			$helper = new KapostBylineMediaLiteCompatHelper($as3cf);
			$attachment = $helper->get_attachment_from_url($url);

			if(!empty($attachment))
				return $attachment;
		}
		catch(Exception $e)
		{
			// ignore any exceptions, because there's nothing we can do about them
		}
	}

	$result = apply_filters('kapost_byline_get_attachment_by_url', array('op' => $op, 'guid' => $guid));
	if(is_array($result) && isset($result['op']) && isset($result['guid']))
	{
		if(!empty($result['op']))
			$op = $result['op'];

		if(!empty($result['guid']))
			$guid = $result['guid'];
	}

	if(kapost_byline_is_s3_cloudfront_installed())
	{
		$re = '/^https?:\/\/.*?\/.*?(\/[0-9]{4}\/[0-9]{2}\/.*?)$/';

		if(!preg_match($re, $url, $matches))
			return null;

		$op   = 'LIKE';
		$guid = '%' . $matches[1];
	}

	$attachment = kapost_byline_get_attachment_by_guid($op, $guid);

	if(empty($attachment) && !kapost_byline_is_s3_cloudfront_installed())
	{
		$path = @parse_url($url, PHP_URL_PATH);
		if(!empty($path))
			$attachment = kapost_byline_get_attachment_by_guid('LIKE', '%' . $path);
	}

	return $attachment;
}

function kapost_byline_wp_handle_upload($file)
{
	if(isset($file['id']) && kapost_byline_is_s3_cloudfront_installed())
		$file['url'] = wp_get_attachment_url($file['id']);

	return $file;
}

if(defined('XMLRPC_REQUEST'))
	add_filter('wp_handle_upload', 'kapost_byline_wp_handle_upload');
?>
