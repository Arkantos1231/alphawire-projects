<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Directory + listing endpoints (build plan Phase 1: Trending, Recently
 * Updated, Recently Launched, Editors' Picks, Top Categories, Trending
 * Narratives). None of these invent a ranking algorithm: Trending and
 * Editors' Picks are plain editorial fields set in wp-admin; Recently
 * Launched/Updated sort by real dates. See the build plan's decision log.
 */
class AlphaWire_Projects_Directory_REST {

	public static function register_routes() {
		$ns = AlphaWire_Projects_REST::NAMESPACE;

		register_rest_route( $ns, '/projects', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_projects' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/projects/trending', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'trending' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/projects/recently-launched', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'recently_launched' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/projects/recently-updated', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'recently_updated' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/projects/editors-picks', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'editors_picks' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/categories', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'categories' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( $ns, '/narratives', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'narratives' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function list_projects( $request ) {
		$search = $request->get_param( 'search' );
		if ( $search ) {
			return self::search( $search );
		}

		return self::query_cards(
			array(
				'per_page' => $request->get_param( 'per_page' ),
				'page'     => $request->get_param( 'page' ),
				'category' => $request->get_param( 'category' ),
				'narrative' => $request->get_param( 'narrative' ),
			)
		);
	}

	/**
	 * Same filtering the REST /projects list uses, callable directly with a
	 * plain args array — used by the Directory template so filtering by
	 * search/category/narrative doesn't require a loopback HTTP call to our
	 * own REST endpoint. Returns the WP_Query so the template can also read
	 * pagination info (max_num_pages etc.), alongside the mapped cards.
	 *
	 * @return array{query: WP_Query, cards: array}
	 */
	public static function query_projects( array $params ) {
		if ( ! empty( $params['search'] ) ) {
			$cards = self::search( $params['search'] );
			return array( 'query' => null, 'cards' => $cards );
		}

		$args = array(
			'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => min( 50, (int) ( $params['per_page'] ?? 20 ) ?: 20 ),
			'paged'          => max( 1, (int) ( $params['page'] ?? 1 ) ),
			'tax_query'      => array(),
		);

		if ( ! empty( $params['category'] ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'pillar',
				'field'    => 'slug',
				'terms'    => sanitize_title( $params['category'] ),
			);
		}

		if ( ! empty( $params['narrative'] ) ) {
			$args['tax_query'][] = array(
				'taxonomy' => 'topic',
				'field'    => 'slug',
				'terms'    => sanitize_title( $params['narrative'] ),
			);
		}

		$query = new WP_Query( $args );
		return array(
			'query' => $query,
			'cards' => array_map( array( __CLASS__, 'card' ), $query->posts ),
		);
	}

	private static function query_cards( array $params ) {
		return self::query_projects( $params )['cards'];
	}

	/**
	 * MVP search — Project name, ticker, category or narrative (spec §5/§7).
	 * Three targeted queries merged in PHP rather than one raw SQL join —
	 * the curated MVP catalogue is small enough that this is plenty.
	 */
	private static function search( $term ) {
		$by_title = get_posts( array(
			'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			's'              => $term,
			'posts_per_page' => 20,
		) );

		$by_ticker = get_posts( array(
			'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'meta_query'     => array(
				array(
					'key'     => 'ticker',
					'value'   => $term,
					'compare' => 'LIKE',
				),
			),
		) );

		$term_ids = get_terms( array(
			'taxonomy'   => array( 'pillar', 'topic' ),
			'name__like' => $term,
			'fields'     => 'ids',
			'hide_empty' => false,
		) );

		$by_taxonomy = array();
		if ( ! empty( $term_ids ) ) {
			$by_taxonomy = get_posts( array(
				'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 20,
				'tax_query'      => array(
					array(
						'taxonomy' => array( 'pillar', 'topic' ),
						'field'    => 'term_id',
						'terms'    => $term_ids,
					),
				),
			) );
		}

		$merged = array();
		foreach ( array_merge( $by_title, $by_ticker, $by_taxonomy ) as $post ) {
			$merged[ $post->ID ] = $post; // de-dupe by post ID
		}

		return array_values( array_map( array( __CLASS__, 'card' ), $merged ) );
	}

	public static function trending( $request ) {
		$query = new WP_Query( array(
			'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 12,
			'meta_key'       => 'trending_order',
			'orderby'        => 'meta_value_num',
			'order'          => 'ASC',
			'meta_query'     => array(
				array(
					'key'     => 'trending_order',
					'compare' => 'EXISTS',
				),
			),
		) );
		return array_map( array( __CLASS__, 'card' ), $query->posts );
	}

	public static function recently_launched( $request ) {
		$query = new WP_Query( array(
			'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 8,
			'meta_key'       => 'launch_date',
			'orderby'        => 'meta_value',
			'order'          => 'DESC',
		) );
		return array_map( array( __CLASS__, 'card' ), $query->posts );
	}

	public static function recently_updated( $request ) {
		$query = new WP_Query( array(
			'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 8,
			'meta_key'       => AlphaWire_Projects_Activity::META_KEY,
			'orderby'        => 'meta_value',
			'order'          => 'DESC',
		) );
		return array_map( array( __CLASS__, 'card' ), $query->posts );
	}

	public static function editors_picks( $request ) {
		$query = new WP_Query( array(
			'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 8,
			'meta_query'     => array(
				array(
					'key'   => 'editors_pick',
					'value' => '1',
				),
			),
		) );
		return array_map( array( __CLASS__, 'card' ), $query->posts );
	}

	/**
	 * "Top Categories": pillar terms counted against Projects specifically —
	 * not against the site's 300+ News articles per term, which is what
	 * the term's own default count reflects.
	 */
	public static function categories( $request ) {
		return self::term_usage( 'pillar' );
	}

	/**
	 * "Trending Narratives": topic terms actually used on a Project, minus
	 * the entity-style terms (Tether, Circle, Kalshi…) the build plan
	 * flagged as "not narratives, needs filtering" — that filter is the
	 * editable exclusion list at Projects → Settings → Directory —
	 * Narratives, not a hand-picked allowlist baked into this file.
	 */
	public static function narratives( $request ) {
		return self::term_usage( 'topic', AlphaWire_Projects_Settings::get_narrative_exclusions() );
	}

	/**
	 * `change24h` here is real, not the Lovable prototype's mocked "▲X%" —
	 * it's the average of the cached 24h price change across every Project
	 * tagged with that term (null if none has market data yet). Ranking
	 * stays by Project count, matching the existing "Top"/"Trending" =
	 * most-used decision; the percentage is a display value, not the sort
	 * key, so a term with 1 volatile Project can't outrank one 10 editors
	 * actually use.
	 */
	private static function term_usage( $taxonomy, array $excluded_names = array() ) {
		$terms = get_terms( array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
		) );

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$results = array();
		foreach ( $terms as $term ) {
			if ( $excluded_names && in_array( strtolower( $term->name ), $excluded_names, true ) ) {
				continue;
			}

			$query = new WP_Query( array(
				'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array(
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				),
			) );

			if ( ! $query->posts ) {
				continue;
			}

			$changes = array();
			foreach ( $query->posts as $project_id ) {
				$coingecko_id = function_exists( 'get_field' ) ? get_field( 'coingecko_id', $project_id ) : get_post_meta( $project_id, 'coingecko_id', true );
				$market       = AlphaWire_Projects_Market_Data_Service::instance()->get_cached_market_data( $coingecko_id );
				if ( null !== $market['change24h'] ) {
					$changes[] = (float) $market['change24h'];
				}
			}

			$results[] = array(
				'label'     => $term->name,
				'slug'      => $term->slug,
				'count'     => count( $query->posts ),
				'change24h' => $changes ? round( array_sum( $changes ) / count( $changes ), 2 ) : null,
			);
		}

		usort(
			$results,
			function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		return $results;
	}

	/**
	 * Public entry point for other classes that need the same lightweight
	 * card shape (e.g. Collections rendering a user's saved Projects)
	 * without duplicating this mapping.
	 */
	public static function card_public( $post ) {
		return self::card( $post );
	}

	/**
	 * Lightweight shape for list/card contexts — full detail (timeline,
	 * coverage, AI summary…) only lives on the single-project endpoint.
	 */
	private static function card( $post ) {
		$coingecko_id = function_exists( 'get_field' ) ? get_field( 'coingecko_id', $post->ID ) : get_post_meta( $post->ID, 'coingecko_id', true );
		$market       = AlphaWire_Projects_Market_Data_Service::instance()->get_cached_market_data( $coingecko_id );

		return array(
			'id'         => $post->ID,
			'slug'       => $post->post_name,
			'name'       => get_the_title( $post ),
			'ticker'     => function_exists( 'get_field' ) ? get_field( 'ticker', $post->ID ) : get_post_meta( $post->ID, 'ticker', true ),
			'tagline'    => get_the_excerpt( $post ),
			'logo'       => get_the_post_thumbnail_url( $post, 'thumbnail' ),
			'categories' => wp_get_post_terms( $post->ID, 'pillar', array( 'fields' => 'names' ) ),
			'price'      => $market['price'],
			'change24h'  => $market['change24h'],
			'volume24h'  => $market['volume24hRaw'],
		);
	}
}
