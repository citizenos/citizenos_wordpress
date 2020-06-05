<?php

class Citizenos_Api_Client {

    private $access_token;
    private $partner_id;
    private $base_url;
    private $ssl_verify;

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
	function __construct($base_url, $partner_id, $ssl_verify = true){
        $this->base_url = $base_url;
        $this->partner_id = $partner_id;
        $this->ssl_verify = $ssl_verify;
        session_start();
        if ($_SESSION["tokens"]) {
            $this->access_token = $_SESSION["tokens"]["access_token"];
        }
    }

    function set_access_token ($token) {
        $this->access_token = $token;
    }

    function request($path, $data = array(), $method = "GET") {
        if (!$this->base_url) {
            return;
        }
        session_start();
        if ($_SESSION["tokens"]) {
            $this->access_token = $_SESSION["tokens"]["access_token"];
        }
        if (!$this->access_token) {
            return new WP_Error(401, 'No access token');
        }
        
		// allow modifications to the request
		$request = apply_filters( "citizenos-alter-request", array(), "get-userinfo" );
        $headers = array();
		// section 5.3.1 of the spec recommends sending the access token using the authorization header
		// a filter may or may not have already added headers - make sure they exist then add the token
		if ( !array_key_exists( "headers", $request ) || !is_array( $request["headers"] ) ) {
			$request["headers"] = array();
        }
		$request["headers"]["Authorization"] = "Bearer ".$this->access_token;
        $request["headers"]["x-partner-id"] = $this->partner_id;
        if (!$this->ssl_verify) {
            $request["sslverify"]= false;
        }
        
        // Add Host header - required for when the openid-connect endpoint is behind a reverse-proxy
        $request_url = $this->base_url . $path ."?sourcePartnerId=" . $this->partner_id;
        $parsed_url = parse_url($request_url);
		$host = $parsed_url["host"];

		if ( !empty( $parsed_url["port"] ) ) {
			$host.= ":{$parsed_url["port"]}";
		}

        if ($data) {
            $request["headers"]["content-type"] = "application/json";
            $request["body"] = json_encode($data);
        }
        $request["headers"]["Host"] = $host;
        
        // attempt the request including the access token in the query string for backwards compatibility
        if ($method === "GET") {
            $response = wp_remote_get( $request_url, $request );
        }
        if ($method === "POST") {            
            $response = wp_remote_post( $request_url, $request );
        }

        if ($method === "PUT") {
            $request['method'] = "PUT";
            $response = wp_remote_request( $request_url, $request );
        }
        
		if ( is_wp_error( $response ) ){
			$response->add( "request_userinfo" , __( "Request for userinfo failed." ) );
        }
        if (is_wp_error($response)) {
            return $response->errors;
        }
        
        $body = json_decode( $response["body"], true );
        if ($body['data']) {
            return $body["data"];
        }

		return $body;
    }

    function build_path ($path, $public = true, $params = array() ) {
        $user_path = "users/self/";
        if ($public) {
            $user_path = "";
        }

        foreach($params as $key => $value) {
            $path = str_replace($key, $value, $path);
        }
        $url = sprintf( '/api/%1$s%2$s',
            $user_path,
            $path
        );

        return $url;
    }

    function get_user_groups () {
        $path = $this->build_path("groups", false);

        return $this->request($path);
    }

    function create_user_group ($name) {
        $body = array(
            "name" => $name,
            "sourcePartnerId" => $this->partner_id
        );
        
        $path = $this->build_path("groups", false);
        return $this->request($path, $body, "POST");
    }

    function get_user_topics () {
        $path = $this->build_path("topics", false);

        return $this->request($path);
    }

    function get_public_topics () {
        $path = $this->build_path("topics", true);

        $request = apply_filters( "citizenos-alter-request", array(), "get-userinfo" );

		// section 5.3.1 of the spec recommends sending the access token using the authorization header
		// a filter may or may not have already added headers - make sure they exist then add the token
		if ( !array_key_exists( "headers", $request ) || !is_array( $request["headers"] ) ) {
			$request["headers"] = array();
        }
        if (!$this->ssl_verify) {
            $request["sslverify"]= false;
        }
        $request["headers"]["x-partner-id"] = $this->partner_id;
        // Add Host header - required for when the openid-connect endpoint is behind a reverse-proxy
        $request_url = $this->base_url . $path ."?sourcePartnerId=" . $this->partner_id;
		$parsed_url = parse_url($request_url);
		$host = $parsed_url["host"];

		if ( !empty( $parsed_url["port"] ) ) {
			$host.= ":{$parsed_url["port"]}";
		}

        if ($data) {
            $request["body"] = $data;
        }
		$request["headers"]["Host"] = $host;
        // attempt the request including the access token in the query string for backwards compatibility
        $response = wp_remote_get( $request_url, $request );
        if (is_wp_error($response)) {
            return $response->errors;
        }
        $body = json_decode( $response["body"], true );
        
        if ($body['data']) {
            return $body["data"];
        }
		return $body;
    }

    function get_topic ($topic_id, $ispublic = false ) {
        $path = $this->build_path("topics", $ispublic);
        $path .= '/'.$topic_id;

        $request = apply_filters( "citizenos-alter-request", array(), "get-userinfo" );

		// section 5.3.1 of the spec recommends sending the access token using the authorization header
		// a filter may or may not have already added headers - make sure they exist then add the token
		if ( !array_key_exists( "headers", $request ) || !is_array( $request["headers"] ) ) {
			$request["headers"] = array();
        }
        if (!$this->ssl_verify) {
            $request["sslverify"]= false;
        }
        $request["headers"]["x-partner-id"] = $this->partner_id;
        // Add Host header - required for when the openid-connect endpoint is behind a reverse-proxy
        $request_url = $this->base_url . $path ."?sourcePartnerId=" . $this->partner_id;
		$parsed_url = parse_url($request_url);
		$host = $parsed_url["host"];

		if ( !empty( $parsed_url["port"] ) ) {
			$host.= ":{$parsed_url["port"]}";
		}

        if ($data) {
            $request["body"] = $data;
        }
		$request["headers"]["Host"] = $host;
        // attempt the request including the access token in the query string for backwards compatibility
        $response = wp_remote_get( $request_url, $request );
        if (is_wp_error($response)) {
            return $response->errors;
        }
        $body = json_decode( $response["body"], true );
        
        if ($body['data']) {
            return $body["data"];
        }
		return $body;
    }

    function get_public_topic($topic_id) {
        return $this->get_topic($topic_id, true);
    }

    function create_user_topic ($title, $content, $visibility = "public", $hashtag = null, $endsAt = null) {
        $description = "<html><head></head><body><h1>" . $title . "</h1><p>". $content . "</p></body><html>"; 
        $body = array(
            "title" => $title,
            "description" => $description,
            "sourcePartnerId" => $this->partner_id,
            "visibility" => $visibility,
            "hashtag" => $hashtag,
            "categories" => array("thetwelvemovie"),
            "endsAt" => $endsAt
        );

        if ($body["endsAt"] == null) {
            unset($body["endsAt"]);
        }

        $path = $this->build_path("topics", false);

        $topic = $this->request($path, $body, "POST");
        if (is_wp_error( $topic )) {
            return $topic;
        }
        $topic["sourcePartnerObjectId"] = $topic["id"];
        $updatepath = $this->build_path("topics/:topicId", false, array(":topicId" => $topic["id"]));
        $updateResponse = $this->request($updatepath, $topic, "PUT");
        if ($updateResponse['status']['code'] == 20000) {
            return $topic;
        }
        return $updateResponse;
    }
}