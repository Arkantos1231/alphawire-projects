<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small render helpers shared by templates/archive-project.php and
 * templates/single-project.php. Plain functions (not a class) since
 * they're called straight from PHP templates, the same way a theme's
 * template-tags.php would be.
 */

/**
 * A colored +/-N.NN% span. $value is a plain float (already the API's
 * 24h-change-percent shape) or null when market data isn't available yet.
 */
function aw_projects_change( $value ) {
	if ( null === $value || '' === $value ) {
		echo '<span class="aw-change flat">—</span>';
		return;
	}
	$value = (float) $value;
	$class = $value > 0 ? 'up' : ( $value < 0 ? 'down' : 'flat' );
	$sign  = $value > 0 ? '+' : '';
	printf(
		'<span class="aw-change %s">%s%s%%</span>',
		esc_attr( $class ),
		esc_html( $sign ),
		esc_html( number_format( $value, 2 ) )
	);
}

/**
 * Circular logo — the Project's featured image if it has one, otherwise a
 * ticker-initial placeholder so a card never looks broken while a project
 * has no artwork yet.
 */
function aw_projects_logo( $card, $size = 40 ) {
	$style = sprintf( 'width:%1$dpx;height:%1$dpx;', (int) $size );
	if ( ! empty( $card['logo'] ) ) {
		printf(
			'<span class="aw-logo" style="%s"><img src="%s" alt="" /></span>',
			esc_attr( $style ),
			esc_url( $card['logo'] )
		);
		return;
	}
	$initial = $card['ticker'] ? mb_substr( $card['ticker'], 0, 1 ) : mb_substr( $card['name'], 0, 1 );
	printf(
		'<span class="aw-logo" style="%s">%s</span>',
		esc_attr( $style ),
		esc_html( mb_strtoupper( $initial ) )
	);
}

/**
 * The Directory grid card — shared by the filtered-results grid, Editor's
 * Picks and the paginated All Projects grid. $card is the lightweight
 * shape from AlphaWire_Projects_Directory_REST's card()/query_projects().
 *
 * A <div> wrapper (not the old bare <a>) because the save/collection star
 * is now a sibling button, not something that can nest inside the link.
 */
function aw_projects_render_card( $card ) {
	?>
	<div class="aw-panel-hover aw-card">
		<a class="aw-card-link" href="<?php echo esc_url( get_permalink( $card['id'] ) ); ?>">
			<?php aw_projects_logo( $card, 40 ); ?>
			<span class="aw-card-body">
				<span class="aw-card-title-row">
					<span class="aw-name"><?php echo esc_html( $card['name'] ); ?></span>
					<span class="aw-ticker"><?php echo esc_html( $card['ticker'] ); ?></span>
				</span>
				<?php if ( ! empty( $card['tagline'] ) ) : ?>
					<span class="aw-card-tagline"><?php echo esc_html( $card['tagline'] ); ?></span>
				<?php elseif ( ! empty( $card['categories'] ) ) : ?>
					<span class="aw-card-tagline"><?php echo esc_html( implode( ', ', $card['categories'] ) ); ?></span>
				<?php endif; ?>
			</span>
			<span class="aw-card-price">
				<?php if ( ! empty( $card['price'] ) ) : ?>
					<span class="aw-price"><?php echo esc_html( $card['price'] ); ?></span>
				<?php endif; ?>
				<?php aw_projects_change( $card['change24h'] ); ?>
			</span>
		</a>
		<?php aw_projects_save_button( $card['id'] ); ?>
	</div>
	<?php
}

/**
 * The numbered Trending Projects strip card — rank badge, logo, name,
 * ticker, tagline, 24h change, real 24h volume (compact — "18.4K" style,
 * matches the Lovable prototype's look but from real CoinGecko data, see
 * class-market-data-service.php's volume24hRaw), and the save star.
 */
function aw_projects_render_trending_card( $card, $rank ) {
	?>
	<div class="aw-trend-card aw-panel-hover">
		<a class="aw-trend-link" href="<?php echo esc_url( get_permalink( $card['id'] ) ); ?>">
			<div class="aw-trend-top">
				<span class="aw-rank"><?php echo (int) $rank; ?></span>
				<?php aw_projects_logo( $card, 44 ); ?>
			</div>
			<span class="aw-name"><?php echo esc_html( $card['name'] ); ?></span>
			<span class="aw-ticker"><?php echo esc_html( $card['ticker'] ); ?></span>
			<?php if ( ! empty( $card['tagline'] ) ) : ?>
				<p class="aw-trend-tagline"><?php echo esc_html( $card['tagline'] ); ?></p>
			<?php endif; ?>
			<div class="aw-trend-foot">
				<?php aw_projects_change( $card['change24h'] ); ?>
				<span class="aw-trend-volume"><?php echo esc_html( aw_projects_compact_number( $card['volume24h'] ?? null ) ); ?></span>
			</div>
		</a>
		<?php aw_projects_save_button( $card['id'] ); ?>
	</div>
	<?php
}

/**
 * "18400" -> "18.4K", "1250000" -> "1.3M" — the compact number format the
 * Lovable prototype used for its (mocked) secondary card metric. Here it
 * formats real data (24h volume) rather than inventing a number.
 */
