<?php

class Citizenos_Client {
	private $client_id;
	private $client_secret;
	private $scope;
	private $response_type;
	private $nonce;
	private $state;
	private $base_url;
	private $api_client;
	private $tokens;
	private $endpoint_login = "/api/auth/openid/authorize";
	private $endpoint_userinfo = "/api/users/self";

	// login flow "ajax" endpoint
	private $redirect_uri;

	// states are only valid for 3 minutes
	private $state_time_limit = 180;

	/**
	 * Client constructor
	 *
	 * @param $client_id
	 * @param $client_secret
	 * @param $scope
	 * @param $base_url
	 * @param $redirect_uri
	 * @param $state_time_limit time states are valid in seconds
	 */
	function __construct( $client_id, $client_secret, $scope, $base_url, $redirect_uri, $state_time_limit, $ssl_verify=true){
		$this->client_id = $client_id;
		$this->client_secret = $client_secret;
		$this->base_url = $base_url;
		$this->scope = $scope;
		$this->redirect_uri = $redirect_uri;
		$this->state_time_limit = $state_time_limit;
		$this->api_client = new Citizenos_Api_Client($base_url, $client_id, $ssl_verify);

		add_action( "wp_ajax_create-user-group", "create_user_group" );
		add_action( "wp_ajax_create-user-topic", "create_user_topic" );
		add_action( "wp_ajax_create_user_location", array($this, "create_user_location"));
	}

	function tokens_set () {
		return isset($this->tokens);
	}
	function set_tokens ($tokens) {
		$this->tokens = $tokens;
	}

	function set_api_access_token ($token) {
		$this->api_client->set_access_token($token);
	}

	function get_nonce($length = 14) {
		return substr(str_shuffle(str_repeat($x="0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ", ceil($length/strlen($x)) )),1,$length);
	}

	/**
	 * Create a single use authentication url
	 *
	 * @return string
	 */
	function directLink($params, $content = "")
    {
        extract($params);

        global $wpdb;
        if(\WPDM\Package::isLocked($params["id"]))
            $linkURL = get_permalink($params["id"]);
        else
            $linkURL = home_url("/?wpdmdl=".$params["id"]);
        $target = isset($params["target"])?"target={$params["target"]}":"";
        $class = isset($params["class"])?"class={$params["class"]}":"";
        $id = isset($params["id"])?"target={$params["id"]}":"";
        $linkLabel = isset($params["label"]) && !empty($params["label"])?$params["label"]:get_post_meta($params["id"], "__wpdm_link_label", true);
        $linkLabel = empty($linkLabel)?get_the_title($params["id"]):$linkLabel;
        return  '<a {$target} {$class} {$id} href="$linkURL">$linkLabel</a>';

    }
	function make_authentication_url() {
				if (!$redirect_uri) {
			$redirect_uri = get_permalink()? get_permalink() : urlencode( $this->redirect_uri );
		}
		$separator = "?";
		if ( stripos( $this->endpoint_login, "?" ) !== FALSE ) {
			$separator = "&";
		}
		$url = sprintf( '%1$s%2$s?response_type=id_token+token&nonce=%3$s&scope=%4$s&client_id=%5$s&state=%6$s&redirect_uri=%7$s',
			$this->base_url,
			$this->endpoint_login,
			$this->get_nonce(),
			urlencode( "openid" ),
			urlencode( $this->client_id ),
			$this->new_state(),
			$redirect_uri
		);

		return apply_filters( "citizenos-auth-url", $url );
	}

	/**
	 * Validate the request for login authentication
	 *
	 * @param $request
	 *
	 * @return array|\WP_Error
	 */
	function validate_authentication_request( $request ){
		// look for an existing error of some kind
		if ( isset( $request["error"] ) ) {
			return new WP_Error( "unknown-error", "An unknown error occurred.", $request );
		}

		return $request;
	}

	/**
	 * Get the authorization code from the request
	 *
	 * @param $request array
	 *
	 * @return string|\WP_Error
	 */
	function get_authentication_code( $request ){
		return $request["code"];
	}


