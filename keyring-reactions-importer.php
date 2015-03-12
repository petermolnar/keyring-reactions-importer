<?php
/*
Plugin Name: Keyring Reactions Importer
Plugin URI: https://github.com/petermolnar/keyring-reactions-importer
Description:
Version: 0.1
Author: Peter Molnar <hello@petermolnar.eu>
Author URI: http://petermolnar.eu/
License: GPLv3
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

// Load Importer API
if ( !function_exists( 'register_importer ' ) )
	require_once ABSPATH . 'wp-admin/includes/import.php';

abstract class Keyring_Reactions_Base {
	// Make sure you set all of these in your importer class
	const SLUG              = '';    // should start with letter & should only contain chars valid in javascript function name
	const LABEL             = '';    // e.g. 'Twitter'
	const KEYRING_NAME      = '';    // Keyring service name; SLUG is not used to avoid conflict with Keyring Social Importer
	const KEYRING_SERVICE   = '';    // Full class name of the Keyring_Service this importer requires
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?
	const KEYRING_VERSION   = '1.4'; // Minimum version of Keyring required
	const CACHE             = false;
	const SILONAME          = '';

	const OPTNAME_POSTPOS   = 'post_todo';
	const OPTNAME_LOG       = 'log';
	const OPTNAME_POSTS     = 'posts';

	const SCHEDULE          = 'daily'; // this may break many things, careful if you wish to change it
	const SCHEDULETIME      = 36400;   // in tandem with the previous
	const RESCHEDULE        = 30;

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

	function __construct() {
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

		// Set some vars
		$this->optname = 'keyring-' . static::SLUG;
		$this->schedule = $this->optname . '_import_auto';

		// Populate options for this importer
		$this->options = get_option( $this->optname );

		// Add a Keyring handler to push us to the next step of the importer once connected
		add_action( 'keyring_connection_verified', array( &$this, 'verified_connection' ), 10, 2 );

		//
		add_filter( 'cron_schedules', array(&$this, 'cron' ));


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

		// Make sure we have a scheduled job to handle auto-imports if enabled
		if ( $this->get_option( 'auto_import' ) && !wp_get_schedule( $this->schedule ) )
			wp_schedule_event( time(), static::SCHEDULE, $this->schedule );


		$this->handle_request();
	}

	function cron ( $schedules ) {
		if (!isset($schedules[ $this->optname ])) {
			$schedules[ $this->optname ] = array(
				'interval' => static::RESCHEDULE,
				'display' => sprintf(__( '%s auto import' ), static::SLUG )
			);
		}
		return $schedules;
	}

	/**
	 * Accept the form submission of the Options page and handle all of the values there.
	 * You'll need to validate/santize things, and probably store options in the DB. When you're
	 * done, set $this->step = 'import' to continue, or 'options' to show the options form again.
	 */
	abstract function handle_request_options();

	/**
	 * Based on whatever you need, create the URL for the next request to the remote Service.
	 * Most likely this will need to grab options from the DB.
	 * @return String containing the URL
	 */
	abstract function make_all_requests( $method, $post );

	/**
	 * Singleton mode on
	 */
	static function &init() {
		static $instance = false;

		if ( !$instance ) {
			$class = get_called_class();
			$instance = new $class;
		}

		return $instance;
	}

	/**
	 * Warn the user that they need Keyring installed and activated.
	 */
	function require_keyring() {
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
	function get_option( $name, $default = false ) {
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
	function set_option( $name, $val = null ) {
		if ( is_array( $name ) )
			$this->options = array_merge( (array) $this->options, $name );
		else if ( is_null( $name ) && is_null( $val ) ) // $name = null to reset all options
			$this->options = array();
		else if ( is_null( $val ) && isset( $this->options[ $name ] ) )
			unset( $this->options[ $name ] );
		else
			$this->options[ $name ] = $val;

		return update_option( $this->optname, $this->options );
	}

	/**
	 * Reset all options for this importer
	 */
	function reset() {
		$this->set_option( null );
	}

	/**
	 * Early handling/validation etc of requests within the importer. This is hooked in early
	 * enough to allow for redirecting the user if necessary.
	 */
	function handle_request() {
		// Only interested in POST requests and specific GETs
		if ( empty( $_GET['import'] ) || static::SLUG != $_GET['import'] )
			return;

		// Heading to a specific step of the importer
		if ( !empty( $_REQUEST['step'] ) && ctype_alpha( $_REQUEST['step'] ) )
			$this->step = (string) $_REQUEST['step'];

		switch ( $this->step ) {
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
					'auto_import'           => null,
					'auto_approve'          => null,
					static::OPTNAME_LOG     => '',
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
			if ( isset( $_POST['refresh'] ) ) {
				$this->auto_import = true;
			}

			// Write a custom request handler in the extending class here
			// to handle processing/storing options for import. Make sure to
			// end it with $this->step = 'import' (if you're ready to continue)
			$this->handle_request_options();

			break;
		}
	}

	/**
	 * Decide which UI to display to the user, kind of a second-stage of handle_request().
	 */
	function dispatch() {
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
	function error( $str ) {
		$this->errors[] = $str;
	}

	/**
	 * A default, basic header for the importer UI
	 */
	function header() {
		?>
		<style type="text/css">
			.keyring-importer ul,
			.keyring-importer ol { margin: 1em 2em; }
			.keyring-importer li { list-style-type: square; }
		</style>
		<div class="wrap keyring-importer">
		<?php screen_icon(); ?>
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
	function footer() {
		echo '</div>';
	}

	/**
	 * The first screen the user sees in the import process. Summarizes the process and allows
	 * them to either select an existing Keyring token or start the process of creating a new one.
	 * Also makes sure they have the correct service available, and that it's configured correctly.
	 */
	function greet() {
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
	function verified_connection( $service, $id ) {
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
	function options() {
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
				<input type="submit" name="submit" class="button-primary" id="options-submit" value="<?php _e( 'Import', 'keyring' ); ?>" />
				<input type="submit" name="reset" value="<?php _e( 'Reset Importer', 'keyring' ); ?>" id="reset" class="button" />
			</p>
		</form>

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
		<?php

		$this->footer();
	}

	/**
	 * Handle a cron request to import "the latest" content for this importer. Should
	 * rely solely on database state of some sort, since nothing is passed in. Make
	 * sure to also update anything in the DB required for the next run. If you set up your
	 * other methods "discretely" enough, you might not need to override this.
	 */
	function do_auto_import() {
		defined( 'WP_IMPORTING' ) or define( 'WP_IMPORTING', true );
		do_action( 'import_start' );
		set_time_limit( 0 );

		// In case auto-import has been disabled, clear all jobs and bail
		if ( !$this->get_option( 'auto_import' ) ) {
			wp_clear_scheduled_hook( 'keyring_' . static::SLUG . '_import_auto' );
			return;
		}
		// Need a token to do anything with this
		if ( !$this->service->get_token() )
			return;

		require_once ABSPATH . 'wp-admin/includes/import.php';
		require_once ABSPATH . 'wp-admin/includes/post.php';
		require_once ABSPATH . 'wp-admin/includes/comment.php';

		$this->auto_import = true;
		$next = 0;
		$position = 0;

		$this->get_posts();

		if ( !$this->finished ) {

			$position = $this->get_option('post_todo', 0);
			if ( !is_array($this->posts) || !isset($this->posts[$position]) )
				return new Keyring_Error(
					'keyring-reactions-post-not-set',
					__( 'The post to work with does not exist in the posts array. Something is definitely wrong.', 'keyring' )
				);

			$todo = $this->posts[$position];
			$msg = sprintf(__('Starting auto import for #%s', 'keyring'), $todo['post_id']);
			Keyring_Util::debug($msg);

			foreach ( $this->methods as $method => $type ) {
				$msg = sprintf(__('Processing %s for post #%s', 'keyring'), $method, $todo['post_id']);
				Keyring_Util::debug($msg);

				$result = $this->make_request( $method, $todo );

				if ( Keyring_Util::is_error( $result ) )
					print $result;
			}

			$next = $position+1;

			// we're done, clean up
			if ($next >= sizeof($this->posts)) {
				$this->finished = true;
				$this->cleanup();
				Keyring_Util::debug( static::SLUG . ' auto import finished' );

				// set the original, daily schedule back for tomorrow from now on
				wp_reschedule_event( time() + static::SCHEDULETIME, static::SCHEDULE , $this->schedule );
			}
			else {
				$this->set_option('post_todo', $next );
				Keyring_Util::debug( static::SLUG . ' auto import: there is more coming' );

				// hack the planet: the next run of this very job should be
				// near immediate, otherwise we'd never finish with all the post;
				// event this way a few thousand posts will result in issues
				// for sure, so there has to be something
				wp_reschedule_event( time() + static::RESCHEDULE , $this->optname,  $this->schedule );
			}
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
	 * Grab a chunk of data from the remote service and process it into comments, and handle actually importing as well.
	 * Keeps track of 'state' in the DB.
	 */
	function import() {
		$this->set_option('log', array());
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
			$position = $this->get_option('post_todo', 0);

			if ( !is_array($this->posts) || !isset($this->posts[$position]) )
				return new Keyring_Error(
					'keyring-reactions-post-not-set',
					__( 'The post to work with does not exist in the posts array. Something is definitely wrong.', 'keyring' )
				);

			$todo = $this->posts[$position];

			foreach ( $this->methods as $method => $type ) {
				$msg = sprintf(__('Processing %s for post <strong>#%s</strong><br />', 'keyring'), $method, $todo['post_id']);
				Keyring_Util::debug($msg);
				echo $msg;
				$result = $this->make_request( $method, $todo );

				if ( Keyring_Util::is_error( $result ) )
					print $result;
			}

			echo "</p>";
			$next = $position+1;
			if ($next >= sizeof($this->posts)) {
				$this->finished = true;
				break;
			}

			$this->set_option('post_todo', $next );
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
	 * Super-basic implementation of making the (probably) authorized request. You can (should)
	 * override this with something more substantial and suitable for the service you're working with.
	 * @return Keyring request response -- either a Keyring_Error or a Service-specific data structure (probably object or array)
	 */
	function make_request( $method, $post ) {
		return $this->make_all_requests( $method, $post );
		//$url = $this->build_request_url( $method, $post );
		//return $this->service->request( $url, array( 'method' => $this->request_method, 'timeout' => 10 ) );
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
		echo '<p>' . sprintf( __( 'Import log: %s', 'keyring' ), join("\n",$this->get_option( 'log' )) )  . '</p>';
		//echo '<p>' . sprintf( __( 'Imported a total of %s posts.', 'keyring' ), number_format( $this->get_option( 'imported' ) ) ) . '</p>';
		//echo '<h3>' . sprintf( __( 'All done. <a href="%2$s">Check out all your new comments</a>.', 'keyring' ), admin_url( 'comments.php' ) ) . '</h3>';
		$this->footer();
		$this->cleanup();
		//do_action( 'import_done', $this->optname );
		//do_action( 'keyring_import_done', $this->optname );
	}

	/**
	 * reset internal variables
	 */
	function cleanup() {
		$this->set_option( 'log', array() );
		$this->set_option( 'posts', array() );
		$this->set_option( 'post_todo', 0 );
	}

	/**
	 * gets all posts with their syndicated url matching self::SILONAME
	 *
	 */
	function get_posts ( ) {
		$posts = $this->get_option('posts');

		// we are in the middle of a run
		if (!empty($posts)) {
			$this->posts = $posts;
			return true;
		}

		$args = array (
			'meta_key' => 'syndication_urls',
			'post_type' => 'any',
			'posts_per_page' => -1,
			'post_status' => 'publish',
		);
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
							//'comment_hashes' => $hashes,
						);
					}
				}
			}
		}

		$this->posts = $posts;
		$this->set_option('posts', $posts);
		return true;
	}

	/**
	 * this is to keep it DRY
	 *
	 */
	function insert_comment ( &$post_id, &$comment, &$raw, &$avatar = '' ) {
		$post = get_post ($post_id);

		//test if we already have this imported
		$args = array(
			'author_email' => $comment['comment_author_email'],
			'post_id' => $post_id,
		);

		Keyring_Util::debug(sprintf(__('checking comment existence for %s for post #%s','keyring'), $comment['comment_author_email'], $post_id));
		// so if the type is comment and you add type = 'comment'
		// WP will not return the comments
		// WordPress, such logical!
		if ( $comment['comment_type'] != 'comment')
			$args['type'] = $comment['comment_type'];

		$existing = get_comments($args);

		if (empty($existing)) {
			Keyring_Util::debug(sprintf(__('inserting comment for post #%s','keyring'), $post_id));
			// add comment
			if ( $comment_id = wp_insert_comment($comment) ) {
				// add avatar for later use if present
				if (!empty($avatar)) {
					update_comment_meta( $comment_id, 'avatar', $avatar );
				}

				// full raw response for the vote, just in case
				update_comment_meta( $comment_id, $this->optname, $raw );

				// info
				$r = sprintf (__("New %s #%s from %s imported from %s for post %s", 'keyring'), $comment['comment_type'], $comment_id, $comment['comment_author'], self::SILONAME, $post->post_title );
			}
		}
		else {
				// info
				$r = sprintf (__("Already exists: %s from %s for %s", 'keyring'), $comment['comment_type'], $comment['comment_author'], $post->post_title );
		}

		Keyring_Util::debug($r);

		return true;
	}

	/**
	 * syslog log message
	 *
	 * @param string $identifier process identifier, falls back to FILE is empty
	 * @param string $message message to add besides basic info, falls back to LINE if empty
	 * @param int $log_level [optional] Level of log, info by default
	 *
	 */
	static public function syslog ( $message = __LINE__ , $log_level = LOG_INFO ) {

		if ( function_exists( 'syslog' ) && function_exists ( 'openlog' ) ) {
			if ( @is_array( $message ) || @is_object ( $message ) )
				$message = json_encode($message);

			$message = strip_tags ( $message );

			switch ( $log_level ) {
				case LOG_ERR :
					openlog('wordpress('.$_SERVER['HTTP_HOST'].')',LOG_NDELAY|LOG_PERROR,LOG_SYSLOG);
					break;
				default:
					openlog('wordpress(' .$_SERVER['HTTP_HOST']. ')', LOG_NDELAY,LOG_SYSLOG);
					break;
			}

			syslog( $log_level , ' Keyring Reactions Importer: ' . $message );
		}
	}

}

function keyring_register_reactions( $slug, $class, $plugin, $info = false ) {
	global $_keyring_reactions;
	//$slug = preg_replace( '/[^a-z_]/', '', $slug );
	$_keyring_reactions[$slug] = call_user_func( array( $class, 'init' ) );
	if ( !$info )
		$info = __( 'Import reactions from %s and save them as Comments within WordPress.', 'keyring' );

	$name = $class::LABEL;

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


?>
