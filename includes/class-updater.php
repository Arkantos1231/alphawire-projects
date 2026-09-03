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

	/**
	 * Cached for a few hours so a busy wp-admin doesn't hit GitHub on every
	 * page load — but bypassed on WordPress's own "Check again" (which adds
	 * ?force-check=1), so that button actually does something.
	 */
	private static function get_remote_version( $repo, $branch ) {
		$cache_key    = self::CACHE_KEY . '_' . md5( $repo . '@' . $branch );
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
