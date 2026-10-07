<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds existing AlphaWire content related to a Project through the
 * content-side "Related Project" field the editor sets on each article or
 * podcast. Explicitly selected News and Podcasts remain a separate
 * Project-side relationship used by the Research tab.
 */
class AlphaWire_Projects_Content_Relationships {

	const CONTENT_TYPES = array( 'news', 'podcast', 'post' );
	const META_KEY               = 'related_project';
	const PROJECT_NEWS_META_KEY  = 'related_news';
	const PROJECT_PODCASTS_META_KEY = 'related_podcasts';
	const PROJECT_RELATED_PROJECTS_META_KEY = 'related_projects';

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
	public static function get_coverage( $project_id, $bucket = null, $limit = 20 ) {
		$project_id = (int) $project_id;
		if ( ! $project_id ) {
			return array();
		}

		// Coverage is the content an editor explicitly linked to THIS
		// Project through the "Related Project" field on the article/
		// podcast (engineering review §13/§15: "Editor selects Project →
		// WordPress creates relationship"). It used to match on shared
		// pillar/topic terms instead, so every Project in a category got
		// the same feed — e.g. a Tether lawsuit story on the USD Coin page
		// (staging feedback #3). The field is a single post_object, stored
		// as a plain post ID, so an exact meta match is enough.
		$args = array(
			'post_type'      => self::CONTENT_TYPES,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
			'meta_query'     => array(
				array(
					'key'     => self::META_KEY,
					'value'   => $project_id,
					'compare' => '=',
				),
			),
		);

		if ( 'podcast' === $bucket ) {
			$args['post_type'] = array( 'podcast' );
		} elseif ( 'news' === $bucket ) {
			$args['post_type'] = array( 'news', 'post' );
		} elseif ( 'research' === $bucket ) {
			$args['category_name'] = 'deepdive';
		} elseif ( 'interviews' === $bucket ) {
			$args['category_name'] = 'interviews';
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = self::coverage_item( $post );
		}

		wp_reset_postdata();

		return $items;
	}

	/**
	 * Returns only the News and Podcasts explicitly selected on the Project
	 * (its own "Research" picks). Related Projects are NOT mixed in here —
	 * they have their own Related tab.
	 *
	 * Staging feedback #4: with nothing picked, ACF returns false/'' and the
	 * old (array) cast turned that into [false]; get_post( false ) returns
	 * the *current* global post, so the Project showed up as its own
	 * Research item. get_project_content() now drops empty values, and the
	 * current Project is excluded explicitly as a second guard.
	 */
	public static function get_selected_coverage( $project_id ) {
		$items = array();
		$seen  = array( (int) $project_id => true );

		$selected_news     = self::get_project_content( self::PROJECT_NEWS_META_KEY, $project_id );
		$selected_podcasts = self::get_project_content( self::PROJECT_PODCASTS_META_KEY, $project_id );

		foreach ( array_merge( $selected_news, $selected_podcasts ) as $selected ) {
			$post = is_object( $selected ) ? $selected : get_post( (int) $selected );
			if (
				! $post ||
				'publish' !== $post->post_status ||
				! in_array( $post->post_type, array( 'news', 'podcast' ), true ) ||
				isset( $seen[ $post->ID ] )
			) {
				continue;
			}

			$items[]           = self::coverage_item( $post );
			$seen[ $post->ID ] = true;
		}

		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $b['date'] ?? '', $a['date'] ?? '' );
			}
		);

		return $items;
	}

	public static function get_coverage_page( $project_id, $scope = 'coverage', $type = 'all', $page = 1, $per_page = 6 ) {
		$items = 'research' === $scope
			? self::get_selected_coverage( $project_id )
			: self::get_coverage( $project_id, null, -1 );

		if ( 'all' !== $type ) {
			$items = array_values(
				array_filter(
					$items,
					function ( $item ) use ( $type ) {
						return sanitize_title( $item['type'] ) === sanitize_title( $type );
					}
				)
			);
		}

		usort(
			$items,
			function ( $a, $b ) {
				return strcmp( $b['date'] ?? '', $a['date'] ?? '' );
			}
		);

		$per_page = max( 1, min( 20, (int) $per_page ) );
		$page     = max( 1, (int) $page );
		$total    = count( $items );
		$offset   = ( $page - 1 ) * $per_page;

		return array(
			'items'   => array_slice( $items, $offset, $per_page ),
			'page'    => $page,
			'perPage' => $per_page,
			'total'   => $total,
			'hasMore' => ( $offset + $per_page ) < $total,
		);
	}

	private static function get_project_content( $meta_key, $project_id ) {
		$value = function_exists( 'get_field' )
			? get_field( $meta_key, $project_id )
			: get_post_meta( $project_id, $meta_key, true );

		if ( empty( $value ) ) {
			return array();
		}

		// Never let a falsy entry through: get_post( false|0|'' ) returns
		// the current global post instead of nothing.
		return array_values( array_filter( is_array( $value ) ? $value : array( $value ) ) );
	}

	private static function coverage_item( $post ) {
		$read_time = null;
		if ( 'podcast' === $post->post_type ) {
			$length = function_exists( 'get_field' ) ? get_field( 'length', $post->ID ) : get_post_meta( $post->ID, 'length', true );
			if ( is_string( $length ) && preg_match( '/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $length, $parts ) ) {
				$seconds   = ( (int) $parts[1] * 60 * ( isset( $parts[3] ) ? 60 : 1 ) ) + ( (int) $parts[2] * ( isset( $parts[3] ) ? 60 : 1 ) ) + ( isset( $parts[3] ) ? (int) $parts[3] : 0 );
				$read_time = max( 1, (int) ceil( $seconds / 60 ) );
			}
		} else {
			$word_count = str_word_count( wp_strip_all_tags( $post->post_content ) );
			$read_time  = max( 1, (int) ceil( $word_count / 200 ) );
		}

		return array(
			'id'      => $post->ID,
			'type'    => self::content_type_label( $post ),
			'title'   => get_the_title( $post ),
			'excerpt' => get_the_excerpt( $post ),
			'image'   => get_the_post_thumbnail_url( $post, 'medium' ),
			'date'    => get_the_date( 'c', $post ),
			'readTime' => $read_time,
			'url'     => get_permalink( $post ),
		);
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
