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
			'text'      => 'approved' === $status ? self::field( 'ai_summary_text', $project_id ) : null,
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
			$out[] = array(
				'id'   => $related_post->ID,
				'slug' => $related_post->post_name,
				'name' => get_the_title( $related_post ),
			);
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
