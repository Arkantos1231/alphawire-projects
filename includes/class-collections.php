<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Collections" — a reader's own named lists of saved Projects (the
 * Lovable prototype's "Create a collection"). Not in the original build
 * plan's Phase 3 scope (which explicitly said "Sin Create Collection") —
 * added afterwards at product's request, once we confirmed the site
 * already has a real reader account system to hang it on (Thirdweb Auth
 * SSO creates/maps a normal WP user; Site Content Gating handles the
 * anonymous/registered split). So this never invents authentication —
 * every collection is just meta on that existing WP user.
 */
class AlphaWire_Projects_Collections {

	const META_KEY         = 'aw_project_collections';
	const MAX_COLLECTIONS  = 30;
	const MAX_NAME_LENGTH  = 60;

	public static function hooks() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		$ns = AlphaWire_Projects_REST::NAMESPACE;

		register_rest_route(
			$ns,
			'/collections',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'list_collections' ),
					'permission_callback' => array( __CLASS__, 'require_login' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'create_collection' ),
					'permission_callback' => array( __CLASS__, 'require_login' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/collections/(?P<id>[a-zA-Z0-9\-]+)',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rename_collection' ),
					'permission_callback' => array( __CLASS__, 'require_login' ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'delete_collection' ),
					'permission_callback' => array( __CLASS__, 'require_login' ),
				),
			)
		);

		register_rest_route(
			$ns,
			'/collections/(?P<id>[a-zA-Z0-9\-]+)/projects',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'add_project' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
			)
		);

		register_rest_route(
			$ns,
			'/collections/(?P<id>[a-zA-Z0-9\-]+)/projects/(?P<project_id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'remove_project' ),
				'permission_callback' => array( __CLASS__, 'require_login' ),
			)
		);
	}

	public static function require_login() {
		return is_user_logged_in();
	}

	private static function get_all( $user_id ) {
		$raw = get_user_meta( $user_id, self::META_KEY, true );
		return is_array( $raw ) ? $raw : array();
	}

	private static function save_all( $user_id, array $collections ) {
		update_user_meta( $user_id, self::META_KEY, array_values( $collections ) );
	}

	public static function list_collections( $request ) {
		return array_map( array( __CLASS__, 'present' ), self::get_all( get_current_user_id() ) );
	}

	/**
	 * Public entry point for template-functions.php — the raw stored shape
	 * (with project_ids, not the REST-facing projectIds) for a given user,
	 * so a template can compute "is this Project saved anywhere" without a
	 * loopback REST call on every render.
	 */
	public static function get_all_public( $user_id ) {
		return self::get_all( $user_id );
	}

	public static function create_collection( $request ) {
		$user_id = get_current_user_id();
		$name    = self::sanitize_name( $request->get_param( 'name' ) );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		$collections = self::get_all( $user_id );
		if ( count( $collections ) >= self::MAX_COLLECTIONS ) {
			return new WP_Error( 'aw_too_many_collections', 'You have reached the maximum of ' . self::MAX_COLLECTIONS . ' collections.', array( 'status' => 400 ) );
		}

		$collection    = array(
			'id'          => wp_generate_uuid4(),
			'name'        => $name,
			'project_ids' => array(),
			'created_at'  => current_time( 'mysql' ),
		);
		$collections[] = $collection;
		self::save_all( $user_id, $collections );

		return self::present( $collection );
	}

	public static function rename_collection( $request ) {
		$name = self::sanitize_name( $request->get_param( 'name' ) );
		if ( is_wp_error( $name ) ) {
			return $name;
		}

		return self::mutate(
			$request->get_param( 'id' ),
			function ( $collection ) use ( $name ) {
				$collection['name'] = $name;
				return $collection;
			}
		);
	}

	public static function delete_collection( $request ) {
		$user_id     = get_current_user_id();
		$id          = $request->get_param( 'id' );
		$collections = self::get_all( $user_id );

		$filtered = array_values(
			array_filter(
				$collections,
				function ( $c ) use ( $id ) {
					return $c['id'] !== $id;
				}
			)
		);

		if ( count( $filtered ) === count( $collections ) ) {
			return new WP_Error( 'aw_not_found', 'Collection not found.', array( 'status' => 404 ) );
		}

		self::save_all( $user_id, $filtered );
		return array( 'deleted' => true );
	}

	public static function add_project( $request ) {
		$project_id = (int) $request->get_param( 'project_id' );
		if ( AlphaWire_Projects_Post_Type::POST_TYPE !== get_post_type( $project_id ) ) {
			return new WP_Error( 'aw_invalid_project', 'Not a valid Project.', array( 'status' => 400 ) );
		}

		return self::mutate(
			$request->get_param( 'id' ),
			function ( $collection ) use ( $project_id ) {
				if ( ! in_array( $project_id, $collection['project_ids'], true ) ) {
					$collection['project_ids'][] = $project_id;
				}
				return $collection;
			}
		);
	}

	public static function remove_project( $request ) {
		$project_id = (int) $request->get_param( 'project_id' );

		return self::mutate(
			$request->get_param( 'id' ),
			function ( $collection ) use ( $project_id ) {
				$collection['project_ids'] = array_values( array_diff( $collection['project_ids'], array( $project_id ) ) );
				return $collection;
			}
		);
	}

	/**
	 * Shared find-mutate-save for the collection-scoped endpoints.
	 *
	 * @param string   $id
	 * @param callable $mutator ( array $collection ) => array $collection
	 */
	private static function mutate( $id, callable $mutator ) {
		$user_id     = get_current_user_id();
		$collections = self::get_all( $user_id );

		foreach ( $collections as $i => $collection ) {
			if ( $collection['id'] === $id ) {
				$collections[ $i ] = $mutator( $collection );
				self::save_all( $user_id, $collections );
				return self::present( $collections[ $i ] );
			}
		}

		return new WP_Error( 'aw_not_found', 'Collection not found.', array( 'status' => 404 ) );
	}

	private static function sanitize_name( $name ) {
		$name = sanitize_text_field( (string) $name );
		if ( '' === $name ) {
			return new WP_Error( 'aw_missing_name', 'A collection needs a name.', array( 'status' => 400 ) );
		}
		return mb_substr( $name, 0, self::MAX_NAME_LENGTH );
	}

	private static function present( array $collection ) {
		return array(
			'id'         => $collection['id'],
			'name'       => $collection['name'],
			'projectIds' => array_map( 'intval', $collection['project_ids'] ?? array() ),
			'createdAt'  => $collection['created_at'] ?? null,
		);
	}

	/**
	 * Server-rendered helper for templates/collections.php — real card data
	 * (not just IDs) for a set of Projects, reusing the same lightweight
	 * shape the Directory itself uses so the two never drift apart.
	 *
	 * @return array
	 */
	public static function get_cards( array $project_ids ) {
		if ( empty( $project_ids ) ) {
			return array();
		}

		$query = new WP_Query(
			array(
				'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'post__in'       => $project_ids,
				'orderby'        => 'post__in',
				'posts_per_page' => -1,
			)
		);

		return array_map( array( 'AlphaWire_Projects_Directory_REST', 'card_public' ), $query->posts );
	}
}
