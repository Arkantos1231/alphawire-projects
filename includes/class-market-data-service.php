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
 *
 * CACHE-ONLY on every accessor, full stop — no per-user/per-page-load
 * CoinGecko calls, ever (product's explicit requirement: visitors must
 * never be able to exhaust the rate limit just by browsing). Only
 * refresh_all(), on its own 15-minute background schedule, ever calls
 * fetch_and_cache(); get_market_data() and get_cached_market_data() both
 * only ever read what that job already wrote to the database. This used
 * to be a real distinction — get_market_data() had a short-timeout live
 * fallback on a cold cache for the single-Project page — but that's
 * exactly the per-user-call path product asked to close, so as of v0.9.2
 * it's gone (the cadence itself went back to 15 min in v0.9.3, after a
 * brief stint at 5 min — see get_market_data()'s own docblock).
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
				// Cadence history: 15min (through v0.9.1) -> 5min (v0.9.2) ->
				// back to 15min (v0.9.3, product's call after trying 5min).
				// Both scheduling checks below only ask "is *something*
				// already scheduled for this hook" — an already-running
				// recurrence at the *previous* interval satisfies that
				// forever and would keep firing on the old cadence with no
				// way to ever pick up a changed one. Same class of bug as
				// the rewrite-rules self-heal in class-post-type.php
				// (v0.7.2): a stored version marker, checked on every
				// request, is what actually catches a stale schedule
				// instead of just trusting "we already asked once, so we
				// must be fine." Bumping this marker again (v2 -> v3) is
				// what makes a site that's currently on the 5-min interval
				// (from v0.9.2) correctly fall back to 15 min here.
				if ( 'v3' !== get_option( 'alphawire_projects_market_cadence_version' ) ) {
					if ( function_exists( 'as_unschedule_all_actions' ) ) {
						as_unschedule_all_actions( self::CRON_HOOK, array(), 'alphawire-projects' );
					}
					wp_clear_scheduled_hook( self::CRON_HOOK );
					update_option( 'alphawire_projects_market_cadence_version', 'v3', false );
				}

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

		$project_count = count( $project_ids );
		$spacing_us    = $this->get_refresh_spacing_us();

		foreach ( $project_ids as $index => $project_id ) {
			$coingecko_id = $this->get_coingecko_id( $project_id );
			if ( empty( $coingecko_id ) ) {
				continue;
			}
			$this->fetch_and_cache( $coingecko_id );

			if ( $project_count > 1 && $index < $project_count - 1 ) {
				usleep( $spacing_us );
			}
		}
	}

	/**
	 * Public entry point for a single Project page. ALWAYS returns the
	 * normalised shape below, even on total failure — callers never get
	 * null and never have to branch on "did this work". Falls back to the
	 * last known-good value rather than blanking a field, per build plan §6.
	 *
	 * CACHE-ONLY — never fetches live. Through v0.9.1 this had a short-
	 * timeout live CoinGecko fallback on a cold cache, since a Project page
	 * can render before the first background refresh ever runs; product
	 * has since asked explicitly that no visitor's page load can ever
	 * trigger a CoinGecko call, so that fallback is gone as of v0.9.2. A
	 * cold cache now behaves exactly like get_cached_market_data() below:
	 * last known-good value if one exists, otherwise an empty payload
	 * until the next background sync runs — every 15 minutes as of
	 * v0.9.3 (v0.9.2 briefly tried 5 minutes; product asked to go back to
	 * 15). The two methods are functionally identical now — kept as
	 * separate names so each call site (single Project page vs.
	 * Directory/listing cards) still documents its own intent, and so a
	 * future difference between them doesn't mean renaming every caller.
	 */
	public function get_market_data( $coingecko_id ) {
		return $this->get_cached_market_data( $coingecko_id );
	}

	private function get_coingecko_id( $project_id ) {
		if ( function_exists( 'get_field' ) ) {
			return get_field( 'coingecko_id', $project_id );
		}
		return get_post_meta( $project_id, 'coingecko_id', true );
	}

	private function fetch_and_cache( $coingecko_id ) {
		$base_url = defined( 'AW_COINGECKO_API_KEY' ) && AW_COINGECKO_API_KEY
			? 'https://pro-api.coingecko.com/api/v3/coins/'
			: 'https://api.coingecko.com/api/v3/coins/';
		$base_url = apply_filters( 'alphawire_projects_coingecko_base_url', $base_url );

		$url = add_query_arg(
			array(
				'localization'   => 'false',
				'tickers'        => 'false',
				'market_data'    => 'true',
				'community_data' => 'false',
				'developer_data' => 'false',
				'sparkline'      => 'true',
			),
			$base_url . rawurlencode( $coingecko_id )
		);

		$args = array( 'timeout' => 15 );

		if ( defined( 'AW_COINGECKO_API_KEY' ) && AW_COINGECKO_API_KEY ) {
			$args['headers'] = array_merge(
				$args['headers'] ?? array(),
				array(
					'x-cg-pro-api-key' => AW_COINGECKO_API_KEY,
				)
			);
		}

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

		$response = $this->remote_get_with_retry( $coingecko_id, $url, $args );
		if ( null === $response ) {
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
			// Raw number alongside the formatted string, same pattern as
			// volume24hRaw below — needed so the Smart Money Leaderboard
			// (Nansen integration, Phase 1) can filter/sort Projects by
			// market cap without re-parsing a "$1,234.56" display string.
			'marketCapRaw'      => isset( $market_data['market_cap']['usd'] ) ? (float) $market_data['market_cap']['usd'] : null,
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
			'marketCapRaw'      => null,
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

	private function get_refresh_spacing_us() {
		$requests_per_minute = (int) apply_filters( 'alphawire_projects_coingecko_rate_limit_per_minute', 500 );
		$safety_factor       = (float) apply_filters( 'alphawire_projects_coingecko_safe_rate_factor', 0.6 );
		$target_rate         = max( 1, (int) round( $requests_per_minute * $safety_factor ) );

		return (int) round( ( 60 * 1000000 ) / $target_rate );
	}

	private function remote_get_with_retry( $coingecko_id, $url, $args ) {
		$max_retries = (int) apply_filters( 'alphawire_projects_coingecko_max_retries', 3 );
		$base_delay  = (int) apply_filters( 'alphawire_projects_coingecko_retry_delay_us', 500000 );

		for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
			$response = wp_remote_get( $url, $args );

			if ( ! is_wp_error( $response ) ) {
				$code = wp_remote_retrieve_response_code( $response );
				if ( 200 === $code ) {
					return $response;
				}

				if ( in_array( $code, array( 429, 500, 502, 503, 504 ), true ) && $attempt < $max_retries ) {
					$delay = $base_delay * ( 2 ** ( $attempt - 1 ) );
					$this->log_failure( $coingecko_id, sprintf( 'HTTP %d; retrying in %d ms', $code, round( $delay / 1000 ) ) );
					usleep( $delay );
					continue;
				}

				$this->log_failure( $coingecko_id, 'HTTP ' . $code . ( 429 === $code ? ' (rate limited)' : '' ) );
				return null;
			}

			if ( $attempt < $max_retries ) {
				$delay = $base_delay * ( 2 ** ( $attempt - 1 ) );
				$this->log_failure( $coingecko_id, sprintf( '%s; retrying in %d ms', $response->get_error_message(), round( $delay / 1000 ) ) );
				usleep( $delay );
				continue;
			}

			$this->log_failure( $coingecko_id, $response->get_error_message() );
			return null;
		}

		return null;
	}

	/**
	 * Cache-only read — never fetches live. Originally written for
	 * Directory/listing contexts specifically (a listing renders many cards
	 * at once, so a live fetch per cold card would mean up to N blocking
	 * HTTP calls on one page render); get_market_data() above is now just
	 * an alias for this same method, so the "never fetches" guarantee here
	 * applies everywhere market data is read, not only listings. Only
	 * refresh_all() — the 15-minute background job — ever calls
	 * fetch_and_cache(); nothing reachable from a page render does.
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
