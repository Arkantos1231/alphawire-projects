<?php
/**
 * Plugin Name: AlphaWire Projects
 * Description: Registers the AlphaWire "Project" entity (directory + profile pages), reuses the site's existing Pillar/Topic taxonomies, syncs market data from CoinGecko, and generates draft AI Project Summaries via OpenAI.
 * Version: 0.7.3
 * Author: AlphaWire
 * Text Domain: alphawire-projects
 *
 * v0.7.3 — The v0.7.1 overflow fix wasn't complete: the Directory grid
 * card's tagline (`.aw-card-tagline`) is a bare <span>, which is
 * display:inline by default — and overflow/text-overflow/a constrained
 * width all silently do nothing on an inline box. So the "ellipsis" rule
 * was never actually taking effect; the tagline text kept running at full
 * length underneath the price/star column instead of eliding. Given it
 * display:block (verified live by injecting the one-line override first).
 * The trending-strip card's tagline was unaffected — it's a <p>, block by
 * default, already had its own line-clamp rule.
 *
 * v0.7.2 — Fixes /projects/ and /projects/{slug} losing to the site's
 * News-page rule again, despite the v0.3.x priority fix still being intact
 * and unchanged. Root cause: the self-healing flush only ever compared a
 * stored version number against REWRITE_VERSION — proof we once *asked*
 * WordPress to flush, not that the flushed rules actually stuck. On the
 * live site they drifted apart (rewrite_rules came back stale while the
 * version option already said "done"), so no future update could ever
 * trigger a retry; only a manual Settings → Permalinks save forced a real
 * flush and fixed it. maybe_flush_rewrite_rules() now also checks the live
 * rewrite_rules option for our own rule keys on every request and re-flushes
 * if they're missing, so this can't go silently stale again.
 *
 * v0.7.1 — Fixes overflow on the Directory grid card introduced in
 * v0.7.0: the save star (absolutely positioned) overlapped the price/
 * change text, and a long name/price could push past the card at the
 * old 230px minimum width. Widened the grid's min column width, gave the
 * price column a reserved min-width, and made flex items actually shrink
 * for their ellipsis to apply (a flex item's default min-width:auto
 * silently defeats text-overflow:ellipsis — this is what was letting
 * "Chainlink"'s and "Ethereum"'s taglines run under their price).
 *
 * v0.7.0 — Directory restyled to match the Lovable prototype's layout
 * (left sidebar: Explore nav + Categories + a Collections CTA; search
 * moved to the header; a numbered Trending Projects strip with real 24h
 * volume from CoinGecko), plus the "Create a collection" feature the
 * build plan explicitly left out of Phase 3 — added now at product's
 * request, on top of the site's existing reader login (Thirdweb Auth SSO
 * already creates/maps a real WP user; nothing new to authenticate).
 * Readers get named, multi-project Collections via a star on every card,
 * a My Collections page at /projects/collections/, user-meta storage and
 * a small authenticated REST surface (see includes/class-collections.php)
 * — no new database tables. Top Categories/Trending Narratives now also
 * carry a real average-24h-change percentage next to each term, matching
 * the Lovable look with real data instead of its mocked figure.
 *
 * v0.6.1 — "Trending Narratives" now matches the build plan's decision log:
 * `topic` also holds entity-style terms (Tether, Circle, Ripple,
 * Polymarket, Kalshi…) that were flagged as "not narratives, needs
 * filtering" but the first cut of the Directory never actually filtered
 * them. Projects → Settings → Directory — Narratives now has an editable
 * exclusion list (seeded with those five) so an entity term can never show
 * up as a Trending Narrative, no matter how many Projects get tagged with
 * it. Top Categories is untouched — pillar has no such entity-term problem.
 *
 * v0.6.0 — Self-updates from GitHub. Projects → Settings gets two new
 * fields (GitHub repo, branch to track). Once set, WordPress checks the
 * `Version:` header of alphawire-projects.php on that branch and offers a
 * normal Plugins-page update whenever it's ahead of what's installed —
 * every push to the tracked branch is a release, no formal GitHub Release
 * needed. No third-party updater library (none could be fetched from this
 * environment) — a small dependency-free class instead, same style as the
 * CoinGecko/OpenAI integrations. See includes/class-updater.php.
 *
 * v0.5.0 — CSV bulk importer (Projects → Import): create/update many
 * Projects at once instead of by hand — matches existing Projects by
 * ticker then by name so re-running a file updates rather than
 * duplicates. Never creates a new Pillar/Narrative taxonomy term on its
 * own; a category/narrative CSV column only applies when that term
 * already exists on the site. See includes/class-csv-importer.php.
 *
 * v0.4.0 — Phases 3-4: the Directory (/projects/) and Project Profile
 * (/projects/{slug}/) front-end templates. Plain PHP templates in this
 * plugin (loaded via `single_template`/`archive_template`, with
 * get_header()/get_footer() pulling in the theme's real nav and footer) —
 * not a page-builder template — that call this plugin's own data classes
 * directly (Market_Data_Service, Content_Relationships,
 * AlphaWire_Projects_REST::build_payload()) rather than looping back
 * through our own REST API over HTTP. See includes/class-templates.php.
 *
 * v0.3.3 — v0.3.2 still lost: the site's competing rule is ALSO reinserted
 * from the `rewrite_rules_array` filter — the last stage WordPress runs
 * before caching the rules, later even than `generate_rewrite_rules`. Now
 * hooked at PHP_INT_MAX too, so we prepend last of all. Self-healing flush
 * bumped again.
 *
 * v0.3.2 — v0.3.1's rewrite fix wasn't strong enough: the site's competing
 * rule re-inserts itself at the very front of the rules array from its own
 * `generate_rewrite_rules` callback, a later stage than a plain
 * add_rewrite_rule(..., 'top').
 *
 * v0.3.1 — Fixes /projects/ and /projects/{slug}/ resolving to the wrong
 * page on the live site. An existing, unrelated rewrite rule (the News
 * page's category/pillar filter) greedily matches any two-segment URL and
 * was never told to exclude "projects", so it intercepted our own single
 * Project rule before WordPress ever tried it.
 *
 * v0.3.0 — OpenAI AI Project Summary integration:
 *   - Settings page (Projects → Settings) holding the OpenAI API key and
 *     model, entered from the WordPress dashboard — never hardcoded
 *   - AI Summary generation service: builds a prompt from a Project's own
 *     editorial data only, calls OpenAI, and always writes the result as
 *     "pending" — never auto-approved, mirrors the site's existing
 *     Market Summaries draft/editor-approval pattern
 *   - Weekly background job that fills in any Project still missing a
 *     summary, plus a manual "Generate / refresh draft" button on the
 *     Project edit screen
 *   - The API key is never exposed via REST or the frontend
 *
 * v0.2.0 — Phase 1 of the build plan:
 *   - Project <-> News/Podcast/Post content relationships (one field on the
 *     content side; no duplication)
 *   - `last_activity_at` auto-tracking, so "Recently Updated" needs zero
 *     extra editorial work
 *   - Project Timeline (ACF repeater)
 *   - Directory listing endpoints: /projects, /projects/trending,
 *     /projects/recently-launched, /projects/recently-updated,
 *     /projects/editors-picks, /categories, /narratives
 *   - Single-project endpoint now includes timeline, relatedProjects and
 *     coverage (existing content, linked — never duplicated)
 *
 * v0.1.0 (Phase 0 + start of Phase 2) covered the CPT, taxonomy reuse,
 * ACF identity/AI-summary fields, and the CoinGecko market-data service.
 *
 * Still ahead: Directory/Profile front-end templates, SEO, analytics.
 * See the build plan, phases 3-5.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

define( 'ALPHAWIRE_PROJECTS_VERSION', '0.7.3' );
define( 'ALPHAWIRE_PROJECTS_PATH', plugin_dir_path( __FILE__ ) );
define( 'ALPHAWIRE_PROJECTS_URL', plugin_dir_url( __FILE__ ) );

require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-post-type.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-taxonomies.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-fields.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-content-relationships.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-activity.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-market-data-service.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-rest-api.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-directory-rest-api.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-settings.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-ai-summary-service.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-ai-summary-metabox.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/template-functions.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-templates.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-csv-importer.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-updater.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-collections.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-activator.php';

final class AlphaWire_Projects {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( 'AlphaWire_Projects_Post_Type', 'register' ) );
		add_action( 'init', array( 'AlphaWire_Projects_Taxonomies', 'register_for_project' ), 20 );

		// register_top_priority_rewrites() only needs to run once before a
		// flush happens — the actual "win" now comes from the PHP_INT_MAX
		// priority on its own generate_rewrite_rules hook, not from init
		// ordering. See that method's docblock.
		add_action( 'init', array( 'AlphaWire_Projects_Post_Type', 'register_top_priority_rewrites' ) );
		add_action( 'init', array( 'AlphaWire_Projects_Post_Type', 'maybe_flush_rewrite_rules' ), 20 );

		add_action( 'acf/init', array( 'AlphaWire_Projects_Fields', 'register' ) );
		add_action( 'acf/init', array( 'AlphaWire_Projects_Content_Relationships', 'register_fields' ) );

		add_action( 'rest_api_init', array( 'AlphaWire_Projects_REST', 'register_routes' ) );
		add_action( 'rest_api_init', array( 'AlphaWire_Projects_Directory_REST', 'register_routes' ) );

		AlphaWire_Projects_Activity::hooks();
		AlphaWire_Projects_Market_Data_Service::instance()->hooks();

		AlphaWire_Projects_Settings::hooks();
		AlphaWire_Projects_AI_Summary_Metabox::hooks();
		( new AlphaWire_Projects_AI_Summary_Service() )->hooks();

		AlphaWire_Projects_Templates::hooks();
		AlphaWire_Projects_CSV_Importer::hooks();
		AlphaWire_Projects_Updater::hooks();
		AlphaWire_Projects_Collections::hooks();
	}
}

register_activation_hook( __FILE__, array( 'AlphaWire_Projects_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AlphaWire_Projects_Activator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'AlphaWire_Projects', 'instance' ) );
