<?php
/*
Plugin Name: Citizen OS
Plugin URI: https://github.com/citizenos/citizenos_wordpress
Description:  Connect to Citizen OS API
Version: 0.0.1
Author: Ilmar Tyrk
Author URI: https://github.com/ilmartyrk
*/

/*
Notes
  Spec Doc - http://openid.net/specs/openid-connect-basic-1_0-32.html

  Filters
  - openid-connect-generic-alter-request      - 3 args: request array, plugin settings, specific request op
  - openid-connect-generic-settings-fields    - modify the fields provided on the settings page
  - openid-connect-generic-login-button-text  - modify the login button text
  - openid-connect-generic-user-login-test    - (bool) should the user be logged in based on their claim
  - openid-connect-generic-user-creation-test - (bool) should the user be created based on their claim
  - openid-connect-generic-auth-url           - modify the authentication url
  - openid-connect-generic-alter-user-claim   - modify the user_claim before a new user is created
  - openid-connect-generic-alter-user-data    - modify user data before a new user is created

  Actions
  - openid-connect-generic-user-create        - 2 args: fires when a new user is created by this plugin
  - openid-connect-generic-user-update        - 1 arg: user ID, fires when user is updated by this plugin
  - openid-connect-generic-update-user-using-current-claim - 2 args: fires every time an existing user logs
  - openid-connect-generic-redirect-user-back - 2 args: $redirect_url, $user. Allows interruption of redirect during login.

  User Meta
  - openid-connect-generic-subject-identity    - the identity of the user provided by the idp
  - openid-connect-generic-last-id-token-claim - the user's most recent id_token claim, decoded
  - openid-connect-generic-last-user-claim     - the user's most recent user_claim
  - openid-connect-generic-last-token-response - the user's most recent token response

  Options
  - openid_connect_generic_settings     - plugin settings
  - openid-connect-generic-valid-states - locally stored generated states
*/


class Citizenos {
	// plugin version
	const VERSION = '3.4.1';

	// plugin settings
	private $settings;

	// openid connect generic client
	private $client;

	// settings admin page
	private $settings_page;

	// login form adjustments
	private $login_form;

	// login form adjustments
	private $elements;

	/**
	 * Setup the plugin
	 *
	 * @param Citizenos_Option_Settings $settings
	 */
	function __construct( Citizenos_Option_Settings $settings ){
		$this->settings = $settings;
	}

	function clear_cos_tokens() {
		if (!session_id()) {
			session_start();
		}
		unset($_SESSION['tokens']);
		unset($_SESSION['cos_user_id']);
	}

	function create_category() {
		$categories = get_categories();
		$exists = false;
		foreach($categories as $category) {
			if ($category->name === 'cos_topic') {
				$exists = true;
			}
		}
		if(!$exists) {
			$catid = wp_create_category('cos_topic', 0);
		}
	}
	/**
	 * WP Hook 'init'
	 */
	function init(){
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$redirect_uri = plugins_url('/citizenos_wordpress/validator.php');
		add_action('admin_init', array($this, 'create_category'));
		if ( $this->settings->alternate_redirect_uri ){
			$redirect_uri = site_url( '/citizenos-connect-authorize' );
		}

		$state_time_limit = 180;
		if ($this->settings->state_time_limit) {
			$state_time_limit = intval($this->settings->state_time_limit);
		}

		$this->client = new Citizenos_Client(
			$this->settings->client_id,
			$this->settings->client_secret,
			$this->settings->scope,
			$this->settings->base_url,
			$redirect_uri,
			$state_time_limit,
			!$this->settings->ssl_verify
		);

		$this->client_wrapper = Citizenos_Client_Wrapper::register( $this->client, $this->settings);
		$this->login_form = Citizenos_Login_Form::register( $this->settings, $this->client_wrapper );
		$this->elements = Citizenos_Elements::register($this->client, $this->settings);

		// add a shortcode to get the auth url
		add_shortcode( 'citizenos_auth_url', array( $this->client_wrapper, 'get_authentication_url' ) );

		add_action( 'wp_ajax_citizenos-create-group', array($this->client, 'create_user_group') );
		add_action( 'wp_ajax_citizenos-create-topic', array($this->client, 'create_user_topic') );

		add_action( 'wp_logout', array($this, 'clear_cos_tokens'));

		$this->upgrade();

		if ( is_admin() ){
			$this->settings_page = Citizenos_Settings_Page::register( $this->settings);
		}

		add_action('wp_enqueue_scripts', array($this, 'user_locate_javascript'));
		add_action('wp_enqueue_scripts', array($this, 'add_styles'));
		add_action('wp_head', array($this, 'cos_widget_script'));

		wp_localize_script('my_js_library', 'COS_DATA', array( 'admin_url' => admin_url( 'admin-ajax.php' ), 'users_map_id' => $this->settings->users_map_id, 'screening_map_id' => $this->settings->screening_map_id));


	}

