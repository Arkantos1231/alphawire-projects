<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Smart Money Leaderboard REST route + a plain-PHP query helper the
 * Leaderboard template calls directly (same reasoning as
 * AlphaWire_Projects_Directory_REST::query_projects() — avoids a loopback
 * HTTP call on render). CACHE-ONLY throughout: every number here comes from
 * AlphaWire_Projects_Smart_Money_Service::get_cached_smart_money() and
 * AlphaWire_Projects_Market_Data_Service::get_cached_market_data(), neither
 * of which ever makes a live request. A Project with no Nansen mapping, or
 * no synced data yet, is simply left off the Leaderboard rather than shown
 * with blank/zeroed numbers.
 */
class AlphaWire_Projects_Smart_Money_REST {

	public static function register_routes() {
		$ns = AlphaWire_Projects_REST::NAMESPACE;

		register_rest_route( $ns, '/smart-money-leaderboard', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'leaderboard' ),
			'permission_callback' => '__return_true',
		) );
	}

	public static function leaderboard( $request ) {
		$result = self::query_leaderboard(
			array(
				'chain'          => $request->get_param( 'chain' ),
				'category'       => $request->get_param( 'category' ),
				'narrative'      => $request->get_param( 'narrative' ),
				'market_cap_min' => $request->get_param( 'market_cap_min' ),
				'market_cap_max' => $request->get_param( 'market_cap_max' ),
			)
		);
		return $result['rows'];
	}

	/**
	 * Callable directly from templates/smart-money-leaderboard.php, same
	 * pattern as AlphaWire_Projects_Directory_REST::query_projects().
	 *
	 * 'timeframe' isn't filtered here yet: the sync
	 * (class-smart-money-service.php) only fetches one 24h netflow window
	 * per Project today, so a timeframe selector on the front end currently
	 * has nothing to switch between. Multi-window support (7d/30d) is a
	 * future enhancement to the sync service, not this query — add the
	 * extra fetches there first, then this method's netflow lookup and
	 * filter can key off $params['timeframe'].
	 *
	 * @return array{rows: array}
	 */
	public static function query_leaderboard( array $params ) {
		$args = array(
			'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
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

		$rows = array();
		foreach ( $query->posts as $post ) {
			$row = self::row( $post, $params );
			if ( null !== $row ) {
				$rows[] = $row;
			}
		}

		usort(
			$rows,
			function ( $a, $b ) {
				// Ranked by Smart Money accumulation, not price — the whole
				// point of this page (proposal: "ranks projects by Smart
				// Money accumulation, not price movement").
				return $b['netflowUsd24h'] <=> $a['netflowUsd24h'];
			}
		);

		return array( 'rows' => $rows );
	}

	/**
	 * @return array|null Null when this Project has no Nansen mapping, no
	 *                     synced data yet, or fails an active filter — the
	 *                     Leaderboard just omits it rather than showing a
	 *                     zeroed-out row.
	 */
	private static function row( $post, array $params ) {
		$chain   = function_exists( 'get_field' ) ? get_field( 'nansen_chain', $post->ID ) : get_post_meta( $post->ID, 'nansen_chain', true );
		$address = function_exists( 'get_field' ) ? get_field( 'nansen_token_address', $post->ID ) : get_post_meta( $post->ID, 'nansen_token_address', true );

		if ( empty( $chain ) || empty( $address ) ) {
			return null;
		}

		if ( ! empty( $params['chain'] ) && sanitize_title( $params['chain'] ) !== sanitize_title( $chain ) ) {
			return null;
		}

		$smart_money = AlphaWire_Projects_Smart_Money_Service::instance()->get_cached_smart_money( $post->ID );
		if ( null === $smart_money['netflowUsd24h'] ) {
			// No synced data yet (cold cache, or the hourly sync hasn't run
			// since this Project was mapped) — leave it off rather than
			// rank it as a zero.
			return null;
		}

		$coingecko_id = function_exists( 'get_field' ) ? get_field( 'coingecko_id', $post->ID ) : get_post_meta( $post->ID, 'coingecko_id', true );
		$market       = AlphaWire_Projects_Market_Data_Service::instance()->get_cached_market_data( $coingecko_id );

		$cap_min = isset( $params['market_cap_min'] ) && is_numeric( $params['market_cap_min'] ) ? (float) $params['market_cap_min'] : null;
		$cap_max = isset( $params['market_cap_max'] ) && is_numeric( $params['market_cap_max'] ) ? (float) $params['market_cap_max'] : null;

		if ( null !== $cap_min && ( null === $market['marketCapRaw'] || $market['marketCapRaw'] < $cap_min ) ) {
			return null;
		}
		if ( null !== $cap_max && ( null === $market['marketCapRaw'] || $market['marketCapRaw'] > $cap_max ) ) {
			return null;
		}

		return array(
			'id'            => $post->ID,
			'slug'          => $post->post_name,
			'name'          => get_the_title( $post ),
			'ticker'        => function_exists( 'get_field' ) ? get_field( 'ticker', $post->ID ) : get_post_meta( $post->ID, 'ticker', true ),
			'logo'          => get_the_post_thumbnail_url( $post, 'thumbnail' ),
			'categories'    => wp_get_post_terms( $post->ID, 'pillar', array( 'fields' => 'names' ) ),
			'chain'         => $chain,
			'price'         => $market['price'],
			'change24h'     => $market['change24h'],
			'marketCapRaw'  => $market['marketCapRaw'],
			'netflowUsd24h' => $smart_money['netflowUsd24h'],
			'holdersCount'  => $smart_money['holdersCount'],
			'blurb'         => $smart_money['blurb'],
			'stale'         => $smart_money['stale'],
		);
	}
}
