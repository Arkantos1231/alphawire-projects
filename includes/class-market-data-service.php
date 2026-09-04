<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ONLY class that talks to a market-data provider.
 *
 * Today: CoinGecko's free public endpoint — no API key, works at our low
 * request volume (a curated set of projects refreshed every 15 min, see
 * build plan §6). No paid CoinGecko access yet, so this is deliberately
 * built against the free tier rather than a placeholder/mock — when a Demo
 * or paid key shows up later, it's added via the
 * `alphawire_projects_coingecko_request_args` filter below and nothing
 * else in the plugin changes, because every caller only ever sees
 * get_market_data()'s normalised shape.
 */
class AlphaWire_Projects_Market_Data_Service {

	const CACHE_PREFIX       = 'aw_project_market_';
	const STALE_OPTION_PREFIX = 'aw_project_market_stale_';
	const CACHE_TTL          = 900; // 15 minutes, matches the background refresh cadence.
	const CRON_HOOK          = 'alphawire_projects_refresh_market_data';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( self::CRON_HOOK, array( $this, 'refresh_all' ) );

		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );

		add_action(
			'init',
			function () {
				if ( function_exists( 'as_schedule_recurring_action' ) ) {
					// Prefer Action Scheduler when it's available (it already
					// ships with several plugins on this site) — it retries
					// and logs, which bare WP-Cron doesn't.
					if ( false === as_next_scheduled_action( self::CRON_HOOK ) ) {
						as_schedule_recurring_action( time(), 15 * MINUTE_IN_SECONDS, self::CRON_HOOK, array(), 'alphawire-projects' );
					}
				} elseif ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_schedule_event( time(), 'alphawire_projects_15min', self::CRON_HOOK );
				}
			}
		);
	}

	public function register_schedule( $schedules ) {
		$schedules['alphawire_projects_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes (AlphaWire Projects)', 'alphawire-projects' ),
		);
		return $schedules;
	}

	/**
	 * Refreshes cached market data for every published Project that has a
	 * CoinGecko ID set. Runs in the background only — never during a page
	 * render, per the "no live external calls on render" rule in the BE spec.
	 */
	public function refresh_all() {
		$project_ids = get_posts(
			array(
				'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		foreach ( $project_ids as $project_id ) {
			$coingecko_id = $this->get_coingecko_id( $project_id );
			if ( empty( $coingecko_id ) ) {
				continue;
			}
			$this->fetch_and_cache( $coingecko_id );
			// The free tier's rate limit is low (5-15 req/min) — a short
			// pause between requests keeps a bigger catalogue from tripping
			// it mid-run rather than just spacing runs 15 min apart.
			usleep( 300000 );
		}
	}

	/**
	 * Public entry point. ALWAYS returns the normalised shape below, even on
	 * total failure — callers never get null and never have to branch on
	 * "did this work". Falls back to the last known-good value rather than
	 * blanking a field, per build plan §6.
	 */
	public function get_market_data( $coingecko_id ) {
		if ( empty( $coingecko_id ) ) {
			return $this->empty_payload();
		}

		$cached = get_transient( self::CACHE_PREFIX . $coingecko_id );
		if ( false !== $cached ) {
			return $cached;
		}

		// Cold cache (first run, or the 15-min TTL lapsed since the last
		// background refresh). This can be reached from a page render, so
		// the request below is short-timeout and fails soft.
		$fetched = $this->fetch_and_cache( $coingecko_id );
		if ( null !== $fetched ) {
			return $fetched;
		}

		$stale = get_option( self::STALE_OPTION_PREFIX . $coingecko_id );
		if ( is_array( $stale ) ) {
			$stale['stale'] = true;
			return $stale;
		}

		return $this->empty_payload();
	}

	private function get_coingecko_id( $project_id ) {
		if ( function_exists( 'get_field' ) ) {
			return get_field( 'coingecko_id', $project_id );
		}
		return get_post_meta( $project_id, 'coingecko_id', true );
	}

	private function fetch_and_cache( $coingecko_id ) {
		$url = add_query_arg(
			array(
				'localization'   => 'false',
				'tickers'        => 'false',
				'market_data'    => 'true',
				'community_data' => 'false',
				'developer_data' => 'false',
				'sparkline'      => 'true',
			),
			'https://api.coingecko.com/api/v3/coins/' . rawurlencode( $coingecko_id )
		);

		$args = array( 'timeout' => 8 );

		/**
		 * Add auth once a CoinGecko Demo/paid key exists, e.g.:
		 *
		 *   add_filter( 'alphawire_projects_coingecko_request_args', function ( $args ) {
		 *       $args['headers']['x-cg-demo-api-key'] = AW_COINGECKO_KEY;
		 *       return $args;
		 *   } );
		 *
		 * Nothing else in the plugin needs to know a key was added.
		 */
		$args = apply_filters( 'alphawire_projects_coingecko_request_args', $args );

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->log_failure( $coingecko_id, $response->get_error_message() );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$this->log_failure( $coingecko_id, 'HTTP ' . $code . ( 429 === $code ? ' (rate limited)' : '' ) );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['market_data'] ) ) {
			$this->log_failure( $coingecko_id, 'Unexpected response shape' );
			return null;
		}

		$payload = $this->normalise( $body['market_data'] );

		set_transient( self::CACHE_PREFIX . $coingecko_id, $payload, self::CACHE_TTL );
		// No expiry on purpose — this is the "last known good" fallback,
		// and is only ever overwritten by a *successful* fetch.
		update_option( self::STALE_OPTION_PREFIX . $coingecko_id, $payload, false );

		return $payload;
	}

	/**
	 * Maps CoinGecko's response onto the FE/BE data contract's Market shape
	 * (build plan §5) so nothing downstream ever touches a raw CoinGecko field.
	 */
	private function normalise( array $market_data ) {
		$usd = function ( $value ) {
			if ( null === $value ) {
				return null;
			}
			$decimals = $value < 1 ? 4 : 2;
			return '$' . number_format( (float) $value, $decimals );
		};

		return array(
			'price'             => $usd( $market_data['current_price']['usd'] ?? null ),
			'change24h'         => isset( $market_data['price_change_percentage_24h'] )
				? round( (float) $market_data['price_change_percentage_24h'], 2 )
				: null,
			'marketCap'         => $usd( $market_data['market_cap']['usd'] ?? null ),
			'volume24h'         => $usd( $market_data['total_volume']['usd'] ?? null ),
			'volume24hRaw'      => isset( $market_data['total_volume']['usd'] ) ? (float) $market_data['total_volume']['usd'] : null,
			'circulatingSupply' => isset( $market_data['circulating_supply'] )
				? number_format( (float) $market_data['circulating_supply'] )
				: null,
			'totalSupply'       => isset( $market_data['total_supply'] )
				? number_format( (float) $market_data['total_supply'] )
				: null,
			'allTimeHigh'       => $usd( $market_data['ath']['usd'] ?? null ),
			'chart'             => $market_data['sparkline_7d']['price'] ?? array(),
			'updatedAt'         => current_time( 'mysql' ),
			'stale'             => false,
		);
	}

	private function empty_payload() {
		return array(
			'price'             => null,
			'change24h'         => null,
			'marketCap'         => null,
			'volume24h'         => null,
			'volume24hRaw'      => null,
			'circulatingSupply' => null,
			'totalSupply'       => null,
			'allTimeHigh'       => null,
			'chart'             => array(),
			'updatedAt'         => null,
			'stale'             => true,
		);
	}

	private function log_failure( $coingecko_id, $message ) {
		// v0.1: error_log is enough to see this is wired up correctly.
		// The site already runs Simple History — route failures there next.
		error_log( sprintf( '[AlphaWire Projects] CoinGecko fetch failed for "%s": %s', $coingecko_id, $message ) );
	}

	/**
	 * Cache-only read for list/card contexts (build plan Phase 1).
	 *
	 * get_market_data() is fine for a single Project page, but a Directory
	 * listing renders many cards at once — falling back to a live
	 * CoinGecko fetch per cold card would mean up to N blocking HTTP calls
	 * on one page render. This never fetches; only the background job and
	 * get_market_data() touch the network.
	 */
	public function get_cached_market_data( $coingecko_id ) {
		if ( empty( $coingecko_id ) ) {
			return $this->empty_payload();
		}

		$cached = get_transient( self::CACHE_PREFIX . $coingecko_id );
		if ( false !== $cached ) {
			return $cached;
		}

		$stale = get_option( self::STALE_OPTION_PREFIX . $coingecko_id );
		if ( is_array( $stale ) ) {
			$stale['stale'] = true;
			return $stale;
		}

		return $this->empty_payload();
	}
}
