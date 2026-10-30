<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates an AI Project Summary DRAFT — never publishes anything itself.
 * Mirrors the Market Summaries pattern already live on the site: Claude
 * writes from approved sources only, the result always lands as "Pending
 * Review", and an editor edits/rejects/regenerates/approves from wp-admin
 * before it's ever shown on the front end (BE spec §8-§10, Darian & Andy
 * §8-§10 — "AI generation must not happen when a user opens a Project page").
 *
 * Was OpenAI (Chat Completions) through v0.9.0 — switched to the Anthropic
 * Messages API at product's request. Same draft-only/never-on-render
 * behavior; only the HTTP call, request/response shape and error text
 * changed. See class-settings.php for the (renamed) API key/model options.
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
		add_action( 'admin_post_alphawire_projects_reject_summary', array( $this, 'handle_reject_trigger' ) );
		add_action( 'admin_post_alphawire_projects_mark_report_reviewed', array( $this, 'handle_mark_report_reviewed' ) );
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
		// GET, not POST — see class-ai-summary-metabox.php::render() for
		// why this is a plain nonce'd link rather than a <form>.
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;

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
	 * Sends an existing AI Summary draft/approved text to its own
	 * "Rejected" status (a one-click "Reject" alongside "Generate /
	 * refresh draft", see class-ai-summary-metabox.php::render()) so an
	 * editor doesn't have to know to flip the Status field by hand to pull
	 * a summary back off the approval track. A dedicated status (rather
	 * than reusing "Draft") keeps "never generated yet" and "an editor
	 * actively rejected this" distinguishable in wp-admin — see the
	 * 'rejected' choice on the ai_summary_status field in class-fields.php.
	 */
	public function handle_reject_trigger() {
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;

		if ( ! $project_id
			|| ! current_user_can( 'edit_post', $project_id )
			|| ! check_admin_referer( 'alphawire_reject_summary_' . $project_id )
		) {
			wp_die( esc_html__( 'You are not authorised to do this.', 'alphawire-projects' ), 403 );
		}

		if ( function_exists( 'update_field' ) ) {
			update_field( 'ai_summary_status', 'rejected', $project_id );
		} else {
			update_post_meta( $project_id, 'ai_summary_status', 'rejected' );
		}

		$redirect = get_edit_post_link( $project_id, 'raw' );
		$redirect = add_query_arg( 'aw_summary', 'rejected', $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Marks one reported issue (class-rest-api.php::report_issue()) as
	 * reviewed from the Project edit screen — the "Editorial review" step
	 * of the correction workflow (Darian & Andy §11 / BE ticket §29). This
	 * only clears it from the open list; actually fixing the summary still
	 * goes through the existing Generate/Reject/Approve buttons above it.
	 */
	public function handle_mark_report_reviewed() {
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
		$report_id  = isset( $_GET['report_id'] ) ? sanitize_text_field( wp_unslash( $_GET['report_id'] ) ) : '';

		if ( ! $project_id || '' === $report_id
			|| ! current_user_can( 'edit_post', $project_id )
			|| ! check_admin_referer( 'alphawire_mark_report_reviewed_' . $project_id . '_' . $report_id )
		) {
			wp_die( esc_html__( 'You are not authorised to do this.', 'alphawire-projects' ), 403 );
		}

		$reports = get_post_meta( $project_id, 'ai_summary_reports', true );
		$reports = is_array( $reports ) ? $reports : array();
		foreach ( $reports as &$report ) {
			if ( isset( $report['id'] ) && $report['id'] === $report_id ) {
				$report['status'] = 'reviewed';
				break;
			}
		}
		unset( $report );
		update_post_meta( $project_id, 'ai_summary_reports', $reports );

		$redirect = get_edit_post_link( $project_id, 'raw' );
		$redirect = add_query_arg( 'aw_summary', 'report_reviewed', $redirect );
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * @return true|WP_Error
	 */
	public function generate_draft( $project_id ) {
		$api_key = AlphaWire_Projects_Settings::get_api_key();
		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', 'No Claude API key configured (Projects → Settings).' );
		}

		$prompt = $this->build_prompt( $project_id );
		if ( is_wp_error( $prompt ) ) {
			return $prompt;
		}

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 25,
				'headers' => array(
					// Anthropic's current docs list Authorization: Bearer as
					// the primary auth method (x-api-key is kept as a legacy
					// fallback) — same header shape the OpenAI call used, so
					// this line barely changed.
					'Authorization'     => 'Bearer ' . $api_key,
					'anthropic-version' => '2023-06-01',
					'Content-Type'      => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model'       => AlphaWire_Projects_Settings::get_model(),
						// `temperature` removed (v0.9.14): Claude started rejecting it
						// as a deprecated parameter for the configured model (error seen
						// in wp-admin: "'temperature' is deprecated for this model"),
						// which meant every AI Summary generation attempt failed. Omitting
						// it just falls back to the API's own default sampling — nothing
						// else about the request or its output format changes.
						'max_tokens'  => 220,
						// The Messages API takes the system prompt as its own
						// top-level field — not a "system"-role message inside
						// `messages` the way OpenAI's Chat Completions did.
						'system'      => 'You write short, factual crypto-project summaries for AlphaWire. Use ONLY the information given to you — never invent facts, figures, or events. Never mention prices, price levels, market capitalisation, trading volume, percentage moves or any other market figure, even if the source material contains them — the page already shows live market data next to this summary, and a number written here can go stale and contradict it. Describe what the project is, what it does and recent milestones. 3-4 sentences, present tense, neutral analytical tone, no marketing language. Output plain prose only: no title, no heading, no Markdown, no bullet points.',
						'messages'    => array(
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

		// Claude's reply text sits at content[0].text (an array of content
		// blocks, text being the only type we ever ask for here) — not
		// choices[0].message.content the way OpenAI shaped it.
		if ( 200 !== $code || empty( $body['content'][0]['text'] ) ) {
			$message = isset( $body['error']['message'] ) ? $body['error']['message'] : ( 'HTTP ' . $code );
			$this->log_failure( $project_id, $message );
			return new WP_Error( 'claude_error', $message );
		}

		// Belt-and-braces for the "plain prose only" instruction: strips any
		// Markdown the model still emits anywhere in the text (a leading
		// "# Title" line, **bold**, list markers, stray backticks) rather
		// than just a leading symbol, so a stray `#`/`*` mid-sentence gets
		// cleaned too (see the MVP feedback item this closes). The same
		// method also runs at read time (class-rest-api.php::ai_summary())
		// so an already-approved summary with a leftover symbol gets fixed
		// the next time it's shown, without needing a manual regenerate.
		$draft = self::clean_summary_text( $body['content'][0]['text'] );

		// "Where practical, previous approved versions should be retained"
		// (Darian & Andy §11) — if there's a currently-APPROVED (i.e. live on
		// the front end) summary about to be overwritten, snapshot it before
		// it's gone, so a correction never silently loses the text a visitor
		// may have just reported an issue against.
		$previous_status = function_exists( 'get_field' ) ? get_field( 'ai_summary_status', $project_id ) : get_post_meta( $project_id, 'ai_summary_status', true );
		if ( 'approved' === $previous_status ) {
			$previous_text = function_exists( 'get_field' ) ? get_field( 'ai_summary_text', $project_id ) : get_post_meta( $project_id, 'ai_summary_text', true );
			if ( $previous_text ) {
				$history = get_post_meta( $project_id, 'ai_summary_history', true );
				$history = is_array( $history ) ? $history : array();
				$history[] = array(
					'text' => $previous_text,
					'at'   => current_time( 'mysql' ),
				);
				// Keep only the 5 most recent previous versions so this
				// meta row doesn't grow unbounded on a Project that gets
				// corrected often.
				if ( count( $history ) > 5 ) {
					$history = array_slice( $history, -5 );
				}
				update_post_meta( $project_id, 'ai_summary_history', $history );
			}
		}

		if ( function_exists( 'update_field' ) ) {
			update_field( 'ai_summary_text', $draft, $project_id );
			update_field( 'ai_summary_status', 'pending', $project_id );
			update_field( 'ai_summary_updated', current_time( 'mysql' ), $project_id );
		} else {
			update_post_meta( $project_id, 'ai_summary_text', $draft );
			update_post_meta( $project_id, 'ai_summary_status', 'pending' );
			update_post_meta( $project_id, 'ai_summary_updated', current_time( 'mysql' ) );
		}

		delete_option( 'aw_ai_summary_last_error_' . $project_id );

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

	/**
	 * Belt-and-braces for the "plain prose only" instruction: strips any
	 * Markdown the model still emits (a leading "# Title" line, **bold**,
	 * list markers) so nothing renders as a literal symbol on the page.
	 * Public/static so class-rest-api.php::ai_summary() can also run it
	 * retroactively over an already-approved summary at read time.
	 */
	public static function clean_summary_text( $text ) {
		$text  = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
		$lines = array();
		foreach ( explode( "\n", $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// A heading line on its own is a title, not part of the summary.
			if ( preg_match( '/^#{1,6}\s/', $line ) ) {
				continue;
			}
			$line    = preg_replace( '/^(?:[-*+]|\d+[.)])\s+/', '', $line );
			$line    = preg_replace( '/^#+\s*/', '', $line );
			$lines[] = $line;
		}
		$text = implode( ' ', $lines );
		$text = preg_replace( '/(\*\*|__)(.+?)\1/', '$2', $text );
		$text = preg_replace( '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/', '$1', $text );
		$text = str_replace( '`', '', $text );

		return trim( preg_replace( '/\s{2,}/', ' ', $text ) );
	}

	/**
	 * Logs to the PHP error log (as before) AND stores the message as a
	 * per-Project option, so an editor can see WHY generation failed
	 * directly in wp-admin (class-ai-summary-metabox.php) without needing
	 * server/FTP access to read the error log — most editors won't have
	 * that, and "check the PHP error log" was a dead end for them.
	 * Overwritten by the next attempt; cleared on the next success.
	 */
	private function log_failure( $project_id, $message ) {
		error_log( sprintf( '[AlphaWire Projects] Claude summary generation failed for Project #%d: %s', $project_id, $message ) );
		update_option(
			'aw_ai_summary_last_error_' . $project_id,
			array(
				'message' => (string) $message,
				'when'    => current_time( 'mysql' ),
			),
			false
		);
	}
}