	/**
	 * Exchange an access_token for a user_claim from the userinfo endpoint
	 *
	 * @param $access_token
	 *
	 * @return array|\WP_Error
	 */
	function request_userinfo( $access_token ) {
		$parsed_url = parse_url($this->base_url . $this->endpoint_userinfo);
		$this->api_client->set_access_token($access_token);
		$response = $this->api_client->request($this->endpoint_userinfo);
		// allow modifications to the request
		/*$request = apply_filters( "citizenos-alter-request", array(), "get-userinfo" );

		// section 5.3.1 of the spec recommends sending the access token using the authorization header
		// a filter may or may not have already added headers - make sure they exist then add the token
		if ( !array_key_exists( "headers", $request ) || !is_array( $request["headers"] ) ) {
			$request["headers"] = array();
		}

		$request["headers"]["Authorization"] = "Bearer ".$access_token;

		// Add Host header - required for when the openid-connect endpoint is behind a reverse-proxy
		$parsed_url = parse_url($this->base_url . $this->endpoint_userinfo);
		$host = $parsed_url["host"];

		if ( !empty( $parsed_url["port"] ) ) {
			$host.= ":{$parsed_url["port"]}";
		}

		$request["headers"]["Host"] = $host;

		// attempt the request including the access token in the query string for backwards compatibility
		$response = wp_remote_get( $this->base_url . $this->endpoint_userinfo, $request );*/
		if ( is_wp_error( $response ) ){
			$response->add( "request_userinfo" , __( "Request for userinfo failed." ) );
		}

		return $response;
	}

	/**
	 * Generate a new state, save it to the states option with a timestamp,
	 *  and return it.
	 *
	 * @return string
	 */
	function new_state() {
		$states = get_option( "citizenos-valid-states", array() );

		// new state w/ timestamp
		$new_state            = md5( mt_rand() . microtime( true ) );
		$states[ $new_state ] = time();

		// save state
		update_option( "citizenos-valid-states", $states );

		return $new_state;
	}

	/**
	 * Check the validity of a given state
	 *
	 * @param $state
	 *
	 * @return bool
	 */
	function check_state( $state ) {
		$states = get_option( "citizenos-valid-states", array() );
		$valid  = false;

		// remove any expired states
		foreach ( $states as $code => $timestamp ) {
			if ( ( $timestamp + $this->state_time_limit ) < time() ) {
				unset( $states[ $code ] );
			}
		}

		// see if the current state is still within the list of valid states
		if ( isset( $states[ $state ] ) ) {
			// state is valid, remove it
			unset( $states[ $state ] );
			$valid = true;
		}

		// save our altered states
		update_option( "citizenos-valid-states", $states );

		return $valid;
	}

	/**
	 * Ensure that the token meets basic requirements
	 *
	 * @param $token_response
	 *
	 * @return bool|\WP_Error
	 */
	function validate_token_response( $token_response ){
		// we need to ensure 2 specific items exist with the token response in order
		// to proceed with confidence:  id_token and token_type == "Bearer"

		if ( ! isset( $token_response["id_token"] ) ) {
			return new WP_Error( "invalid-token-response", "Invalid token response", $token_response );
		}

		return true;
	}

	/**
	 * Extract the id_token_claim from the token_response
	 *
	 * @param $token_response
	 *
	 * @return array|\WP_Error
	 */
	function get_id_token_claim( $token_response ){
		// name sure we have an id_token
		if ( ! isset( $token_response["id_token"] ) ) {
			return new WP_Error( "no-identity-token", __( "No identity token" ), $token_response );
		}

		// break apart the id_token in the response for decoding
		$tmp = explode( ".", $token_response["id_token"] );

		if ( ! isset( $tmp[1] ) ) {
			return new WP_Error( "missing-identity-token", __( "Missing identity token" ), $token_response );
		}

		// Extract the id_token"s claims from the token
		$id_token_claim = json_decode(
			base64_decode(
				str_replace( // because token is encoded in base64 URL (and not just base64)
					array("-", "_"),
					array("+", "/"),
					$tmp[1]
				)
			)
			, true
		);

		return $id_token_claim;
	}

