<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dependency-free "update from GitHub" checker. No third-party library —
 * this sandbox has no network path to fetch one, and it would be one more
 * thing to keep in sync anyway. Same hand-rolled wp_remote_get() style
 * already used for CoinGecko/OpenAI.
 *
 * Tracks a branch, not a formal GitHub Release: it compares the
 * `Version:` header of alphawire-projects.php on that branch (fetched from
 * raw.githubusercontent.com) against the installed version. Every push to
 * that branch that bumps the version becomes an available update — no
 * extra tagging/release step, matching how fast this plugin iterates.
 *
 * Repo ("owner/repo") and branch are read from Projects → Settings, not
 * hard-coded, so this doesn't need editing before the repo exists.
 */
class AlphaWire_Projects_Updater {

	const CACHE_KEY = 'alphawire_projects_gh_version';

	public static function hooks() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'check_for_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );

		// A one-click "Check for updates" — before this, the only way to
		// pick up a fresh GitHub push before the 6-hour cache (see
		// get_remote_version()) expired on its own was WordPress's
		// site-wide "Check again" on Dashboard -> Updates, which most
		// people don't know re-checks custom updaters too. This link does
		// the same thing but scoped to just this plugin, from the Plugins
		// list row where people actually look for it.
		add_filter( 'plugin_action_links_' . self::plugin_file(), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_post_aw_projects_check_update', array( __CLASS__, 'handle_check_update' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_checked_notice' ) );
	}

	private static function plugin_file() {
		return plugin_basename( ALPHAWIRE_PROJECTS_PATH . 'alphawire-projects.php' );
	}

	public static function check_for_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$repo = AlphaWire_Projects_Settings::get_github_repo();
		if ( '' === $repo ) {
			return $transient;
		}

		$branch          = AlphaWire_Projects_Settings::get_github_branch();
		$remote_version  = self::get_remote_version( $repo, $branch );
		$plugin_file     = self::plugin_file();

		if ( ! $remote_version ) {
			return $transient;
		}

		if ( version_compare( $remote_version, ALPHAWIRE_PROJECTS_VERSION, '>' ) ) {
			$transient->response[ $plugin_file ] = (object) array(
				'id'          => 'github.com/' . $repo,
				'slug'        => dirname( $plugin_file ),
				'plugin'      => $plugin_file,
				'new_version' => $remote_version,
				'url'         => 'https://github.com/' . $repo,
				'package'     => self::zip_url( $repo, $branch ),
				'tested'      => get_bloginfo( 'version' ),
				'icons'       => array(),
			);
			unset( $transient->no_update[ $plugin_file ] );
		} else {
			$transient->no_update[ $plugin_file ] = (object) array(
				'id'          => 'github.com/' . $repo,
				'slug'        => dirname( $plugin_file ),
				'plugin'      => $plugin_file,
				'new_version' => ALPHAWIRE_PROJECTS_VERSION,
				'url'         => 'https://github.com/' . $repo,
				'package'     => '',
			);
			unset( $transient->response[ $plugin_file ] );
		}

		return $transient;
	}

	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) ) {
			return $result;
		}

		$plugin_file = self::plugin_file();
		if ( $args->slug !== dirname( $plugin_file ) ) {
			return $result;
		}

		$repo = AlphaWire_Projects_Settings::get_github_repo();
		if ( '' === $repo ) {
			return $result;
		}

		$branch  = AlphaWire_Projects_Settings::get_github_branch();
		$version = self::get_remote_version( $repo, $branch );

		return (object) array(
			'name'          => 'AlphaWire Projects',
			'slug'          => dirname( $plugin_file ),
			'version'       => $version ? $version : ALPHAWIRE_PROJECTS_VERSION,
			'author'        => '<a href="https://github.com/' . esc_attr( $repo ) . '">AlphaWire</a>',
			'homepage'      => 'https://github.com/' . $repo,
			'sections'      => array(
				'description' => 'Internal AlphaWire plugin, updated straight from the <code>' . esc_html( $branch )
					. '</code> branch of <a href="https://github.com/' . esc_attr( $repo ) . '" target="_blank" rel="noreferrer">'
					. esc_html( $repo ) . '</a> on GitHub — not distributed via WordPress.org, so there is no formal changelog feed here; see the commit history on GitHub.',
			),
			'download_link' => self::zip_url( $repo, $branch ),
		);
	}

	/**
	 * GitHub's branch-zipball download extracts to a folder named
	 * "{owner}-{repo}-{sha}/", not "alphawire-projects/" — left as-is,
	 * WordPress would install a second, disconnected copy of the plugin
	 * instead of upgrading this one in place. Rename it before WP moves it
	 * into wp-content/plugins/.
	 */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $args = array() ) {
		global $wp_filesystem;

		if ( empty( $args['plugin'] ) || self::plugin_file() !== $args['plugin'] ) {
			return $source;
		}

		if ( ! $wp_filesystem instanceof WP_Filesystem_Base ) {
			return $source;
		}

		$target_slug = dirname( self::plugin_file() );
		$desired     = trailingslashit( $remote_source ) . $target_slug . '/';

		if ( trailingslashit( $source ) === $desired ) {
			return $source;
		}

		if ( $wp_filesystem->move( $source, $desired, true ) ) {
			return $desired;
		}

		return $source;
	}

	private static function zip_url( $repo, $branch ) {
		return 'https://github.com/' . $repo . '/archive/refs/heads/' . $branch . '.zip';
	}

	private static function transient_cache_key( $repo, $branch ) {
		return self::CACHE_KEY . '_' . md5( $repo . '@' . $branch );
	}

	/**
	 * Adds "Check for updates" to this plugin's row on the Plugins page,
	 * right alongside "Deactivate" — first in the list, since it's the one
	 * people reach for right after a push.
	 */
	public static function action_links( $links ) {
		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=aw_projects_check_update' ),
			'aw_projects_check_update'
		);

		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'alphawire-projects' ) . '</a>'
		);

		return $links;
	}

	/**
	 * Clears both our own 6-hour GitHub-version cache and WordPress's own
	 * update_plugins transient, then redirects back. Deleting
	 * update_plugins (rather than just waiting for ?force-check=1 to be
	 * present on the next page) is what makes WordPress itself re-run the
	 * whole plugin/theme update check on the very next admin page load —
	 * see _maybe_update_plugins() in WordPress core.
	 */
	public static function handle_check_update() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You are not allowed to check for updates.', 'alphawire-projects' ) );
		}
		check_admin_referer( 'aw_projects_check_update' );

		$repo = AlphaWire_Projects_Settings::get_github_repo();
		if ( '' !== $repo ) {
			delete_transient( self::transient_cache_key( $repo, AlphaWire_Projects_Settings::get_github_branch() ) );
		}
		delete_site_transient( 'update_plugins' );

		$redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'plugins.php' );
		wp_safe_redirect( add_query_arg( 'aw-projects-checked', '1', $redirect ) );
		exit;
	}

	/**
	 * Reads the update_plugins transient that the redirect above just
	 * forced WordPress to rebuild, and says in plain language whether that
	 * found a new version — so clicking the link gives a real answer
	 * instead of just silently landing back on the same page.
	 */
	public static function maybe_show_checked_notice() {
		if ( empty( $_GET['aw-projects-checked'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$plugin_file = self::plugin_file();
		$current     = get_site_transient( 'update_plugins' );

		if ( isset( $current->response[ $plugin_file ] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				sprintf(
					/* translators: %s: version number available on GitHub */
					esc_html__( 'AlphaWire Projects: checked GitHub — version %s is available below.', 'alphawire-projects' ),
					esc_html( $current->response[ $plugin_file ]->new_version )
				)
			);
		} else {
			printf(
				'<div class="notice notice-info is-dismissible"><p>%s</p></div>',
				esc_html__( 'AlphaWire Projects: checked GitHub — you already have the latest version installed.', 'alphawire-projects' )
			);
		}
	}

	/**
	 * Cached for a few hours so a busy wp-admin doesn't hit GitHub on every
	 * page load — but bypassed on WordPress's own "Check again" (which adds
	 * ?force-check=1) and on this plugin's own "Check for updates" link
	 * (which deletes the transient outright, see handle_check_update()).
	 */
	private static function get_remote_version( $repo, $branch ) {
		$cache_key    = self::transient_cache_key( $repo, $branch );
		$force_check  = ! empty( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $force_check ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached ? $cached : false;
			}
		}

		$url      = 'https://raw.githubusercontent.com/' . $repo . '/' . $branch . '/alphawire-projects.php';
		$response = wp_remote_get( $url, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $cache_key, '', 15 * MINUTE_IN_SECONDS );
			return false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( ! preg_match( '/Version:\s*([0-9][0-9A-Za-z.\-]*)/', $body, $matches ) ) {
			set_transient( $cache_key, '', 15 * MINUTE_IN_SECONDS );
			return false;
		}

		$version = trim( $matches[1] );
		set_transient( $cache_key, $version, 6 * HOUR_IN_SECONDS );

		return $version;
	}
}