function aw_projects_compact_number( $value ) {
	if ( null === $value || '' === $value ) {
		return '—';
	}
	$n   = (float) $value;
	$neg = $n < 0;
	$n   = abs( $n );

	if ( $n >= 1000000000 ) {
		$out = rtrim( rtrim( number_format( $n / 1000000000, 1 ), '0' ), '.' ) . 'B';
	} elseif ( $n >= 1000000 ) {
		$out = rtrim( rtrim( number_format( $n / 1000000, 1 ), '0' ), '.' ) . 'M';
	} elseif ( $n >= 1000 ) {
		$out = rtrim( rtrim( number_format( $n / 1000, 1 ), '0' ), '.' ) . 'K';
	} else {
		$out = number_format( $n, 0 );
	}

	return ( $neg ? '-' : '' ) . $out;
}

/**
 * Every Project the current user has saved into any Collection, flattened
 * to one array of IDs and memoized per request — so rendering a grid of
 * 24 cards costs one lookup, not 24.
 */
function aw_projects_current_user_saved_ids() {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}

	$ids = array();
	if ( is_user_logged_in() && class_exists( 'AlphaWire_Projects_Collections' ) ) {
		foreach ( AlphaWire_Projects_Collections::get_all_public( get_current_user_id() ) as $collection ) {
			foreach ( (array) ( $collection['project_ids'] ?? array() ) as $id ) {
				$ids[] = (int) $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
	}
	return $ids;
}

/**
 * The star/save control on every card. Logged out, it's a plain link to
 * the login page (Thirdweb SSO) with a redirect back to the current page —
 * clicking it never silently fails, it explains what to do. Logged in,
 * it's a real toggle button that assets/js/projects.js wires up against
 * the Collections REST endpoints.
 */
function aw_projects_save_button( $project_id ) {
	if ( ! is_user_logged_in() ) {
		if ( function_exists( 'wp_login_url' ) ) {
			$current = ( isset( $_SERVER['REQUEST_URI'] ) ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
			printf(
				'<a class="aw-save-btn" href="%s" title="%s" aria-label="%s">☆</a>',
				esc_url( wp_login_url( home_url( $current ) ) ),
				esc_attr__( 'Log in to save this Project', 'alphawire-projects' ),
				esc_attr__( 'Log in to save this Project', 'alphawire-projects' )
			);
		}
		return;
	}

	$saved = in_array( (int) $project_id, aw_projects_current_user_saved_ids(), true );
	printf(
		'<button type="button" class="aw-save-btn%s" data-aw-save-project="%d" aria-pressed="%s" title="%s">%s</button>',
		$saved ? ' is-saved' : '',
		(int) $project_id,
		$saved ? 'true' : 'false',
		$saved ? esc_attr__( 'Manage collections for this Project', 'alphawire-projects' ) : esc_attr__( 'Save to a collection', 'alphawire-projects' ),
		$saved ? '★' : '☆'
	);
}

/**
 * One shared modal, printed once per page (only for logged-in users — see
 * archive-project.php/single-project.php), that assets/js/projects.js
 * opens and populates from the Collections REST endpoints. Static markup
 * only; no collection data is server-rendered into it, so it's identical
 * regardless of which star on the page triggered it.
 */
function aw_projects_render_collection_modal() {
	?>
	<div id="aw-collection-modal" class="aw-modal" hidden>
		<div class="aw-modal-backdrop" data-aw-modal-close></div>
		<div class="aw-modal-panel" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Save to a collection', 'alphawire-projects' ); ?>">
			<div class="aw-modal-head">
				<h3><?php esc_html_e( 'Save to a collection', 'alphawire-projects' ); ?></h3>
				<button type="button" class="aw-modal-close" data-aw-modal-close aria-label="<?php esc_attr_e( 'Close', 'alphawire-projects' ); ?>">×</button>
			</div>
			<div class="aw-modal-list" data-aw-collection-list>
				<p class="aw-muted"><?php esc_html_e( 'Loading your collections…', 'alphawire-projects' ); ?></p>
			</div>
			<form class="aw-modal-new" data-aw-new-collection-form>
				<input type="text" maxlength="60" placeholder="<?php esc_attr_e( 'New collection name', 'alphawire-projects' ); ?>" data-aw-new-collection-name required />
				<button type="submit" class="aw-btn"><?php esc_html_e( 'Create', 'alphawire-projects' ); ?></button>
			</form>
			<p class="aw-modal-error" data-aw-modal-error hidden></p>
		</div>
	</div>
	<?php
}

/**
 * A minimal inline SVG sparkline from a plain array of prices (the
 * market payload's 7-day series) — no charting library, no client-side
 * JS. Renders nothing (rather than an empty/broken chart) when there
 * isn't enough data to draw a line.
 */
function aw_projects_sparkline( $prices, $width = 300, $height = 48 ) {
	$prices = array_values( array_filter( (array) $prices, 'is_numeric' ) );
	if ( count( $prices ) < 2 ) {
		return;
	}

	$min = min( $prices );
	$max = max( $prices );
	$range = $max - $min;
	if ( 0.0 === $range ) {
		$range = 1.0;
	}

	$count  = count( $prices );
	$points = array();
	foreach ( $prices as $i => $p ) {
		$x = ( $i / ( $count - 1 ) ) * $width;
		$y = $height - ( ( ( $p - $min ) / $range ) * $height );
		$points[] = round( $x, 1 ) . ',' . round( $y, 1 );
	}

	$trend_up = end( $prices ) >= reset( $prices );
	$stroke   = $trend_up ? '#10ac84' : '#ef877f';

	printf(
		'<svg class="aw-sparkline" viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" aria-hidden="true"><polyline points="%3$s" fill="none" stroke="%4$s" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" /></svg>',
		(int) $width,
		(int) $height,
		esc_attr( implode( ' ', $points ) ),
		esc_attr( $stroke )
	);
}
