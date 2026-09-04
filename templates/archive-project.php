<?php
/**
 * Directory (/projects/) — build plan Phase 3, restyled to match the
 * Lovable prototype's layout at product's request: a left sidebar
 * (Explore nav + Categories + the Collections CTA) instead of a top
 * filter bar, search moved to the header.
 *
 * Still server-rendered on purpose (no client-side fetch loop against our
 * own REST API): filtering reads plain GET params and runs the exact same
 * WP_Query logic the REST /projects endpoint uses, via
 * AlphaWire_Projects_Directory_REST::query_projects(). get_header()/
 * get_footer() pull in the theme's real nav, ticker bar and footer. The
 * one piece of client-side JS on this page (assets/js/projects.js) is the
 * save-to-collection star — everything else still works with JS off.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$search    = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
$category  = isset( $_GET['category'] ) ? sanitize_title( wp_unslash( $_GET['category'] ) ) : '';
$narrative = isset( $_GET['narrative'] ) ? sanitize_title( wp_unslash( $_GET['narrative'] ) ) : '';
$paged     = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$view      = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'all';
if ( ! in_array( $view, array( 'all', 'trending', 'recently-updated', 'recently-launched' ), true ) ) {
	$view = 'all';
}

$filters_active = ( '' !== $search ) || ( '' !== $category ) || ( '' !== $narrative );

$archive_url = get_post_type_archive_link( AlphaWire_Projects_Post_Type::POST_TYPE );

$result = AlphaWire_Projects_Directory_REST::query_projects(
	array(
		'search'    => $search,
		'category'  => $category,
		'narrative' => $narrative,
		'page'      => $paged,
		'per_page'  => 24,
	)
);
$cards     = $result['cards'];
$query     = $result['query'];
$max_pages = $query ? (int) $query->max_num_pages : 1;

$sidebar_categories = AlphaWire_Projects_Directory_REST::categories( null );
?>

<div class="aw-projects aw-directory">

	<aside class="aw-sidebar">
		<div class="aw-sidebar-block">
			<div class="aw-sidebar-heading">Explore</div>
			<a class="aw-nav-item<?php echo ( ! $filters_active && 'all' === $view ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( $archive_url ); ?>">
				<span class="aw-nav-icon" aria-hidden="true">◎</span> All Projects
			</a>
			<a class="aw-nav-item<?php echo ( ! $filters_active && 'trending' === $view ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'view', 'trending', $archive_url ) ); ?>">
				<span class="aw-nav-icon" aria-hidden="true">⚡</span> Trending
			</a>
			<a class="aw-nav-item<?php echo ( ! $filters_active && 'recently-updated' === $view ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'view', 'recently-updated', $archive_url ) ); ?>">
				<span class="aw-nav-icon" aria-hidden="true">↻</span> Recently Updated
			</a>
			<a class="aw-nav-item<?php echo ( ! $filters_active && 'recently-launched' === $view ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'view', 'recently-launched', $archive_url ) ); ?>">
				<span class="aw-nav-icon" aria-hidden="true">✦</span> Recently Launched
			</a>
		</div>

		<?php if ( $sidebar_categories ) : ?>
			<div class="aw-sidebar-block">
				<div class="aw-sidebar-heading">Categories</div>
				<?php foreach ( $sidebar_categories as $c ) : ?>
					<a class="aw-nav-item<?php echo ( $category === $c['slug'] ) ? ' is-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'category', $c['slug'], $archive_url ) ); ?>">
						<span><?php echo esc_html( $c['label'] ); ?></span>
						<span class="aw-muted"><?php echo (int) $c['count']; ?></span>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<div class="aw-collection-cta">
			<strong>Create a collection</strong>
			<p>Save and organize your favorite projects.</p>
			<?php if ( is_user_logged_in() ) : ?>
				<button type="button" class="aw-btn aw-btn-ghost" data-aw-new-collection>+ New collection</button>
			<?php else : ?>
				<a class="aw-btn aw-btn-ghost" href="<?php echo esc_url( wp_login_url( home_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' ) ) ); ?>">Log in to start</a>
			<?php endif; ?>
			<a class="aw-sidebar-link" href="<?php echo esc_url( add_query_arg( array(), trailingslashit( $archive_url ) . 'collections/' ) ); ?>">View my collections</a>
		</div>
	</aside>

	<main class="aw-directory-main">

		<div class="aw-directory-head">
			<div>
				<h1>Projects</h1>
				<p>A curated set of AlphaWire project profiles — market data, approved AI summaries, timelines
					and our editorial coverage in one place.</p>
			</div>
			<form class="aw-searchbar" method="get" action="<?php echo esc_url( $archive_url ); ?>">
				<input type="text" name="q" value="<?php echo esc_attr( $search ); ?>"
					placeholder="Search by project, ticker, category or narrative" />
				<?php if ( '' !== $category ) : ?>
					<input type="hidden" name="category" value="<?php echo esc_attr( $category ); ?>" />
				<?php endif; ?>
				<?php if ( '' !== $narrative ) : ?>
					<input type="hidden" name="narrative" value="<?php echo esc_attr( $narrative ); ?>" />
				<?php endif; ?>
				<button type="submit" aria-label="Search">⌕</button>
			</form>
		</div>

		<?php if ( $filters_active ) : ?>

			<section class="aw-section">
				<div class="aw-section-header">
					<h2><?php echo count( $cards ); ?> result<?php echo 1 === count( $cards ) ? '' : 's'; ?></h2>
					<a class="aw-hint" href="<?php echo esc_url( $archive_url ); ?>">Clear filters</a>
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

		<?php elseif ( 'trending' === $view ) : ?>

			<?php $focused = AlphaWire_Projects_Directory_REST::trending( null ); ?>
			<section class="aw-section">
				<div class="aw-section-header">
					<h2>Trending Projects</h2>
					<p class="aw-hint">Projects gaining momentum across AlphaWire coverage.</p>
				</div>
				<?php if ( $focused ) : ?>
					<div class="aw-trend-grid">
						<?php foreach ( $focused as $i => $card ) : ?>
							<?php aw_projects_render_trending_card( $card, $i + 1 ); ?>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="aw-empty">No Project has a manual trending order set yet.</p>
				<?php endif; ?>
			</section>

		<?php elseif ( 'recently-updated' === $view ) : ?>

			<?php $focused = AlphaWire_Projects_Directory_REST::recently_updated( null ); ?>
			<section class="aw-section">
				<div class="aw-section-header"><h2>Recently Updated</h2></div>
				<?php if ( $focused ) : ?>
					<div class="aw-grid">
						<?php foreach ( $focused as $card ) : ?>
							<?php aw_projects_render_card( $card ); ?>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="aw-empty">Nothing here yet.</p>
				<?php endif; ?>
			</section>

		<?php elseif ( 'recently-launched' === $view ) : ?>

			<?php $focused = AlphaWire_Projects_Directory_REST::recently_launched( null ); ?>
			<section class="aw-section">
				<div class="aw-section-header"><h2>Recently Launched</h2></div>
				<?php if ( $focused ) : ?>
					<div class="aw-grid">
						<?php foreach ( $focused as $card ) : ?>
							<?php aw_projects_render_card( $card ); ?>
						<?php endforeach; ?>
					</div>
				<?php else : ?>
					<p class="aw-empty">Nothing here yet.</p>
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
						<a class="aw-hint aw-view-all" href="<?php echo esc_url( add_query_arg( 'view', 'trending', $archive_url ) ); ?>">View all</a>
					</div>
					<div class="aw-trend-grid">
						<?php foreach ( array_slice( $trending, 0, 6 ) as $i => $card ) : ?>
							<?php aw_projects_render_trending_card( $card, $i + 1 ); ?>
						<?php endforeach; ?>
					</div>
				</section>
			<?php endif; ?>

			<section class="aw-section aw-two-col">
				<div class="aw-panel">
					<div class="aw-section-header">
						<h2>Top Categories</h2>
						<a class="aw-hint" href="<?php echo esc_url( add_query_arg( 'view', 'trending', $archive_url ) ); ?>">View all</a>
					</div>
					<?php foreach ( array_slice( $sidebar_categories, 0, 5 ) as $c ) : ?>
						<a class="aw-list-row" href="<?php echo esc_url( add_query_arg( 'category', $c['slug'], $archive_url ) ); ?>">
							<span><?php echo esc_html( $c['label'] ); ?></span>
							<?php aw_projects_change( $c['change24h'] ); ?>
						</a>
					<?php endforeach; ?>
				</div>

				<div class="aw-panel">
					<div class="aw-section-header">
						<h2>Trending Narratives</h2>
						<a class="aw-hint" href="<?php echo esc_url( add_query_arg( 'view', 'trending', $archive_url ) ); ?>">View all</a>
					</div>
					<?php foreach ( array_slice( AlphaWire_Projects_Directory_REST::narratives( null ), 0, 5 ) as $n ) : ?>
						<a class="aw-list-row" href="<?php echo esc_url( add_query_arg( 'narrative', $n['slug'], $archive_url ) ); ?>">
							<span><?php echo esc_html( $n['label'] ); ?></span>
							<?php aw_projects_change( $n['change24h'] ); ?>
						</a>
					<?php endforeach; ?>
				</div>
			</section>

			<section class="aw-section aw-two-col">
				<div class="aw-panel">
					<div class="aw-section-header">
						<h2>Recently Launched</h2>
						<a class="aw-hint" href="<?php echo esc_url( add_query_arg( 'view', 'recently-launched', $archive_url ) ); ?>">View all</a>
					</div>
					<?php foreach ( AlphaWire_Projects_Directory_REST::recently_launched( null ) as $card ) : ?>
						<a class="aw-list-row" href="<?php echo esc_url( get_permalink( $card['id'] ) ); ?>">
							<span><?php echo esc_html( $card['name'] ); ?> <span class="aw-muted"><?php echo esc_html( $card['ticker'] ); ?></span></span>
						</a>
					<?php endforeach; ?>
				</div>

				<div class="aw-panel">
					<div class="aw-section-header">
						<h2>Recently Updated</h2>
						<a class="aw-hint" href="<?php echo esc_url( add_query_arg( 'view', 'recently-updated', $archive_url ) ); ?>">View all</a>
					</div>
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

	</main>

</div>

<?php
if ( is_user_logged_in() ) {
	aw_projects_render_collection_modal();
}
get_footer();