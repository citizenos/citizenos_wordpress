<?php

class Citizenos_Client_Wrapper {

	private $client;

	// settings object
	private $settings;

	// token refresh info cookie key
	private $cookie_token_refresh_key = 'citizenos-refresh';

	// user redirect cookie key
	public $cookie_redirect_key = 'citizenos-redirect';

	// WP_Error if there was a problem, or false if no error
	private $error = false;

	public $tokens;


	/**
	 * Inject necessary objects and services into the client
	 *
	 * @param \Citizenos_Client $client
	 * @param \Citizenos_Option_Settings $settings
	 */
	function __construct( Citizenos_Client $client, Citizenos_Option_Settings $settings){
		$this->client = $client;
		$this->settings = $settings;
	}

	/**
	 * Hook the client into WP
	 *
	 * @param \Citizenos_Client $client
	 * @param \Citizenos_Option_Settings $settings
	 *
	 * @return \Citizenos_Client_Wrapper
	 */
	static public function register( Citizenos_Client $client, Citizenos_Option_Settings $settings){
		$client_wrapper  = new self( $client, $settings );

		// integrated logout
		if ( $settings->endpoint_end_session ) {
			add_filter( 'allowed_redirect_hosts', array( $client_wrapper, 'update_allowed_redirect_hosts' ), 99, 1 );
			add_filter( 'logout_redirect', array( $client_wrapper, 'get_end_session_logout_redirect_url' ), 99, 3 );
		}

		// alter the requests according to settings
		add_filter( 'citizenos-alter-request', array( $client_wrapper, 'alter_request' ), 10, 3 );

		if ( is_admin() ) {
			// use the ajax url to handle processing authorization without any html output
			// this callback will occur when then IDP returns with an authenticated value
			add_action( 'wp_ajax_citizenos-connect-authorize', array( $client_wrapper, 'authentication_request_callback' ) );
			add_action( 'wp_ajax_nopriv_citizenos-connect-authorize', array( $client_wrapper, 'authentication_request_callback' ) );
		}

		if ( $settings->alternate_redirect_uri ){
			// provide an alternate route for authentication_request_callback
			add_rewrite_rule( '^citizenos-connect-authorize/?', 'index.php?citizenos-connect-authorize=1', 'top' );
			add_rewrite_tag( '%citizenos-connect-authorize%', '1' );
			add_action( 'parse_request', array( $client_wrapper, 'alternate_redirect_uri_parse_request' ) );
		}

		return $client_wrapper;
	}

	/**
	 * Implements WP action - parse_request
	 *
	 * @param $query
	 *
	 * @return mixed
	 */
	function alternate_redirect_uri_parse_request( $query ){
		if ( isset( $query->query_vars['citizenos-connect-authorize'] ) &&
		     $query->query_vars['citizenos-connect-authorize'] === '1' )
		{
			$this->authentication_request_callback();
			exit;
		}

		return $query;
	}

	/**
	 * Get the authentication url from the client
	 *
	 * @return string
	 */
	function get_authentication_url($params = array(), $content = ""){
		return $this->client->make_authentication_url($params, $content);
	}

	/**
	 * Handle errors by redirecting the user to the login form
	 *  along with an error code
	 *
	 * @param $error WP_Error
	 */
	function error_redirect( $error ) {

		// redirect user back to login page
		wp_redirect(
			wp_login_url() .
			'?login-error=' . $error->get_error_code() .
		    '&message=' . urlencode( $error->get_error_message() )
		);
		exit;
	}

	/**
	 * Get the current error state
	 *
	 * @return bool | WP_Error
	 */
	function get_error(){
		return $this->error;
	}

	/**
	 * Add the end_session endpoint to WP core's whitelist of redirect hosts
	 *
	 * @param array $allowed
	 *
	 * @return array
	 */
	function update_allowed_redirect_hosts( array $allowed ) {
		$host = parse_url( $this->settings->endpoint_end_session, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}

		$allowed[] = $host;
		return $allowed;
	}

