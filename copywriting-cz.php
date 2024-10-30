<?php
/**
* Plugin Name: Copywriting.cz
* Plugin URI: https://www.copywriting.cz/
* Description: Umožňuje nahrávat texty zakoupené na Copywriting.cz přímo na Váš web bez nutnosti kopírování
* Version: 1.0.4
* Author: Otakar Pěnkava, WebDeal s.r.o.
* Author URI: https://www.firma-webdeal.cz/
* License: GPL2
*/


$copywritingcz = new copywritingcz();

class copywritingcz {
		public $prefix = 'cpcz';

		public function __construct() {
				add_option($this->prefix.'_token', '');
				add_action('admin_menu', [$this, 'adminMenu']);
				add_action('init', [$this, 'addEndpoint']);
				add_action('template_redirect', [$this, 'endPoint']);
		}

		private function json($array) {
				echo json_encode($array);
				exit;
		}

		private function form($array) {
				$json = file_get_contents("php://input");
				$data = json_decode($json, true);

				$formData = [];
				foreach($array as $formItem) {
						if(is_array($data[$formItem])) {
								$formData[$formItem] = $data[$formItem] ?? '';
						} else {
								$formData[$formItem] = sanitize_text_field($data[$formItem]);
						}
				}

				return $formData;
		}

		private function checkToken($token) {
				return $token == $this->token();
		}

		private function token() {
				return get_option($this->prefix.'_token');
		}

		public function adminMenu() {
				add_management_page('Copywriting.cz', 'Copywriting.cz', 'manage_options', 'copywriting-cz', function() {
						return $this->adminContent();
				});
		}

		public function adminContent() {
				global $wpdb, $copywritingcz;

				if(isset($_POST['submit'])) {
						if(sanitize_text_field($_POST['submit'])) {
								if(!check_admin_referer('save')) {
										_e('<div class="error"><p><strong>Chyba!</strong><br />U formuláře vypršel časový limit, prosím odešlete jej znovu.|'.$_POST['cpcz_wpnonce'].'</p></div>');
								} else {
										file_get_contents('https://api.copywriting.cz/plugin/remove-token/'.$copywritingcz->token());

										update_option($this->prefix.'_token', '');

										_e('<script type="text/javascript">window.location=document.location.href;</script>');
										_e('<div class="updated"><p><strong>Propojení s Copywriting.cz bylo přerušeno</strong></p></div>');
								}
						}
				}

				$content = '
					<div class="postbox">
						<div class="inside">
							<form action="' . str_replace( '%7E', '~', $_SERVER['REQUEST_URI']) . '" method="POST" class="initial-form" id="quick-press">
								<h1>Copywriting.cz</h1>
								<p>Plugin umožňuje snadné nahrání zakoupeného obsahu z Copywriting.cz bez nutnosti kopírování.</p>
				';
				if($copywritingcz->token() != '') {
							$content .= '
								<div style="border: 1px solid #1aa850; background: #1aa850; padding: 0 10px; margin-bottom: 15px;">
									<h3 style="color: #ffffff;">Propojení je aktivní</h3>
								</div>
								<div style="border: 1px solid #ff0000; padding: 0 10px;">
									<h3 style="color: #ff0000;">Nebezpečná zóna</h3>
									<p style="line-height: 28px;">Odstraněním propojení znemožníte Copywriting.cz vkládat koncepty na Váš web:
						     		' . wp_nonce_field('save') . '
										<input type="submit" class="button button-primary" name="submit" value="Odstranit">
									</p>
								</div>';
				} else {
					$content .= '
						<div style="border: 1px solid #ff6600; background: #ff6600; padding: 0 10px;">
							<h3 style="color: #fff;">Propojení není aktivní</h3>
							<p style="color: #fff;">Jděte na Copywriting.cz -> vyberte text k exportu -> zadejte adresu tohoto webu. Poté se spárujeme s tímto webem a bude možné nahrávat koncepty příspěvků.</p>
						</div>';
				}
				$content .= '
							</form>
						</div>
					</div>';

				echo $content;
		}

		public function addEndpoint() {
		  	add_rewrite_endpoint($this->prefix, EP_ALL);
		}

