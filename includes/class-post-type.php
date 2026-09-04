<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AlphaWire_Projects_Post_Type {

	const POST_TYPE = 'project';

	// Bump this whenever register_top_priority_rewrites() changes shape —
	// it drives the one-time self-healing flush in maybe_flush_rewrite_rules().
	const REWRITE_VERSION = 6;

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Projects', 'alphawire-projects' ),
					'singular_name' => __( 'Project', 'alphawire-projects' ),
					'add_new_item'  => __( 'Add New Project', 'alphawire-projects' ),
					'edit_item'     => __( 'Edit Project', 'alphawire-projects' ),
					'all_items'     => __( 'Projects', 'alphawire-projects' ),
					'search_items'  => __( 'Search Projects', 'alphawire-projects' ),
					'not_found'     => __( 'No projects found', 'alphawire-projects' ),
				),
				'public'          => true,
				'menu_position'   => 5,
				'menu_icon'       => 'dashicons-chart-line',
				// 'custom-fields' is here so raw postmeta (coingecko_id, etc.)
				// is inspectable even before ACF is confirmed active in an
				// environment — remove once ACF is the confirmed system of record.
				'supports'        => array( 'title', 'editor', 'excerpt', 'thumbnail', 'revisions', 'custom-fields' ),
				// has_archive gives us /projects/ (the Directory) for free;
				// the archive template is still ours to build in Phase 3.
				'has_archive'     => 'projects',
				'rewrite'         => array(
					'slug'       => 'projects',
					'with_front' => false,
				),
				'show_in_rest'    => true,
				'rest_base'       => 'projects',
				// Deliberately empty: pillar/topic are attached in
				// class-taxonomies.php via register_taxonomy_for_object_type()
				// rather than declared here, since this plugin doesn't own them.
				'taxonomies'      => array(),
				'capability_type' => 'post',
				'hierarchical'    => false,
				'show_in_menu'    => true,
				'show_ui'         => true,
			)
		);
	}

	/**
	 * register_post_type()'s own rewrite rules land wherever WordPress's
	 * rule-generation order happens to put them. On this site an existing,
	 * unrelated rule already claims every two-segment URL:
	 *
	 *   ^(?!(podcast|podcasts|tw-auth|author))(?!.*sitemap|.*\.xml)([^/]+)/([^/]+)/?$
	 *     → pagename=news&news_category=$matches[2]&news_pillar=$matches[3]
	 *
	 * — a News-page category/pillar filter that was never told to exclude
	 * "projects", so /projects/{slug}/ matched IT first and WordPress never
	 * even tried our post type's own rule.
	 *
	 * This took two attempts to pin down. A plain add_rewrite_rule(...,
	 * 'top') wasn't strong enough — that rule turned out to be re-inserted
	 * from a `generate_rewrite_rules` callback, a later stage than 'top'.
	 * Hooking `generate_rewrite_rules` ourselves (below) got our rule into
	 * the list, but STILL behind theirs — because it's actually reinserted
	 * a second time from `rewrite_rules_array`, the very last filter
	 * WP_Rewrite::rewrite_rules() runs before the result is cached. So we
	 * hook that one too, at PHP_INT_MAX, and prepend there as well — that
	 * is the last possible place to go first, so nothing can outrank it.
	 * We keep the generate_rewrite_rules hook too; harmless belt-and-braces.
	 */
	public static function register_top_priority_rewrites() {
		add_action( 'generate_rewrite_rules', array( __CLASS__, 'prepend_rewrite_rules' ), PHP_INT_MAX );
		add_filter( 'rewrite_rules_array', array( __CLASS__, 'prepend_to_rules_array' ), PHP_INT_MAX );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_vars' ) );
	}

	public static function register_query_vars( $vars ) {
		$vars[] = 'aw_projects_view';
		return $vars;
	}

	public static function prepend_rewrite_rules( $wp_rewrite ) {
		$wp_rewrite->rules = array_merge( self::top_rules(), (array) $wp_rewrite->rules );
	}

	public static function prepend_to_rules_array( $rules ) {
		return array_merge( self::top_rules(), (array) $rules );
	}

	private static function top_rules() {
		return array(
			// Must come before the single-Project catch-all below, or a
			// visit to /projects/collections/ would be parsed as a Project
			// whose slug is "collections" instead.
			'^projects/collections/?$' => 'index.php?aw_projects_view=collections',
			'^projects/([^/]+)/?$'     => 'index.php?' . self::POST_TYPE . '=$matches[1]',
			'^projects/?$'             => 'index.php?post_type=' . self::POST_TYPE,
		);
	}

	/**
	 * WordPress only rebuilds its cached rewrite_rules option on plugin
	 * activation (or a manual visit to Settings → Permalinks) — so a change
	 * to the rules above would otherwise stay broken on every site that's
	 * already active until someone remembers to deactivate/reactivate.
	 * Comparing a stored version number is meant to self-heal on the very
	 * next request after an update instead — but that guard trusts that a
	 * past flush_rewrite_rules() call actually stuck, which turned out not
	 * to always be true (see v0.7.2). It's now backed up by actually
	 * checking the live rewrite_rules option content on every request.
	 */
	public static function maybe_flush_rewrite_rules() {
		$stored_version   = (string) get_option( 'alphawire_projects_rewrite_version' );
		$version_mismatch = $stored_version !== (string) self::REWRITE_VERSION;

		// The version guard above only proves we *asked* WordPress to
		// flush once — not that what it persisted still contains our
		// rules. Those drifted apart on the live site: rewrite_rules came
		// back stale (most likely a write that never stuck) while the
		// version option already said "done", so /projects/ and
		// /projects/{slug} silently lost to the site's News-page rule
		// again with no version bump to trigger a retry. A manual
		// Settings -> Permalinks save fixed it instantly — the very same
		// flush_rewrite_rules() call made here — so the guard was the
		// bug, not the mechanism. Checking the *actual* option content
		// closes that gap: any future drift self-heals on the next
		// request instead of waiting on a version bump that's already
		// spent.
		$current_rules = get_option( 'rewrite_rules' );
		$rules_missing  = ! is_array( $current_rules ) || ! self::top_rules_are_present( $current_rules );

		if ( $version_mismatch || $rules_missing ) {
			flush_rewrite_rules();
			update_option( 'alphawire_projects_rewrite_version', self::REWRITE_VERSION, false );
		}
	}

	/**
	 * True only if every one of our top_rules() patterns is present as a
	 * key in the live rewrite_rules option — i.e. WordPress actually
	 * persisted them, not just that we once asked it to.
	 *
	 * @param array $current_rules The live `rewrite_rules` option value.
	 */
	private static function top_rules_are_present( array $current_rules ) {
		foreach ( array_keys( self::top_rules() ) as $pattern ) {
			if ( ! array_key_exists( $pattern, $current_rules ) ) {
				return false;
			}
		}
		return true;
	}
}