	/**
	 * Handle the logout redirect for end_session endpoint
	 *
	 * @param $redirect_url
	 *
	 * @return string
	 */
	function get_end_session_logout_redirect_url( $redirect_url, $requested_redirect_to, $user ) {
		$url = $this->settings->endpoint_end_session;
		$query = parse_url( $url, PHP_URL_QUERY );
		$url .= $query ? '&' : '?';

		// prevent redirect back to the IdP when logging out in auto mode
		if ( $this->settings->login_type === 'auto' && $redirect_url === 'wp-login.php?loggedout=true' ) {
			$redirect_url = '';
		}

		$token_response = $user->get('citizenos-last-token-response');
		if (! $token_response ) {
			// happens if non-openid login was used
			return $redirect_url;
		}
		else if ( ! parse_url( $redirect_url, PHP_URL_HOST ) ) {
			// convert to absolute url if needed. site_url() to be friendly with non-standard (Bedrock) layout
			$redirect_url = site_url( $redirect_url );
		}

		$claim = $user->get( 'citizenos-last-id-token-claim' );

		if ( isset( $claim['iss'] ) && $claim['iss'] == 'https://accounts.google.com' ) {
			/* Google revoke endpoint
			   1. expects the *access_token* to be passed as "token"
			   2. does not support redirection (post_logout_redirect_uri)
			   So just redirect to regular WP logout URL.
			   (we would *not* disconnect the user from any Google service even if he was
			   initially disconnected to them) */
			return $redirect_url;
		}
		else {
			return $url . sprintf( 'id_token_hint=%s&post_logout_redirect_uri=%s', $token_response['id_token'], urlencode( $redirect_url ) );
		}
	}

	/**
	 * Modify outgoing requests according to settings
	 *
	 * @param $request
	 * @param $op
	 *
	 * @return mixed
	 */
	function alter_request( $request, $op ) {
		if ( !empty( $this->settings->http_request_timeout ) && is_numeric( $this->settings->http_request_timeout ) ) {
			$request['timeout'] = intval( $this->settings->http_request_timeout );
		}

		if ( $this->settings->no_sslverify ) {
			$request['sslverify'] = false;
		}

		return $request;
	}

	/**
	 * Control the authentication and subsequent authorization of the user when
	 *  returning from the IDP.
	 */
	function authentication_request_callback() {
		$client = $this->client;

		// start the authentication flow

		$token_response = $client->validate_authentication_request( $_GET );
		if ( is_wp_error( $token_response ) ) {
			$this->error_redirect( $token_response );
		}

		// ensure the that response contains required information
		$valid = $client->validate_token_response( $token_response );

		if ( is_wp_error( $valid ) ) {
			$this->error_redirect( $valid );
		}

		/**
		 * End authentication
		 * -
		 * Start Authorization
		 */
		// The id_token is used to identify the authenticated user, e.g. for SSO.
		// The access_token must be used to prove access rights to protected resources
		// e.g. for the userinfo endpoint

		$id_token_claim = $client->get_id_token_claim( $token_response );

		if ( is_wp_error( $id_token_claim ) ){
			$this->error_redirect( $id_token_claim );
		}

		// validate our id_token has required values
		$valid = $client->validate_id_token_claim( $id_token_claim );

		if ( is_wp_error( $valid ) ){
			$this->error_redirect( $valid );
		}

		// if userinfo endpoint is set, exchange the token_response for a user_claim
		if ( !empty( $this->settings->endpoint_userinfo ) && isset( $token_response['access_token'] )) {
			$user_claim = $client->get_user_claim( $token_response );
		} else {
			$user_claim = $id_token_claim;
		}

		if ( is_wp_error( $user_claim ) ){
			$this->error_redirect( $user_claim );
		}
		// validate our user_claim has required values
		$valid = $client->validate_user_claim( $user_claim, $id_token_claim );

		if ( is_wp_error( $valid ) ){
			$this->error_redirect( $valid );
		}

		/**
		 * End authorization
		 * -
		 * Request is authenticated and authorized - start user handling
		 */
		$subject_identity = $client->get_subject_identity( $id_token_claim );
		$user = $this->get_user_by_identity( $subject_identity );
		$cos_user = $this->client->request_userinfo( $token_response['access_token'] );

		// if we didn't find an existing user, we'll need to create it
		if ( ! $user ) {
			$user = $this->create_new_user( $subject_identity, $user_claim );
			if ( is_wp_error( $user ) ) {
				$this->error_redirect( $user );
				return;
			}
		} else {
			// allow plugins / themes to take action using current claims on existing user (e.g. update role)
			do_action( 'citizenos-update-user-using-current-claim', $user, $user_claim );
		}

		// validate the found / created user
		$valid = $this->validate_user( $user );

		if ( is_wp_error( $valid ) ){
			$this->error_redirect( $valid );
		}
		// login the found / created user
		$client->set_api_access_token($token_response['access_token']);
		$client->set_tokens($token_response);

		if (!session_id()) {
			session_start();
		}

		$_SESSION['tokens'] = $token_response;
		$_SESSION['cos_user_id'] = $cos_user['id'];
		$this->login_user( $user, $token_response, $id_token_claim, $user_claim, $subject_identity  );
		// redirect back to the origin page if enabled
		$redirect_url = isset( $_COOKIE[ $this->cookie_redirect_key ] ) ? esc_url( $_COOKIE[ $this->cookie_redirect_key ] ) : false;

		if( $this->settings->redirect_user_back && !empty( $redirect_url ) ) {
			do_action( 'citizenos-redirect-user-back', $redirect_url, $user );
			setcookie( $this->cookie_redirect_key, $redirect_url, 1, COOKIEPATH, COOKIE_DOMAIN, is_ssl() );
			wp_redirect( $redirect_url );
		}
		// otherwise, go home!
		else {
			wp_redirect( home_url() );
		}

		exit;
	}

