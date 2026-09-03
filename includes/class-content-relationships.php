<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connects existing AlphaWire content (News, Podcasts, legacy Posts) to a
 * Project via a single field on the CONTENT side — not a new relationship
 * CPT, and never a duplicated copy of the article. This is the "editor
 * picks the Project when publishing" workflow from the BE spec and the
 * Darian & Andy review (§13/§15).
 */
class AlphaWire_Projects_Content_Relationships {

	const CONTENT_TYPES = array( 'news', 'podcast', 'post' );
	const META_KEY       = 'related_project';

	public static function register_fields() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		$location = array();
		foreach ( self::CONTENT_TYPES as $type ) {
			$location[] = array(
				array(
					'param'    => 'post_type',
					'operator' => '==',
					'value'    => $type,
				),
			);
		}

		acf_add_local_field_group(
			array(
				'key'      => 'group_alphawire_project_relation',
				'title'    => 'AlphaWire Projects',
				'fields'   => array(
					array(
						'key'          => 'field_aw_related_project',
						'label'        => 'Related Project',
						'name'         => self::META_KEY,
						'type'         => 'post_object',
						'post_type'    => array( AlphaWire_Projects_Post_Type::POST_TYPE ),
						'allow_null'   => 1,
						'instructions' => "If this piece is about a specific Project, select it here — it appears automatically on that Project's AlphaWire Coverage / Research tab. Don't duplicate the content inside Projects.",
					),
				),
				'location' => $location,
				'position' => 'side',
			)
		);
	}

	/**
	 * @param int         $project_id
	 * @param string|null $bucket 'news' | 'podcast' | 'research' | 'interviews' | null (all)
	 */
	public static function get_coverage( $project_id, $bucket = null ) {
		$args = array(
			'post_type'      => self::CONTENT_TYPES,
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array(
				array(
					'key'   => self::META_KEY,
					'value' => $project_id,
				),
			),
		);

		if ( 'podcast' === $bucket ) {
			$args['post_type'] = array( 'podcast' );
		} elseif ( 'news' === $bucket ) {
			$args['post_type'] = array( 'news' );
		} elseif ( 'research' === $bucket ) {
			$args['category_name'] = 'deepdive';
		} elseif ( 'interviews' === $bucket ) {
			$args['category_name'] = 'interviews';
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'      => $post->ID,
				'type'    => self::content_type_label( $post ),
				'title'   => get_the_title( $post ),
				'excerpt' => get_the_excerpt( $post ),
				'image'   => get_the_post_thumbnail_url( $post, 'medium' ),
				'date'    => get_the_date( 'c', $post ),
				'url'     => get_permalink( $post ),
			);
		}

		wp_reset_postdata();

		return $items;
	}

	private static function content_type_label( $post ) {
		if ( 'podcast' === $post->post_type ) {
			return 'Podcast';
		}
		if ( has_category( 'interviews', $post ) ) {
			return 'Interview';
		}
		if ( has_category( 'deepdive', $post ) ) {
			return 'Research';
		}
		return 'News';
	}
}
