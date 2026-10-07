<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds a JSON-LD block for a Project's structured data.
 *
 * Rank Math already covers title/meta description/canonical/Open Graph/
 * sitemap for this CPT for free once `project` is enabled in its Titles &
 * Meta and Sitemap settings — no code needed for any of that. What Rank
 * Math does NOT do, in Free or Pro: it has no built-in "crypto / financial
 * asset" schema type, and its own docs say ACF field values don't map into
 * its Schema Generator reliably. Its documented fix for exactly this case
 * is a plugin-side filter on `rank_math/json_ld` — this class is that
 * filter.
 *
 * Deliberately NOT emitting schema.org Product/Offer: that vocabulary
 * asserts a purchasable listing on this exact page (price + availability +
 * an implied checkout), which a Project profile is not — Google Search
 * Console flags Product markup like that as invalid/incomplete, and it's
 * simply not true of this page. Organization was used before v0.9.4 and is
 * equally untrue (a token isn't a company) — see add_project_schema().
 *
 * Pulls from AlphaWire_Projects_REST::build_payload() — the exact same
 * data contract the REST endpoint and templates/single-project.php already
 * use — so this can never drift out of sync with what a visitor actually
 * sees on the page.
 */
class AlphaWire_Projects_Schema {

	public static function hooks() {
		add_filter( 'rank_math/json_ld', array( __CLASS__, 'add_project_schema' ), 99, 2 );
	}

	/**
	 * @param array $data   Rank Math's collected schema pieces, keyed by an
	 *                      arbitrary string — we just add our own key.
	 * @param mixed $jsonld Rank Math's Schema/JsonLD instance (unused here).
	 */
	public static function add_project_schema( $data, $jsonld ) {
		if ( ! is_singular( AlphaWire_Projects_Post_Type::POST_TYPE ) ) {
			return $data;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) || ! class_exists( 'AlphaWire_Projects_REST' ) ) {
			return $data;
		}

		$project = AlphaWire_Projects_REST::build_payload( $post );
		$market  = $project['market'];

		// Staging feedback #6: this used to be '@type' => 'Organization',
		// which tells Google the coin/token is a company. schema.org has no
		// crypto-asset type, so the entity is a plain Thing, described by
		// the page (WebPage.about) rather than claiming to be an
		// organisation or a product for sale. The ticker goes in
		// `identifier` (a Thing property). Price/market cap/volume are no
		// longer emitted: additionalProperty isn't valid on Thing (nor was
		// it on Organization), and point-in-time prices in cached HTML go
		// stale the moment the page is crawled.
		$entity = array(
			'@type' => 'Thing',
			'@id'   => get_permalink( $post ) . '#project',
			'name'  => $project['name'],
			'url'   => get_permalink( $post ),
		);

		if ( $project['ticker'] ) {
			$entity['alternateName'] = $project['ticker'];
			$entity['identifier']    = array(
				'@type'      => 'PropertyValue',
				'propertyID' => 'Ticker',
				'value'      => (string) $project['ticker'],
			);
		}

		if ( $project['description'] ) {
			$entity['description'] = wp_strip_all_tags( (string) $project['description'] );
		}

		if ( $project['logo'] ) {
			$entity['image'] = $project['logo'];
		}

		$same_as = array();
		foreach ( (array) $project['links'] as $link ) {
			if ( ! empty( $link['url'] ) ) {
				$same_as[] = $link['url'];
			}
		}
		if ( $same_as ) {
			$entity['sameAs'] = array_values( array_unique( $same_as ) );
		}

		// Point Rank Math's own WebPage node at this entity, so the page is
		// "about" the project rather than the project being the publisher.
		foreach ( $data as $key => $piece ) {
			if ( is_array( $piece ) && isset( $piece['@type'] ) && in_array( 'WebPage', (array) $piece['@type'], true ) ) {
				$data[ $key ]['about'] = array( '@id' => $entity['@id'] );
			}
		}

		/**
		 * Lets a future need (e.g. a confirmed CoinGecko paid mapping, or a
		 * product decision to try a different schema shape) adjust or
		 * replace this without editing this file.
		 */
		$data['aw_project'] = apply_filters( 'alphawire_projects_schema_entity', $entity, $project, $post );

		return $data;
	}
}
