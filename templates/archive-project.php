<?php
/**
 * Directory (/projects/) — build plan Phase 3.
 *
 * Server-rendered on purpose (no client-side fetch loop against our own
 * REST API): filtering reads plain GET params and runs the exact same
 * WP_Query logic the REST /projects endpoint uses, via
 * AlphaWire_Projects_Directory_REST::query_projects(). get_header()/
 * get_footer() pull in the theme's real nav, ticker bar and footer.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$search    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$category  = isset( $_GET['category'] ) ? sanitize_title( wp_unslash( $_GET['category'] ) ) : '';
$narrative = isset( $_GET['narrative'] ) ? sanitize_title( wp_unslash( $_GET['narrative'] ) ) : '';
$paged     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );

$filters_active = ( '' !== $search ) || ( '' !== $category ) || ( '' !== $narrative );

$result = AlphaWire_Projects_Directory_REST::query_projects(
	array(
		'search'    => $search,
		'category'  => $category,
		'narrative' => $narrative,
		'page'      => $paged,
		'per_page'  => 24,
	)
);
$cards       = $result['cards'];
$query       = $result['query'];
$max_pages   = $query ? (int) $query->max_num_pages : 1;

$category_terms = get_terms( array( 'taxonomy' => 'pillar', 'hide_empty' => false ) );
$narrative_terms = get_terms( array( 'taxonomy' => 'topic', 'hide_empty' => false ) );
?>

<div class="aw-projects">

	<div class="aw-directory-head">
		<div>
			<h1>Projects</h1>
			<p>A curated set of AlphaWire project profiles — market data, editorial coverage and approved AI
				summaries in one place.</p>
		</div>
	</div>

	<form class="aw-filters" method="get" action="<?php echo esc_url( get_post_type_archive_link( AlphaWire_Projects_Post_Type::POST_TYPE ) ); ?>">
		<input type="text" name="q" value="<?php echo esc_attr( $search ); ?>"
			placeholder="Search by project, ticker, category or narrative" />

		<?php if ( ! is_wp_error( $category_terms ) && $category_terms ) : ?>
			<select name="category">
				<option value="">All categories</option>
				<?php foreach ( $category_terms as $term ) : ?>
					<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $category, $term->slug ); ?>>
						<?php echo esc_html( $term->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>

		<?php if ( ! is_wp_error( $narrative_terms ) && $narrative_terms ) : ?>
			<select name="narrative">
				<option value="">All narratives</option>
				<?php foreach ( $narrative_terms as $term ) : ?>
					<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $narrative, $term->slug ); ?>>
						<?php echo esc_html( $term->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		<?php endif; ?>

		<button type="submit">Search</button>
		<?php if ( $filters_active ) : ?>
			<a class="aw-clear" href="<?php echo esc_url( get_post_type_archive_link( AlphaWire_Projects_Post_Type::POST_TYPE ) ); ?>">Clear filters</a>
		<?php endif; ?>
	</form>

	<?php if ( $filters_active ) : ?>

		<section class="aw-section">
			<div class="aw-section-header">
				<h2><?php echo count( $cards ); ?> result<?php echo 1 === count( $cards ) ? '' : 's'; ?></h2>
			</div>
			<?php if ( $cards ) : ?>
				<div class="aw-grid">
					<?php foreach ( $cards as $card ) : ?>
						<?php aw_projects_render_card( $card ); ?>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="aw-empty">No projects match this search yet.</p>
			<?php endif; ?>
		</section>

	<?php else : ?>

		<?php
		$trending = AlphaWire_Projects_Directory_REST::trending( null );
		if ( $trending ) :
			?>
			<section class="aw-section">
				<div class="aw-section-header">
					<h2>Trending Projects</h2>
					<p class="aw-hint">Projects gaining momentum across AlphaWire coverage.</p>
				</div>
				<div class="aw-strip">
					<?php foreach ( $trending as $i => $card ) : ?>
						<a class="aw-panel-hover aw-strip-card" href="<?php echo esc_url( get_permalink( $card['id'] ) ); ?>">
							<span class="aw-rank"><?php echo (int) $i + 1; ?></span>
							<?php aw_projects_logo( $card, 40 ); ?>
							<span class="aw-name"><?php echo esc_html( $card['name'] ); ?></span>
							<span class="aw-ticker"><?php echo esc_html( $card['ticker'] ); ?></span>
							<div style="margin-top:8px;"><?php aw_projects_change( $card['change24h'] ); ?></div>
						</a>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<section class="aw-section aw-four-col">
			<div class="aw-panel">
				<div class="aw-section-header"><h2>Top Categories</h2></div>
				<?php foreach ( AlphaWire_Projects_Directory_REST::categories( null ) as $c ) : ?>
					<a class="aw-list-row" href="<?php echo esc_url( add_query_arg( 'category', $c['slug'], get_post_type_archive_link( AlphaWire_Projects_Post_Type::POST_TYPE ) ) ); ?>">
						<span><?php echo esc_html( $c['label'] ); ?></span>
						<span class="aw-muted"><?php echo (int) $c['count']; ?></span>
					</a>
				<?php endforeach; ?>
			</div>

			<div class="aw-panel">
				<div class="aw-section-header"><h2>Trending Narratives</h2></div>
				<?php foreach ( AlphaWire_Projects_Directory_REST::narratives( null ) as $n ) : ?>
					<a class="aw-list-row" href="<?php echo esc_url( add_query_arg( 'narrative', $n['slug'], get_post_type_archive_link( AlphaWire_Projects_Post_Type::POST_TYPE ) ) ); ?>">
						<span><?php echo esc_html( $n['label'] ); ?></span>
						<span class="aw-muted"><?php echo (int) $n['count']; ?></span>
					</a>
				<?php endforeach; ?>
			</div>

			<div class="aw-panel">
				<div class="aw-section-header"><h2>Recently Launched</h2></div>
				<?php foreach ( AlphaWire_Projects_Directory_REST::recently_launched( null ) as $card ) : ?>
					<a class="aw-list-row" href="<?php echo esc_url( get_permalink( $card['id'] ) ); ?>">
						<span><?php echo esc_html( $card['name'] ); ?> <span class="aw-muted"><?php echo esc_html( $card['ticker'] ); ?></span></span>
					</a>
				<?php endforeach; ?>
			</div>

			<div class="aw-panel">
				<div class="aw-section-header"><h2>Recently Updated</h2></div>
				<?php foreach ( AlphaWire_Projects_Directory_REST::recently_updated( null ) as $card ) : ?>
					<a class="aw-list-row" href="<?php echo esc_url( get_permalink( $card['id'] ) ); ?>">
						<span><?php echo esc_html( $card['name'] ); ?> <span class="aw-muted"><?php echo esc_html( $card['ticker'] ); ?></span></span>
					</a>
				<?php endforeach; ?>
			</div>
		</section>

		<?php
		$picks = AlphaWire_Projects_Directory_REST::editors_picks( null );
		if ( $picks ) :
			?>
			<section class="aw-section">
				<div class="aw-section-header">
					<h2>Editor's Picks</h2>
					<p class="aw-hint">Projects our editorial team is watching closely.</p>
				</div>
				<div class="aw-grid">
					<?php foreach ( $picks as $card ) : ?>
						<?php aw_projects_render_card( $card ); ?>
					<?php endforeach; ?>
				</div>
			</section>
		<?php endif; ?>

		<section class="aw-section">
			<div class="aw-section-header">
				<h2>All Projects</h2>
			</div>
			<?php if ( $cards ) : ?>
				<div class="aw-grid">
					<?php foreach ( $cards as $card ) : ?>
						<?php aw_projects_render_card( $card ); ?>
					<?php endforeach; ?>
				</div>
			<?php else : ?>
				<p class="aw-empty">No projects published yet.</p>
			<?php endif; ?>

			<?php if ( $max_pages > 1 ) : ?>
				<div class="aw-pagination">
					<?php for ( $i = 1; $i <= $max_pages; $i++ ) : ?>
						<?php if ( $i === $paged ) : ?>
							<span class="aw-current"><?php echo (int) $i; ?></span>
						<?php else : ?>
							<a href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>"><?php echo (int) $i; ?></a>
						<?php endif; ?>
					<?php endfor; ?>
				</div>
			<?php endif; ?>
		</section>

	<?php endif; ?>

</div>

<?php get_footer(); ?>
