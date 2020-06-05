<?php

/**
 * Class Citizenos_Settings_Page.
 * Admin settings page.
 */
class Citizenos_Settings_Page {

	// local copy of the settings provided by the base plugin
	private $settings;

	// The controlled list of settings & associated
	// defined during construction for i18n reasons
	private $settings_fields = array();

	// options page slug
	private $options_page_name = 'citizenos-settings';

	// options page settings group name
	private $settings_field_group;

	/**
	 * @param Citizenos_Option_Settings $settings
	 */
	function __construct( Citizenos_Option_Settings $settings ) {
		$this->settings             = $settings;
		$this->settings_field_group = $this->settings->get_option_name() . '-group';
		$this->settings->endpoint_userinfo = '/api/users/self';
		/*
		 * Simple settings fields simply have:
		 * 
		 * - title
		 * - description
		 * - type ( checkbox | text | select )
		 * - section - settings/option page section ( client_settings | authorization_settings )
		 * - example (optional example will appear beneath description and be wrapped in <code>)
		 */
		$fields = array(
			'login_type'        => array(
				'title'       => __( 'Login Type' ),
				'description' => __( 'Select how the client (login form) should provide login options.' ),
				'type'        => 'select',
				'options'     => array(
					'button' => __( 'OpenID Connect button on login form' ),
					'auto'   => __( 'Auto Login - SSO' ),
				),
				'section'     => 'client_settings',
			),
			'client_id'         => array(
				'title'       => __( 'Client ID' ),
				'description' => __( 'The ID this client will be recognized as when connecting the to Identity provider server.' ),
				'example'     => 'my-wordpress-client-id',
				'type'        => 'text',
				'section'     => 'client_settings',
			),
			'scope'             => array(
				'title'       => __( 'OpenID Scope' ),
				'description' => __( 'Space separated list of scopes this client should access.' ),
				'example'     => 'email profile openid offline_access',
				'type'        => 'text',
				'section'     => 'client_settings',
			),
			'base_url'          => array(
				'title'       => __( 'API Base URL' ),
				'description' => __( 'Base URL for Citizen OS API.' ),
				'example'     => 'https://api.citizenos.com',
				'type'        => 'text',
				'section'     => 'client_settings',
			),
			'widget_url'          => array(
				'title'       => __( 'Widget script location' ),
				'description' => __( 'URL where to load widget script from' ),
				'example'     => 'https://app.citizenos.com/js/widgets.js',
				'type'        => 'text',
				'section'     => 'client_settings',
			),
			'no_sslverify'      => array(
				'title'       => __( 'Disable SSL Verify' ),
				'description' => __( 'Do not require SSL verification during authorization. The OAuth extension uses curl to make the request. By default CURL will generally verify the SSL certificate to see if its valid an issued by an accepted CA. This setting disabled that verification.<br><strong>Not recommended for production sites.</strong>' ),
				'type'        => 'checkbox',
				'section'     => 'client_settings',
			),
			'enforce_privacy'   => array(
				'title'       => __( 'Enforce Privacy' ),
				'description' => __( 'Require users be logged in to see the site.' ),
				'type'        => 'checkbox',
				'section'     => 'authorization_settings',
			),
			'screening_map_id'   => array(
				'title'       => __( 'Screenings map id' ),
				'description' => __( 'Wordpress google maps map id for screening locations' ),
				'type'        => 'text',
				'section'     => 'client_settings',
			),
			'users_map_id'   => array(
				'title'       => __( 'Users map id' ),
				'description' => __( 'Wordpress google maps map id for users locations' ),
				'type'        => 'text',
				'section'     => 'client_settings',
			),
		);

		$fields = apply_filters( 'citizenos-settings-fields', $fields );

		// some simple pre-processing
		foreach ( $fields as $key => &$field ) {
			$field['key']  = $key;
			$field['name'] = $this->settings->get_option_name() . '[' . $key . ']';
		}

		// allow alterations of the fields
		$this->settings_fields = $fields;
	}

	/**
	 * @param \Citizenos_Option_Settings $settings
	 *
	 * @return \Citizenos_Settings_Page
	 */
	static public function register( Citizenos_Option_Settings $settings ){
		$settings_page = new self( $settings );

		// add our options page the the admin menu
		add_action( 'admin_menu', array( $settings_page, 'admin_menu' ) );

		// register our settings
		add_action( 'admin_init', array( $settings_page, 'admin_init' ) );
		
		return $settings_page;
	}

	/**
	 * Implements hook admin_menu to add our options/settings page to the
	 *  dashboard menu
	 */
	public function admin_menu() {
		add_options_page(
			__( 'Citizen OS plugin' ),
			__( 'Citizen OS client' ),
			'manage_options',
			$this->options_page_name,
			array( $this, 'settings_page' ) );
	}

