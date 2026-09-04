<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A settings screen under Projects → Settings, so the OpenAI key and model
 * live in wp-admin — never hard-coded in the plugin, never exposed on the
 * front end or through any REST endpoint.
 */
class AlphaWire_Projects_Settings {

	const OPTION_API_KEY = 'alphawire_projects_openai_api_key';
	const OPTION_MODEL   = 'alphawire_projects_openai_model';
	const DEFAULT_MODEL  = 'gpt-5.6-luna';

	const OPTION_GITHUB_REPO   = 'alphawire_projects_github_repo';
	const OPTION_GITHUB_BRANCH = 'alphawire_projects_github_branch';
	const DEFAULT_GITHUB_BRANCH = 'main';

	const OPTION_NARRATIVE_EXCLUSIONS   = 'alphawire_projects_narrative_exclusions';
	const DEFAULT_NARRATIVE_EXCLUSIONS  = "Tether\nCircle\nRipple\nPolymarket\nKalshi";

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . AlphaWire_Projects_Post_Type::POST_TYPE,
			'AlphaWire Projects Settings',
			'Settings',
			'manage_options',
			'alphawire-projects-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'alphawire_projects_settings',
			self::OPTION_API_KEY,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_api_key' ),
				'default'           => '',
			)
		);

		register_setting(
			'alphawire_projects_settings',
			self::OPTION_MODEL,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => self::DEFAULT_MODEL,
			)
		);

		add_settings_section(
			'alphawire_projects_openai_section',
			'OpenAI — AI Project Summary',
			function () {
				echo '<p>' . esc_html__( 'Used only to draft the Project Summary — in the background weekly, or when an editor clicks "Generate draft" on a Project. Never called when a visitor opens a Project page. A draft always lands as "Pending Review"; nothing publishes without an editor approving it.', 'alphawire-projects' ) . '</p>';
			},
			'alphawire-projects-settings'
		);

		add_settings_field(
			self::OPTION_API_KEY,
			'OpenAI API key',
			array( __CLASS__, 'render_api_key_field' ),
			'alphawire-projects-settings',
			'alphawire_projects_openai_section'
		);

		add_settings_field(
			self::OPTION_MODEL,
			'Model',
			array( __CLASS__, 'render_model_field' ),
			'alphawire-projects-settings',
			'alphawire_projects_openai_section'
		);

		register_setting(
			'alphawire_projects_settings',
			self::OPTION_GITHUB_REPO,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
			)
		);

		register_setting(
			'alphawire_projects_settings',
			self::OPTION_GITHUB_BRANCH,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => self::DEFAULT_GITHUB_BRANCH,
			)
		);

		add_settings_section(
			'alphawire_projects_updates_section',
			'Updates — GitHub',
			function () {
				echo '<p>' . esc_html__( 'Point this at the public GitHub repo hosting this plugin and WordPress will offer an update whenever the tracked branch\'s version number is ahead of what\'s installed here — no formal GitHub "Release" needed, just a push to that branch.', 'alphawire-projects' ) . '</p>';
			},
			'alphawire-projects-settings'
		);

		add_settings_field(
			self::OPTION_GITHUB_REPO,
			'GitHub repo',
			array( __CLASS__, 'render_github_repo_field' ),
			'alphawire-projects-settings',
			'alphawire_projects_updates_section'
		);

		add_settings_field(
			self::OPTION_GITHUB_BRANCH,
			'Branch to track',
			array( __CLASS__, 'render_github_branch_field' ),
			'alphawire-projects-settings',
			'alphawire_projects_updates_section'
		);

		register_setting(
			'alphawire_projects_settings',
			self::OPTION_NARRATIVE_EXCLUSIONS,
			array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
				'default'           => self::DEFAULT_NARRATIVE_EXCLUSIONS,
			)
		);

		add_settings_section(
			'alphawire_projects_narratives_section',
			'Directory — Narratives',
			function () {
				echo '<p>' . esc_html__( 'The topic taxonomy also holds entity-style terms (Tether, Circle…) that aren\'t narratives — per the build plan, they never belong in "Trending Narratives" on the Directory, even if a Project happens to get tagged with one.', 'alphawire-projects' ) . '</p>';
			},
			'alphawire-projects-settings'
		);

		add_settings_field(
			self::OPTION_NARRATIVE_EXCLUSIONS,
			'Exclude from Narratives',
			array( __CLASS__, 'render_narrative_exclusions_field' ),
			'alphawire-projects-settings',
			'alphawire_projects_narratives_section'
		);
	}

	/**
	 * The field is always rendered blank (we never echo the real key back
	 * into the page). So a blank submit means "nothing changed", not
	 * "clear the key" — otherwise saving the Model field would silently
	 * wipe the key.
	 */
	public static function sanitize_api_key( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return get_option( self::OPTION_API_KEY, '' );
		}
		return $value;
	}

	public static function render_api_key_field() {
		$existing = get_option( self::OPTION_API_KEY, '' );
		$masked   = $existing ? ( 'Saved — ends in ' . substr( $existing, -4 ) ) : 'Not set yet';
		?>
		<input
			type="password"
			name="<?php echo esc_attr( self::OPTION_API_KEY ); ?>"
			value=""
			autocomplete="off"
			class="regular-text"
			placeholder="sk-..."
		/>
		<p class="description">
			<?php echo esc_html( $masked ); ?> — leave this field blank to keep the current key.
			Stored server-side only; no REST endpoint or front-end page ever returns it.
		</p>
		<?php
	}

	public static function render_model_field() {
		$model = get_option( self::OPTION_MODEL, self::DEFAULT_MODEL );
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_MODEL ); ?>"
			value="<?php echo esc_attr( $model ); ?>"
			class="regular-text"
		/>
		<p class="description">
			Current options for a short summary task like this one: <code>gpt-5.6-luna</code>
			(cost-sensitive — the default here), <code>gpt-5.6-terra</code> (balanced),
			<code>gpt-5.6-sol</code> (flagship, for higher-stakes cases). Model names change —
			check <a href="https://platform.openai.com/docs/models" target="_blank" rel="noreferrer">OpenAI's model list</a>
			before changing this.
		</p>
		<?php
	}

	public static function render_github_repo_field() {
		$repo = get_option( self::OPTION_GITHUB_REPO, '' );
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_GITHUB_REPO ); ?>"
			value="<?php echo esc_attr( $repo ); ?>"
			class="regular-text"
			placeholder="owner/repo"
		/>
		<p class="description">
			Just <code>owner/repo</code> (e.g. <code>alphawire/alphawire-projects</code>) — not a full URL.
			Leave blank to disable update checks.
		</p>
		<?php
	}

	public static function render_github_branch_field() {
		$branch = get_option( self::OPTION_GITHUB_BRANCH, self::DEFAULT_GITHUB_BRANCH );
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_GITHUB_BRANCH ); ?>"
			value="<?php echo esc_attr( $branch ); ?>"
			class="regular-text"
			placeholder="<?php echo esc_attr( self::DEFAULT_GITHUB_BRANCH ); ?>"
		/>
		<p class="description">
			Every push to this branch that bumps the <code>Version:</code> header becomes an available
			update — no tag or Release required.
		</p>
		<?php
	}

	public static function render_narrative_exclusions_field() {
		$value = get_option( self::OPTION_NARRATIVE_EXCLUSIONS, self::DEFAULT_NARRATIVE_EXCLUSIONS );
		?>
		<textarea
			name="<?php echo esc_attr( self::OPTION_NARRATIVE_EXCLUSIONS ); ?>"
			rows="4"
			class="large-text code"
		><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description">
			Term names from the <code>topic</code> taxonomy, one per line (commas also work) — matched
			case-insensitively by exact name. These never show up in "Trending Narratives" on the
			Directory, no matter how many Projects get tagged with them.
		</p>
		<?php
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>AlphaWire Projects — Settings</h1>
			<?php settings_errors(); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'alphawire_projects_settings' );
				do_settings_sections( 'alphawire-projects-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	public static function get_api_key() {
		return get_option( self::OPTION_API_KEY, '' );
	}

	public static function get_model() {
		$model = get_option( self::OPTION_MODEL, self::DEFAULT_MODEL );
		return $model ? $model : self::DEFAULT_MODEL;
	}

	public static function get_github_repo() {
		return trim( (string) get_option( self::OPTION_GITHUB_REPO, '' ), " \t\n\r\0\x0B/" );
	}

	public static function get_github_branch() {
		$branch = trim( (string) get_option( self::OPTION_GITHUB_BRANCH, self::DEFAULT_GITHUB_BRANCH ) );
		return $branch ? $branch : self::DEFAULT_GITHUB_BRANCH;
	}

	/**
	 * @return string[] Lower-cased term names to exclude from "Trending
	 *                   Narratives" — entity-style `topic` terms (Tether,
	 *                   Circle…) that were never meant to count as a
	 *                   narrative, per the build plan's decision log.
	 */
	public static function get_narrative_exclusions() {
		$raw   = get_option( self::OPTION_NARRATIVE_EXCLUSIONS, self::DEFAULT_NARRATIVE_EXCLUSIONS );
		$parts = preg_split( '/[,\r\n]+/', (string) $raw );

		$out = array();
		foreach ( $parts as $part ) {
			$part = strtolower( trim( $part ) );
			if ( '' !== $part ) {
				$out[] = $part;
			}
		}
		return $out;
	}
}
