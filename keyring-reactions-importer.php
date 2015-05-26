<?php
/*
Plugin Name: Keyring Reactions Importer
Plugin URI: https://github.com/petermolnar/keyring-reactions-importer
Description: A recations (comments, favs, etc. ) importer based on Keyring
Version: 1.1
Author: Peter Molnar <hello@petermolnar.eu>
Author URI: http://petermolnar.eu/
License: GPLv3
*/

/*
    This plugin could never had been done without [Keyring Social Importers](https://wordpress.org/plugins/keyring-social-importers/) and [Keyring](https://wordpress.org/plugins/keyring/) from [Beau Lebens](http://dentedreality.com.au/).
    Thank you!
*/

/*  Copyright 2010-2014 Peter Molnar ( hello@petermolnar.eu )

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 3, as
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

/*
 * TODO // IDEAS
 *
 * instead of one gigantic importer, we could fire up a schedule per host
 * to import all the reactions from all the networks for that particular post
 * the question is, how good is the schedule in wp-cron, could it handle
 * thousands of schedules?
 *
 */

// Load Importer API
if ( !function_exists( 'register_importer ' ) )
	require_once ABSPATH . 'wp-admin/includes/import.php';

abstract class Keyring_Reactions_Base {
	// Make sure you set all of these in your importer class
	const SLUG              = '';    // should start with letter & should only contain chars valid in javascript function name
	const LABEL             = '';    // the line that will show up in Import page
	const KEYRING_NAME      = '';    // Keyring service name; SLUG is not used to avoid conflict with Keyring Social Importer
	const KEYRING_SERVICE   = '';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many posts should queried before moving over to the next branch / reload the page
	const REQUESTS_PER_AUTO = 16;
	const KEYRING_VERSION   = '1.4'; // Minimum version of Keyring required

	const SILONAME          = '';    // identifier for the silo in the syndication_url field entry

	const OPTNAME_POSTPOS   = 'post_todo';  // options key for next post id in posts array
	const OPTNAME_POSTS     = 'posts';      // option key for posts array

	const SCHEDULE          = 'daily'; // this may break many things, careful if you wish to change it
	const SCHEDULETIME      = 36400;   // in tandem with the previous
	const RESCHEDULE        = 60;

	// You shouldn't need to edit (or override) these ones
	var $step               = 'greet';
	var $service            = false;
	var $token              = false;
	var $finished           = false;
	var $options            = array();
	var $posts              = array();
	var $errors             = array();
	var $request_method     = 'GET';
	var $optname            = '';
	var $methods            = array(); // method name for functions => comment type to store with
	var $schedule           = '';

	public function __construct() {
		// Can't do anything if Keyring is not available.
		// Prompt user to install Keyring (if they can), and bail
		if ( !defined( 'KEYRING__VERSION' ) || version_compare( KEYRING__VERSION, static::KEYRING_VERSION, '<' ) ) {
			if ( current_user_can( 'install_plugins' ) ) {
				add_thickbox();
				wp_enqueue_script( 'plugin-install' );
				add_filter( 'admin_notices', array( &$this, 'require_keyring' ) );
			}
			return false;
		}

		// Set some internal vars
		$this->optname = 'keyring-' . static::SLUG;
		$this->schedule = $this->optname . '_import_auto';

		// Populate options for this importer
		$this->options = get_option( $this->optname );

		// Add a Keyring handler to push us to the next step of the importer once connected
		add_action( 'keyring_connection_verified', array( &$this, 'verified_connection' ), 10, 2 );

		//add_action( 'add_meta_boxes', array(&$this, 'add_post_meta_box' ));
		//add_action( 'save_post', array(&$this, 'handle_post_meta_box' ) );

		// additional comment types
		add_action('admin_comment_types_dropdown', array(&$this, 'comment_types_dropdown'));

		// ...
		add_filter('get_avatar_comment_types', array( &$this, 'add_comment_types'));

		// additional cron schedules
		add_filter( 'cron_schedules', array(&$this, 'cron' ));

		// additional avatar filter
		add_filter( 'get_avatar' , array(&$this, 'get_avatar'), 1, 5 );

		// If a request is made for a new connection, pass it off to Keyring
		if (
			( isset( $_REQUEST['import'] ) && static::SLUG == $_REQUEST['import'] )
		&&
			(
				( isset( $_POST[ static::SLUG . '_token' ] ) && 'new' == $_POST[ static::SLUG . '_token' ] )
			||
				isset( $_POST['create_new'] )
			)
		) {
			$this->reset();
			Keyring_Util::connect_to( static::KEYRING_NAME, $this->optname );
			exit;
		}

		// If we have a token set already, then load some details for it
		if ( $this->get_option( 'token' ) && $token = Keyring::get_token_store()->get_token( array( 'service' => static::KEYRING_NAME, 'id' => $this->get_option( 'token' ) ) ) ) {
			$this->service = call_user_func( array( static::KEYRING_SERVICE, 'init' ) );
			$this->service->set_token( $token );
		}

		// jump to the first worker
		$this->handle_request();
	}

	/**
	 * Singleton mode on
	 */
	static public function &init() {
		static $instance = false;

		if ( !$instance ) {
			$class = get_called_class();
			$instance = new $class;
		}

		return $instance;
	}

	/**
	* Extend the "filter by comment type" of in the comments section
	* of the admin interface with all of our methods
	*
	* @param array $types the different comment types
	*
	* @return array the filtered comment types
	*/
	public function comment_types_dropdown($types) {
		foreach ($this->methods as $method => $type ) {
			if (!isset($types[ $type ]))
				$types[ $type ] = ucfirst( $type );
		}
		return $types;
	}

	/**
	 *
	 */
	public function add_comment_types ( $types ) {
		foreach ($this->methods as $method => $type ) {
			if (!in_array( $type, $types ))
				array_push( $types, $type );
		}
		return $types;
	}

