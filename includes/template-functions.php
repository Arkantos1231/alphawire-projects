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
 */
function aw_projects_render_card( $card ) {
	?>
	<a class="aw-panel-hover aw-card" href="<?php echo esc_url( get_permalink( $card['id'] ) ); ?>">
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
