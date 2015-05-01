<?php

// a horrible hack borrowed from Beau Lebens
function Keyring_Flickr_Reactions() {

class Keyring_Flickr_Reactions extends Keyring_Reactions_Base {
	const SLUG			  = 'flickr_reactions';
	const LABEL			 = 'Flickr - comments and favorites';
	const KEYRING_SERVICE   = 'Keyring_Service_Flickr';
	const KEYRING_NAME	  = 'flickr';
	const REQUESTS_PER_LOAD = 3;	 // How many remote requests should be made before reloading the page?
	const NUM_PER_REQUEST   = 50;	 // Number of images per request to ask for

	const SILONAME		   = 'flickr.com';

	function __construct() {
		$this->methods = array (
			// method name => comment type
			'favs'  => 'favorite',
			'comments' => 'comment'
		);

		//$this->methods = array ('votes', 'favs', 'comments');
		parent::__construct();
	}

	function make_all_requests( $method, $post ) {
		extract($post);

		if (empty($post_id))
			return new Keyring_Error(
				'keyring-flickr-reactions-missing-post-id',
				__( 'Missing post ID to make request for.', 'keyring')
			);

		if (empty($syndication_url))
			return new Keyring_Error(
				'keyring-flickr-reactions-missing-syndication-url',
				__( 'Missing syndication URL.', 'keyring')
			);

		$silo_id = trim(end((explode('/', rtrim($syndication_url, '/')))));
		if (empty($silo_id))
			return new Keyring_Error(
				'keyring-flickr-reactions-photo-id-not-found',
				__( 'Cannot get photo ID out of syndication URL.', 'keyring' )
			);

		$func = 'get_' . $method;
		if ( !method_exists( $this, $func ) )
			return new Keyring_Error(
				'keyring-flickr-reactions-missing-func',
				sprintf(__( 'Function is missing for this method (%s), cannot proceed!', 'keyring'), $method)
			);

		return $this->$func ( $post_id, $silo_id );
	}

	/**
	 * FAVS
	 */

	function get_favs ( $post_id, $silo_id ) {

		$results = $this->query_favs( $silo_id );

		if ($results && is_array($results) && !empty($results)) {

			$auto = ( $this->get_option( 'auto_approve' ) == 1 ) ? 1 : 0;
			$type = $this->methods[ 'favs' ];
			$tpl = __( '<a href="%s" rel="nofollow">%s</a> added this photo to their favorites on <a href="https://flickr.com" rel="nofollow">Flickr.com</a>','keyring');

			foreach ( $results as $element ) {

				$name = empty($element->realname) ? $element->username : $element->realname;
				// flickr is a bastard putting @ in the username...
				$email =  str_replace('@', '+', $element->nsid) .'@'. self::SILONAME;
				//	'http://static.flickr.com/'. $author['iconserver'] .'/buddyicons/'. $author['id'] .'.jpg';
				$avatar = '';
				if ( isset( $element->iconserver ) && $element->iconserver > 0 )
					$avatar = sprintf('http://static.flickr.com/%s/buddyicons/%s.jpg', $element->iconserver, $element->nsid );

				$author_url = 'https://www.flickr.com/people/' . $element->nsid;

				$c = array (
					'comment_author'		=> $name,
					'comment_author_url'	=> $author_url,
					'comment_author_email'  => $email,
					'comment_post_ID'	   => $post_id,
					'comment_type'		  => $type,
					'comment_date'		  => date("Y-m-d H:i:s", $element->favedate ),
					'comment_date_gmt'	  => date("Y-m-d H:i:s", $element->favedate ),
					'comment_agent'		 => get_class($this),
					'comment_approved'	  => $auto,
					'comment_content'	   => sprintf( $tpl, $author_url, $name ),
				);

				$this->insert_comment ( $post_id, $c, $element, $avatar);
			}
		}
	}

	/**
	 *
	 */
	function query_favs ( $silo_id ) {

		$page = 1;
		$finished = false;
		$res = array();

		$baseurl = "https://api.flickr.com/services/rest/?";

		while (!$finished) {
			$params = array(
				'method'		 => 'flickr.photos.getFavorites',
				'api_key'		=> $this->service->key,
				'photo_id'	   => $silo_id,
				'per_page'	   => self::NUM_PER_REQUEST,
				'page'		   => $page,
			);

			$url = $baseurl . http_build_query( $params );
			$data = $this->service->request( $url, array( 'method' => $this->request_method, 'timeout' => 10 ) );

			if ( Keyring_Util::is_error( $data ) )
				print $data;

			if (!empty($data->photo->person))
				foreach ($data->photo->person as $element )
					$res[] = $element;

			// jump to the next page or finish
			if ( ceil($data->photo->total / self::NUM_PER_REQUEST) > $page )
				$page += 1;
			else
				$finished = true;
		}

		return $res;
	}

	/**
	 * COMMENTS
	 */
	function get_comments ( $post_id, $silo_id ) {
		$results = $this->query_comments( $silo_id );
		if ($results && is_array($results) && !empty($results)) {

			$auto = ( $this->get_option( 'auto_approve' ) == 1 ) ? 1 : 0;
			$type = $this->methods[ 'comments' ];

			foreach ( $results as $element ) {

				$name = empty($element->realname) ? $element->authorname : $element->realname;
				// flickr is a bastard putting @ in the username...
				$email =  str_replace('@', '+', $element->author) .'@'. self::SILONAME;
				//	'http://static.flickr.com/'. $author['iconserver'] .'/buddyicons/'. $author['id'] .'.jpg';
				$avatar = '';
				if ( isset( $element->iconserver ) && $element->iconserver > 0 )
					$avatar = sprintf('http://static.flickr.com/%s/buddyicons/%s.jpg', $element->iconserver, $element->nsid );

				$author_url = 'https://www.flickr.com/people/' . $element->author;

				$c = array (
					'comment_author'		=> $name,
					'comment_author_url'	=> $author_url,
					'comment_author_email'  => $email,
					'comment_post_ID'	   => $post_id,
					'comment_type'		  => $type,
					'comment_date'		  => date("Y-m-d H:i:s", $element->datecreate ),
					'comment_date_gmt'	  => date("Y-m-d H:i:s", $element->datecreate ),
					'comment_agent'		 => get_class($this),
					'comment_approved'	  => $auto,
					'comment_content'	   => $element->_content
				);

				if ( $comment_id = $this->insert_comment ( $post_id, $c, $element, $avatar))
					if (isset($element->permalink))
						update_comment_meta( $comment_id, 'permalink', $element->permalink );
			}
		}
	}

	/**
	 *
	 */
	function query_comments ( $silo_id ) {
		$res = array();

		$baseurl = "https://api.flickr.com/services/rest/?";

		// Flickr does not seem to support paged comment requests; easier for me...
		// https://www.flickr.com/services/api/flickr.photos.comments.getList.htm
		$params = array(
			'method'		 => 'flickr.photos.comments.getList',
			'api_key'		=> $this->service->key,
			'photo_id'	   => $silo_id,
		);

		$url = $baseurl . http_build_query( $params );
		$data = $this->service->request( $url, array( 'method' => $this->request_method, 'timeout' => 10 ) );

		if ( Keyring_Util::is_error( $data ) )
			print $data;

		if (!empty($data->comments->comment))
			foreach ($data->comments->comment as $element )
				$res[] = $element;

		return $res;
	}

}}


add_action( 'init', function() {
	Keyring_Flickr_Reactions(); // Load the class code from above
	keyring_register_reactions(
		Keyring_Flickr_Reactions::SLUG,
		'Keyring_Flickr_Reactions',
		plugin_basename( __FILE__ ),
		__( 'Import comments and favorites from Flickr for your syndicated posts.', 'keyring' )
	);
} );