		public function endPoint() {
		    global $wpdb, $wp_query, $post, $pdckl_db_version;

		    if (!isset($wp_query->query_vars[$this->prefix])) {
						return;
				}
				http_response_code(200);

				switch($wp_query->query_vars[$this->prefix]) {
						case 'addToken':
								//
								if(get_option($this->prefix.'_token') == '') {
										$form = $this->form(['token']);

										update_option($this->prefix.'_token', esc_sql($form['token']));

										$this->json(['status'	=>	'OK', 'message'	=>	'TOKEN_INSERTED']);
								} else {
										$this->json(['status'	=>	'ERROR', 'message'	=>	'TOKEN_ALREADY_SET']);
								}
						break;

						case 'checkToken':
								$form = $this->form(['token']);

								sleep(1);
								if($this->checkToken($form['token'])) {
										$this->json(['status'	=>	'OK', 'message'	=>	'TOKEN_VALID']);
								} else {
										$this->json(['status'	=>	'OK', 'message'	=>	'TOKEN_NO_MATCHES']);
								}
						break;

						// Endpoint for api.copywriting.cz (when we delete access between copywriting/this, copywriting sends callback, which remove token here)
						// The most uses screnario is:
						// - user deletes token in this admin
						// - this web sends token to api.copywriting
						// - we delete token at our side
						// - we send token to this web to delete it
						case 'removeToken':
								$form = $this->form(['token']);

								if($this->checkToken($form['token'])) {
										update_option($this->prefix.'_token', '');

										$this->json(['status'	=>	'OK', 'message'	=>	'TOKEN_REMOVED']);
								} else {
										$this->json(['status'	=>	'ERROR', 'message'	=>	'TOKEN_NO_MATCHES']);
								}
						break;

						case 'addDraft':
								$form = $this->form(['title', 'text', 'images', 'token']);

								if($this->checkToken($form['token'])) {
										/*
										** Insert post
										*/
										$text = html_entity_decode(base64_decode($form['text']));
										$ary = [
												'post_author'	=>	1,
												'post_date'		=>	date('Y-m-d H:i:s'),
												'post_content'	=>	$text,
												'post_title'		=>	$form['title'],
												'post_status'			=>	'draft',
												'comment_status'	=>	'closed',
												'ping_status'			=>	'closed',
										];
										$postId = wp_insert_post($ary);

										/*
										** Upload images
										*/
										$tempFiles = [];
										$uploadDir = wp_upload_dir();
										$path = $uploadDir['path'];

										foreach($form['images'] as $image) {
												$file				 = $path.'/'.$image['name'];
												$filename 	 = basename($file);
												$upload_file = wp_upload_bits($filename, null, base64_decode($image['content']));

												if(!$upload_file['error']) {
														$wp_filetype = wp_check_filetype($filename, null);
														$attachment  = [
																'post_mime_type' =>  $wp_filetype['type'],
																'post_parent' 	 =>  $postId,
																'post_title' 		 =>  preg_replace('/\.[^.]+$/', '', $filename),
																'post_content'   =>  '',
																'post_status' 	 =>  'inherit'
														];
														$attachment_id = wp_insert_attachment($attachment, $upload_file['file'], $postId);

														if (!is_wp_error($attachment_id)) {
																require_once(ABSPATH . "wp-admin" . '/includes/image.php');
																$attachment_data = wp_generate_attachment_metadata($attachment_id, $upload_file['file']);
																wp_update_attachment_metadata($attachment_id,  $attachment_data);
														}

														$tempFiles[]	=	$upload_file['url'];
												}
										}

										/*
										** Complete images to text
										*/
										if(isset($form['images']) && count($form['images']) > 0) {
												$textCompleted = '';
												$textHelper = explode('{image}', $text);
												foreach($textHelper as $key => $textPart) {
														$textCompleted .= $textPart;
														if(isset($tempFiles[$key]))
															$textCompleted .='<img class="alignnone size-medium wp-image" src="'.$tempFiles[$key].'">';
												}

												if($textCompleted != '') {
														wp_update_post([
																'ID'				=>	$postId,
																'post_content'	=>	$textCompleted,
														]);
												}
										}

										$this->json(['status'	=>	'OK', 'message'	=>	'PUBLISHED']);
								} else {
										$this->json(['status'	=>	'ERROR', 'message'	=>	'TOKEN_NO_MATCHES']);
								}
						break;
				}
		}
}
?>
