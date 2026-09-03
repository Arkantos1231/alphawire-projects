<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end templates for the Directory (/projects/) and the Project
 * Profile (/projects/{slug}/) — build plan Phases 3-4. Plain PHP templates
 * loaded via the standard `single_template`/`archive_template` filters
 * rather than a page-builder template, so the whole front end lives in the
 * plugin's own version-controlled files instead of the database. They call
 * this plugin's own data classes directly (Market_Data_Service,
 * Content_Relationships, the REST payload builders) rather than looping
 * back through our own REST API over HTTP.
 *
 * get_header()/get_footer() are used as normal, so the site's real nav,
 * ticker bar and footer render exactly as they do on every other page —
 * only the content in between is ours.
 */
class AlphaWire_Projects_Templates {

	public static function hooks() {
		add_filter( 'single_template', array( __CLASS__, 'single_project_template' ) );
		add_filter( 'archive_template', array( __CLASS__, 'archive_project_template' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	public static function single_project_template( $template ) {
		if ( is_singular( AlphaWire_Projects_Post_Type::POST_TYPE ) ) {
			$custom = ALPHAWIRE_PROJECTS_PATH . 'templates/single-project.php';
			if ( file_exists( $custom ) ) {
				return $custom;
			}
		}
		return $template;
	}

	public static function archive_project_template( $template ) {
		if ( is_post_type_archive( AlphaWire_Projects_Post_Type::POST_TYPE ) ) {
			$custom = ALPHAWIRE_PROJECTS_PATH . 'templates/archive-project.php';
			if ( file_exists( $custom ) ) {
				return $custom;
			}
		}
		return $template;
	}

	public static function enqueue_assets() {
		if ( is_singular( AlphaWire_Projects_Post_Type::POST_TYPE ) || is_post_type_archive( AlphaWire_Projects_Post_Type::POST_TYPE ) ) {
			wp_enqueue_style(
				'alphawire-projects',
				ALPHAWIRE_PROJECTS_URL . 'assets/css/projects.css',
				array(),
				ALPHAWIRE_PROJECTS_VERSION
			);
		}
	}
}
