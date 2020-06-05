<?php

class Citizenos_Elements {

	private $client;
	private $settings;

	/**
	 * @param $client
	 * @param $settings
	 */
	function __construct( $client, $settings ){
		$this->client = $client;
		$this->settings = $settings;
	}

	/**
	 * @param $client
	 * @param $settings
	 *
	 * @return \Citizenos_Elements
	 */
	static public function register( $client, $settings ){
		$elements = new self( $client, $settings );
        session_start();

		// add a shortcode for the login button
		add_shortcode( 'cos_groups_list', array( $elements, 'make_groups_list' ) );
		add_shortcode( 'cos_public_topics_list', array( $elements, 'make_public_topics_list' ) );
		add_shortcode( 'cos_user_topics_list', array( $elements, 'make_user_topics_list' ) );
		add_shortcode( 'cos_user_create_topic', array( $elements, 'make_topic_create_form' ) );
		add_shortcode( 'create_user_location_form', array( $elements, 'make_user_location_form') );
		add_shortcode( 'cos_login_link', array($elements, 'make_login_link') );

		return $login_form;
	}

	/**
	 * Display an error message to the user
	 *
	 * @param $error_code
	 *
	 * @return string
	 */
	function make_error_output( $error_code, $error_message ) {

		ob_start();
		?>
		<div id="login_error">
			<strong><?php _e( 'ERROR'); ?>: </strong>
			<?php print esc_html($error_message); ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
     * @usage Generate direct link to download
     * @param $params
     * @param string $content
     * @return string
     */
    function make_login_link($params = array(), $content = "")
    {
		if (is_user_logged_in()) {
			$current_user = wp_get_current_user();
			return '<div class="logged-in-message">You are logged in as: '.$current_user->user_login .' </div>';
		}
		extract($params);
		$linkparams = array();
		if ($params['redirect_uri']) {
			$linkparams['redirect_uri'] = $params['redirect_uri'];
		}
		$linkURL = $this->client->make_authentication_url($linkparams);
        $target = isset($params['target'])?"target={$params['target']}":"";
        $class = isset($params['class'])?"class={$params['class']}":"";
        $id = isset($params['id'])?"id={$params['id']}": "id='cos_login_link'";
		$linkLabel = isset($params['label'])? $params['label'] : __( "Login using Citizen OS" );
		ob_start();
		?>
        <a <?=$target?> <?=$class?> <?=$id?> href='<?=$linkURL?>'><?=$linkLabel?></a>
		<?php
        return ob_get_clean ();
	}

	function make_group_create_form() {
		if (!is_user_logged_in()) {
			return;
		}
		ob_start();
            ?>
            <div>
            <form method="post" action="<?php echo admin_url('admin-ajax.php?action=citizenos-create-group');?>">
                <input type="text" name="group_name" />
                <input type="text" name="group_location" />
                <input type="submit" value="Add group"/>
            </form>
            </div>
            <?php

        return ob_get_clean();
	}

	function make_groups_list() {
		$text = apply_filters( 'citizenos-groups-list-text', __( 'Citizen OS Groups' ) );

        if (!$this->client->tokens_set()) {
            $this->client->set_api_access_token($_SESSION['tokens']['access_token']);
        }
        $groups = $this->client->get_user_groups();
        if ($groups['count'] > 0) {
            ob_start();
            foreach($groups['rows'] as $group):
            ?>
            <li><?=$group['name'];?></li>
            <?php
            endforeach;
            return ob_get_clean();
        } else {
            return $this->make_group_create_form();
        }

	}

	function make_topic_create_form () {
		$loggedIn = true;
		if (!is_user_logged_in()) {
			$loggedIn = false;
		}
		ob_start();
            ?>
            <div>
			<script>
				jQuery(document).ready(function () {
					var input = document.getElementById('citizenos-location-input');
					if (google!==undefined) {
						autocomplete = new google.maps.places.Autocomplete(input, {fields: ["name", "formatted_address", "place_id", "geometry",], types: ['geocode']});
						google.maps.event.addListener(autocomplete, 'place_changed', function(v) {
							var place = autocomplete.getPlace();
							var lat = place.geometry.location.lat();
							var lng = place.geometry.location.lng()
							jQuery('#location_lat').val(lat);
							jQuery('#location_lng').val(lng);
						});
					}
					jQuery('#topic_create_form').on('submit',function (event) {
						event.preventDefault();
						var formData = new FormData(event.target);
						jQuery.ajax({
							type: 'POST',
							url: '<?php echo admin_url('admin-ajax.php?action=citizenos-create-topic');?>',
							data: jQuery('#topic_create_form').serialize(),
							success: function (data) {
								console.log('Submission was successful.');
								console.log(data);
								location.reload();
							},
							error: function (error) {
								console.log('An error occurred.');
								console.log(error);
							},
						});
					})
				});
			</script>
            <form id="topic_create_form" method="post">
				<input type="hidden" id="location_lat" name="location_lat" />
				<input type="hidden" id="location_lng" name="location_lng" />
				<input type="hidden" name="map_id" value="<?= $this->settings->screening_map_id;?>" />
                <div><label for="title"><?= apply_filters( 'citizenos-topics-label-text', __( 'Screening title' ) );?></label><br><input type="text" name="title" /></div>
				<div><label for="content"><?= apply_filters( 'citizenos-topics-label-text', __( 'Screening description' ) );?></label><textarea name="content"></textarea></div>
				<div><label for="location"><?= apply_filters( 'citizenos-topics-label-text', __( 'Screening location' ) );?><br></label><input id="citizenos-location-input" type="text" name="location"/></div>
                <input type="submit" value="<?=__( 'Add screening' )?>"  <?php if(!$loggedIn) {echo 'disabled="true"';}?>/>
            </form>
            </div>
            <?php

        return ob_get_clean();
	}

	function make_excerpt($text, $cutOffLength) {
		$charAtPosition = "";
		$textLength = strlen($text);

		if ($cutOffLength > $textLength) {
			return $text;
		}
		do {
			$cutOffLength++;
			$charAtPosition = substr($text, $cutOffLength, 1);
		} while ($cutOffLength < $textLength && $charAtPosition != " ");

		return substr($text, 0, $cutOffLength) . '...';
	}

	function make_public_topics_list($params = array(), $content = "") {
		if (is_array($params)) {
			extract($params);
		}
		$fixed_topics = array();
		$topics_result = array();
		if (is_array($params) && $params["fixed_topics"]) {
			$fixed_topics = explode(",", $params["fixed_topics"]);
			foreach($fixed_topics as $key => $topic_id) {
				$topic_id = trim($topic_id);
				$fixed_topics[$key] = $topic_id;
				$topic = $this->client->get_public_topic($topic_id);
				if ($topic["id"]) {
					$topics_result[] = $topic;
				}
			}
		}
		$text = apply_filters( 'citizenos-topics-list-text', __( 'Citizen OS Topics' ) );

        if (!$this->client->tokens_set()) {
            $this->client->set_api_access_token($_SESSION['tokens']['access_token']);
        }
		$topics = $this->client->get_public_topics();
		if (count($fixed_topics)) {
			foreach ($topics["rows"] as $topic) :
					if (in_array($topic["id"], $fixed_topics)) {
						continue;
					}
					$topics_result[] = $topic;
			endforeach;
		} else {
			$topics_result = $topics["rows"];
		}
		ob_start();

        if (is_array($topics) && $topics['count'] > 0) {
		?>
		<div>
			<script>
				var toggleDiscussion = function (topicId) {
					<?php if ($params && $params['show_widget']) {?>
					event.preventDefault();
					if (jQuery("#widget_"+topicId).html().length) {
						jQuery("#widget_"+topicId).html("");
					} else {
						window.CITIZENOS.widgets.Argument('en', topicId, '<?=$this->settings->client_id?>', 'widget_'+topicId);
					}
					<?php }?>
				}

			</script>
			<?php
			foreach ($topics_result as $topic) :
				$title = $topic["title"];
				$description = strip_tags($topic["description"]);
				$description = str_replace($title, '', $description);
				$description = $this->make_excerpt($description, 250);
            ?>
			<a class="topic" href="<?= $topic["url"]?>" onclick="toggleDiscussion('<?=$topic['id']?>');">
				<div class="topic_wrap">
					<div class="top text_small">
						<div class="date"> <?= $topic["createdAt"]?> </div>
						<div class="author"> <?= $topic["creator"]["name"]?> </div>
						<div class="clearer"></div>
					</div>
					<div class="main_text">
						<div class="text_big"><?= $topic["title"]?></div>
						<div class="text_small"><?= $description ?></div>
					</div>
					<div id="widget_<?= $topic['id']?>"></div>
					<div class="line"></div>
				</div>
			</a>
            <?php
			endforeach;
			?>
		</div>
		<?php
        }

		return ob_get_clean();
	}

	function make_user_topics_list($params = array(), $content = "") {
		if (is_array($params)) {
			extract($params);
		}

		$text = apply_filters( 'citizenos-topics-list-text', __( 'Citizen OS Topics' ) );

        if (!$this->client->tokens_set()) {
            $this->client->set_api_access_token($_SESSION['tokens']['access_token']);
        }
		$topics = $this->client->get_user_topics();
		ob_start();

        if (is_array($topics) && $topics['count'] > 0) { ?>
			<div>
				<script>
					var toggleDiscussion = function (topicId) {
						<?php if ($params && $params['show_widget']) {?>
						event.preventDefault();
						if (jQuery("#widget_"+topicId).html().length) {
							jQuery("#widget_"+topicId).html("");
						} else {
							window.CITIZENOS.widgets.Argument('en', topicId, '<?=$this->settings->client_id?>', 'widget_'+topicId);
						}
						<?php }?>
					}

				</script>
				<?php
				foreach($topics['rows'] as $topic):
					$title = $topic["title"];
					$description = strip_tags($topic["description"]);
					$description = str_replace($title, '', $description);
				?>
					<a class="topic varia" href="<?= $topic["url"]?>" onclick="toggleDiscussion('<?=$topic['id']?>');">
						<div class="topic_wrap">
							<div class="top text_small">
								<div class="date"><?= $topic["createdAt"]?></div>
								<div class="author"><?= $topic["creator"]["name"]?></div>
								<div class="clearer"></div>
							</div>

							<div class="main_text">
								<div class="text_big"><?= $topic["title"]?></div>
								<div class="text_small"><?= $description ?></div>
							</div>
							<div id="widget_<?= $topic['id']?>"></div>
							<div class="line"></div>
						</div>
					</a>
				<?php
				endforeach;
				?>
			</div>
		<?php

        }

		return ob_get_clean();
	}

	function make_user_location_form () {
		if (!is_user_logged_in()) {
			return;
		}
		ob_start(); ?>
		<div>
			<script>
				jQuery(document).ready(function () {
					var input = document.getElementById('citizenos-user-location-input');
					if (COS_DATA && COS_DATA.users_map_id) {
						jQuery('#user_map_id').val(COS_DATA.users_map_id);
					}

					autocomplete = new google.maps.places.Autocomplete(input, {fields: ["name", "formatted_address", "place_id", "geometry",], types: ['geocode']});
					google.maps.event.addListener(autocomplete, 'place_changed', function(v) {
						var place = autocomplete.getPlace();
						var lat = place.geometry.location.lat();
						var lng = place.geometry.location.lng()
						jQuery('#user_location_lat').val(lat);
						jQuery('#user_location_lng').val(lng);
					});
					var getLocation= function () {
						if (navigator.geolocation) {
							navigator.geolocation.getCurrentPosition(function (pos) {
								var latlng = {lat: parseFloat(pos.coords.latitude), lng: parseFloat(pos.coords.longitude)};
								var geocoder = new google.maps.Geocoder;
								geocoder.geocode({'location': latlng}, function(results, status) {
									if (status === 'OK') {
										if (results[0]) {
											jQuery('#citizenos-user-location-input').val(results[0].formatted_address);
											jQuery('#user_location_lat').val(results[0].geometry.location.lat());
											jQuery('#user_location_lng').val(results[0].geometry.location.lng());
										} else {
										window.alert('No results found');
										}
									} else {
										window.alert('Geocoder failed due to: ' + status);
									}
								});
							});

						} else {
							console.info("Geolocation is not supported by this browser.");
						}
					}

					getLocation();
					jQuery('#submit_user_location').on('click', function (event) {
						event.preventDefault();
						var location = jQuery('#citizenos-user-location-input').val();
						var lat = jQuery('#user_location_lat').val();
						var lng = jQuery('#user_location_lng').val();
						jQuery.ajax({
							method: 'POST',
							url: COS_DATA.admin_url + '?action=create_user_location',
							data: {
								location: location,
								lat: lat,
								lng: lng,
								title: 'tetetete',
								map_id: COS_DATA.users_map_id
							},
							success: function (data) {
								location.reload();
							},
							error: function (err) {
								console.log(err);
							}
						});
					});
				});
			</script>
			<form>
			<input type="hidden" id="user_location_lat" name="lat"/>
			<input type="hidden" id="user_location_lng" name="lng"/>
			<input type="hidden" id="user_map_id" name="map_id"/>
			<input type="text" id="citizenos-user-location-input" />
			<input id="submit_user_location" type="submit" value="Add location"/>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
