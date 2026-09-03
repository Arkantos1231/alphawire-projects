<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keeps `last_activity_at` current on a Project automatically — no extra
 * editorial step — so "Recently Updated" (build plan §8) can just sort by
 * it. Touched when the Project itself is saved, or when a piece of content
 * gets tagged to it via AlphaWire_Projects_Content_Relationships.
 */
class AlphaWire_Projects_Activity {

	const META_KEY = 'last_activity_at';

	public static function hooks() {
		add_action( 'save_post_' . AlphaWire_Projects_Post_Type::POST_TYPE, array( __CLASS__, 'touch_on_project_save' ) );

		foreach ( AlphaWire_Projects_Content_Relationships::CONTENT_TYPES as $type ) {
			add_action( 'save_post_' . $type, array( __CLASS__, 'touch_on_content_save' ) );
		}
	}

	public static function touch_on_project_save( $post_id ) {
		if ( self::should_skip( $post_id ) ) {
			return;
		}
		self::touch( $post_id );
	}

	public static function touch_on_content_save( $post_id ) {
		if ( self::should_skip( $post_id ) ) {
			return;
		}

		$related = function_exists( 'get_field' )
			? get_field( AlphaWire_Projects_Content_Relationships::META_KEY, $post_id )
			: get_post_meta( $post_id, AlphaWire_Projects_Content_Relationships::META_KEY, true );

		// ACF's post_object field returns a WP_Post or an ID depending on
		// the field's Return Format setting — handle both.
		if ( is_object( $related ) && isset( $related->ID ) ) {
			$related = $related->ID;
		}

		if ( empty( $related ) ) {
			return;
		}

		self::touch( (int) $related );
	}

	private static function touch( $project_id ) {
		update_post_meta( $project_id, self::META_KEY, current_time( 'mysql' ) );
	}

	private static function should_skip( $post_id ) {
		return wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id );
	}
}