	function cos_widget_script() {
		?><script src="<?=$this->settings->widget_url?>"></script><?php
	}

	function user_locate_javascript() {
		wp_enqueue_script( 'user-locate-js', plugins_url( '/js/user-locate.js', __FILE__ ));
	}

	function add_styles() {
		wp_enqueue_style( 'cos-styles', plugins_url( '/css/style.css', __FILE__ ));
	}

	/**
	 * Check if privacy enforcement is enabled, and redirect users that aren't
	 * logged in.
	 */
	function enforce_privacy_redirect() {
		if ( $this->settings->enforce_privacy && ! is_user_logged_in() ) {
			// our client endpoint relies on the wp admind ajax endpoint
			if ( ! defined( 'DOING_AJAX') || ! DOING_AJAX || ! isset( $_GET['action'] ) || $_GET['action'] != 'openid-connect-authorize' ) {
				auth_redirect();
			}
		}
	}

	/**
	 * Enforce privacy settings for rss feeds
	 *
	 * @param $content
	 *
	 * @return mixed
	 */
	function enforce_privacy_feeds( $content ){
		if ( $this->settings->enforce_privacy && ! is_user_logged_in() ) {
			$content = 'Private site';
		}
		return $content;
	}

	/**
	 * Handle plugin upgrades
	 */
	function upgrade(){
		$last_version = get_option( 'citizenos-plugin-version', 0 );
		$settings = $this->settings;

		if ( version_compare( self::VERSION, $last_version, '>' ) ) {
			// upgrade required

			// @todo move this to another file for upgrade scripts
			if ( isset( $settings->ep_login ) ) {
				$settings->endpoint_login = $settings->ep_login;

				unset( $settings->ep_login, $settings->ep_token, $settings->ep_userinfo );
				$settings->save();
			}

			// update the stored version number
			update_option( 'citizenos-plugin-version', self::VERSION );
		}
	}

	/**
	 * Simple autoloader
	 *
	 * @param $class
	 */
	static public function autoload( $class ) {
		$prefix = 'Citizenos_';

		if ( stripos($class, $prefix) !== 0 ) {
			return;
		}

		$filename = $class . '.php';

		// internal files are all lowercase and use dashes in filenames
		if ( false === strpos( $filename, '\\' ) ) {
			$filename = strtolower( str_replace( '_', '-', $filename ) );
		}
		else {
			$filename  = str_replace('\\', DIRECTORY_SEPARATOR, $filename);
		}

		$filepath = dirname( __FILE__ ) . '/includes/' . $filename;
		if ( file_exists( $filepath ) ) {
			require_once $filepath;
		}
	}

	/**
	 * Instantiate the plugin and hook into WP
	 */
	static public function bootstrap(){
		spl_autoload_register( array( 'Citizenos', 'autoload' ) );

		$settings = new Citizenos_Option_Settings(
			'citizenos_settings',
			// default settings values
			array(
				// oauth client settings
				'login_type'        => 'button',
				'client_id'         => '',
				'client_secret'     => '',
				'base_url'          => '',
				'scope'             => 'openid',
				'response_type'     => 'token_id token',
				'endpoint_login'    => '',
				'endpoint_end_session' => '',

				// non-standard settings
				'no_sslverify'    => 0,
				'http_request_timeout' => 5,
				'identity_key'    => 'preferred_username',
				'nickname_key'    => 'preferred_username',
				'email_format'       => '{email}',
				'displayname_format' => '',
				'identify_with_username' => false,

				// plugin settings
				'enforce_privacy' => 0,
				'alternate_redirect_uri' => 0,
				'link_existing_users' => 0,
				'redirect_user_back' => 0,
				'redirect_on_logout' => 1,
				'enable_logging'  => 0,
				'log_limit'       => 1000,
			)
		);


		$plugin = new self( $settings );

		add_action( 'init', array( $plugin, 'init' ) );

		// privacy hooks
		add_action( 'template_redirect', array( $plugin, 'enforce_privacy_redirect' ), 0 );
		add_filter( 'the_content_feed', array( $plugin, 'enforce_privacy_feeds' ), 999 );
		add_filter( 'the_excerpt_rss',  array( $plugin, 'enforce_privacy_feeds' ), 999 );
		add_filter( 'comment_text_rss', array( $plugin, 'enforce_privacy_feeds' ), 999 );
	}
}

Citizenos::bootstrap();
