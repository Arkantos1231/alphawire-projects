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
 * simply not true of this page. Organization + PropertyValue states the
 * same facts (ticker, price, market cap, 24h volume, launch date) without
 * making that claim, which is the standard safe pattern for describing an
 * asset/entity rather than a product for sale.
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

		$entity = array(
			'@type'       => 'Organization',
			'@id'         => get_permalink( $post ) . '#project',
			'name'        => $project['name'],
			'url'         => get_permalink( $post ),
		);

		if ( $project['description'] ) {
			$entity['description'] = wp_strip_all_tags( (string) $project['description'] );
		}

		if ( $project['logo'] ) {
			$entity['logo']  = $project['logo'];
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

		$properties = array();
		if ( $project['ticker'] ) {
			$properties[] = self::property( 'Ticker', $project['ticker'] );
		}
		if ( ! empty( $market['price'] ) ) {
			$properties[] = self::property( 'Price (USD)', $market['price'] );
		}
		if ( ! empty( $market['marketCap'] ) ) {
			$properties[] = self::property( 'Market cap (USD)', $market['marketCap'] );
		}
		if ( ! empty( $market['volume24h'] ) ) {
			$properties[] = self::property( '24h volume (USD)', $market['volume24h'] );
		}
		if ( null !== $market['change24h'] && '' !== $market['change24h'] ) {
			$properties[] = self::property( '24h change (%)', $market['change24h'] . '%' );
		}
		if ( $project['launchDate'] ) {
			$properties[] = self::property( 'Launch date', $project['launchDate'] );
		}
		if ( ! empty( $market['updatedAt'] ) ) {
			// Whatever's in this block reflects the data as of when this
			// HTML was generated/crawled, not "right now" — said explicitly
			// here rather than implying a live price feed to anything
			// reading the markup (search engines included).
			$properties[] = self::property( 'Market data as of', $market['updatedAt'] );
		}
		if ( $properties ) {
			$entity['additionalProperty'] = $properties;
		}

		/**
		 * Lets a future need (e.g. a confirmed CoinGecko paid mapping, or a
		 * product decision to try a different schema shape) adjust or
		 * replace this without editing this file.
		 */
		$data['aw_project'] = apply_filters( 'alphawire_projects_schema_entity', $entity, $project, $post );

		return $data;
	}

	private static function property( $name, $value ) {
		return array(
			'@type' => 'PropertyValue',
			'name'  => $name,
			'value' => (string) $value,
		);
	}
}
