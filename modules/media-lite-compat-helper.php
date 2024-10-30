<?php
if(class_exists('AS3CF_S3_To_Local') && !class_exists('KapostBylineMediaLiteCompatHelper'))
{
	class KapostBylineMediaLiteCompatHelper extends AS3CF_S3_To_Local
	{
		protected function init()
		{
			// nop
		}

		public function get_attachment_from_url($url)
		{
			if(empty($url))
				return null;

			$id = $this->get_attachment_id_from_url($url);
			if(empty($id))
				return null;

			return get_post($id);
		}
	}
}
?>
