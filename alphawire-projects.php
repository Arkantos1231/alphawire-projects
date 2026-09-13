<?php
/**
 * Plugin Name: AlphaWire Projects
 * Description: Registers the AlphaWire "Project" entity (directory + profile pages), reuses the site's existing Pillar/Topic taxonomies, syncs market data from CoinGecko, and generates draft AI Project Summaries via OpenAI.
 * Version: 0.8.7
 * Author: AlphaWire
 * Text Domain: alphawire-projects
 *
 * v0.8.7 — Fixed the Directory search (the header search bar / MVP search
 * by name/ticker/category/narrative) throwing PHP warnings and returning
 * no category/narrative matches whenever the typed term matched a pillar
 * or topic term name. Root cause: class-directory-rest-api.php's search()
 * passed `'taxonomy' => array( 'pillar', 'topic' )` inside a single
 * tax_query clause — get_terms() does accept an array of taxonomies there,
 * but WP_Tax_Query wants exactly one taxonomy per clause, so the array
 * broke its internals instead (see the Query Monitor trace: the failure
 * sat inside class-wp-tax-query.php, reached via this search() call).
 * Replaced with two single-taxonomy clauses ('pillar', 'topic') joined by
 * `'relation' => 'OR'` — same matching intent, valid input. Also added an
 * `is_wp_error( $term_ids )` guard before building that tax_query, missing
 * here even though the same file's term_usage() already does this check.
 *
 * v0.8.6 — Adds a JSON-LD structured-data block per Project (ticker,
 * price, market cap, 24h volume/change, launch date, external links as
 * sameAs) on top of whatever Rank Math already outputs for this CPT.
 * Rank Math (already active site-wide) covers SEO title/meta description/
 * canonical/Open Graph/sitemap for `project` for free once that CPT is
 * enabled in its own settings — nothing here duplicates that. What it
 * doesn't do, in Free or Pro, is a "crypto/financial asset" schema type,
 * and its own docs say ACF fields don't reliably map into its Schema
 * Generator — its documented fix for exactly this is a plugin-side filter
 * on `rank_math/json_ld`, which is what includes/class-schema.php adds.
 * Deliberately modeled as Organization + PropertyValue rather than
 * Product/Offer: Product schema asserts a purchasable listing on the page
 * (price + availability + an implied checkout) which isn't true here and
 * Search Console flags as invalid — Organization + PropertyValue states
 * the same facts without that claim. Reuses
 * AlphaWire_Projects_REST::build_payload() so this can't drift from what
 * the page itself shows. No-op with no visible effect if Rank Math (or
 * its Schema module) isn't active — it just adds a filter that never
 * fires.
 *
 * v0.8.5 — Restyled the header's "Key Stats" panel to match the
 * reference pixel-for-pixel: dropped the sparkline chart (that stays
 * exclusive to the Overview tab's "Market" card — Key Stats is meant to
 * be the compact text-only version), added a "Token" row at the top
 * (the Project's ticker), replaced the conditional "last known price"
 * pill with a small plain-text label next to the heading that always
 * shows ("Live market data" normally, "Last known price" when the
 * cached market data is stale — no invented "Mock market data" label,
 * since ours is real CoinGecko data, not mock), added a small
 * "Editorial" pill next to the "Launched" row (it's the one stat here
 * that's editorially set rather than pulled from the market API), and
 * added a divider + a "Market data · external" pill + the two-line
 * footnote below the stat list. New CSS: .aw-badge-text /
 * .aw-editorial-tag / .aw-panel-divider / .aw-panel-footnote.
 *
 * v0.8.4 — v0.8.3 went too far: it stripped the description, narrative
 * chips, link buttons and the "Key Stats" panel out of the
 * always-visible header entirely, moving that content only into the new
 * Overview tab cards. The reference design actually keeps both — the
 * header stays as a full always-visible summary (identity, description,
 * narratives, links, Key Stats, AI Project Summary) regardless of which
 * tab is open, and the Overview tab additionally shows its own modular
 * cards ("What is X?", Key Links, a compact Timeline preview, Market,
 * Top Narratives) for a fuller read. Restored the header to what it had
 * before v0.8.3 (back to a 3-column layout: identity | Key Stats | AI
 * Project Summary) and restored the .aw-desc/.aw-chip-row/.aw-link-row/
 * .aw-link-pill CSS v0.8.3 had removed as "dead" — it wasn't dead, it
 * was still needed here. The v0.8.3 Overview grid itself is unchanged.
 *
 * Not done yet, still needs a product decision before building: the
 * newer reference screenshot also shows a few things with no existing
 * data behind them — a "#1 in {category}" rank badge, a short one-line
 * tag next to the ticker separate from the paragraph description (today
 * there's only one description field, so this can't be built without
 * either reusing the same text twice or adding a new field), per-
 * platform icons on the link buttons (Website/X/Discord/Docs/GitHub)
 * instead of a generic label, an "EDITORIAL" badge on the Launched date,
 * and AI Summary action buttons ("Review in Editorial Preview",
 * "Report an issue") that aren't real features anywhere in this plugin
 * yet — only "Generate / refresh draft" exists today.
 *
 * v0.8.3 — Rebuilt the Overview tab of a Project's profile page into the
 * modular grid layout requested (reference: a Hyperliquid-style profile
 * page). Key Stats and the description/narratives/links that used to sit
 * in the always-visible header above the tabs moved into the Overview
 * tab itself, as five cards: "What is {Project}?" (description + a
 * "Read more" link to the project's primary URL + the "AlphaWire
 * editorial" badge), "Key Links" (every configured link as a row),
 * "Timeline" (a compact vertical preview of the same ACF timeline data,
 * newest first, with a "View full timeline" link that switches to the
 * Timeline tab via JS instead of a dead anchor), "Market" (the same
 * stats/sparkline the old "Key Stats" panel showed, renamed to match the
 * reference and its badge switched to "Market data · external" outside
 * the stale-price case), and "Top Narratives" (this Project's own
 * `topic` terms with a 🔥 marker). The always-visible header above the
 * tabs now only holds the identity block (logo/name/ticker/verified) and
 * the AI Project Summary panel. Also fixed a real bug this uncovered:
 * projects.css defined ".aw-timeline" twice — the old vertical-list
 * rules (further down the file) were silently overriding the horizontal
 * card rules added for the Timeline tab in v0.8.2, so that fix was never
 * actually rendering as intended. Renamed the vertical version to
 * .aw-timeline-compact/.aw-tlc-* (now used only by the new Overview
 * preview) so the two no longer collide. New JS: a small
 * data-aw-profile-goto click handler in projects.js that activates the
 * real tab button (keeping the tab bar and the shown panel in sync)
 * instead of just following an anchor link. Removed the now-unused
 * .aw-profile-identity .aw-desc / .aw-chip-row / .aw-link-row /
 * .aw-link-pill CSS left over from the header (projects.css).
 *
 * v0.8.2 — The Timeline tab on a Project's profile page
 * (/projects/{slug}/) was empty — the timeline markup had always been
 * rendered inside the Overview tab panel instead of the dedicated
 * Timeline panel (id="aw-panel-timeline"), which was left as an empty
 * self-closing div. Moved it into that panel and restyled it to match
 * the requested reference design: a horizontally scrollable row of
 * milestone cards connected by a thin line, each with a small dot, a
 * formatted "Mon YYYY" date, title, description and a "Milestone N"
 * label, plus an "AlphaWire editorial" pill badge underneath. Also
 * switched from array_reverse() to natural chronological order (oldest
 * first, matching the reference) and formats the raw ACF date_picker
 * value with wp_date( 'M Y', ... ) instead of printing it raw. New CSS:
 * .aw-timeline-scroll / .aw-tl-item / .aw-tl-dot / .aw-tl-card /
 * .aw-tl-milestone / .aw-tl-badge (projects.css); reuses the existing
 * .aw-timeline / .aw-tl-date / .aw-tl-title / .aw-tl-desc selectors.
 *
 * v0.8.1 — Fixed "Generate / refresh draft" (the AI Summary meta box on
 * a Project's edit screen) never actually calling OpenAI. Root cause:
 * the meta box's own <form> (posting to admin-post.php) was nested
 * inside WordPress's main #post edit form — invalid HTML, and browsers
 * respond to nested <form> elements by folding the inner one's fields
 * into the outer form rather than keeping them separate. So clicking
 * the button silently submitted the whole "Update" post form instead
 * (re-saving the post, no visible error) and never reached
 * generate_draft() or OpenAI at all. Confirmed live: the OpenAI API key
 * in Projects → Settings was in fact saving correctly the whole time —
 * only the generate button itself was broken. Fixed by replacing the
 * nested <form> with a plain nonce'd GET link to admin-post.php (the
 * same pattern the v0.7.8 "Check for updates" link already uses), and
 * updated handle_manual_trigger() to read project_id from $_GET.
 *
 * v0.8.0 — Removed the Collections feature entirely (product decision:
 * won't be used). This was the v0.7.0 "Create a collection" addition —
 * named, multi-project lists a reader could save Projects into via a
 * star on every card. Since the save star's only purpose was "save to a
 * collection", removing Collections meant removing the star with it —
 * there is no separate plain-favorite feature left behind. Gone:
 * includes/class-collections.php and templates/collections.php (deleted),
 * the /projects/collections/ rewrite rule and its aw_projects_view query
 * var (class-post-type.php — REWRITE_VERSION bumped to 7 so the live
 * site's rewrite_rules option drops the stale rule on the next request),
 * the "My Collections" template_include routing (class-templates.php),
 * the save-star button/modal/current-user-saved-ids helpers
 * (template-functions.php), the sidebar "Create a collection" CTA and
 * "View my collections" link (archive-project.php), the star on the
 * Project Profile header (single-project.php), the Collections REST
 * surface's card_public() helper (class-directory-rest-api.php), and all
 * of the .aw-collection-cta / .aw-save-btn / .aw-modal (and its
 * sub-parts) / .aw-collection-row / .aw-collections-toolbar / .aw-btn /
 * .aw-btn-ghost / .aw-sidebar-link CSS plus the whole save/collections JS
 * module (projects.css, projects.js).
 *
 * v0.7.9 — Fixed a route collision that made
 * /projects/trending, /projects/recently-launched,
 * /projects/recently-updated and /projects/editors-picks all 404 with
 * {"code":"not_found","message":"Project not found"}. WP_REST_Server
 * matches routes in registration order and stops at the first regex
 * that fits the path; AlphaWire_Projects_REST's single-project catch-all
 * "/projects/{slug}" (pattern [a-zA-Z0-9-]+) was being registered before
 * AlphaWire_Projects_Directory_REST's specific "/projects/..." routes,
 * so a request for "/projects/trending" matched the catch-all first,
 * treated "trending" as a slug, found no such Project, and 404'd before
 * Directory REST's own route ever got a chance. Fixed by registering
 * Directory REST at priority 5 and the single-project REST at priority
 * 20 on rest_api_init, so the specific routes are always added — and
 * therefore matched — first. See alphawire-projects.php's constructor.
 *
 * v0.7.8 — A one-click "Check for updates" link on this plugin's row on
 * the Plugins page (next to Deactivate). Before this, picking up a fresh
 * GitHub push before the updater's own 6-hour cache expired meant using
 * WordPress's site-wide "Check again" on Dashboard -> Updates — it works
 * (it re-runs every plugin's update check, ours included), but it's not
 * where anyone thinks to look for "did my push show up yet". This link
 * clears just this plugin's cached GitHub version plus WordPress's own
 * update_plugins transient, forces a fresh check on the very next admin
 * page load, and shows a plain-language notice with the result. See
 * includes/class-updater.php.
 *
 * v0.7.7 — Matched the Lovable reference for the Top Categories/Trending
 * Narratives/Recently Launched/Recently Updated panels: dropped the
 * divider line between rows (spacing alone separates them now), and
 * Recently Launched/Updated rows show each Project's own logo (or ticker-
 * initial placeholder) next to its name, the same way the Directory grid
 * and Trending strip already do. Top Categories/Trending Narratives stay
 * text-only — those rows are taxonomy terms, not Projects, so there's no
 * icon to show. See .aw-list-row / .aw-list-row-identity in projects.css
 * and templates/archive-project.php.
 *
 * v0.7.6 — v0.7.5 didn't finish the job: the star still showed a pink
 * 1px border and a 52x42px box (instead of a plain 18px icon) after that
 * update went live. A second, separate theme rule was the cause — a
 * global form-control reset, `[type="button"], [type="submit"], button {
 * border:1px solid #c36; padding:.5rem 1rem; }`. Our button has
 * `type="button"`, so `[type="button"]` matches it at exactly the same
 * specificity as a bare `.aw-save-btn` (one class-tier selector each) —
 * a tie that the theme's stylesheet won simply by loading later. Same
 * fix as the SVG fill/stroke override in v0.7.5: qualify with the
 * plugin's own .aw-projects wrapper so it's unambiguously 2 classes vs.
 * their 1, rather than relying on a tie that depends on load order.
 * Confirmed live via getComputedStyle before shipping.
 *
 * v0.7.5 — The save/favorite star looked wrong everywhere it appeared
 * (Directory grid, Trending strip, Project profile): a bordered circle
 * chip around a ★/☆ text glyph, boxy next to the rest of the UI. Swapped
 * for a bare inline SVG outline star (Lucide's path, chosen for staying
 * legible as a hollow outline at small sizes), no background or border,
 * matching how the Lovable reference draws this icon everywhere. Also
 * found and fixed the reason it briefly still rendered as a solid white
 * blob regardless of saved state while building this: the theme carries a
 * site-wide dark-mode rule forcing every SVG's fill AND stroke to white
 * (`body.dark-mode svg:not(.alphaclub-signal *) path {
 * fill/stroke:#fff!important }`), specific enough to beat a naive
 * `!important` override — fixed by out-specifying it with the plugin's own
 * .aw-projects wrapper rather than fighting importance alone. See the
 * "Save button" section of projects.css and aw_projects_star_icon() in
 * template-functions.php for the full story.
 *
 * v0.7.4 — Restyled the Trending Projects strip to match the Lovable
 * prototype exactly: a centered column (icon, name, ticker, description)
 * instead of the old left-aligned rank+logo row, a divider line above the
 * footer, an up/down triangle next to the 24h change, and the save star
 * moved from the top-right corner to sit beside the volume figure at the
 * bottom. Footers now sit flush with the bottom of every card in a row
 * (flex `margin-top: auto`) regardless of how long each description is, so
 * a row of cards lines up evenly the way the mock's does. Verified against
 * the live site by restructuring one card's DOM and injecting the new CSS
 * before touching any file.
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

define( 'ALPHAWIRE_PROJECTS_VERSION', '0.8.7' );
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
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-schema.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-settings.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-ai-summary-service.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-ai-summary-metabox.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/template-functions.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-templates.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-csv-importer.php';
require_once ALPHAWIRE_PROJECTS_PATH . 'includes/class-updater.php';
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

		// Order matters here, not just readability: WP_REST_Server matches
		// routes in registration order and stops at the first regex that
		// matches the path. AlphaWire_Projects_REST registers the catch-all
		// "/projects/{slug}" pattern, which also matches literal segments
		// like "trending" or "recently-launched" (both fit
		// [a-zA-Z0-9-]+) — so the Directory REST's specific "/projects/..."
		// routes MUST be registered first, or they get shadowed by the
		// single-project endpoint and 404 with "Project not found".
		add_action( 'rest_api_init', array( 'AlphaWire_Projects_Directory_REST', 'register_routes' ), 5 );
		add_action( 'rest_api_init', array( 'AlphaWire_Projects_REST', 'register_routes' ), 20 );

		AlphaWire_Projects_Activity::hooks();
		AlphaWire_Projects_Market_Data_Service::instance()->hooks();

		AlphaWire_Projects_Settings::hooks();
		AlphaWire_Projects_AI_Summary_Metabox::hooks();
		( new AlphaWire_Projects_AI_Summary_Service() )->hooks();

		AlphaWire_Projects_Templates::hooks();
		AlphaWire_Projects_CSV_Importer::hooks();
		AlphaWire_Projects_Updater::hooks();
		AlphaWire_Projects_Schema::hooks();
	}
}

register_activation_hook( __FILE__, array( 'AlphaWire_Projects_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AlphaWire_Projects_Activator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'AlphaWire_Projects', 'instance' ) );
