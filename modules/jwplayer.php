<?php
// adapted from http://wordpress.org/plugins/jw-player-plugin-for-wordpress/
function kapost_byline_jw_player_get_youtube_meta_data($video_id)
{
	$youtube_meta = array();

	$youtube_url = "http://gdata.youtube.com/feeds/api/videos/" . $video_id;
	$youtube_result = @file_get_contents($youtube_url);

	if($youtube_result === FALSE)
		return false;

	$youtube_xml = simplexml_load_string($youtube_result);
	$youtube_media = $youtube_xml->children("http://search.yahoo.com/mrss/");

	$youtube_meta["title"] = $youtube_media->group->title;
	$youtube_meta["description"] = $youtube_media->group->description;

	$thumbnails = $youtube_xml->xpath("media:group/media:thumbnail");
	$youtube_meta["thumbnail_url"] = (string) $thumbnails[0]["url"];

	return $youtube_meta;
}

function kapost_byline_update_jw_player($post_id)
{
	$post = get_post($post_id);
	$content = $post->post_content;

	// check for our special jwplayer short-code in the post content
	$matches = array();
	if(!preg_match('/\[jwplayer\s+.*?url="(.*?)"\s*.*?\]/m', $content, $matches))
		return;

	// adapted from http://wordpress.org/plugins/jw-player-plugin-for-wordpress/
	$youtube_pattern = "/youtube.com\/watch\?v=([0-9a-zA-Z_-]*)/i";
	$youtube_api = null;

	$url = $matches[1];
	$attachment = array
	(
		"post_mime_type"=> "video/mp4",
		"guid"			=> $url,
		"post_parent"	=> $post_id,
	);

	if(preg_match($youtube_pattern, $url, $matches))
	{
		$youtube_api = kapost_byline_jw_player_get_youtube_meta_data($matches[1]);
		if($youtube_api)
		{
			$attachment["post_title"]	= $youtube_api["title"];
			$attachment["post_content"] = $youtube_api["description"];
		}
	}
	else
	{
		$file_info = wp_check_filetype($url);
		if($file_info["type"] != null)
		{
			$attachment["post_mime_type"]	= $file_info["type"];
			$attachment["post_content"]		= "";
			$attachment["post_title"]		= "";
		}
	}

	$id = wp_insert_attachment($attachment, $url, $post_id);

	if($youtube_api)
		update_post_meta($id, LONGTAIL_KEY . "thumbnail", $youtube_api["thumbnail_url"]);
	else if(strstr($url, "rtmp://"))
		update_post_meta($id, LONGTAIL_KEY . "rtmp", $url);

	update_post_meta($id, LONGTAIL_KEY . "external", true);
	wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $url));

	$post->post_content = preg_replace('/(\[jwplayer\s+.*?)(url=".*?")(\s*.*?\])/m', "$1mediaid=\"${id}\"$3", $content);

	remove_action('wp_insert_post', 'kapost_byline_update_jw_player'); // remove to avoid recursion ...
	wp_update_post($post);
}

if(class_exists("JWP6_Plugin")) // register only if the JWP6 plugin is present ...
	add_action('wp_insert_post', 'kapost_byline_update_jw_player');
