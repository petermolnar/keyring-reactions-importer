<?php

function Keyring_Instagram_Reactions() {

class Keyring_Instagram_Reactions extends Keyring_Reactions_Base {
	const SLUG              = 'instagram_reactions';
	const LABEL             = 'Instagram - comments and likes';
	const KEYRING_SERVICE   = 'Keyring_Service_Instagram';
	const KEYRING_NAME      = 'instagram';
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?
	const NUM_PER_REQUEST   = 50;     // Number of images per request to ask for

	const SILONAME           = 'instagram.com';

	function __construct() {
		$this->methods = array (
			// method name => comment type
			'likes'  => 'like',
			'comments' => 'comment'
		);

		//$this->methods = array ('votes', 'favs', 'comments');
		parent::__construct();
	}

	function make_all_requests( $method, $post ) {
		extract($post);

		if (empty($post_id))
			return new Keyring_Error(
				'keyring-instagram-reactions-missing-post-id',
				__( 'Missing post ID to make request for.', 'keyring')
			);

		if (empty($syndication_url))
			return new Keyring_Error(
				'keyring-instagram-reactions-missing-syndication-url',
				__( 'Missing syndication URL.', 'keyring')
			);

		// get media id here
		$api = file_get_contents(sprintf('https://api.instagram.com/oembed?url=%s', $syndication_url));
		$apiObj = json_decode($api,true);
		if ( !isset($apiObj['media_id']) || empty($apiObj['media_id']))
			return new Keyring_Error(
				'keyring-instagram-reactions-photo-id-not-found',
				__( 'Cannot get photo ID out of syndication URL.', 'keyring' )
			);

		$silo_id = $apiObj['media_id'];

		$func = 'get_' . $method;
		if ( !method_exists( $this, $func ) )
			return new Keyring_Error(
				'keyring-instagram-reactions-missing-func',
				sprintf(__( 'Function is missing for this method (%s), cannot proceed!', 'keyring'), $method)
			);

		return $this->$func ( $post_id, $silo_id );
	}

	/**
	 * LIKES
	 */

	function get_likes ( $post_id, $silo_id ) {
		$baseurl = sprintf ('https://api.instagram.com/v1/media/%s/likes?', $silo_id );

		$params = array(
			'access_token'   => $this->service->token->token,
		);

		$url = $baseurl . http_build_query( $params );
		$data = $this->service->request( $url, array( 'method' => $this->request_method, 'timeout' => 10 ) );

		if ( Keyring_Util::is_error( $data ) )
			Keyring_Util::Debug (json_encode($data));

		if ($data->data && is_array($data->data) && !empty($data->data)) {

			$auto = ( $this->get_option( 'auto_approve' ) == 1 ) ? 1 : 0;
			$type = $this->methods[ 'likes' ];
			$tpl = __( '<a href="%s" rel="nofollow">%s</a> liked this entry on <a href="https://instagram.com" rel="nofollow">instagram</a>','keyring');

			foreach ( $data->data as $element ) {

				$name = empty($element->full_name) ? $element->username : $element->full_name;
				$email =  $element->username .'@'. self::SILONAME;
				$avatar = empty ($element->profile_picture) ? '' : $element->profile_picture;
				$author_url = 'https://instagram.com/' . $element->username;

				$c = array (
					'comment_author'        => $name,
					'comment_author_url'    => $author_url,
					'comment_author_email'  => $email,
					'comment_post_ID'       => $post_id,
					'comment_type'          => $type,
					//'comment_date'          => date("Y-m-d H:i:s", $element->favedate ),
					//'comment_date_gmt'      => date("Y-m-d H:i:s", $element->favedate ),
					'comment_agent'         => get_class($this),
					'comment_approved'      => $auto,
					'comment_content'       => sprintf( $tpl, $author_url, $name ),
				);

				$this->insert_comment ( $post_id, $c, $element, $avatar);
			}
		}
	}

	/**
	 * COMMENTS
	 */

	function get_comments ( $post_id, $silo_id ) {
		$baseurl = sprintf ('https://api.instagram.com/v1/media/%s/comments?', $silo_id );

		$params = array(
			'access_token'   => $this->service->token->token,
		);

		$url = $baseurl . http_build_query( $params );
		$data = $this->service->request( $url, array( 'method' => $this->request_method, 'timeout' => 10 ) );

		if ( Keyring_Util::is_error( $data ) )
			Keyring_Util::Debug (json_encode($data));


		if ($data->data && is_array($data->data) && !empty($data->data)) {

			$auto = ( $this->get_option( 'auto_approve' ) == 1 ) ? 1 : 0;
			$type = $this->methods[ 'comments' ];

			foreach ( $data->data as $element ) {

				$name = empty($element->from->full_name) ? $element->from->username : $element->from->full_name;
				$email =  $element->from->username .'@'. self::SILONAME;
				$avatar = empty ($element->from->profile_picture) ? '' : $element->from->profile_picture;
				$author_url = 'https://instagram.com/' . $element->from->username;

				$c = array (
					'comment_author'        => $name,
					'comment_author_url'    => $author_url,
					'comment_author_email'  => $email,
					'comment_post_ID'       => $post_id,
					'comment_type'          => $type,
					'comment_date'          => date("Y-m-d H:i:s", $element->created_time ),
					'comment_date_gmt'      => date("Y-m-d H:i:s", $element->created_time ),
					'comment_agent'         => get_class($this),
					'comment_approved'      => $auto,
					'comment_content'       => $element->text
				);

				$this->insert_comment ( $post_id, $c, $element, $avatar);
			}
		}
	}

}}


add_action( 'init', function() {
	Keyring_Instagram_Reactions(); // Load the class code from above
	keyring_register_reactions(
		Keyring_Instagram_Reactions::SLUG,
		'Keyring_Instagram_Reactions',
		plugin_basename( __FILE__ ),
		__( 'Import comments and likes from Instagram for your syndicated posts.', 'keyring' )
	);
} );