	/**
	 * add our own, ridiculously intense schedule for chanining all the requests
	 * wee need for the imports
	 *
	 * @param array $schedules the current schedules in WP CRON
	 *
	 * @return array the filtered WP CRON schedules
	 */
	public function cron ( $schedules ) {
		/*
		if (!isset($schedules[ $this->optname ])) {
			$schedules[ $this->optname ] = array(
				'interval' => static::RESCHEDULE,
				'display' => sprintf(__( '%s auto import' ), static::SLUG )
			);
		}
		*/
		return $schedules;
	}

	/**
	 * of there is a comment meta 'avatar' field, use that as avatar for the commenter
	 *
	 * @param string $avatar the current avatar image string
	 * @param mixed $id_or_email this could be anything that triggered the avatar all
	 * @param string $size size for the image to display
	 * @param string $default optional fallback
	 * @param string $alt alt text for the avatar image
	 */
	public static function get_avatar($avatar, $id_or_email, $size, $default = '', $alt = '') {
		if (!is_object($id_or_email) || !isset($id_or_email->comment_type))
			return $avatar;

		// check if comment has an avatar
		$c_avatar = get_comment_meta($id_or_email->comment_ID, 'avatar', true);

		if (!$c_avatar)
			return $avatar;

		if (false === $alt)
			$safe_alt = '';
		else
			$safe_alt = esc_attr($alt);


		return sprintf( '<img alt="%s" src="%s" class="avatar photo u-photo" style="width: %spx; height: %spx;" />', $safe_alt, $c_avatar, $size, $size );
	}

	/**
	 * Accept the form submission of the Options page and handle all of the values there.
	 * You'll need to validate/santize things, and probably store options in the DB. When you're
	 * done, set $this->step = 'import' to continue, or 'options' to show the options form again.
	 */
	protected function handle_request_options() {
		$auto_import = (isset( $_POST['auto_import']) && !empty($_POST['auto_import'])) ? true : false;

		$auto_approve = (isset( $_POST['auto_approve']) && !empty($_POST['auto_approve'])) ? true : false;

		/*
		if ( $this->get_option('auto_import') && !wp_get_schedule( $this->schedule ) ) {
			wp_schedule_event( time() + static::SCHEDULETIME, static::SCHEDULE, $this->schedule );
		}
		elseif ( $this->get_option('auto_import') && wp_get_schedule( $this->schedule != static::SCHEDULE ) ) {
			wp_clear_scheduled_hook ( $this->schedule );
			wp_schedule_event( time() + static::SCHEDULETIME, static::SCHEDULE, $this->schedule );
		}
		elseif ( !$this->get_option('auto_import') ) {
			Keyring_Util::Debug('Ez most fut?');
			wp_clear_scheduled_hook ( $this->schedule );
		}
		*/

		wp_clear_scheduled_hook ( $this->schedule );

		if ($auto_import) {
			wp_schedule_event( time() + static::SCHEDULETIME, static::SCHEDULE, $this->schedule );
		}
		else {
			$this->cleanup();
		}

		// If there were errors, output them, otherwise store options and start importing
		if ( count( $this->errors ) ) {
			$this->step = 'greet';
		} else {
			$this->set_option( array(
				'auto_import'     => $auto_import,
				'auto_approve'    => $auto_approve,
				'limit_posts'     => sanitize_text_field($_POST['limit_posts']),
			) );

			$this->step = 'options';
		}
	}

	/**
	 * This step will do all the required calls for a specific method for a
	 * specific post, parse them and insert them into the DB as comments.
	 *
	 * @param string $method the method to call and work with (eg. favs, comments)
	 * @param post $post WP Post object
	 *
	 * @return None
	 */
	abstract protected function make_all_requests( $method, $post );

