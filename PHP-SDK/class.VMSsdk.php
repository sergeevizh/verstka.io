<?php

	/**
	 * Created by PhpStorm.
	 * User: daemaken
	 * Date: 02.10.15
	 * Time: 22:50
	 */

	namespace devnow;

	class VMSsdk
	{
		protected $default_vms_api_url = 'https://verstka.io/api';
		private $apikey;
		private $secret;
		private $host_name;
		private $temp_dir_abs;
		private $web_root_abs;
		private $call_back_url;
		private $call_back_function;
		private $ds = DIRECTORY_SEPARATOR;
		private $force_lacking_images = false;

		//$image_dir, $call_user_func, $temp_dir

		public function __construct ($apikey, $secret, $call_back_url, $call_back_function, $temp_dir_abs, $web_root_abs, $static_host_name)
		{
			if (empty($apikey) || empty($secret)) {
				$this->brakeExecution('empty api-key, contact us to get one (hello@verstka.io)');
			}

			$this->apikey = $apikey;
			$this->secret = $secret;

			if (empty($call_back_url)) {
				if (($_SERVER['REQUEST_SCHEME'] == 'https') or ($_SERVER['SERVER_PORT'] == '443')) {
					$this->call_back_url = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
				} else {
					$this->call_back_url = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['SCRIPT_NAME'];
				}
			} else {
				$this->call_back_url = $call_back_url;
			}

			if (empty($static_host_name)) {
				$this->host_name = parse_url($this->call_back_url);
				$this->host_name = $this->host_name['host'];
			} else {
				$this->host_name = $static_host_name;
			}

			if (!empty($call_back_function)) {
				$this->call_back_function = $call_back_function;
			} else {
				$this->call_back_function = '\devnow\default_callback_function';
			}

			if (empty($temp_dir_abs)) {
				$temp_dir_abs = __DIR__ . $this->ds . 'vms_temp';
			}

			$this->temp_dir_abs = $this->normalizePath($temp_dir_abs);
			if (!is_writable($this->temp_dir_abs)) {
				@mkdir($this->temp_dir_abs, 0777, true);
			}

			if (empty($web_root_abs)) {
				$this->web_root_abs = $this->normalizePath($_SERVER['DOCUMENT_ROOT']);
			} else {
				$this->web_root_abs = $this->normalizePath($web_root_abs);
			}

			$this->check_if_callback();
		}

		public function check_if_callback ()
		{
			if (!empty($_REQUEST['download_url']) && ($this->getRequestSalt($this->secret, $_REQUEST, 'session_id, user_id, material_id, download_url') == $_REQUEST['callback_sign'])) {

				set_time_limit(0);

				$result['json'] = $this->requestUri($_REQUEST['download_url'], array(
						'api-key' => $this->apikey,
				));
				$result['data'] = json_decode($result['json'], true);
				if (empty($result['data']) || $result['data']['rc'] != 1) {
					$this->brakeExecution($result['json']);
				} else {
					$result = $result['data']['data'];
				}

				$images_to_download = array();
				foreach ($result as $image) {
					if ((strpos($_REQUEST['html_body'], $image) !== false) or ($image == 'preview.png')) {
						$images_to_download[] = array(
								'image' => $image,
								'url' => $_REQUEST['download_url'] . '/' . $image,
								'download_to' => $this->temp_dir_abs . $image
						);
					}
				}
				
				$images_to_download = $this->multiRequest($images_to_download);

				$images = array();
				foreach ($images_to_download as $image) {
					if (!empty($images_to_download['result']) and $this->force_lacking_images) {
						$this->brakeExecution($images_to_download['result']);
					}
					if (empty($images_to_download['result'])) {
						$images[$image['image']] = $image['download_to'];
					}
				}
				unset($images_to_download, $result);

				$custom_fields = json_decode($_REQUEST['custom_fields'], true);
				$call_back_result = call_user_func($this->call_back_function, $_REQUEST['html_body'], $_REQUEST['material_id'], $_REQUEST['user_id'], $images, $custom_fields);

				if ($call_back_result === true) {
					foreach ($images as $image) {    // clean temp folder if callback successfull
						if (is_readable($this->temp_dir_abs . $image)) {
							unlink($this->temp_dir_abs . $image);
						}
					}

					die($this->formJSON(1, 'save sucessfull'));
				} else {
					die($this->formJSON(0, 'callback fail', $call_back_result));
				}
			}
		}

		public function edit ($body, $material_id, $user_id, $custom_fields)
		{
			/*			additional fields, any arbitrary array, this data will be returned to the callback uri in same field
						$custom_fields = array(
							'auth_user'		//if You have http authorization on callback url
							'auth_pw' 		//if You have http authorization on callback url
							'editUrl'			//for copy Edit URI	feature
						)
			*/

			if (empty($user_id)) { //it is strongly recommended to declare for safety and working of article locks
				if (!empty($_SERVER['PHP_AUTH_USER'])) {
					$user_id = $_SERVER['PHP_AUTH_USER'];
				} elseif (!empty($_SERVER['REMOTE_USER'])) {
					$user_id = $_SERVER['REMOTE_USER'];
				} else {
					$user_id = $_SERVER['REMOTE_ADDR'];
				}
			}
			if (empty($material_id)) { //it is strongly recommended to declare for save reasons (if You will have more than one article)
				$material_id = 1;
			}

			// if empty $body default template will be used

			$params = array(
					'POST' => array(
							'user_id' => $user_id,
							'user_ip' => $_SERVER['REMOTE_ADDR'],
							'material_id' => $material_id,
							'html_body' => $body,
							'callback_url' => $this->call_back_url,
							'host_name' => $this->host_name,
							'api-key' => $this->apikey,
							'custom_fields' => json_encode($custom_fields)
					)
			);

			$params['POST']['callback_sign'] = $this->getRequestSalt($this->secret, $params['POST'], 'api-key, material_id, user_id, callback_url');

			$result['json'] = $this->requestUri($this->default_vms_api_url . '/open', $params);

			$result['data'] = json_decode($result['json'], true);
			if (empty($result['data'])) {
				$this->brakeExecution($result['json']);
			} else {
				$result = $result['data'];
			}

			if ($result['rc'] == 1) {
				if (!empty($result['data']['upload_url']) and !empty($result['data']['lacking_pictures'])) {

					$will_upload = array();
					$missing_files = array();

					foreach ($result['data']['lacking_pictures'] as $lacking_image) {
						$lacking_image_abs = $this->web_root_abs . ltrim($lacking_image, '/');

						if (is_readable($lacking_image_abs)) {
							$will_upload[] = $lacking_image_abs;
						} else {
							$missing_files[] = $lacking_image_abs;
						}
					}

					if (!empty($will_upload)) {

						$upload_result['json'] = $this->requestUri($result['data']['upload_url'], array(
								'api-key' => $this->apikey,
								'upload_from' => $will_upload
						));

						$upload_result['data'] = json_decode($upload_result['json'], true);

						if (empty($upload_result['data'])) {
							$this->brakeExecution($upload_result['json']);
						} else {
							$upload_result = $upload_result['data'];
						}
					}

					if ($this->force_lacking_images && !empty($missing_files)) {
						$this->brakeExecution('missing ' . print_r($missing_files, true) . ' in ' . $material_id);
					}

					if (empty($upload_result)) {
						return $result['data'];
					}

					return $upload_result['data'];
				}

				return $result['data'];

			} else {
				$this->brakeExecution($result);
			}
		}

		public function make_template ($html)
		{
			$result['json'] = $this->requestUri($this->default_vms_api_url . '/parse_images', array(
					'POST' => array(
							'article_body' => $html
					)
			));
			$result['data'] = json_decode($result['json'], true);
			if (empty($result['data'])) {
				$this->brakeExecution($result['json']);
			} else {
				$result = $result['data']['data'];
			}

			foreach ($result as $image_rel) {

				$image_abs = $this->web_root_abs . ltrim($image_rel, '/');

				if (is_readable($image_abs) && is_file($image_abs)) {

					$type = pathinfo($image_abs, PATHINFO_EXTENSION);

					if ($type == 'svg') {
						$type .= '+xml';
					}

					$data = file_get_contents($image_abs);
					$base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
					$html = str_replace($image_rel, $base64, $html);
				}
			}

			return $html;
		}

		protected function multiRequest ($requests, $params)
		{

			$max_requests_per_batch = 99;
			if (!empty($this->max_multi_request_batch)) {
				$max_requests_per_batch = $this->max_multi_request_batch;
			}

			$queues = array();
			while (count($requests) > $max_requests_per_batch) {
				$queues[] = array_splice($requests, 0, $max_requests_per_batch);
			}
			$queues[] = array_splice($requests, 0, $max_requests_per_batch);

			foreach ($queues as $queue_id => $requests) {

				$mh = curl_multi_init();
				foreach ($requests as $conf_id => $conf) {
					$params['return_handler'] = true;
					$conf = array_merge($params, $conf);
					$requests[$conf_id]['curl'] = $this->requestUri($conf['url'], $conf);
					curl_multi_add_handle($mh, $requests[$conf_id]['curl']);
				}

				$mrc = CURLM_OK;
				$active = true;
				while ($active && $mrc == CURLM_OK) {
					if (curl_multi_select($mh) == -1) {
						usleep(100);
					}
					do {
						$mrc = curl_multi_exec($mh, $active);
					} while ($mrc == CURLM_CALL_MULTI_PERFORM);
				}

				foreach ($requests as $conf_id => $conf) {
					$requests[$conf_id]['result'] = curl_multi_getcontent($requests[$conf_id]['curl']);
					$error = curl_error($requests[$conf_id]['curl']);
					if (!empty($error)) {
						$requests[$conf_id]['result'] = $error;
						if (!empty($requests[$conf_id]['download_to'])) {
							unlink($requests[$conf_id]['download_to']);
						}
					}
					curl_multi_remove_handle($mh, $requests[$conf_id]['curl']);
					unset($requests[$conf_id]['curl']);
				}
				curl_multi_close($mh);
				$queues[$queue_id] = $requests;

			}

			$requests = array();
			foreach ($queues as $conf) {
				foreach ($conf as $buff) {
					$requests[] = $buff;
				}
			}

			return $requests;
		}

		protected function requestUri ($url, $params = null)
		{
			if (function_exists('curl_version')) {

				if (!empty($params['download_to'])) {
					set_time_limit(0);
					$fp = fopen($params['download_to'], 'w+');
				}

				$ch = curl_init();

				if (!empty($params['upload_from'])) {
					set_time_limit(0);
					curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
					if (!is_array($params['upload_from'])) {
						$params['upload_from'] = array($params['upload_from']);
					}
					foreach ($params['upload_from'] as $local_file) {
						$local_file = pathinfo($local_file);
						$params['POST'][$local_file['basename']] = '@' . $local_file['dirname'] . $this->ds . $local_file['basename'];
					}
				}

				curl_setopt($ch, CURLOPT_TIMEOUT, 99);
				if (!empty($params['auth_user']) && !empty($params['auth_pw'])) {
					curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
					curl_setopt($ch, CURLOPT_USERPWD, $params['auth_user'] . ':' . $params['auth_pw']);
				}

				if (!empty($fp)) {
					curl_setopt($ch, CURLOPT_FILE, $fp);
				} else {
					curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
				}

				if (!empty($params['GET'])) {
					curl_setopt($ch, CURLOPT_URL, $url . '?' . http_build_query($params['GET']));
				} else {
					curl_setopt($ch, CURLOPT_URL, $url);
				}

				if (!empty($params['POST'])) {
					curl_setopt($ch, CURLOPT_POST, 1);
					curl_setopt($ch, CURLOPT_POSTFIELDS, $params['POST']);
				}

				curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
				curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
				curl_setopt($ch, CURLOPT_FAILONERROR, 1);

				$result = curl_exec($ch);

				if (0 == curl_errno($ch)) {
					curl_close($ch);
					if (!empty($fp)) {
						fclose($fp);
					}
					return $result;
				} else {
					$result = array('request' => array('url' => $url, 'params' => $params),
							'error' => array('code' => curl_errno($ch), 'error' => curl_error($ch)));
					curl_close($ch);
					if (!empty($fp)) {
						fclose($fp);
					}
					return $result;
				}
			} else {
				die('there no lib Curl enabled see https://secure.php.net/curl');
			}
		}

		protected function brakeExecution ($messages)
		{
			$messages = print_r($messages, true);
			die($messages);
		}

		private function formJSON ($res_code, $res_msg, $data = array())
		{
			return json_encode(array(
					'rc' => $res_code,
					'rm' => $res_msg,
					'data' => $data
			), JSON_NUMERIC_CHECK);
		}

		/*
		 * $request is an Array to verify
		 * $fields is a comma separated values
		 */
		private function getRequestSalt ($secret, $request, $fields)
		{

			$fields = array_filter(array_map('trim', explode(',', $fields)));
			$data = $secret;
			foreach ($fields as $field) {
				$data .= $request[$field];
			}
			return md5($data);
		}

		private function normalizePath ($path)
		{
			if (strpos($path, '..') !== false) {
				$path = trim($path, $this->ds);
				$parts = explode($this->ds, $path);
				$new_path = '';
				foreach ($parts as $part) {
					if ($part == '..') {
						$new_path = $this->ds . trim(dirname($this->ds . $new_path), $this->ds);
					} else {
						$new_path .= '/' . $part;
					}
				}
				return $this->normalizePath($new_path);

			} else {
				$path = $this->ds . trim($path, $this->ds) . $this->ds;
				do {
					$last_path = $path;
					$path = str_replace('//', '/', $last_path);
				} while ($last_path != $path);
				return $path;
			}
		}
	}

	function default_callback_function ($body, $material_id, $user_id, $images, $custom_fields)
	{

		$ds = DIRECTORY_SEPARATOR;
		$material_images_dir_relative = $ds . 'upload' . $ds . $material_id . $ds;
		$material_images_dir_absolute = $_SERVER['DOCUMENT_ROOT'] . $material_images_dir_relative;
		$material_html_file_absolute = $_SERVER['DOCUMENT_ROOT'] . $ds . $material_id . '.html';

		@mkdir($material_images_dir_absolute, 0777, true);

		$body = str_replace('/vms_images/', $material_images_dir_relative, $body);

		$body .= PHP_EOL . PHP_EOL . '<style>[data-vms-version="1"]{position: relative; margin: 0 auto;}</style>' . PHP_EOL .
				'<script>window.onVMSAPIReady = function ( api ) {api.Article.enable();};</script>' . PHP_EOL .
				'<script src="http://go.verstka.io/api.js" type="text/javascript"></script>';

		if (file_put_contents($material_html_file_absolute, $body)) {

			$moved = true;
			foreach ($images as $image_name => $image_file) {
				$moved = $moved & rename($image_file, $material_images_dir_absolute . $image_name);
			}
			return !!$moved;

		} else {
			return 'client call back function fail message';
		}
	}
?>