	/**
	 * Implements hook admin_init to register our settings
	 */
	public function admin_init() {
		register_setting( $this->settings_field_group, $this->settings->get_option_name(), array(
			$this,
			'sanitize_settings'
		) );

		add_settings_section( 'client_settings',
			__( 'Client Settings' ),
			array( $this, 'client_settings_description' ),
			$this->options_page_name
		);
		
		add_settings_section( 'authorization_settings',
			__( 'Authorization Settings' ),
			array( $this, 'authorization_settings_description' ),
			$this->options_page_name
		);

		// preprocess fields and add them to the page
		foreach ( $this->settings_fields as $key => $field ) {
			// make sure each key exists in the settings array
			if ( ! isset( $this->settings->{ $key } ) ) {
				$this->settings->{ $key } = NULL;
			}

			// determine appropriate output callback
			switch ( $field['type'] ) {
				case 'checkbox':
					$callback = 'do_checkbox';
					break;

				case 'select':
					$callback = 'do_select';
					break;

				case 'text':
				default:
					$callback = 'do_text_field';
					break;
			}

			// add the field
			add_settings_field( $key, $field['title'],
				array( $this, $callback ),
				$this->options_page_name,
				$field['section'],
				$field
			);
		}
	}

	/**
	 * Sanitization callback for settings/option page
	 *
	 * @param $input - submitted settings values
	 *
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$options = array();

		// loop through settings fields to control what we're saving
		foreach ( $this->settings_fields as $key => $field ) {
			if ( isset( $input[ $key ] ) ) {
				$options[ $key ] = sanitize_text_field( trim( $input[ $key ] ) );
			} 
			else {
				$options[ $key ] = '';
			}
		}

		return $options;
	}

	/**
	 * Output the options/settings page
	 */
	public function settings_page() {
		$redirect_uri = admin_url( 'admin-ajax.php?action=citizenos-connect-authorize' );

		if ( $this->settings->alternate_redirect_uri ){
			$redirect_uri = site_url( '/citizenos-connect-authorize' );
		}
		?>
		<div class="wrap">
			<h2><?php print esc_html( get_admin_page_title() ); ?></h2>

			<form method="post" action="options.php">
				<?php
				settings_fields( $this->settings_field_group );
				do_settings_sections( $this->options_page_name );
				submit_button();
				
				// simple debug to view settings array
				if ( isset( $_GET['debug'] ) ) {
					var_dump( $this->settings->get_values() );
				}
				?>
			</form>

			<h4><?php _e( 'Notes' ); ?></h4>

			<p class="description">
				<strong><?php _e( 'Redirect URI' ); ?></strong>
				<code><?php print $redirect_uri; ?></code>
			</p>
			<p class="description">
				<strong><?php _e( 'Login Button Shortcode' ); ?></strong>
				<code>[citizenos_login_button]</code>
			</p>
		</div>
		<?php
	}

	/**
	 * Output a standard text field
	 *
	 * @param $field
	 */
	public function do_text_field( $field ) {
		?>
		<input type="<?php print esc_attr( $field['type'] ); ?>"
		       id="<?php print esc_attr( $field['key'] ); ?>"
		       class="large-text"
		       name="<?php print esc_attr( $field['name'] ); ?>"
		       value="<?php print esc_attr( $this->settings->{ $field['key'] } ); ?>">
		<?php
		$this->do_field_description( $field );
	}

	/**
	 * Output a checkbox for a boolean setting
	 *  - hidden field is default value so we don't have to check isset() on save
	 *
	 * @param $field
	 */
	public function do_checkbox( $field ) {
		?>
		<input type="hidden" name="<?php print esc_attr( $field['name'] ); ?>" value="0">
		<input type="checkbox"
		       id="<?php print esc_attr( $field['key'] ); ?>"
		       name="<?php print esc_attr( $field['name'] ); ?>"
		       value="1"
			<?php checked( $this->settings->{ $field['key'] }, 1 ); ?>>
		<?php
		$this->do_field_description( $field );
	}

	/**
	 * @param $field
	 */
	function do_select( $field ) {
		$current_value = isset( $this->settings->{ $field['key'] } ) ? $this->settings->{ $field['key'] } : '';
		?>
		<select name="<?php print esc_attr( $field['name'] ); ?>">
			<?php foreach ( $field['options'] as $value => $text ): ?>
				<option value="<?php print esc_attr( $value ); ?>" <?php selected( $value, $current_value ); ?>><?php print esc_html( $text ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
		$this->do_field_description( $field );
	}

	/**
	 * Simply output the field description, and example if present
	 *
	 * @param $field
	 */
	public function do_field_description( $field ) {
		?>
		<p class="description">
			<?php print $field['description']; ?>
			<?php if ( isset( $field['example'] ) ) : ?>
				<br/><strong><?php _e( 'Example' ); ?>: </strong>
				<code><?php print $field['example']; ?></code>
			<?php endif; ?>
		</p>
		<?php
	}

	public function client_settings_description() {
		_e( 'Enter your OpenID Connect identity provider settings' );
	}
	
	public function authorization_settings_description() {
		_e( 'Control the authorization mechanics of the site' );
	}
}
