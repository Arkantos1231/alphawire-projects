<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The ONLY class that talks to Nansen. Mirrors Market_Data_Service's shape
 * (singleton, background sync, cache + permanent stale fallback) but is
 * STRICTER on one point that matters: this class has NO live-fallback
 * method. Market_Data_Service::get_market_data() will do a short-timeout
 * live CoinGecko call on a cold cache because a Project page can render
 * before the first background refresh runs. The Nansen integration
 * proposal is explicit that this must never happen for Nansen — "AlphaWire
 * should never compete with Nansen" and zero live Nansen calls, ever, not
 * even as a cold-cache fallback — so every accessor here is cache-only,
 * full stop. A cold cache just means "no Smart Money data yet" until the
 * next hourly sync runs, exactly like a brand-new Project with no
 * CoinGecko ID would show no Key Stats.
 *
 * Phase 1 only (Smart Money Leaderboard) — see the AlphaWire × Nansen
 * Integration Proposal doc for the full three-phase plan. Phases 2 (Alpha
 * Intelligence) and 3 (AlphaClub) are meant to reuse this exact cached
 * dataset when they're built; nothing here is Leaderboard-specific.
 */
class AlphaWire_Projects_Smart_Money_Service {

	const CACHE_PREFIX        = 'aw_smart_money_';
	const STALE_OPTION_PREFIX = 'aw_smart_money_stale_';
	const CACHE_TTL           = 3600; // 1 hour, matches the sync cadence.
	const CRON_HOOK           = 'alphawire_projects_sync_smart_money';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( self::CRON_HOOK, array( $this, 'sync_all' ) );

