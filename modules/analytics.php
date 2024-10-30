<?php
function kapost_byline_get_post()
{
	global $wp_query, $post;

	if(isset($wp_query) && !empty($wp_query->post))
		return $wp_query->post;

	return $post;
}

function kapost_byline_inject_analytics()
{
	if(!is_single())
		return;

	$settings = kapost_byline_settings();
	if($settings['analytics'] != 'on')
		return;

	$post = kapost_byline_get_post();

	if(!isset($post) || ($post->post_status != 'publish') || (strpos($post->post_content, '<!-- END KAPOST ANALYTICS CODE -->') !== FALSE))
		return;

	$kapost_analytics = get_post_meta($post->ID, '_kapost_analytics', true);
	if(empty($kapost_analytics))
		return;

	$url = KAPOST_BYLINE_ANALYTICS_URL;

	$post_id = esc_js($kapost_analytics['post_id']);

	if(isset($kapost_analytics['site_id']))
		$site_id = esc_js($kapost_analytics['site_id']);
	else
		$site_id = '';

echo "<!-- BEGIN KAPOST ANALYTICS CODE -->
<script type=\"text/javascript\">
<!--
var _kaq = _kaq || [];
_kaq.push([2, '$post_id', '$site_id']);
(function(){
var scheme = location.protocol == 'https:' ? location.protocol : 'http:';
var ka = document.createElement('script'); ka.async=true; ka.id='ka_tracker'; ka.src= scheme + '$url/ka.js';
var s = document.getElementsByTagName('script')[0]; s.parentNode.insertBefore(ka, s);
})();
//-->
</script>
<!-- END KAPOST ANALYTICS CODE -->";
}

add_action('wp_footer', 'kapost_byline_inject_analytics');
?>