	/**
	 * Validate the potential WP_User
	 *
	 * @param $user
	 *
	 * @return true|\WP_Error
	 */
	function validate_user( $user ){
		// ensure our found user is a real WP_User
		if ( ! is_a( $user, 'WP_User' ) || ! $user->exists() ) {
			return new WP_Error( 'invalid-user', __( 'Invalid user' ), $user );
		}

		return true;
	}

	/**
	 * Record user meta data, and provide an authorization cookie
	 *
	 * @param $user
	 */
	function login_user( $user, $token_response, $id_token_claim, $user_claim, $subject_identity ){
		// hey, we made it!
		// let's remember the tokens for future reference
		update_user_meta( $user->ID, 'citizenos-last-token-response', $token_response );
		update_user_meta( $user->ID, 'citizenos-last-id-token-claim', $id_token_claim );
		update_user_meta( $user->ID, 'citizenos-last-user-claim', $user_claim );

		// Create the WP session, so we know its token
		$expiration = time() + apply_filters( 'auth_cookie_expiration', 2 * DAY_IN_SECONDS, $user->ID, false );
		$manager = WP_Session_Tokens::get_instance( $user->ID );
		$token = $manager->create( $expiration );

		// you did great, have a cookie!
		wp_set_auth_cookie( $user->ID, false, '', $token);
		do_action( 'wp_login', $user->user_login, $user );
	}

	/**
	 * Get the user that has meta data matching a
	 *
	 * @param $subject_identity
	 *
	 * @return false|\WP_User
	 */
	function get_user_by_identity( $subject_identity ){
		// look for user by their citizenos-subject-identity value
		$user_query = new WP_User_Query( array(
			'meta_query' => array(
				array(
					'key'   => 'citizenos-subject-identity',
					'value' => $subject_identity,
				)
			)
		) );

		// if we found an existing users, grab the first one returned
		if ( $user_query->get_total() > 0 ) {
			$users = $user_query->get_results();
			return $users[0];
		}

		return false;
	}

	/**
	 * Avoid user_login collisions by incrementing
	 *
	 * @param $user_claim array
	 *
	 * @return string
	 */
	private function get_username_from_claim( $user_claim ) {
		// allow settings to take first stab at username
		if ( !empty( $this->settings->identity_key ) && isset( $user_claim[ $this->settings->identity_key ] ) ) {
			$desired_username =  $user_claim[ $this->settings->identity_key ];
		}
		else if ( isset( $user_claim['preferred_username'] ) && ! empty( $user_claim['preferred_username'] ) ) {
			$desired_username = $user_claim['preferred_username'];
		}
		else if ( isset( $user_claim['name'] ) && ! empty( $user_claim['name'] ) ) {
			$desired_username = $user_claim['name'];
		}
		else if ( isset( $user_claim['email'] ) && ! empty( $user_claim['email'] ) ) {
			$tmp = explode( '@', $user_claim['email'] );
			$desired_username = $tmp[0];
		}
		else {
			// nothing to build a name from
			return new WP_Error( 'no-username', __( 'No appropriate username found' ), $user_claim );
		}

		// normalize the data a bit
		$desired_username = strtolower( preg_replace( '/[^a-zA-Z\_0-9]/', '', iconv( 'UTF-8', 'ASCII//TRANSLIT',  $desired_username ) ) );

		// copy the username for incrementing
		$username = $desired_username;

		// original user gets "name"
		// second user gets "name2"
		// etc
		$count = 1;
		while ( username_exists( $username ) ) {
			$count ++;
			$username = $desired_username . $count;
		}

		return $username;
	}