		add_action(
			'init',
			function () {
				if ( function_exists( 'as_schedule_recurring_action' ) ) {
					if ( false === as_next_scheduled_action( self::CRON_HOOK ) ) {
						as_schedule_recurring_action( time(), HOUR_IN_SECONDS, self::CRON_HOOK, array(), 'alphawire-projects' );
					}
				} elseif ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					// 'hourly' ships built into WP core — unlike
					// Market_Data_Service's 15-minute and AI_Summary_Service's
					// weekly cadences, no custom cron_schedules filter is
					// needed here.
					wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
				}
			}
		);
	}

	/**
	 * Background-only — never called from a page render. Syncs every
	 * published Project that has BOTH a Nansen chain and token address set
	 * (class-fields.php) — same "skip gracefully if unmapped" rule already
	 * used for CoinGecko IDs.
	 */
	public function sync_all() {
		$api_key = AlphaWire_Projects_Settings::get_nansen_api_key();
		if ( empty( $api_key ) ) {
			// No key yet — see class-settings.php's OPTION_NANSEN_API_KEY
			// comment. Not an error: the Leaderboard just stays empty until
			// a key is added, the same way a fresh install with zero
			// CoinGecko IDs mapped shows empty Key Stats rather than errors.
			return;
		}

		$project_ids = get_posts(
			array(
				'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$mapped_ids = array();
		foreach ( $project_ids as $project_id ) {
			if ( $this->get_nansen_chain( $project_id ) && $this->get_nansen_token_address( $project_id ) ) {
				$mapped_ids[] = $project_id;
			}
		}

		$count = count( $mapped_ids );
		foreach ( $mapped_ids as $index => $project_id ) {
			$this->fetch_and_cache(
				$project_id,
				$this->get_nansen_chain( $project_id ),
				$this->get_nansen_token_address( $project_id ),
				$api_key
			);

			// Courteous pacing — two calls per Project (netflow + holdings)
			// is well within even Nansen's Free tier (15 req/sec, 300/min),
			// but this keeps the sync a good API citizen, matching the
			// pacing already used for CoinGecko and OpenAI elsewhere in
			// this plugin.
			if ( $count > 1 && $index < $count - 1 ) {
				usleep( 250000 );
			}
		}
	}

	private function get_nansen_chain( $project_id ) {
		return function_exists( 'get_field' )
			? get_field( 'nansen_chain', $project_id )
			: get_post_meta( $project_id, 'nansen_chain', true );
	}

	private function get_nansen_token_address( $project_id ) {
		return function_exists( 'get_field' )
			? get_field( 'nansen_token_address', $project_id )
			: get_post_meta( $project_id, 'nansen_token_address', true );
	}

	private function fetch_and_cache( $project_id, $chain, $address, $api_key ) {
		$netflow  = $this->request( '/smart-money/netflow', $chain, $address, $api_key );
		$holdings = $this->request( '/smart-money/holdings', $chain, $address, $api_key );

		if ( null === $netflow && null === $holdings ) {
			// Both calls failed — leave any existing cache/stale value
			// alone rather than overwrite good data with an empty payload.
			$this->log_failure( $project_id, 'Both netflow and holdings requests failed' );
			return;
		}

		$payload = $this->normalise( $project_id, $netflow, $holdings );

		set_transient( self::CACHE_PREFIX . $project_id, $payload, self::CACHE_TTL );
		// No expiry on purpose, same reasoning as Market_Data_Service's
		// stale option — this is the "last known good" fallback and is
		// only ever overwritten by a successful sync.
		update_option( self::STALE_OPTION_PREFIX . $project_id, $payload, false );
	}

	/**
	 * @param string $endpoint Nansen API path, e.g. '/smart-money/netflow'.
	 * @return array|null Decoded JSON body, or null on any failure.
	 */
	private function request( $endpoint, $chain, $address, $api_key ) {
		$response = wp_remote_post(
			'https://api.nansen.ai/api/v1' . $endpoint,
			array(
				'timeout' => 20,
				'headers' => array(
					// Nansen auth: a flat 'apikey' header (confirmed from
					// their public docs) — not Bearer-token style like
					// OpenAI/CoinGecko Pro.
					'apikey'       => $api_key,
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'token_address' => $address,
						// Nansen's request shape takes a chains ARRAY even
						// for a single chain — confirmed from their public
						// API examples.
						'chains'        => array( $chain ),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log( sprintf( '[AlphaWire Projects] Nansen %s request failed: %s', $endpoint, $response->get_error_message() ) );
			return null;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			error_log( sprintf( '[AlphaWire Projects] Nansen %s request returned HTTP %d', $endpoint, $code ) );
			return null;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return is_array( $body ) ? $body : null;
	}

	/**
	 * Maps Nansen's response onto a stable internal shape. Nansen doesn't
	 * publish a full OpenAPI/Swagger spec (confirmed via research at
	 * implementation time — GitBook docs only, no machine-readable schema),
	 * so the field names read below are split into two tiers:
	 *
	 *  - CONFIRMED from Nansen's own published docs/examples: holders_count.
	 *  - BEST-EFFORT for everything else (net flow field naming) — several
	 *    candidate keys are tried so this keeps working across the more
	 *    likely response shapes, but flag this comment for whoever adds the
	 *    real Nansen key first: verify against one live response and trim
	 *    this to the actual key before trusting it beyond Phase 1's simple
	 *    "net buying / net selling / flat" Leaderboard sort.
	 */
	private function normalise( $project_id, $netflow, $holdings ) {
		$netflow_usd = $netflow['net_flow_usd']
			?? $netflow['netflow_usd']
			?? $netflow['data']['net_flow_usd']
			?? null;

		// CONFIRMED field name (Nansen's own docs).
		$holders_count = $holdings['holders_count'] ?? $holdings['data']['holders_count'] ?? null;

		$blurb = $this->maybe_generate_blurb( $project_id, $netflow_usd, $holders_count );

		return array(
			'netflowUsd24h' => is_numeric( $netflow_usd ) ? (float) $netflow_usd : null,
			'holdersCount'  => is_numeric( $holders_count ) ? (int) $holders_count : null,
			'blurb'         => $blurb,
			'updatedAt'     => current_time( 'mysql' ),
			'stale'         => false,
		);
	}

	/**
	 * Short OpenAI-written blurb explaining the Smart Money movement,
	 * generated once per background sync — never on render, same rule as
	 * the AI Project Summary feature (class-ai-summary-service.php). Gated
	 * on the OpenAI key already used for AI Summaries; a missing key just
	 * skips the blurb rather than failing the whole Nansen sync, since the
	 * Leaderboard's numbers and sort order don't depend on it.
	 */
	private function maybe_generate_blurb( $project_id, $netflow_usd, $holders_count ) {
		$api_key = AlphaWire_Projects_Settings::get_api_key();
		if ( empty( $api_key ) || null === $netflow_usd ) {
			return '';
		}

		$direction = $netflow_usd > 0 ? 'net buying' : ( $netflow_usd < 0 ? 'net selling' : 'roughly flat flow' );
		$title     = get_the_title( $project_id );

		$prompt = sprintf(
			'Project: %s. Smart Money 24h net flow: $%s (%s). Holder count: %s. Write ONE short, neutral, factual sentence describing this Smart Money activity for a project card. No speculation about price, no advice, no marketing language.',
			$title,
			number_format( abs( (float) $netflow_usd ) ),
			$direction,
			null !== $holders_count ? number_format( $holders_count ) : 'unknown'
		);

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'                 => AlphaWire_Projects_Settings::get_model(),
						'temperature'           => 0.4,
						// Same OpenAI parameter fix as class-ai-summary-service.php
						// (v0.8.9) — 'max_tokens' is rejected on newer models.
						'max_completion_tokens' => 60,
						'messages'              => array(
							array(
								'role'    => 'system',
								'content' => 'You write single-sentence, factual Smart Money activity summaries for AlphaWire. Use ONLY the numbers given to you — never invent facts.',
							),
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return '';
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['choices'][0]['message']['content'] ) ) {
			// The blurb is a nice-to-have, not a sync-blocking dependency —
			// log and move on rather than surfacing a hard error the way
			// the primary, user-facing AI Summary action does.
			error_log(
				sprintf(
					'[AlphaWire Projects] Nansen blurb generation skipped for Project #%d: %s',
					$project_id,
					isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code )
				)
			);
			return '';
		}

		return trim( $body['choices'][0]['message']['content'] );
	}

	/**
	 * Cache-only. NEVER fetches live, NEVER falls back to a live Nansen
	 * call even on a totally cold cache — see this class's docblock. That
	 * is the one deliberate difference from
	 * Market_Data_Service::get_market_data().
	 */
	public function get_cached_smart_money( $project_id ) {
		$cached = get_transient( self::CACHE_PREFIX . $project_id );
		if ( false !== $cached ) {
			return $cached;
		}

		$stale = get_option( self::STALE_OPTION_PREFIX . $project_id );
		if ( is_array( $stale ) ) {
			$stale['stale'] = true;
			return $stale;
		}

		return $this->empty_payload();
	}

	private function empty_payload() {
		return array(
			'netflowUsd24h' => null,
			'holdersCount'  => null,
			'blurb'         => '',
			'updatedAt'     => null,
			'stale'         => true,
		);
	}

	private function log_failure( $project_id, $message ) {
		error_log( sprintf( '[AlphaWire Projects] Nansen sync failed for Project #%d: %s', $project_id, $message ) );
	}
}
