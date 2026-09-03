<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates an AI Project Summary DRAFT — never publishes anything itself.
 * Mirrors the Market Summaries pattern already live on the site: OpenAI
 * writes from approved sources only, the result always lands as "Pending
 * Review", and an editor edits/rejects/regenerates/approves from wp-admin
 * before it's ever shown on the front end (BE spec §8-§10, Darian & Andy
 * §8-§10 — "AI generation must not happen when a user opens a Project page").
 */
class AlphaWire_Projects_AI_Summary_Service {

	const CRON_HOOK = 'alphawire_projects_generate_missing_summaries';

	public function hooks() {
		add_action( self::CRON_HOOK, array( $this, 'generate_missing_drafts' ) );
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );

		add_action(
			'init',
			function () {
				if ( function_exists( 'as_schedule_recurring_action' ) ) {
					if ( false === as_next_scheduled_action( self::CRON_HOOK ) ) {
						as_schedule_recurring_action( time(), WEEK_IN_SECONDS, self::CRON_HOOK, array(), 'alphawire-projects' );
					}
				} elseif ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					wp_schedule_event( time(), 'alphawire_projects_weekly', self::CRON_HOOK );
				}
			}
		);

		add_action( 'admin_post_alphawire_projects_generate_summary', array( $this, 'handle_manual_trigger' ) );
	}

	public function register_schedule( $schedules ) {
		$schedules['alphawire_projects_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => __( 'Once weekly (AlphaWire Projects)', 'alphawire-projects' ),
		);
		return $schedules;
	}

	/**
	 * The weekly auto-fill job, mirroring Market Summaries exactly: only
	 * touches Projects with NO summary text yet. Same rule as production —
	 * "boxes that already have text are never touched" by the scheduled run.
	 */
	public function generate_missing_drafts() {
		$project_ids = get_posts(
			array(
				'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'     => 'ai_summary_text',
						'value'   => '',
						'compare' => '=',
					),
					array(
						'key'     => 'ai_summary_text',
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		foreach ( $project_ids as $project_id ) {
			$this->generate_draft( $project_id );
			sleep( 1 ); // courteous pacing between requests within one run.
		}
	}

	public function handle_manual_trigger() {
		$project_id = isset( $_POST['project_id'] ) ? (int) $_POST['project_id'] : 0;

		if ( ! $project_id
			|| ! current_user_can( 'edit_post', $project_id )
			|| ! check_admin_referer( 'alphawire_generate_summary_' . $project_id )
		) {
			wp_die( esc_html__( 'You are not authorised to do this.', 'alphawire-projects' ), 403 );
		}

		$result = $this->generate_draft( $project_id );

		$redirect = get_edit_post_link( $project_id, 'raw' );
		$redirect = add_query_arg( 'aw_summary', is_wp_error( $result ) ? 'error' : 'success', $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * @return true|WP_Error
	 */
	public function generate_draft( $project_id ) {
		$api_key = AlphaWire_Projects_Settings::get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', 'No OpenAI API key configured (Projects → Settings).' );
		}

		$prompt = $this->build_prompt( $project_id );
		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$response = wp_remote_post(
			'https://api.openai.com/v1/chat/completions',
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => AlphaWire_Projects_Settings::get_model(),
						'temperature' => 0.4,
						'max_tokens'  => 220,
						'messages'    => array(
							array(
								'role'    => 'system',
								'content' => 'You write short, factual crypto-project summaries for AlphaWire. Use ONLY the information given to you — never invent facts, figures, or events. 3-4 sentences, present tense, neutral analytical tone, no marketing language.',
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
			$this->log_failure( $project_id, $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || empty( $body['choices'][0]['message']['content'] ) ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code );
			$this->log_failure( $project_id, $message );
			return new WP_Error( 'openai_error', $message );
		}

		$draft = trim( $body['choices'][0]['message']['content'] );

		if ( function_exists( 'update_field' ) ) {
			update_field( 'ai_summary_text', $draft, $project_id );
			update_field( 'ai_summary_status', 'pending', $project_id );
			update_field( 'ai_summary_updated', current_time( 'mysql' ), $project_id );
		} else {
			update_post_meta( $project_id, 'ai_summary_text', $draft );
			update_post_meta( $project_id, 'ai_summary_status', 'pending' );
			update_post_meta( $project_id, 'ai_summary_updated', current_time( 'mysql' ) );
		}

		return true;
	}

	/**
	 * Only approved, editorial-owned sources go in: description, timeline,
	 * and already-published AlphaWire coverage. Matches BE spec §27 ("AI
	 * should primarily summarise... not independently invent Project facts").
	 */
	private function build_prompt( $project_id ) {
		$post = get_post( $project_id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Project not found.' );
		}

		$lines   = array();
		$lines[] = 'Project name: ' . get_the_title( $post );

		$ticker = function_exists( 'get_field' ) ? get_field( 'ticker', $project_id ) : get_post_meta( $project_id, 'ticker', true );
		if ( $ticker ) {
			$lines[] = 'Ticker: ' . $ticker;
		}

		$description = get_the_excerpt( $post );
		if ( $description ) {
			$lines[] = 'Description: ' . wp_strip_all_tags( $description );
		}

		$categories = wp_get_post_terms( $project_id, 'pillar', array( 'fields' => 'names' ) );
		if ( ! empty( $categories ) && ! is_wp_error( $categories ) ) {
			$lines[] = 'Categories: ' . implode( ', ', $categories );
		}

		$timeline = function_exists( 'get_field' ) ? get_field( 'timeline', $project_id ) : array();
		if ( ! empty( $timeline ) ) {
			$lines[] = 'Timeline:';
			foreach ( array_slice( $timeline, 0, 8 ) as $event ) {
				$lines[] = sprintf(
					'- %s: %s — %s',
					$event['date'] ?? '',
					$event['title'] ?? '',
					$event['description'] ?? ''
				);
			}
		}

		$coverage = class_exists( 'AlphaWire_Projects_Content_Relationships' )
			? AlphaWire_Projects_Content_Relationships::get_coverage( $project_id )
			: array();
		if ( ! empty( $coverage ) ) {
			$lines[] = 'Recent approved AlphaWire coverage:';
			foreach ( array_slice( $coverage, 0, 5 ) as $item ) {
				$lines[] = sprintf( '- [%s] %s — %s', $item['type'], $item['title'], $item['excerpt'] );
			}
		}

		if ( empty( $description ) && empty( $timeline ) && empty( $coverage ) ) {
			return new WP_Error( 'insufficient_source', 'This Project has no description, timeline or coverage yet — add at least one before generating a summary.' );
		}

		return implode( "\n", $lines );
	}

	private function log_failure( $project_id, $message ) {
		error_log( sprintf( '[AlphaWire Projects] OpenAI summary generation failed for Project #%d: %s', $project_id, $message ) );
	}
}