	/**
	 * Get a nickname
	 *
	 * @param $user_claim array
	 *
	 * @return string
	 */
	private function get_nickname_from_claim( $user_claim ) {
		$desired_nickname = null;
		// allow settings to take first stab at nickname
		if ( !empty( $this->settings->nickname_key ) && isset( $user_claim[ $this->settings->nickname_key ] ) ) {
			$desired_nickname =  $user_claim[ $this->settings->nickname_key ];
		}
		return $desired_nickname;
	}

	/**
	 * Build a string from the user claim according to the specified format.
	 *
	 * @param $format string
	 * @param $user_claim array
	 *
	 * @return string
	 */
	private function format_string_with_claim( $format, $user_claim, $error_on_missing_key = false ) {
		$matches = null;
		$string = '';
		$i = 0;
		if ( preg_match_all( '/\{[^}]*\}/u', $format, $matches, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $matches[ 0 ] as $match ) {
				$key = substr($match[ 0 ], 1, -1);
				$string .= substr( $format, $i, $match[ 1 ] - $i );
				if ( ! isset( $user_claim[ $key ] ) ) {
					if ( $error_on_missing_key ) {
						return new WP_Error( 'incomplete-user-claim', __( 'User claim incomplete' ), $user_claim );
					}
				} else {
					$string .= $user_claim[ $key ];
				}
				$i = $match[ 1 ] + strlen( $match[ 0 ] );
			}
		}
		$string .= substr( $format, $i );
		return $string;
	}

	/**
	 * Get a displayname
	 *
	 * @param $user_claim array
	 *
	 * @return string
	 */
	private function get_displayname_from_claim( $user_claim, $error_on_missing_key = false ) {
		if ( ! empty( $this->settings->displayname_format ) ) {
			return $this->format_string_with_claim( $this->settings->displayname_format, $user_claim, $error_on_missing_key );
		}
		return null;
	}

	/**
	 * Get an email
	 *
	 * @param $user_claim array
	 *
	 * @return string
	 */
	private function get_email_from_claim( $user_claim, $error_on_missing_key = false ) {
		if ( ! empty( $this->settings->email_format ) ) {
			return $this->format_string_with_claim( $this->settings->email_format, $user_claim, $error_on_missing_key );
		}
		return null;
	}

