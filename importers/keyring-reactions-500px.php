<?php

// a horrible hack borrowed from Beau Lebens
function Keyring_500px_Reactions() {

class Keyring_500px_Reactions extends Keyring_Reactions_Base {
	const SLUG              = 'fivehpx_reactions';
	const LABEL             = '500px - likes, favorites, comments';
	const KEYRING_SERVICE   = 'Keyring_Service_500px';
	const KEYRING_NAME      = '500px';
	const REQUESTS_PER_LOAD = 3;     // How many remote requests should be made before reloading the page?
	const NUM_PER_REQUEST   = 100;     // Number of images per request to ask for

	const SILONAME           = '500px.com';
	const PHOTOPATTERN       = '/https:\/\/500px\.com\/photo\/([0-9]+)[\/]?(.*)/';

	function __construct() {
		$this->methods = array (
			// method name => comment type
			'votes' => 'like',
			'favs'  => 'favorite',
			'comments' => 'comment'
		);

		//$this->methods = array ('votes', 'favs', 'comments');
		parent::__construct();
	}

	/**
	 * implementation for all the requests for one method of one post
	 *
	 * the reason why this is here and not in the base class is that getting the
	 * id out of the syndication link my be pretty tricky and be silo specific
	 */
	function make_all_requests( $method, $post ) {
		extract($post);

		if (empty($post_id))
			return new Keyring_Error(
				'keyring-500px-reactions-missing-post-id',
				__( 'Missing post ID to make request for.', 'keyring')
			);

		if (empty($syndication_url))
			return new Keyring_Error(
				'keyring-500px-reactions-missing-syndication-url',
				__( 'Missing syndication URL.', 'keyring')
			);

		$matches = array();
		$match = preg_match ( static::PHOTOPATTERN, $syndication_url, $matches );
		if ( !$match || empty($matches) || !isset($matches[1]) || empty($matches[1])) {
			return new Keyring_Error(
				'keyring-500px-reactions-photo-id-not-found',
				__( 'Cannot get photo ID out of syndication URL.', 'keyring' )
			);
		}

		$silo_id = $matches[1];

		$func = 'get_' . $method;
		if ( !method_exists( $this, $func ) )
			return new Keyring_Error(
				'keyring-500px-reactions-missing-func',
				sprintf(__( 'Function is missing for this method (%s), cannot proceed!', 'keyring'), $method)
			);

		return $this->$func ( $post_id, $silo_id );
	}


	/**
	 * VOTES (LIKES)
	 *
	 */
	function get_votes ( &$post_id, &$silo_id ) {
		$baseurl = sprintf("https://api.500px.com/v1/photos/%s/votes", $silo_id);

		$res = $this->request ( $baseurl, 'users' );
		$tpl = __( '<a href="%s" rel="nofollow">%s</a> liked this photo on <a href="https://500px.com" rel="nofollow">500px.com</a>','keyring');

		$this->parser_fav_vote ( $post_id, $res, 'votes', $tpl );

		return true;
	}

	/**
	 * FAVS
	 */
	function get_favs ( &$post_id, &$silo_id ) {
		$baseurl = sprintf("https://api.500px.com/v1/photos/%s/favorites", $silo_id);
		$res = $this->request ( $baseurl, 'users' );

		$tpl = __( '<a href="%s" rel="nofollow">%s</a> added this photo to their favorites on <a href="https://500px.com" rel="nofollow">500px.com</a>','keyring');

		$this->parser_fav_vote ( $post_id, $res, 'favs', $tpl );

		return true;
	}

	/**
	 * common parser for fav & vote, since they are nearly the same
	 */
	function parser_fav_vote ( &$post_id, &$results, $method, &$content_template ) {

		if ($results && is_array($results) && !empty($results)) {

			$auto = ( $this->get_option( 'auto_approve' ) == 1 ) ? 1 : 0;
			$type = $this->methods[ $method ];

			foreach ( $results as $element ) {

				$name = empty($element->fullname) ? $element->username : $element->fullname;
				$email = $element->id .'@'. self::SILONAME;
				$avatar = $element->userpic_https_url;
				$author_url =  'https://500px.com/' . $element->username;
				$avatar = empty($element->userpic_https_url) ? '' : $element->userpic_https_url;

				$c = array (
					'comment_author'        => $name,
					'comment_author_url'    => $author_url,
					'comment_author_email'  => $email,
					'comment_post_ID'       => $post_id,
					'comment_type'          => $type,
					// DON'T set the date unless it's provided - not with favs & votes
					//'comment_date'          => date("Y-m-d H:i:s"),
					//'comment_date_gmt'      => date("Y-m-d H:i:s"),
					'comment_agent'         => get_class($this),
					'comment_approved'      => $auto,
					'comment_content'       => sprintf( $content_template, $author_url, $name ),
				);

				$this->insert_comment ( $post_id, $c, $element, $avatar);

			}
		}
	}

	/**
	 * COMMENTS
	 */
	function get_comments ( &$post_id, &$silo_id ) {
		$baseurl = sprintf("https://api.500px.com/v1/photos/%s/comments", $silo_id);
		$results = $this->request ( $baseurl, 'comments' );
		if ($results && is_array($results) && !empty($results)) {
			$auto = ( $this->get_option( 'auto_approve' ) == 1 ) ? 1 : 0;
			$type = $this->methods[ 'comments' ];

			foreach ( $results as $element ) {
				$name = empty($element->user->fullname) ? $element->user->username : $element->user->fullname;
				$content = $element->body;
				$email = $element->user->id .'@'. self::SILONAME;
				$avatar = $element->user->userpic_https_url;
				$author_url =  'https://500px.com/' . $element->user->username;
				$date = empty( $element->created_at ) ? date("Y-m-d H:i:s") : date("Y-m-d H:i:s", strtotime($element->created_at));
				$avatar = empty($element->user->userpic_https_url) ? '' : $element->user->userpic_https_url;

				$c = array (
					'comment_author'        => $name,
					'comment_author_url'    => $author_url,
					'comment_author_email'  => $email,
					'comment_post_ID'       => $post_id,
					'comment_type'          => $type,
					'comment_date'          => $date,
					'comment_date_gmt'      => $date,
					'comment_agent'         => get_class($this),
					'comment_approved'      => $auto,
					'comment_content'       => $content,
				);

				$this->insert_comment ( $post_id, $c, $element, $avatar );

			}
		}
	}


	/**
	 * base worker since the 500px results for comments, votes & favs are
	 * similar enough to group them like this
	 *
	 */
	function request ( $baseurl, $results_element ) {
		$page = 1;
		$finished = false;

		$res = array();

		while (!$finished) {
			$params = array(
				'rpp'    => self::NUM_PER_REQUEST,
				'page'   => $page,
			);

			$url = $baseurl . '?' . http_build_query( $params );
			$data = $this->service->request( $url, array( 'method' => $this->request_method, 'timeout' => 10 ) );

			if ( Keyring_Util::is_error( $data ) )
				return $data;

			if (!empty($data->$results_element))
				foreach ($data->$results_element as $element )
					$res[] = $element;

			// jump to the next page or finish
			if ( ceil($data->total_items / self::NUM_PER_REQUEST) > $page )
				$page += 1;
			else
				$finished = true;
		}

		return $res;
	}


}}


add_action( 'init', function() {
	Keyring_500px_Reactions(); // Load the class code from above
	keyring_register_reactions(
		Keyring_500px_Reactions::SLUG,
		'Keyring_500px_Reactions',
		plugin_basename( __FILE__ ),
		__( 'Import comments, likes and votes from 500px for your syndicated posts.', 'keyring' )
	);
} );