	/**
	 * Ensure the id_token_claim contains the required values
	 *
	 * @param $id_token_claim
	 *
	 * @return bool|\WP_Error
	 */
	function validate_id_token_claim( $id_token_claim ){
		if ( ! is_array( $id_token_claim ) ) {
			return new WP_Error( "bad-id-token-claim", __( "Bad ID token claim" ), $id_token_claim );
		}

		// make sure we can find our identification data and that it has a value
		if ( ! isset( $id_token_claim["sub"] ) || empty( $id_token_claim["sub"] ) ) {
			return new WP_Error( "no-subject-identity", __( "No subject identity" ), $id_token_claim );
		}

		return true;
	}

	/**
	 * Attempt to exchange the access_token for a user_claim
	 *
	 * @param $token_response
	 *
	 * @return array|mixed|object|\WP_Error
	 */
	function get_user_claim( $token_response ){
		// send a userinfo request to get user claim
		$user_claim_result = $this->request_userinfo( $token_response["access_token"] );

		// make sure we didn"t get an error, and that the response body exists
		if ( is_wp_error( $user_claim_result ) ) {
			return new WP_Error( "bad-claim", __( "Bad user claim" ), $user_claim_result );
		}

		return $user_claim_result;
	}

	/**
	 * Make sure the user_claim has all required values, and that the subject
	 * identity matches of the id_token matches that of the user_claim.
	 *
	 * @param $user_claim
	 * @param $id_token_claim
	 *
	 * @return \WP_Error
	 */
	function validate_user_claim( $user_claim, $id_token_claim ) {
		// must be an array
		if ( ! is_array( $user_claim ) ){
			return new WP_Error( "invalid-user-claim", __( "Invalid user claim" ), $user_claim );
		}

		// allow for errors from the IDP
		if ( isset( $user_claim["error"] ) ) {
			$message = __( "Error from the IDP" );
			if ( !empty( $user_claim["error_description"] ) ) {
				$message = $user_claim["error_description"];
			}
			return new WP_Error( "invalid-user-claim-" . $user_claim["error"], $message, $user_claim );
		}

		// allow for other plugins to alter the login success
		$login_user = apply_filters( "citizenos-user-login-test", true, $user_claim );

		if ( ! $login_user ) {
			return new WP_Error( "unauthorized", __( "Unauthorized access" ), $login_user );
		}

		return true;
	}

	/**
	 * Retrieve the subject identity from the id_token
	 *
	 * @param $id_token_claim array
	 *
	 * @return mixed
	 */
	function get_subject_identity( $id_token_claim ){
		return $id_token_claim["sub"];
	}

	/**
	 * Returns authenticated users groups from Citizen OS API
	*/
	function get_user_groups () {
		return $this->api_client->get_user_groups();
	}

	/**
	 * Creates a new user group through WP ajax form
	*/
	function create_user_group () {
		$response = array(
			"error" => false,
		);

		// Example for creating an response with error information, to know in our js file
		// about the error and behave accordingly, like adding error message to the form with JS
		if (trim($_POST["group_name"]) == "") {
			$response["error"] = true;
			$response["error_message"] = "Group name is required";

			// Exit here, for not processing further because of the error
			exit(json_encode($response));
		}

		if (trim($_POST["group_location"]) == "") {
			$response["error"] = true;
			$response["error_message"] = "Group location is required";

			// Exit here, for not processing further because of the error
			exit(json_encode($response));
		}

		$response = $this->api_client->create_user_group(sanitize_text_field($_POST["group_name"]));
		// Don"t forget to exit at the end of processing
		exit(json_encode($response));
	}

	/**
	 * Returns authenticated users topics from Citizen OS API
	*/
	function get_user_topics () {
		return $this->api_client->get_user_topics();
	}