	/**
	 * Create a new user from details in a user_claim
	 *
	 * @param $subject_identity
	 * @param $user_claim
	 *
	 * @return \WP_Error | \WP_User
	 */
	function create_new_user( $subject_identity, $user_claim ) {
		// default username & email to the subject identity
		$username = $subject_identity;
		$email    = $subject_identity;
		$nickname = $subject_identity;
		$displayname = $subject_identity;

		$values_missing = false;

		// allow claim details to determine username, email, nickname and displayname.
		$_email = $this->get_email_from_claim( $user_claim, true );

		if ( is_wp_error( $_email ) ) {
			$values_missing = true;
		} else if ( $_email !== null ) {
			$email = $_email;
		}

		$_username = $this->get_username_from_claim( $user_claim );

		if ( is_wp_error( $_username ) ) {
			$values_missing = true;
		} else if ( $_username !== null ) {
			$username = $_username;
		}

		$_nickname = $this->get_nickname_from_claim( $user_claim );

		if ( is_wp_error( $_nickname ) ) {
			$values_missing = true;
		} else if ( $_nickname !== null) {
			$nickname = $_nickname;
		}

		$_displayname = $this->get_displayname_from_claim( $user_claim, true );

		if ( is_wp_error( $_displayname ) ) {
			$values_missing = true;
		} else if ( $_displayname !== null ) {
			$displayname = $_displayname;
		}
		// attempt another request for userinfo if some values are missing
		if ( $values_missing && isset( $token_response['access_token'] ) && !empty( $this->settings->endpoint_userinfo) ) {
			$user_claim_result = $this->client->request_userinfo( $token_response['access_token'] );

			// make sure we didn't get an error
			if ( is_wp_error( $user_claim_result ) ) {
				return new WP_Error( 'bad-user-claim-result', __( 'Bad user claim result' ), $user_claim_result );
			}
			$user_claim = $user_claim_result;
		}

		$_email = $this->get_email_from_claim( $user_claim, true );
		if ( is_wp_error( $_email ) ) {
			return $_email;
		} else if ( $_email !== null ) {
			$email = $_email;
		}

		$_username = $this->get_username_from_claim( $user_claim );
		if ( is_wp_error( $_username ) ) {
			return $_username;
		} else if ( $_username !== null ) {
			$username = $_username;
		}

		$_nickname = $this->get_nickname_from_claim( $user_claim );
		if ( is_wp_error( $_nickname ) ) {
			return $_nickname;
		} else if ( $_nickname === null) {
			$nickname = $username;
		}

		$_displayname = $this->get_displayname_from_claim( $user_claim, true );
		if ( is_wp_error( $_displayname ) ) {
			return $_displayname;
		} else if ( $_displayname === null ) {
			$displayname = $nickname;
		}

		// before trying to create the user, first check if a user with the same email already exists
		if( $this->settings->link_existing_users ) {
			if ( $this->settings->identify_with_username) {
				$uid = username_exists( $username );
			} else {
				$uid = email_exists( $email );
			}
			if ( $uid ) {
				return $this->update_existing_user( $uid, $subject_identity );
			}
		}

		// allow other plugins / themes to determine authorization
		// of new accounts based on the returned user claim
		$userdata = WP_User::get_data_by( 'email', $email );

		if ( ! $userdata ) {
			$create_user = apply_filters( 'citizenos-user-creation-test', true, $user_claim );

			if ( ! $create_user ) {
				return new WP_Error( 'cannot-authorize', __( 'Can not authorize.' ), $create_user );
			}

			$user_claim = apply_filters( 'citizenos-alter-user-claim', $user_claim );
			$user_data = array(
				'user_login' => $username,
				'user_pass' => wp_generate_password( 32, true, true ),
				'user_email' => $email,
				'display_name' => $displayname,
				'nickname' => $nickname,
				'first_name' => isset( $user_claim[ 'given_name' ] ) ? $user_claim[ 'given_name' ]: '',
				'last_name' => isset( $user_claim[ 'family_name' ] ) ? $user_claim[ 'family_name' ]: '',
			);
			$user_data = apply_filters( 'citizenos-alter-user-data', $user_data, $user_claim );

			// create the new user
			$uid = wp_insert_user( $user_data );
			add_user_meta( $uid, 'show_admin_bar_front', FALSE, TRUE );
		} else {
			$uid = $userdata->ID;
		}
		// make sure we didn't fail in creating the user
		if ( is_wp_error( $uid ) ) {
			return new WP_Error( 'failed-user-creation', __( 'Failed user creation.' ), $uid );
		}

		// retrieve our new user
		$user = get_user_by( 'id', $uid );

		// save some meta data about this new user for the future
		add_user_meta( $user->ID, 'citizenos-subject-identity', (string) $subject_identity, TRUE );

		// allow plugins / themes to take action on new user creation
		do_action( 'citizenos-user-create', $user, $user_claim );

		return $user;
	}


	/**
	 * Update an existing user with OpenID Connect meta data
	 *
	 * @param $uid
	 * @param $subject_identity
	 *
	 * @return \WP_Error | \WP_User
	 */
	function update_existing_user( $uid, $subject_identity ) {
		// add the OpenID Connect meta data
		add_user_meta( $uid, 'citizenos-subject-identity', (string) $subject_identity, TRUE );

		// allow plugins / themes to take action on user update
		do_action( 'citizenos-user-update', $uid );

		// return our updated user
		return get_user_by( 'id', $uid );
	}
}
