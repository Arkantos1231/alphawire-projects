<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * We deliberately do NOT register new "Categories" / "Narratives" taxonomies.
 *
 * The live site audit (build plan §3) found that `pillar` already holds
 * 8/8 of the categories the Lovable prototype hard-coded (Bitcoin, Ethereum,
 * Solana, XRP, Chainlink, Stablecoins, AI Agents, Prediction Markets) with
 * real article counts, and `topic` is a workable base for "Narratives".
 * So: attach `project` to both, reuse the terms as-is.
 */
class AlphaWire_Projects_Taxonomies {

	const REUSED_TAXONOMIES = array( 'pillar', 'topic' );

	public static function register_for_project() {
		foreach ( self::REUSED_TAXONOMIES as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				register_taxonomy_for_object_type( $taxonomy, AlphaWire_Projects_Post_Type::POST_TYPE );
			}
			// If a taxonomy is missing entirely (e.g. running against a
			// fresh/staging DB without the site's other plugins), we skip it
			// quietly rather than fatal — Projects still works, just without
			// that filter until the real taxonomy is present.
		}
	}

	/**
	 * Content-type buckets like "News", "Deep Dives" (= Research) and
	 * "Podcasts" already exist as `category` terms. "Interviews" does not —
	 * see build plan §3/§8. We add it here, on activation, instead of by
	 * hand in wp-admin, so it's versioned and reproducible on every
	 * environment this plugin is installed on.
	 */
	public static function seed_missing_terms() {
		if ( ! taxonomy_exists( 'category' ) ) {
			return;
		}

		if ( ! term_exists( 'interviews', 'category' ) ) {
			wp_insert_term(
				'Interviews',
				'category',
				array(
					'slug'        => 'interviews',
					'description' => "Interview-format editorial content. Tag News/Post content with this so it appears under a Project's AlphaWire Coverage tab, filtered to Interviews.",
				)
			);
		}
	}
}