	/**
	 * Warn the user that they need Keyring installed and activated.
	 */
	protected function require_keyring() {
		global $keyring_required; // So that we only send the message once

		if ( 'update.php' == basename( $_SERVER['REQUEST_URI'] ) || $keyring_required )
			return;

		$keyring_required = true;

		echo '<div class="updated">';
		echo '<p>';
		printf(
			__( 'The <strong>Keyring Recations Importers</strong> plugin package requires the %1$s plugin to handle authentication. Please install it by clicking the button below, or activate it if you have already installed it, then you will be able to use the importers.', 'keyring' ),
			'<a href="http://wordpress.org/extend/plugins/keyring/" target="_blank">Keyring</a>'
		);
		echo '</p>';
		echo '<p><a href="plugin-install.php?tab=plugin-information&plugin=keyring&from=import&TB_iframe=true&width=640&height=666" class="button-primary thickbox">' . __( 'Install Keyring', 'keyring' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Get one of the options specific to this plugin from the array in which we retain them.
	 *
	 * @param string $name The name of the option you'd like to get
	 * @param mixed $default What to return if the option requested isn't available, defaults to false
	 * @return mixed
	 */
	protected function get_option( $name, $default = false ) {
		if ( isset( $this->options[ $name ] ) )
			return $this->options[ $name ];
		return $default;
	}

	/**
	 * Set an option within the array maintained for this plugin. Optionally set multiple options
	 * by passing an array of named pairs in. Passing null as the name will reset all options.
	 * If you want to store something big, then use core's update_option() or similar so that it's
	 * outside of this array.
	 *
	 * @param mixed $name String for a name/value pair, array for a collection of options, or null to reset everything
	 * @param mixed $val The value to set this option to
	 */
	protected function set_option( $name, $val = null ) {
		if ( is_array( $name ) )
			$this->options = array_merge( (array) $this->options, $name );
		else if ( is_null( $name ) && is_null( $val ) ) { // $name = null to reset all options
			$this->options = array();
			wp_clear_scheduled_hook ( $this->schedule );
		}
		else if ( is_null( $val ) && isset( $this->options[ $name ] ) )
			unset( $this->options[ $name ] );
		else
			$this->options[ $name ] = $val;

		return update_option( $this->optname, $this->options );
	}

	/**
	 * Reset all options for this importer
	 */
	protected function reset() {
		$this->set_option( null );
	}

	/**
	 * Early handling/validation etc of requests within the importer. This is hooked in early
	 * enough to allow for redirecting the user if necessary.
	 */
	protected function handle_request() {

		// Only interested in POST requests and specific GETs
		if ( empty( $_GET['import'] ) || static::SLUG != $_GET['import'] )
			return;

		// Heading to a specific step of the importer
		if ( !empty( $_REQUEST['step'] ) && ctype_alpha( $_REQUEST['step'] ) ) {
			$this->step = (string) $_REQUEST['step'];
		}

		// Heading to a specific step of the importer
		if ( isset( $_POST['refresh'] ) ) {
			$this->step = 'import';
		}

		switch ( $this->step ) {
		case 'importsingle':
			if ( empty( $_REQUEST['post_id'] ) || ctype_alpha( $_REQUEST['post_id'] ) ) {
				$this->step = 'greet';
				// fall through here, no break
			}
			else {
				$post_id = $_REQUEST['post_id'];
				$this->do_single_import($post_id);
				break;
			}
		case 'greet':
			if ( !empty( $_REQUEST[ static::SLUG . '_token' ] ) ) {

				// Coming into the greet screen with a token specified.
				// Set it internally as our access token and then initiate the Service for it
				$this->set_option( 'token', (int) $_REQUEST[ static::SLUG . '_token' ] );
				$this->service = call_user_func( array( static::KEYRING_SERVICE, 'init' ) );
				$token = Keyring::get_token_store()->get_token( array( 'service' => static::SLUG, 'id' => (int) $_REQUEST[ static::SLUG . '_token' ] ) );
				$this->service->set_token( $token );
			}

			if ( $this->service && $this->service->get_token() ) {
				// If a token has been selected (and is available), then jump to the next setp
				$this->step = 'options';
			} else {
				// Otherwise reset all default/built-ins
				$this->set_option( array(
					'auto_import'           => false,
					'auto_approve'          => false,
					'limit_posts'           => '',
					static::OPTNAME_POSTS   => array(),
					static::OPTNAME_POSTPOS => 0,
				) );
			}

			break;

		case 'options':
			// Clear token and start again if a reset was requested
			if ( isset( $_POST['reset'] ) ) {
				$this->reset();
				$this->step = 'greet';
				break;
			}

			// If we're "refreshing", then just act like it's an auto import
			//if ( isset( $_POST['refresh'] ) ) {
			//	$this->auto_import = true;
			//}

			// Write a custom request handler in the extending class here
			// to handle processing/storing options for import. Make sure to
			// end it with $this->step = 'import' (if you're ready to continue)
			$this->handle_request_options();
			if ( method_exists( $this, 'full_custom_request_options' ) ) {
				$this->handle_request_options();
				return;
			}
			else {
				$this->handle_request_options();
			}

			break;
		}


	}

	/**
	 * Decide which UI to display to the user, kind of a second-stage of handle_request().
	 */
	public function dispatch() {
		// Don't allow access to ::options() unless a service/token are set
		if ( !$this->service || !$this->service->get_token() ) {
			$this->step = 'greet';
		}

		switch ( $this->step ) {
		case 'greet':
			$this->greet();
			break;
		case 'options':
			$this->options();
			break;
		case 'import':
			$this->do_import();
			break;
		case 'import_single':
			$this->do_single_import();
			break;
		case 'done':
			$this->done();
			break;
		}

	}

	/**
	 * Raise an error to display to the user. Multiple errors per request may be triggered.
	 * Should be called before ::header() to ensure that the errors are displayed to the user.
	 *
	 * @param string $str The error message to display to the user
	 */
	protected function error( $str ) {
		$this->errors[] = $str;
	}

	/**
	 * A default, basic header for the importer UI
	 */
	protected function header() {
		?>
		<style type="text/css">
			.keyring-importer ul,
			.keyring-importer ol { margin: 1em 2em; }
			.keyring-importer li { list-style-type: square; }
		</style>
		<div class="wrap keyring-importer">
		<?php //screen_icon(); ?>
		<h2><?php printf( __( '%s Importer', 'keyring' ), esc_html( static::LABEL ) ); ?></h2>
		<?php
		if ( count( $this->errors ) ) {
			echo '<div class="error"><ol>';
			foreach ( $this->errors as $error ) {
				echo '<li>' . esc_html( $error ) . '</li>';
			}
			echo '</ol></div>';
		}
	}

	/**
	 * Default, basic footer for importer UI
	 */
	protected function footer() {
		echo '</div>';
	}

	/**
	 * The first screen the user sees in the import process. Summarizes the process and allows
	 * them to either select an existing Keyring token or start the process of creating a new one.
	 * Also makes sure they have the correct service available, and that it's configured correctly.
	 */
	protected function greet() {
		if ( method_exists( $this, 'full_custom_greet' ) ) {
			$this->full_custom_greet();
			return;
		}

		$this->header();

		// If this service is not configured, then we can't continue
		if ( ! $service = Keyring::get_service_by_name( static::KEYRING_NAME ) ) : ?>
			<p class="error"><?php echo esc_html( sprintf( __( "It looks like you don't have the %s service for Keyring installed.", 'keyring' ), static::LABEL ) ); ?></p>
			<?php
			$this->footer();
			return;
			?>
		<?php elseif ( ! $service->is_configured() ) : ?>
			<p class="error"><?php echo esc_html( sprintf( __( "Before you can use this importer, you need to configure the %s service within Keyring.", 'keyring' ), static::LABEL ) ); ?></p>
			<?php
			if (
				current_user_can( 'read' ) // @todo this capability should match whatever the UI requires in Keyring
			&&
				! KEYRING__HEADLESS_MODE // In headless mode, there's nowhere (known) to link to
			&&
				has_action( 'keyring_' . static::KEYRING_NAME . '_manage_ui' ) // Does this service have a UI to link to?
			) {
				$manage_kr_nonce = wp_create_nonce( 'keyring-manage' );
				$manage_nonce = wp_create_nonce( 'keyring-manage-' . static::SLUG );
				echo '<p><a href="' . esc_url( Keyring_Util::admin_url( static::SLUG, array( 'action' => 'manage', 'kr_nonce' => $manage_kr_nonce, 'nonce' => $manage_nonce ) ) ) . '" class="button-primary">' . sprintf( __( 'Configure %s Service', 'keyring' ), static::LABEL ) . '</a></p>';
			}
			$this->footer();
			return;
			?>
		<?php endif; ?>
		<div class="narrow">
			<form action="admin.php?import=<?php echo static::SLUG; ?>&amp;step=greet" method="post">
				<p><?php printf( __( "Howdy! This importer requires you to connect to %s before you can continue.", 'keyring' ), static::LABEL ); ?></p>
				<?php do_action(  $this->optname . '_greet' ); ?>
				<?php if ( $service->is_connected() ) : ?>
					<p><?php echo sprintf( esc_html( __( 'It looks like you\'re already connected to %1$s via %2$s. You may use an existing connection, or create a new one:', 'keyring' ) ), static::LABEL, '<a href="' . esc_attr( Keyring_Util::admin_url() ) . '">Keyring</a>' ); ?></p>
					<?php $service->token_select_box( static::SLUG . '_token', true ); ?>
					<input type="submit" name="connect_existing" value="<?php echo esc_attr( __( 'Continue&hellip;', 'keyring' ) ); ?>" id="connect_existing" class="button-primary" />
				<?php else : ?>
					<p><?php echo esc_html( sprintf( __( "To get started, we'll need to connect to your %s account so that we can access your tweets.", 'keyring' ), static::LABEL ) ); ?></p>
					<input type="submit" name="create_new" value="<?php echo esc_attr( sprintf( __( 'Connect to %s&#0133;', 'keyring' ), static::LABEL ) ); ?>" id="create_new" class="button-primary" />
				<?php endif; ?>
			</form>
		</div>
		<?php
		$this->footer();
	}

	/**
	 * If the user created a new Keyring connection, then this method handles intercepting
	 * when the user returns back to WP/Keyring, passing the details of the created token back to
	 * the importer.
	 *
	 * @param array $request The $_REQUEST made after completing the Keyring connection process
	 */
	public function verified_connection( $service, $id ) {
		// Only handle connections that were for us
		global $keyring_request_token;

		if ( ! $keyring_request_token || $this->optname != $keyring_request_token->get_meta( 'for' ) )
			return;

		// Only handle requests that were successful, and for our specific service
		if ( static::KEYRING_NAME == $service && $id ) {
			// Redirect to ::greet() of our importer, which handles keeping track of the token in use, then proceeds
			wp_safe_redirect(
				add_query_arg(
					static::SLUG . '_token',
					(int) $id,
					admin_url( 'admin.php?import=' . static::SLUG . '&step=greet' )
				)
			);
			exit;
		}
	}

	/**
	 * Once a connection is selected/created, this UI allows the user to select
	 * the details of their imported tweets.
	 */
	protected function options() {
		// in case there is a fully customized page for options, use that instead
		if ( method_exists( $this, 'full_custom_options' ) ) {
			$this->full_custom_options();
			return;
		}
		$this->header();
		?>
		<form name="import-<?php echo esc_attr( static::SLUG ); ?>" method="post" action="admin.php?import=<?php esc_attr_e( static::SLUG ); ?>&amp;step=options">
		<?php
		if ( $this->get_option( 'auto_import' ) ) :
			$auto_import_button_label = __( 'Save Changes', 'keyring' );
			?>
			<div class="updated inline">
				<p><?php _e( "You are currently auto-importing new content using the settings below.", 'keyring' ); ?></p>
				<p><input type="submit" name="refresh" class="button" id="options-refresh" value="<?php esc_attr_e( 'Check for new content now', 'keyring' ); ?>" /></p>
			</div><?php
		else :
			$auto_import_button_label = __( 'Start auto-importing', 'keyring' );
			?><p><?php _e( "Now that we're connected, we can go ahead and download your content, importing it all as posts.", 'keyring' ); ?></p><?php
		endif;
		?>
			<p><?php _e( "You can optionally choose to 'Import new content automatically', which will continually import any new posts you make, using the settings below.", 'keyring' ); ?></p>
			<?php do_action( 'keyring_importer_' . static::SLUG . '_options_info' ); ?>
			<table class="form-table">
				<tr valign="top">
					<th scope="row">
						<label><?php _e( 'Connected as', 'keyring' ) ?></label>
					</th>
					<td>
						<strong><?php echo $this->service->get_token()->get_display(); ?></strong>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">
						<label for="limit_posts"><?php _e( 'Limit posts to check reaction for', 'keyring' ) ?></label>
					</th>
					<td>
						<select name="limit_posts" id="limit_posts">
							<?php $opt = "1 day" ?>
							<option value="<?php echo $opt ?>" <?php echo selected( $this->get_option( 'limit_posts', '' ) == $opt );?>><?php _e("Yesterday", 'keyring') ?></option>
							<?php $opt = "1 week" ?>
							<option value="<?php echo $opt ?>" <?php echo selected( $this->get_option( 'limit_posts', '' ) == $opt );?>><?php _e("Last week", 'keyring') ?></option>
							<?php $opt = "2 weeks" ?>
							<option value="<?php echo $opt ?>" <?php echo selected( $this->get_option( 'limit_posts', '' ) == $opt );?>><?php _e("Last 2 weeks", 'keyring') ?></option>
							<?php $opt = "1 month" ?>
							<option value="<?php echo $opt ?>" <?php echo selected( $this->get_option( 'limit_posts', '' ) == $opt );?>><?php _e("Last month", 'keyring') ?></option>
							<?php $opt = "6 months" ?>
							<option value="<?php echo $opt ?>" <?php echo selected( $this->get_option( 'limit_posts', '' ) == $opt );?>><?php _e("Last 6 month", 'keyring') ?></option>
							<?php $opt = "" ?>
							<?php $opt = "1 year" ?>
							<option value="<?php echo $opt ?>" <?php echo selected( $this->get_option( 'limit_posts', '' ) == $opt );?>><?php _e("Last year", 'keyring') ?></option>
							<?php $opt = "" ?>
							<option value="<?php echo $opt ?>" <?php echo selected( $this->get_option( 'limit_posts', '' ) == $opt );?>><?php _e("Don't limit", 'keyring') ?></option>
						</select>
					</td>
				</tr>
				<tr valign="top">
					<th scope="row">
						<label for="auto_approve"><?php _e( 'Auto-approve imported comments', 'keyring' ) ?></label>
					</th>
					<td>
						<input type="checkbox" value="1" name="auto_approve" id="auto_approve"<?php echo checked( 'true' == $this->get_option( 'auto_approve', 'true' ) ); ?> />
					</td>
				</tr>
				<?php
				// This is a perfect place to hook in if you'd like to output some additional options
				do_action( $this->optname . '_custom_options' );
				?>

				<tr valign="top">
					<th scope="row">
						<label for="auto_import"><?php _e( 'Auto-import new content', 'keyring' ) ?></label>
					</th>
					<td>
						<input type="checkbox" value="1" name="auto_import" id="auto_import"<?php echo checked( 'true' == $this->get_option( 'auto_import', 'true' ) ); ?> />
					</td>
				</tr>
			</table>

			<p class="submit">
				<input type="submit" name="submit" class="button-primary" id="options-submit" value="<?php _e( 'Save settings', 'keyring' ); ?>" />
				<input type="submit" name="reset" value="<?php _e( 'Reset Importer', 'keyring' ); ?>" id="reset" class="button" />
			</p>
		</form>
		<!--
		<script type="text/javascript" charset="utf-8">
			jQuery( document ).ready( function() {
				jQuery( '#auto_import' ).on( 'change', function() {
					if ( jQuery( this ).attr( 'checked' ) ) {
						jQuery( '#options-submit' ).val( '<?php echo esc_js( $auto_import_button_label ); ?>' );
					} else {
						jQuery( '#options-submit' ).val( '<?php echo esc_js( __( 'Import all posts (once-off)', 'keyring' ) ); ?>' );
					}
				} ).change();
			} );
		</script>
		-->
		<?php

		$this->footer();
	}

	/**
	 *
	 */
	public function do_single_import( $post_id ) {
		defined( 'WP_IMPORTING' ) or define( 'WP_IMPORTING', true );
		do_action( 'import_start' );
		set_time_limit( 0 );

		$this->header();
		echo '<p>' . __( 'Importing Reactions...' ) . '</p>';

		$syndication_url = false;

		// Need a token to do anything with this
		if ( !$this->service->get_token() )
			return;

		require_once ABSPATH . 'wp-admin/includes/import.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';
		require_once ABSPATH . 'wp-admin/includes/comment.php';

		$post = get_post($post_id);
		if (!$post)
			return false;

		$syndication_urls = get_post_meta ( $post->ID, 'syndication_urls', true );
		if (strstr( $syndication_urls, static::SILONAME )) {
			$syndication_urls = explode("\n", $syndication_urls );

			foreach ( $syndication_urls as $url ) {
				if (strstr( $url, static::SILONAME )) {
					$syndication_url = $url;
				}
			}
		}

		if (!$syndication_url)
			return false;

		$todo = array (
			'post_id' => $post->ID,
			'syndication_url' => $syndication_url,
		);

		$msg = sprintf(__('Starting auto import for #%s', 'keyring'), $post->ID );
		Keyring_Util::debug($msg);


		foreach ( $this->methods as $method => $type ) {
			$msg = sprintf(__('Processing %s for post #%s', 'keyring'), $method, $post->ID);
			Keyring_Util::debug($msg);

			$result = $this->make_all_requests( $method, $todo );

			if ( Keyring_Util::is_error( $result ) )
				print $result;
		}


		$this->importer_goto( 'done', 1 );
		$this->footer();

		do_action( 'import_end' );
		return true;
	}


	function add_post_meta_box () {
		$post_id = false;
		if (!empty($_POST['post_ID']))
			$post_id = $_POST['post_ID'];
		else if (!empty($_GET['post']))
			$post_id = $_GET['post'];

		$syndication_urls = get_post_meta( $post_id, 'syndication_urls', true);

		if ( strstr( $syndication_urls, static::SILONAME )) {
			add_meta_box(
				'keyring-reactions-import-' . static::SILONAME,
				esc_html__ (sprintf ( __('Import reactions for this post from %s', 'Keyring'), static::SILONAME)),
				array(&$this, 'display_post_meta_box'),
				'post',
				'normal',
				'default'
			);
		}
	}

	function display_post_meta_box() {
		wp_nonce_field( basename( __FILE__ ), static::SILONAME );
		global $post;
		?>
		<p>
			<?php
				printf ( '<input style="margin-right: 1em;" class="button button-primary " "id="import-%s" name="import-%s" type="submit" value="Import for #%s"></input>', static::SLUG, static::SLUG, $post->ID );
				printf ('<label for="import-%s">%s</label><br />', static::SLUG, sprintf(__('Manually import reactions from %s for this post now'), static::SILONAME ));
			?>
		</p>
		<?php

		/*"admin.php?import=<?php echo static::SLUG; ?>&amp;step=greet" */
	}

	function handle_post_meta_box( $post_id ) {
		if ( !isset( $_POST [ static::SILONAME  ] ))
			return $post_id;

		 if (!wp_verify_nonce( $_POST[ static::SILONAME ], basename( __FILE__ ) ) )
			return $post_id;

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return $post_id;

		if ( ! current_user_can( 'edit_page', $post_id ) )
			return $post_id;

		if (isset( $_POST['import-' . static::SLUG ] )) {
			$id = split('#', $_POST['import-' . static::SLUG ]);
			$id = $id[1];
			$this->do_single_import( $id );
		}

	}

	/**
	 * Handle a cron request to pick up importing reactions from where we left off.
	 * Since the posts array & the post pointer stays untouched until the import
	 * job if finished, we'll just continuing the import for the next post to process.
	 *
	 * We cannot do the whole import in one batch - there could be a massive amount
	 * of posts to check reactions for - so we reschedule the job to start
	 * immediately after eachother.
	 * We're also not using the batch mode (X posts per page load) but instead
	 * one-by-one so one iteration of the WP CRON event will not take that long
	 * and may not cause issues later on.
	 */
	public function do_auto_import( ) {
		defined( 'WP_IMPORTING' ) or define( 'WP_IMPORTING', true );
		do_action( 'import_start' );
		set_time_limit( 0 );

		Keyring_Util::debug( static::SLUG . sprintf(' auto import: init'));

		// In case auto-import has been disabled, clear all jobs and bail
		if ( !$this->get_option( 'auto_import' ) ) {
			Keyring_Util::debug( static::SLUG . sprintf(' auto import: clearing hook'));
			wp_clear_scheduled_hook( 'keyring_' . static::SLUG . '_import_auto' );
			return;
		}

		// Need a token to do anything with this
		if ( !$this->service->get_token() )
			return;

		require_once ABSPATH . 'wp-admin/includes/import.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';
		require_once ABSPATH . 'wp-admin/includes/comment.php';

		$next = 0;
		$position = 0;
		$num = 0;

		$this->get_posts();

		while ( !$this->finished && $num < static::REQUESTS_PER_AUTO ) {

			$position = $this->get_option( static::OPTNAME_POSTPOS, 0);
			if ( !is_array($this->posts) || !isset($this->posts[$position]) )
				return new Keyring_Error(
					'keyring-reactions-post-not-set',
					__( 'The post to work with does not exist in the posts array. Something is definitely wrong.', 'keyring' )
				);

			$todo = $this->posts[$position];
			Keyring_Util::debug( static::SLUG . sprintf(' auto import: doing %s/%s',  $position, count($this->posts)-1 ));
			//$msg = sprintf(__('Starting auto import for #%s', 'keyring'), $todo['post_id']);
			//Keyring_Util::debug($msg);

			foreach ( $this->methods as $method => $type ) {
				$msg = sprintf(__('Processing %s for post #%s', 'keyring'), $method, $todo['post_id']);
				Keyring_Util::debug($msg);

				$result = $this->make_all_requests( $method, $todo );

				if ( Keyring_Util::is_error( $result ) )
					print $result;
			}

			$next = $position+1;
			$num++;

			// we're done, clean up
			if ( $next >= count($this->posts) ) {
				$this->finished = true;
				break;
			}
			else {
				$this->set_option( static::OPTNAME_POSTPOS, $next );
			}
		}

		Keyring_Util::debug( sprintf ('%s auto import: current batch finised (%s to %s out of %s )', static::SLUG, (int) ($position - static::REQUESTS_PER_AUTO), $position, count($this->posts)-1 ));

		if ( $this->finished || $next >= count($this->posts) ) {
			Keyring_Util::debug( sprintf ('%s auto import: FINISHED', static::SLUG));
			$this->cleanup();
			do_action( 'keyring_import_done', $this->optname );
		}
		else {
			Keyring_Util::debug( sprintf ('%s auto import: Rescheduling event', static::SLUG));
			wp_schedule_single_event( time() + static::RESCHEDULE, $this->schedule );
		}

		do_action( 'import_end' );
	}

	/**
	 * Hooked into ::dispatch(), this just handles triggering the import and then dealing with
	 * any value returned from it.
	 */
	function do_import() {
		set_time_limit( 0 );
		$res = $this->import();
		if ( true !== $res ) {
			echo '<div class="error"><p>';
			if ( Keyring_Util::is_error( $res ) ) {
				$http = $res->get_error_message(); // The entire HTTP object is passed back if it's an error
				if ( 400 == wp_remote_retrieve_response_code( $http ) ) {
					printf( __( "Received an error from %s. Please wait for a while then try again.", 'keyring' ), static::LABEL );
				} else if ( in_array( wp_remote_retrieve_response_code( $http ), array( 502, 503 ) ) ) {
					printf( __( "%s is currently experiencing problems. Please wait for a while then try again.", 'keyring' ), static::LABEL );
				} else {
					// Raw dump, sorry
					echo '<p>' . sprintf( __( "We got an unknown error back from %s. This is what they said.", 'keyring' ), static::LABEL ) . '</p>';
					$body = wp_remote_retrieve_body( $http );
					echo '<pre>';
					print_r( $body );
					echo '</pre>';
				}
			} else {
				_e( 'Something went wrong. Please try importing again in a few minutes (your details have been saved and the import will continue from where it left off).', 'keyring' );
			}
			echo '</p></div>';
			$this->footer(); // header was already done in import()
		}
	}

	/**
	 * Iterate through X of the matching posts and pull reactions for them before
	 * reloading the page or finishing up.
	 * Since there is no persistent cache by default in WP we're using the DB to
	 * store the current pointer of which post should be done next on the posts
	 * array which is also stored in the DB (until the import is done and the
	 * array & the pointer is resetted ).
	 */
	function import() {
		defined( 'WP_IMPORTING' ) or define( 'WP_IMPORTING', true );
		do_action( 'import_start' );
		$num = 0;
		$next = 0;
		$position = 0;

		$this->header();
		echo '<p>' . __( 'Importing Reactions...' ) . '</p>';

		$this->get_posts();

		while ( !$this->finished && $num < static::REQUESTS_PER_LOAD ) {
			echo "<p>";
			$position = $this->get_option( static::OPTNAME_POSTPOS, 0);

			if ( !is_array($this->posts) || !isset($this->posts[$position]) ) {
				$this->cleanup();
				return new Keyring_Error(
					'keyring-reactions-post-not-set',
					__( 'The post to work with does not exist in the posts array. Something is definitely wrong. I\'m resetting myself now, please try importing again.', 'keyring' )
				);
			}

			$todo = $this->posts[$position];

			foreach ( $this->methods as $method => $type ) {
				$msg = sprintf(__('Processing %s for post <strong>#%s</strong><br />', 'keyring'), $method, $todo['post_id']);
				Keyring_Util::debug($msg);
				echo $msg;
				$result = $this->make_all_requests( $method, $todo );

				if ( Keyring_Util::is_error( $result ) )
					print_r ($result);
			}

			echo "</p>";
			$next = $position+1;
			if ($next >= sizeof($this->posts)) {
				$this->finished = true;
				break;
			}

			$this->set_option( static::OPTNAME_POSTPOS, $next );
			$num+=1;

		}

		if ( $this->finished ) {
			$this->importer_goto( 'done', 1 );
		}
		else {
			$this->importer_goto( 'import' );
		}

		$this->footer();

		do_action( 'import_end' );
		return true;
	}

	/**
	 * To keep the process moving while avoiding memory issues, it's easier to just
	 * end a response (handling a set chunk size) and then start another one. Since
	 * we don't want to have the user sit there hitting "next", we have this helper
	 * which includes some JS to keep things bouncing on to the next step (while
	 * there is a next step).
	 *
	 * @param string $step Which step should we direct the user to next?
	 * @param int $seconds How many seconds should we wait before auto-redirecting them? Set to null for no auto-redirect.
	 */
	function importer_goto( $step, $seconds = 3 ) {
		echo '<form action="admin.php?import=' . esc_attr( static::SLUG ) . '&amp;step=' . esc_attr( $step ) . '" method="post" id="' . esc_attr( static::SLUG ) . '-import">';
		echo wp_nonce_field( static::SLUG . '-import', '_wpnonce', true, false );
		echo wp_referer_field( false );
		echo '<p><input type="submit" class="button-primary" value="' . __( 'Continue with next batch', 'keyring' ) . '" /> <span id="auto-message"></span></p>';
		echo '</form>';

		if ( null !== $seconds ) :
		?><script type="text/javascript">
			next_counter = <?php echo (int) $seconds ?>;
			jQuery(document).ready(function(){
				<?php echo esc_js( static::SLUG ); ?>_msg();
			});

			function <?php echo esc_js( static::SLUG ); ?>_msg() {
				str = '<?php _e( "Continuing in #num#", 'keyring' ) ?>';
				jQuery( '#auto-message' ).text( str.replace( /#num#/, next_counter ) );
				if ( next_counter <= 0 ) {
					if ( jQuery( '#<?php echo esc_js( static::SLUG ); ?>-import' ).length ) {
						jQuery( "#<?php echo esc_js( static::SLUG ); ?>-import input[type='submit']" ).hide();
						var str = '<?php _e( 'Continuing', 'keyring' ); ?> <img src="images/loading.gif" alt="" id="processing" align="top" />';
						jQuery( '#auto-message' ).html( str );
						jQuery( '#<?php echo esc_js( static::SLUG ); ?>-import' ).submit();
						return;
					}
				}
				next_counter = next_counter - 1;
				setTimeout( '<?php echo esc_js( static::SLUG ); ?>_msg()', 1000 );
			}
		</script><?php endif;
	}


	/**
	 * When they're complete, give them a quick summary and a link back to their website.
	 */
	function done() {
		$this->header();
		echo '<h2>' . __( 'All done!', 'keyring' ) . '</h2>';
		echo '<h3>' . sprintf( __( '<a href="%s">Check out all your new comments</a>.', 'keyring' ), admin_url( 'edit-comments.php' ) ) . '</h3>';
		$this->footer();
		$this->cleanup();
		do_action( 'import_done', $this->optname );
		do_action( 'keyring_import_done', $this->optname );
	}

	/**
	 * When they're complete, give them a quick summary and a link back to their website.
	 */
	function saved() {
		$this->header();
		echo '<h2>' . __( 'Setting saved!', 'keyring' ) . '</h2>';
		$this->footer();
	}

	/**
	 * reset internal variables
	 */
	function cleanup() {
		$msg = __('DOING CLEANUP', 'keyring');
		Keyring_Util::debug($msg);
		$this->set_option( static::OPTNAME_POSTS );
		$this->set_option( static::OPTNAME_POSTPOS );
	}

	/**
	 * Gets all posts with their syndicated url matching self::SILONAME
	 * The matching posts will be stored in the DB because we need them in between
	 * page loads and from scheduled cron events.
	 * Then the import is finished for all the posts, the entry will be nulled.
	 *
	 */
	function get_posts ( ) {
		$posts = $this->get_option( static::OPTNAME_POSTS );

		// we are in the middle of a run
		if (!empty($posts)) {
			$this->posts = $posts;
			return true;
		}

		 //load this for test, in case you need it for a specific post only
		//$raw = array ( get_post( 8180 ));

		$args = array (
			'meta_key'         => 'syndication_urls',
			'post_type'        => 'any',
			'posts_per_page'   => -1,
			'post_status'      => 'publish',
			'orderby'          => 'post_date',
			'order'            => 'DESC',
		);
		$limit = $this->get_option('limit_posts');
		if ( $limit != '') {
			$args['date_query'] = array(
					array(
						'after' => $limit . ' ago',
					)
			);
		}

		$raw = get_posts( $args );

		foreach ( $raw as $p ) {
			$syndication_urls = get_post_meta ( $p->ID, 'syndication_urls', true );
			if (strstr( $syndication_urls, static::SILONAME )) {
				$syndication_urls = explode("\n", $syndication_urls );

				foreach ( $syndication_urls as $url ) {
					if (strstr( $url, static::SILONAME )) {

						$posts[] = array (
							'post_id' => $p->ID,
							'syndication_url' => $url,
						);
					}
				}
			}
		}

		$this->posts = $posts;
		$this->set_option( static::OPTNAME_POSTS, $posts);
	}

	/**
	 * Comment inserter
	 *
	 * @param string &$post_id post ID
	 * @param array &$comment array formatted to match a WP Comment requirement
	 * @param mixed &$raw Raw format of the comment, like JSON response from the provider
	 * @param string &$avatar Avatar string to be stored as comment meta
	 *
	 */
	function insert_comment ( &$post_id, &$comment, &$raw, &$avatar = '' ) {

		$comment_id = false;

		// safety first
		$comment['comment_author_email'] = filter_var ( $comment['comment_author_email'], FILTER_SANITIZE_EMAIL );
		$comment['comment_author_url'] = filter_var ( $comment['comment_author_url'], FILTER_SANITIZE_URL );
		$comment['comment_author'] = filter_var ( $comment['comment_author'], FILTER_SANITIZE_STRING);
		//$comment['comment_content'] = filter_var ( $comment['comment_content'], FILTER_SANITIZE_SPECIAL_CHARS );

		//test if we already have this imported
		$testargs = array(
			'author_email' => $comment['comment_author_email'],
			'post_id' => $post_id,
		);

		// so if the type is comment and you add type = 'comment', WP will not return the comments
		// such logical!
		if ( $comment['comment_type'] != 'comment')
			$testargs['type'] = $comment['comment_type'];

		// in case it's a fav or a like, the date field is not always present
		// but there should be only one of those, so the lack of a date field indicates
		// that we should not look for a date when checking the existence of the
		// comment
		if ( isset( $comment['comment_date']) && !empty($comment['comment_date']) ) {
			// in case you're aware of a nicer way of doing this, please tell me
			// or commit a change...

			$tmp = explode ( " ", $comment['comment_date'] );
			$d = explode( "-", $tmp[0]);
			$t = explode (':',$tmp[1]);

			$testargs['date_query'] = array(
				'year'     => $d[0],
				'monthnum' => $d[1],
				'day'      => $d[2],
				'hour'     => $t[0],
				'minute'   => $t[1],
				'second'   => $t[2],
			);

			//$testargs['date_query'] = $comment['comment_date'];

			//test if we already have this imported
			Keyring_Util::Debug(sprintf(__('checking comment existence for %s (with date) for post #%s','keyring'), $comment['comment_author_email'], $post_id));
		}
		else {
			// we do need a date
			$comment['comment_date'] = date("Y-m-d H:i:s");
			$comment['comment_date_gmt'] = date("Y-m-d H:i:s");

			//test if we already have this imported
			Keyring_Util::debug(sprintf(__('checking comment existence for %s (no date) for post #%s','keyring'), $comment['comment_author_email'], $post_id));
		}

		Keyring_Util::debug(json_encode($testargs));
		$existing = get_comments($testargs);
		Keyring_Util::debug(json_encode($existing));

		// no matching comment yet, insert it
		if (empty($existing)) {

			// disable flood control, just in case
			remove_filter('check_comment_flood', 'check_comment_flood_db', 10, 3);

			//Keyring_Util::debug(sprintf(__('inserting comment for post #%s','keyring'), $post_id));

			// add comment
			// DON'T use wp_new_comment - if there are like ~1k reactions,
			// Akismet, flood control, mail notifications & all would kick in
			// and no one wants thousands of mails sent from their WordPress
			// because that is usually a hacked system indicator
			if ( $comment_id = wp_insert_comment($comment) ) {
				// add avatar for later use if present
				if (!empty($avatar)) {
					update_comment_meta( $comment_id, 'avatar', $avatar );
				}

				// full raw response for the vote, just in case
				update_comment_meta( $comment_id, $this->optname, $raw );

				// info
				$r = sprintf (__("New %s #%s from %s imported from %s for #%s", 'keyring'), $comment['comment_type'], $comment_id, $comment['comment_author'], self::SILONAME, $post_id );
			}
			else {
				$r = sprintf (__("Something went wrong inserting %s for #%s from %s", 'keyring'), $comment['comment_type'], $post_id, self::SILONAME);
			}
			// re-add flood control
			add_filter('check_comment_flood', 'check_comment_flood_db', 10, 3);
		}
		else {
				// info
				$r = sprintf (__("Already exists: %s from %s for #%s", 'keyring'), $comment['comment_type'], $comment['comment_author'], $post_id );
		}

		Keyring_Util::debug($r);
		return $comment_id;
	}

}

function keyring_register_reactions( $slug, $class, $plugin, $info = false ) {
	global $_keyring_reactions;
	//$slug = preg_replace( '/[^a-z_]/', '', $slug );
	$_keyring_reactions[$slug] = call_user_func( array( $class, 'init' ) );
	if ( !$info )
		$info = __( 'Import reactions from %s and save them as Comments within WordPress.', 'keyring' );

	$name = $class::LABEL;
	$options = get_option( 'keyring-' . $class::SLUG );
	if ( !empty( $options['auto_import'] ) && !empty( $options['token'] ) )
		$name = '&#10003; ' . $name;

	register_importer(
		$slug,
		$name,
		sprintf(
			$info,
			$class::LABEL
		),
		array( $_keyring_reactions[$slug], 'dispatch' )
	);

	// Handle auto-import requests
	add_action( 'keyring-' . $class::SLUG . '_import_auto' , array( $_keyring_reactions[$slug], 'do_auto_import' ) );
}

$keyring_reactions = glob( dirname( __FILE__ ) . "/importers/*.php" );
$keyring_reactions = apply_filters( 'keyring_reactions', $keyring_reactions );
foreach ( $keyring_reactions as $keyring_reaction )
	require $keyring_reaction;
unset( $keyring_reactions, $keyring_reaction );
