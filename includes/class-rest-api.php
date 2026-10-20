<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Full single-project data contract (build plan §5). List/card contexts use
 * AlphaWire_Projects_Directory_REST's lighter shape instead — this one is
 * meant for the Project Profile page.
 */
class AlphaWire_Projects_REST {

	const NAMESPACE = 'alphawire-projects/v1';

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<slug>[a-zA-Z0-9-]+)/coverage',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_coverage_page' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<slug>[a-zA-Z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_project' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/projects/(?P<slug>[a-zA-Z0-9-]+)/report-issue',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'report_issue' ),
				// Intentionally public: Darian & Andy §11 / BE ticket §29 /
				// FE ticket §17 all describe this as a visitor-facing action
				// ("User identifies an issue" / "Report Issue interaction"),
				// not something gated behind login. See report_issue() for
				// the lighter-weight anti-abuse checks used instead.
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function get_project( $request ) {
		$slug = $request->get_param( 'slug' );
		$post = get_page_by_path( $slug, OBJECT, AlphaWire_Projects_Post_Type::POST_TYPE );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'not_found', 'Project not found', array( 'status' => 404 ) );
		}

		return self::build_payload( $post );
	}

	public static function get_coverage_page( $request ) {
		$slug    = $request->get_param( 'slug' );
		$post    = get_page_by_path( $slug, OBJECT, AlphaWire_Projects_Post_Type::POST_TYPE );
		$scope   = sanitize_key( $request->get_param( 'scope' ) ?: 'coverage' );
		$type    = sanitize_title( $request->get_param( 'type' ) ?: 'all' );
		$page    = max( 1, (int) ( $request->get_param( 'page' ) ?: 1 ) );
		$per_page = max( 1, min( 20, (int) ( $request->get_param( 'per_page' ) ?: 6 ) ) );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'not_found', 'Project not found', array( 'status' => 404 ) );
		}
		if ( ! in_array( $scope, array( 'coverage', 'research' ), true ) ) {
			$scope = 'coverage';
		}

		return AlphaWire_Projects_Content_Relationships::get_coverage_page( $post->ID, $scope, $type, $page, $per_page );
	}

	/**
	 * The same full data contract, callable directly with an already-loaded
	 * post — used by the Project Profile template so it doesn't have to
	 * make a loopback HTTP call to its own REST endpoint just to get data
	 * it already has the post object for.
	 */
	public static function build_payload( $post ) {
		$coingecko_id = self::field( 'coingecko_id', $post->ID );

		return array(
			'id'              => $post->ID,
			'slug'            => $post->post_name,
			'name'            => get_the_title( $post ),
			'description'     => get_the_excerpt( $post ),
			'ticker'          => self::field( 'ticker', $post->ID ),
			'verified'        => (bool) self::field( 'verified', $post->ID ),
			'logo'            => get_the_post_thumbnail_url( $post, 'medium' ),
			'launchDate'      => self::field( 'launch_date', $post->ID ),
			'categories'      => wp_get_post_terms( $post->ID, 'pillar', array( 'fields' => 'names' ) ),
			'narratives'      => wp_get_post_terms( $post->ID, 'topic', array( 'fields' => 'names' ) ),
			'links'           => self::field( 'links', $post->ID, array() ),
			'market'          => AlphaWire_Projects_Market_Data_Service::instance()->get_market_data( $coingecko_id ),
			'aiSummary'       => self::ai_summary( $post->ID ),
			'timeline'        => self::field( 'timeline', $post->ID, array() ),
			'relatedProjects' => self::related_projects( $post->ID ),
			'coverage'        => AlphaWire_Projects_Content_Relationships::get_coverage( $post->ID ),
		);
	}

	/**
	 * Only ever exposes the *approved* summary — a pending/draft summary
	 * exists in wp-admin but is invisible here, per the AI Summary
	 * workflow's editorial gate (never shown until an editor approves it).
	 */
	private static function ai_summary( $project_id ) {
		$status = self::field( 'ai_summary_status', $project_id );
		$text   = 'approved' === $status ? self::field( 'ai_summary_text', $project_id ) : null;

		// Run the same Markdown/symbol cleanup generate_draft() applies to
		// a new draft against whatever is already stored, at read time —
		// so an older approved summary that still has a stray `#`/`**` in
		// it (saved before this cleanup existed, or before it covered the
		// whole text rather than just a leading symbol) gets fixed the
		// next time it's shown, without needing a manual regenerate.
		if ( $text && class_exists( 'AlphaWire_Projects_AI_Summary_Service' ) ) {
			$text = AlphaWire_Projects_AI_Summary_Service::clean_summary_text( $text );
		}

		return array(
			'status'    => $status,
			'text'      => $text,
			'updatedAt' => self::field( 'ai_summary_updated', $project_id ),
		);
	}

	private static function related_projects( $project_id ) {
		$related = self::field( 'related_projects', $project_id, array() );
		if ( empty( $related ) ) {
			return array();
		}

		$out = array();
		foreach ( $related as $item ) {
			$related_post = is_object( $item ) ? $item : get_post( $item );
			if ( ! $related_post ) {
				continue;
			}
			$out[] = AlphaWire_Projects_Directory_REST::card( $related_post );
		}
		return $out;
	}

	/**
	 * Public step of the AI Summary correction workflow (Darian & Andy
	 * Review §11 "AI Correction Workflow", BE ticket §29 "REPORT /
	 * CORRECTION WORKFLOW", FE ticket §17 "Report Issue interaction") — any
	 * visitor viewing a Project's approved AI Summary can flag it. This
	 * never edits or republishes anything itself; it only queues the report
	 * for an editor to review on the Project edit screen (see
	 * class-ai-summary-metabox.php's "Reported issues" list) — same
	 * editorial-gate rule the rest of the AI Summary workflow already
	 * follows (nothing reaches the front end without an editor's approval).
	 *
	 * @return array|WP_Error
	 */
	public static function report_issue( $request ) {
		$slug = $request->get_param( 'slug' );
		$post = get_page_by_path( $slug, OBJECT, AlphaWire_Projects_Post_Type::POST_TYPE );

		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'not_found', 'Project not found', array( 'status' => 404 ) );
		}

		// Lightweight anti-abuse, not authentication: an anonymous visitor
		// reporting an issue is the expected, intended caller here. This
		// only requires the nonce every front-end fetch() already sends
		// (class-templates.php::enqueue_assets() now creates one
		// unconditionally, logged in or not) so a request has to have come
		// from a page this site actually rendered, filtering out the most
		// naive scripted spam without requiring a login nobody is meant to
		// need for this action.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'invalid_nonce', 'Could not verify this request — please reload the page and try again.', array( 'status' => 403 ) );
		}

		// Honeypot: a field real visitors never see or fill in (hidden via
		// CSS in assets/css/projects.css, see .aw-report-hp). A filled-in
		// value means something submitted every field programmatically, so
		// the report is silently dropped — the caller still sees a normal
		// success response, which tells a scraping bot nothing about what
		// gave it away.
		$honeypot = $request->get_param( 'website' );
		if ( ! empty( $honeypot ) ) {
			return array( 'success' => true );
		}

		$message = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		if ( mb_strlen( $message ) < 10 || mb_strlen( $message ) > 1000 ) {
			return new WP_Error( 'invalid_message', 'Please describe the issue in 10-1000 characters.', array( 'status' => 422 ) );
		}

		// Simple per-Project/per-visitor throttle (10 min) so one person
		// re-submitting (double click, page refresh) doesn't flood the
		// editor's review list with duplicates of the same report.
		$ip           = $request->get_header( 'X-Forwarded-For' );
		$ip           = $ip ? trim( explode( ',', $ip )[0] ) : ( $_SERVER['REMOTE_ADDR'] ?? '' );
		$throttle_key = 'aw_report_throttle_' . $post->ID . '_' . md5( $ip );
		if ( get_transient( $throttle_key ) ) {
			return array( 'success' => true, 'throttled' => true );
		}
		set_transient( $throttle_key, 1, 10 * MINUTE_IN_SECONDS );

		$reports = get_post_meta( $post->ID, 'ai_summary_reports', true );
		$reports = is_array( $reports ) ? $reports : array();

		$reports[] = array(
			'id'               => uniqid( 'rep_', true ),
			'message'          => $message,
			// What was actually approved/shown when this was reported — by
			// the time an editor reviews it, generate_draft() may already
			// have overwritten ai_summary_text (which itself gets snapshotted
			// into ai_summary_history, see class-ai-summary-service.php), so
			// this keeps the report tied to the exact text the visitor saw.
			'summary_snapshot' => self::field( 'ai_summary_text', $post->ID ),
			'created'          => current_time( 'mysql' ),
			'status'           => 'new',
		);

		// Cap at the most recent 50 reports so this meta row can't grow
		// unbounded under sustained abuse.
		if ( count( $reports ) > 50 ) {
			$reports = array_slice( $reports, -50 );
		}

		update_post_meta( $post->ID, 'ai_summary_reports', $reports );

		// Also emails whoever's configured in Settings (defaults to the
		// site admin email) so a report doesn't just sit unseen in
		// wp-admin until someone happens to open this Project — the list
		// in class-ai-summary-metabox.php stays the system of record either
		// way (an email never includes the full report list, only this one).
		wp_mail(
			AlphaWire_Projects_Settings::get_report_email(),
			sprintf( '[AlphaWire Projects] Summary issue reported: %s', get_the_title( $post ) ),
			sprintf(
				"A reader reported an issue with the AI Project Summary for %s.\n\nReport:\n%s\n\nReview it here:\n%s",
				get_the_title( $post ),
				$message,
				admin_url( 'post.php?post=' . $post->ID . '&action=edit' )
			)
		);

		return array( 'success' => true );
	}

	private static function field( $name, $post_id, $default = null ) {
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $name, $post_id );
			return null === $value || '' === $value ? $default : $value;
		}
		$value = get_post_meta( $post_id, $name, true );
		return '' === $value ? $default : $value;
	}
}