	/**
	 * Returns public topics from Citizen OS API
	*/
	function get_public_topics () {
		return $this->api_client->get_public_topics();
	}

	/**
	 * Returns topic from Citizen OS API by id
	*/
	function get_topic ($topic_id) {
		return $this->api_client->get_topic($topic_id);
	}

	/**
	 * Returns public topic from Citizen OS API by id
	*/
	function get_public_topic ($topic_id) {
		return $this->api_client->get_public_topic($topic_id);
	}

	function insert_map_data ($params) {
		global $wpdb;
		$lat = $params["lat"];
		$lng = $params["lng"];
		$map_id = $params["map_id"];
		$title = ($params["title"])? $params["title"]: "";
		$content = ($params["content"])? $params["content"]: "";
		$location = $params["location"];
		$other_data = $params["other_data"] || "";
		if (!$map_id && !(!($lat && $lng) || $location) ){
			return;
		}

		$ins_array = array(
			"map_id" => $map_id,
			"title" => $title,
			"address" => sanitize_text_field( $location ),
			"description" => $content,
			"lat" => $lat,
			"lng" => $lng,
			"infoopen" => "",
			"anim" => "",
			"link" => "",
			"pic" => "",
			"category" => "",
			"other_data" => $other_data,
			"approved" => true
		);

		$rows_affected = $wpdb->insert( $wpdb->prefix."wpgmza", $ins_array );
		return $ins_array;
		return $rows_affected;
	}

	/**
	 * Creates a new user topic through WP ajax form
	*/
	function create_user_topic () {

		$response = array(
			"error" => false,
		);

		// Example for creating an response with error information, to know in our js file
		// about the error and behave accordingly, like adding error message to the form with JS
		if (trim($_POST["title"]) == "" && trim($_POST["content"]) == "") {
			$response["error"] = true;
			$response["error_message"] = "Topic title or content is required";

			// Exit here, for not processing further because of the error
			exit(json_encode($response));
		}

		$title = sanitize_text_field($_POST["title"]);
		$content = sanitize_text_field($_POST["content"]);
		$visibility = sanitize_text_field($_POST["visibility"]);
		if ($visibility !== "private") {
			$visibility = "public";
		}
		$hashtag = sanitize_text_field($_POST["hashtag"]);
		$endsAt = sanitize_text_field($_POST["endsAt"]);

		$response = $this->api_client->create_user_topic($title, $content, $visibility, $hashtag, $endsAt);
		// Don"t forget to exit at the end of processing
		if (is_wp_error( $response )) {
			return $response;
		}
		$lat = $_POST["location_lat"];
		$lng = $_POST["location_lng"];
		$map_id = $_POST["map_id"];
		$mapdata = $this->insert_map_data(array(
			"map_id" => $_POST["map_id"],
			"lat" => $_POST["location_lat"],
			"lng" => $_POST["location_lng"],
			"title" => $title,
			"content" => $content,
			"location" => $_POST["location"],
			"other_data" => json_encode($response)
		));

		exit(json_encode($response));
	}

	function get_user_location(){
		global $wpdb;
		$user_data = json_encode(array("user_id" => get_current_user_id()));
		$sql = "SELECT * FROM {$wpdb->prefix}wpgmza WHERE other_data = $user_data;";

		$results = $wpdb->get_row( 'SELECT id FROM {$wpdb->prefix}wpgmza WHERE other_data = "$user_data"', OBJECT );

		return $results;
	}

	function create_user_location () {
		/// handle duplicate issues
		$user_inserted = $this->get_user_location();
		if ($user_inserted->id) {
			return;
		}

		$map = $this->insert_map_data(array(
			"map_id" => $_POST["map_id"],
			"lat" => $_POST["lat"],
			"lng" => $_POST["lng"],
			"other_data" => json_encode(array("user_id" => get_current_user_id()))
		));

		exit(json_encode($map));
	}
}
