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
			'/projects/(?P<slug>[a-zA-Z0-9-]+)/report-issue',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'report_issue' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'message' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
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

	const REPORTS_META_KEY = 'ai_summary_reports';
	const REPORTS_MAX      = 50;

	/**
	 * "Report an issue" on the AI Project Summary (engineering review §11:
	 * reader reports → editorial review → correct/regenerate → approve).
	 * Stores the report on the Project — listed in the AI Summary box on
	 * its edit screen — and emails the configured address. Public, so it
	 * has a honeypot and a per-IP rate limit instead of a login.
	 */
	public static function report_issue( $request ) {
		$slug = $request->get_param( 'slug' );
		$post = get_page_by_path( $slug, OBJECT, AlphaWire_Projects_Post_Type::POST_TYPE );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return new WP_Error( 'not_found', 'Project not found', array( 'status' => 404 ) );
		}

		// Bots fill every field; people never see this one.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return array( 'ok' => true );
		}

		$message = trim( sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
		if ( mb_strlen( $message ) < 5 ) {
			return new WP_Error( 'too_short', 'Please tell us what looks wrong.', array( 'status' => 400 ) );
		}
		$message = mb_substr( $message, 0, 1000 );

		$ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$rate_key = 'aw_report_rl_' . md5( $ip . '|' . $post->ID );
		if ( get_transient( $rate_key ) ) {
			return new WP_Error( 'rate_limited', 'Thanks — we already have your report. Please try again in a minute.', array( 'status' => 429 ) );
		}
		set_transient( $rate_key, 1, MINUTE_IN_SECONDS );

		$reports = get_post_meta( $post->ID, self::REPORTS_META_KEY, true );
		$reports = is_array( $reports ) ? $reports : array();
		array_unshift(
			$reports,
			array(
				'message' => $message,
				'when'    => current_time( 'mysql' ),
				'summary' => (string) self::field( 'ai_summary_text', $post->ID ),
			)
		);
		update_post_meta( $post->ID, self::REPORTS_META_KEY, array_slice( $reports, 0, self::REPORTS_MAX ) );

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

		return array( 'ok' => true );
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
		return array(
			'status'    => $status,
			// Cleaned at read time too, so summaries approved before the
			// Markdown fix stop showing a literal "#" without a regenerate.
			'text'      => 'approved' === $status
				? AlphaWire_Projects_AI_Summary_Service::clean_summary_text( self::field( 'ai_summary_text', $project_id ) )
				: null,
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

	private static function field( $name, $post_id, $default = null ) {
		if ( function_exists( 'get_field' ) ) {
			$value = get_field( $name, $post_id );
			return null === $value || '' === $value ? $default : $value;
		}
		$value = get_post_meta( $post_id, $name, true );
		return '' === $value ? $default : $value;
	}
}
