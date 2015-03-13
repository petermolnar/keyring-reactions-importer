<?php

// a horrible hack borrowed from Beau Lebens
function Keyring_Facebook_Reactions() {

class Keyring_Facebook_Reactions extends Keyring_Reactions_Base {
	const SLUG              = 'facebook_reactions';
	const LABEL             = 'Facebook - comments, likes and re-shares';
	const KEYRING_SERVICE   = 'Keyring_Service_Facebook';
	const KEYRING_NAME      = 'facebook';
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?
	const NUM_PER_REQUEST   = 100;     // Number of images per request to ask for

	const SILONAME          = 'facebook.com';

	const GRAPHAPI          = '2.2';

	function __construct() {
		$this->methods = array (
			// method name => comment type
			'likes'  => 'like',
			'comments' => 'comment',
			//'shares' => 'share'
		);

		//$this->methods = array ('votes', 'favs', 'comments');
		parent::__construct();
	}

	/**
	 * Accept the form submission of the Options page and handle all of the values there.
	 * You'll need to validate/santize things, and probably store options in the DB. When you're
	 * done, set $this->step = 'import' to continue, or 'options' to show the options form again.
	 */
 	function handle_request_options() {
		$bools = array('auto_import','auto_approve');

		foreach ( $bools as $bool ) {
			if ( isset( $_POST[$bool] ) )
				$_POST[$bool] = true;
			else
				$_POST[$bool] = false;
		}

		// If there were errors, output them, otherwise store options and start importing
		if ( count( $this->errors ) ) {
			$this->step = 'greet';
		} else {
			$this->set_option( array(
				'auto_import'     => (bool) $_POST['auto_import'],
				'auto_approve'    => (bool) $_POST['auto_approve'],
			) );

			$this->step = 'import';
		}
	}


	function make_all_requests( $method, $post ) {
		extract($post);

		if (empty($post_id))
			return new Keyring_Error(
				'keyring-facebook-reactions-missing-post-id',
				__( 'Missing post ID to make request for.', 'keyring')
			);

		if (empty($syndication_url))
			return new Keyring_Error(
				'keyring-facebook-reactions-missing-syndication-url',
				__( 'Missing syndication URL.', 'keyring')
			);

		$photo_id = trim(end((explode('/', rtrim($syndication_url, '/')))));
		if (empty($photo_id))
			return new Keyring_Error(
				'keyring-facebook-reactions-photo-id-not-found',
				__( 'Cannot get photo ID out of syndication URL.', 'keyring' )
			);

		$func = 'get_' . $method;
		if ( !method_exists( $this, $func ) )
			return new Keyring_Error(
				'keyring-facebook-reactions-missing-func',
				sprintf(__( 'Function is missing for this method (%s), cannot proceed!', 'keyring'), $method)
			);

		return $this->$func ( $post_id, $photo_id );
	}

	/**
	 * FAVS
	 */

	function get_likes ( $post_id, $fb_id ) {
		$res = array();
		$baseurl = sprintf( "https://graph.facebook.com/v%s/%s/likes?", static::GRAPHAPI, $fb_id );

		$params = array(
			'access_token'   => $this->service->token->token,
			'limit'          => static::NUM_PER_REQUEST,
		);

		$starturl = $baseurl . http_build_query( $params );
		$results = $this->query($starturl);

		if ($results && is_array($results) && !empty($results)) {

			$auto = ( $this->get_option( 'auto_approve' ) == 1 ) ? 1 : 0;
			$type = $this->methods[ 'likes' ];

			foreach ( $results as $element ) {

				$avatar = sprintf('https://graph.facebook.com/%s/picture', $element->id );
				$author_url = 'https://facebook.com/' . $element->id;
				$name = $element->name;
				$tpl = __( '<a href="%s" rel="nofollow">%s</a> liked this entry on <a href="https://facebook.com" rel="nofollow">facebook</a>','keyring');

				$c = array (
					'comment_author'        => $name,
					'comment_author_url'    => $author_url,
					'comment_author_email'  => $element->id . '@' . static::SILONAME,
					'comment_post_ID'       => $post_id,
					'comment_type'          => $type,
					// DON'T set the date unless it's provided - not with favs & votes
					//'comment_date'          => date("Y-m-d H:i:s"),
					//'comment_date_gmt'      => date("Y-m-d H:i:s"),
					'comment_agent'         => get_class($this),
					'comment_approved'      => $auto,
					'comment_content'       => sprintf( $tpl, $author_url, $name ),
				);

				$this->insert_comment($post_id, $c, $element, $avatar);
			}
		}

	}


	/**
	 * comments
	 */

	function get_comments ( $post_id, $fb_id ) {
		$res = array();
		$baseurl = sprintf( "https://graph.facebook.com/v%s/%s/comments?", static::GRAPHAPI, $fb_id );

		$params = array(
			'access_token'   => $this->service->token->token,
			'limit'          => static::NUM_PER_REQUEST,
		);

		$starturl = $baseurl . http_build_query( $params );
		$results = $this->query($starturl);

		if ($results && is_array($results) && !empty($results)) {

			$auto = ( $this->get_option( 'auto_approve' ) == 1 ) ? 1 : 0;
			$type = $this->methods[ 'comments' ];

			foreach ( $results as $element ) {
				$ctime = strtotime($element->created_time);
				$author_url = 'https://facebook.com/' . $element->from->id;
				$name = $element->from->name;
				$avatar = sprintf('https://graph.facebook.com/%s/picture/?width=%s&height=%s', $element->from->id, get_option( 'thumbnail_size_w' ), get_option( 'thumbnail_size_h' ));

				$message = $element->message;
				if ( isset($element->message_tags) && !empty($element->message_tags) && is_array($element->message_tags) ) {
					foreach ( $element->message_tags as $tag ) {
						$message = str_replace( $tag->name, sprintf('<a href="https://facebook.com/%s">%s</a>' , $tag->id, $tag->name), $message);
					}
				}

				$c = array (
					'comment_author'        => $name,
					'comment_author_url'    => $author_url,
					'comment_author_email'  => $element->from->id . '@' . static::SILONAME,
					'comment_post_ID'       => $post_id,
					'comment_type'          => $type,
					'comment_date'          => date("Y-m-d H:i:s", $ctime),
					'comment_date_gmt'      => date("Y-m-d H:i:s", $ctime),
					'comment_agent'         => get_class($this),
					'comment_approved'      => $auto,
					'comment_content'       => $message,
				);

				$this->insert_comment($post_id, $c, $element, $avatar);
			}
		}
	}

	/**
	 *
	 */
	function query ( $starturl ) {

		$nexurl = false;
		$finished = false;
		$res = array();

		if (empty($starturl))
			return false;

		while (!$finished) {

			if (empty($nexturl) or !filter_var($nexturl, FILTER_VALIDATE_URL) ) {
				$url = $starturl;
			}
			else {
				$url = $nexturl;
			}

			$data = $this->service->request( $url, array( 'method' => $this->request_method, 'timeout' => 10 ) );

			if ( Keyring_Util::is_error( $data ) )
				return ($data);

			if (!empty($data->data))
				foreach ($data->data as $element )
					$res[] = $element;

			// jump to the next url or finish
			if (isset($data->paging->next) && filter_var($data->paging->next, FILTER_VALIDATE_URL) )
				$nexturl = $data->paging->next;
			else
				$finished = true;
		}

		return $res;
	}


}}


add_action( 'init', function() {
	Keyring_Facebook_Reactions(); // Load the class code from above
	keyring_register_reactions(
		Keyring_Facebook_Reactions::SLUG,
		'Keyring_Facebook_Reactions',
		plugin_basename( __FILE__ ),
		__( 'Import comments, likes and re-shares from Facebook as comments for your syndicated posts.', 'keyring' )
	);
} );
