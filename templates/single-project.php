<?php
/**
 * Project Profile (/projects/{slug}/) — build plan Phase 4.
 *
 * Reuses AlphaWire_Projects_REST::build_payload() — the exact same data
 * contract the REST endpoint returns — so this page and
 * GET /alphawire-projects/v1/projects/{slug} can never drift apart.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();

	$project = AlphaWire_Projects_REST::build_payload( get_post() );
	$market  = $project['market'];

	$coverage_by_type = array();
	foreach ( $project['coverage'] as $item ) {
		$coverage_by_type[ $item['type'] ][] = $item;
	}
	$coverage_items = $project['coverage'];
	usort(
		$coverage_items,
		function ( $a, $b ) {
			return strcmp( $b['date'] ?? '', $a['date'] ?? '' );
		}
	);
	$research_items = AlphaWire_Projects_Content_Relationships::get_selected_coverage( $project['id'] );
	$research_by_type = array();
	foreach ( $research_items as $item ) {
		$research_by_type[ $item['type'] ][] = $item;
	}
	?>

	<div class="aw-projects">

		<nav class="aw-breadcrumb">
			<a href="<?php echo esc_url( get_post_type_archive_link( AlphaWire_Projects_Post_Type::POST_TYPE ) ); ?>">Projects</a>
			<span>/</span>
			<span><?php echo esc_html( $project['name'] ); ?></span>
		</nav>

		<div class="aw-profile-head">

			<div class="aw-profile-identity">
				<?php aw_projects_logo( $project, 96 ); ?>
				<div>
					<?php if ( ! empty( $project['categories'] ) ) : ?>
						<p class="aw-eyebrow"><?php echo esc_html( implode( ' · ', $project['categories'] ) ); ?></p>
					<?php endif; ?>
					<h1>
						<?php echo esc_html( $project['name'] ); ?>
						<?php if ( $project['verified'] ) : ?>
							<span class="aw-verified" title="Verified project">✓</span>
						<?php endif; ?>
					</h1>
					<div class="aw-ticker-row">
						<?php if ( $project['ticker'] ) : ?>
							<span class="aw-chip"><?php echo esc_html( $project['ticker'] ); ?></span>
						<?php endif; ?>
					</div>
					<?php if ( $project['description'] ) : ?>
						<p class="aw-desc"><?php echo esc_html( wp_strip_all_tags( $project['description'] ) ); ?></p>
					<?php endif; ?>
					<?php if ( ! empty( $project['narratives'] ) ) : ?>
						<div class="aw-chip-row">
							<?php foreach ( $project['narratives'] as $n ) : ?>
								<span class="aw-chip"><?php echo esc_html( $n ); ?></span>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
					<?php if ( ! empty( $project['links'] ) ) : ?>
						<div class="aw-link-row">
							<?php foreach ( $project['links'] as $link ) : ?>
								<?php if ( empty( $link['url'] ) ) { continue; } ?>
								<a class="aw-panel-hover aw-link-pill" href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noreferrer noopener">
									<?php echo esc_html( $link['label'] ? $link['label'] : $link['url'] ); ?> ↗
								</a>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</div>
			</div>

			<div class="aw-panel">
				<div class="aw-panel-title-row">
					<h2>Key Stats</h2>
					<?php if ( ! empty( $market['stale'] ) ) : ?>
						<span class="aw-badge">last known price</span>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $market['chart'] ) ) : ?>
					<?php aw_projects_sparkline( $market['chart'], 260, 48 ); ?>
				<?php endif; ?>
				<dl>
					<div class="aw-stat-row">
						<dt>Price</dt>
						<dd><?php echo esc_html( $market['price'] ?? '—' ); ?> <?php aw_projects_change( $market['change24h'] ?? null ); ?></dd>
					</div>
					<div class="aw-stat-row">
						<dt>Market Cap</dt>
						<dd><?php echo esc_html( $market['marketCap'] ?? '—' ); ?></dd>
					</div>
					<div class="aw-stat-row">
						<dt>24h Volume</dt>
						<dd><?php echo esc_html( $market['volume24h'] ?? '—' ); ?></dd>
					</div>
					<div class="aw-stat-row">
						<dt>Circulating Supply</dt>
						<dd><?php echo esc_html( $market['circulatingSupply'] ?? '—' ); ?></dd>
					</div>
					<div class="aw-stat-row">
						<dt>Total Supply</dt>
						<dd><?php echo esc_html( $market['totalSupply'] ?? '—' ); ?></dd>
					</div>
					<div class="aw-stat-row">
						<dt>All-Time High</dt>
						<dd><?php echo esc_html( $market['allTimeHigh'] ?? '—' ); ?></dd>
					</div>
					<?php if ( $project['launchDate'] ) : ?>
						<div class="aw-stat-row">
							<dt>Launched</dt>
							<dd><?php echo esc_html( $project['launchDate'] ); ?></dd>
						</div>
					<?php endif; ?>
				</dl>
				<p style="font-size:10px;color:var(--aw-muted);margin-top:10px;">Market data — read only, never
					edited by AlphaWire editorial.</p>
			</div>

			<div class="aw-panel">
				<div class="aw-panel-title-row">
					<h2>AI Project Summary</h2>
					<span class="aw-badge">Beta</span>
				</div>
				<?php if ( 'approved' === $project['aiSummary']['status'] && $project['aiSummary']['text'] ) : ?>
					<p class="aw-ai-summary-text"><?php echo esc_html( $project['aiSummary']['text'] ); ?></p>
				<?php else : ?>
					<div class="aw-ai-pending">
						An AI summary is generated in the background and reviewed by an editor before it appears
						here.
					</div>
				<?php endif; ?>
			</div>

		</div>

		<nav class="aw-profile-tabs" role="tablist" aria-label="Project sections">
			<button type="button" class="aw-profile-tab is-active" id="aw-tab-overview" role="tab" aria-selected="true" aria-controls="aw-panel-overview" data-aw-profile-tab="overview">Overview</button>
			<button type="button" class="aw-profile-tab" id="aw-tab-timeline" role="tab" aria-selected="false" aria-controls="aw-panel-timeline" data-aw-profile-tab="timeline">Timeline</button>
			<button type="button" class="aw-profile-tab" id="aw-tab-coverage" role="tab" aria-selected="false" aria-controls="aw-panel-coverage" data-aw-profile-tab="coverage">AlphaWire Coverage</button>
			<button type="button" class="aw-profile-tab" id="aw-tab-research" role="tab" aria-selected="false" aria-controls="aw-panel-research" data-aw-profile-tab="research">Research</button>
			<button type="button" class="aw-profile-tab" id="aw-tab-related" role="tab" aria-selected="false" aria-controls="aw-panel-related" data-aw-profile-tab="related">Related</button>
		</nav>

		<div class="aw-profile-tab-panels">
			<div class="aw-profile-tab-panel is-active" id="aw-panel-overview" role="tabpanel" aria-labelledby="aw-tab-overview" data-aw-profile-panel="overview">
				<div class="aw-overview-grid">

					<div class="aw-overview-col">
						<?php if ( $project['description'] || ! empty( $project['links'] ) ) : ?>
							<div class="aw-panel aw-about-panel">
								<h2><?php echo esc_html( sprintf( 'What is %s?', $project['name'] ) ); ?></h2>
								<?php if ( $project['description'] ) : ?>
									<p class="aw-about-text"><?php echo esc_html( wp_strip_all_tags( $project['description'] ) ); ?></p>
								<?php endif; ?>
								<?php
								$primary_link = null;
								foreach ( $project['links'] as $link ) {
									if ( ! empty( $link['url'] ) ) {
										$primary_link = $link;
										break;
									}
								}
								?>
								<?php if ( $primary_link ) : ?>
									<a class="aw-link-accent" href="<?php echo esc_url( $primary_link['url'] ); ?>" target="_blank" rel="noreferrer noopener">Read more →</a>
								<?php endif; ?>
								<span class="aw-tl-badge">AlphaWire editorial</span>
							</div>
						<?php endif; ?>

						<?php if ( ! empty( $project['links'] ) ) : ?>
							<div class="aw-panel">
								<h2>Key Links</h2>
								<?php foreach ( $project['links'] as $link ) : ?>
									<?php if ( empty( $link['url'] ) ) { continue; } ?>
									<a class="aw-list-row" href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noreferrer noopener">
										<span><?php echo esc_html( $link['label'] ? $link['label'] : $link['url'] ); ?></span>
										<span aria-hidden="true">↗</span>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

					<div class="aw-overview-col">
						<?php if ( ! empty( $project['timeline'] ) ) : ?>
							<div class="aw-panel">
								<div class="aw-panel-title-row">
									<h2>Timeline</h2>
									<a class="aw-link-accent" href="#aw-panel-timeline" data-aw-profile-goto="timeline">View full timeline →</a>
								</div>
								<ol class="aw-timeline-compact">
									<?php foreach ( array_reverse( $project['timeline'] ) as $event ) : ?>
										<li class="aw-tlc-item">
											<span class="aw-tlc-date"><?php echo esc_html( ! empty( $event['date'] ) ? wp_date( 'M Y', strtotime( $event['date'] ) ) : '' ); ?></span>
											<p class="aw-tlc-title"><?php echo esc_html( $event['title'] ?? '' ); ?></p>
											<?php if ( ! empty( $event['description'] ) ) : ?>
												<p class="aw-tlc-desc"><?php echo esc_html( $event['description'] ); ?></p>
											<?php endif; ?>
										</li>
									<?php endforeach; ?>
								</ol>
							</div>
						<?php endif; ?>
					</div>

					<div class="aw-overview-col">
						<div class="aw-panel">
							<div class="aw-panel-title-row">
								<h2>Market</h2>
								<span class="aw-badge"><?php echo esc_html( ! empty( $market['stale'] ) ? 'last known price' : 'Market data · external' ); ?></span>
							</div>
							<?php if ( ! empty( $market['chart'] ) ) : ?>
								<?php aw_projects_sparkline( $market['chart'], 260, 48 ); ?>
							<?php endif; ?>
							<dl>
								<div class="aw-stat-row">
									<dt>Price</dt>
									<dd><?php echo esc_html( $market['price'] ?? '—' ); ?> <?php aw_projects_change( $market['change24h'] ?? null ); ?></dd>
								</div>
								<div class="aw-stat-row">
									<dt>Market Cap</dt>
									<dd><?php echo esc_html( $market['marketCap'] ?? '—' ); ?></dd>
								</div>
								<div class="aw-stat-row">
									<dt>24h Volume</dt>
									<dd><?php echo esc_html( $market['volume24h'] ?? '—' ); ?></dd>
								</div>
								<div class="aw-stat-row">
									<dt>Circulating Supply</dt>
									<dd><?php echo esc_html( $market['circulatingSupply'] ?? '—' ); ?></dd>
								</div>
								<div class="aw-stat-row">
									<dt>Total Supply</dt>
									<dd><?php echo esc_html( $market['totalSupply'] ?? '—' ); ?></dd>
								</div>
								<div class="aw-stat-row">
									<dt>All-Time High</dt>
									<dd><?php echo esc_html( $market['allTimeHigh'] ?? '—' ); ?></dd>
								</div>
								<?php if ( $project['launchDate'] ) : ?>
									<div class="aw-stat-row">
										<dt>Launched</dt>
										<dd><?php echo esc_html( $project['launchDate'] ); ?></dd>
									</div>
								<?php endif; ?>
							</dl>
							<p style="font-size:10px;color:var(--aw-muted);margin-top:10px;">Market data — read only, never
								edited by AlphaWire editorial.</p>
						</div>

						<?php if ( ! empty( $project['narratives'] ) ) : ?>
							<div class="aw-panel">
								<h2>Top Narratives</h2>
								<?php foreach ( $project['narratives'] as $n ) : ?>
									<div class="aw-list-row">
										<span><span class="aw-narrative-flame" aria-hidden="true">🔥</span> <?php echo esc_html( $n ); ?></span>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</div>

				</div>

				<?php if ( $coverage_by_type ) : ?>
					<section class="aw-section aw-panel aw-coverage-section" data-aw-coverage-slug="<?php echo esc_attr( $project['slug'] ); ?>" data-aw-coverage-scope="coverage">
				<div class="aw-section-header">
					<h2>Latest from AlphaWire</h2>
					<div class="aw-coverage-filters" role="group" aria-label="Filter AlphaWire coverage">
						<button type="button" class="aw-coverage-filter is-active" data-aw-coverage-filter="all">All</button>
						<?php foreach ( array_keys( $coverage_by_type ) as $type ) : ?>
							<button type="button" class="aw-coverage-filter" data-aw-coverage-filter="<?php echo esc_attr( sanitize_title( $type ) ); ?>"><?php echo esc_html( $type ); ?></button>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="aw-grid">
					<?php foreach ( array_slice( $coverage_items, 0, 6 ) as $item ) : ?>
						<a class="aw-panel-hover aw-coverage-item" data-aw-coverage-type="<?php echo esc_attr( sanitize_title( $item['type'] ) ); ?>" href="<?php echo esc_url( $item['url'] ); ?>">
									<?php if ( ! empty( $item['image'] ) ) : ?>
										<img class="aw-cov-thumb" src="<?php echo esc_url( $item['image'] ); ?>" alt="" />
									<?php else : ?>
										<span class="aw-cov-thumb" aria-hidden="true"></span>
									<?php endif; ?>
									<span class="aw-cov-content">
										<span class="aw-cov-type"><?php echo esc_html( $item['type'] ); ?></span>
										<span class="aw-cov-title"><?php echo esc_html( $item['title'] ); ?></span>
										<?php if ( ! empty( $item['excerpt'] ) ) : ?>
											<span class="aw-cov-excerpt"><?php echo esc_html( wp_strip_all_tags( $item['excerpt'] ) ); ?></span>
										<?php endif; ?>
										<span class="aw-cov-date">
											<?php echo esc_html( $item['date'] ? wp_date( 'M j, Y', strtotime( $item['date'] ) ) : '' ); ?>
											<?php if ( ! empty( $item['readTime'] ) ) : ?>
												· <?php echo ( 'Podcast' === $item['type'] ) ? (int) $item['readTime'] . ' min listen' : (int) $item['readTime'] . ' min read'; ?>
											<?php endif; ?>
										</span>
									</span>
								</a>
					<?php endforeach; ?>
				</div>
				<?php if ( count( $coverage_items ) > 6 ) : ?>
					<button type="button" class="aw-coverage-load-more" data-aw-coverage-load-more>Load more</button>
				<?php endif; ?>
					</section>
				<?php endif; ?>
			</div>

			<div class="aw-profile-tab-panel" id="aw-panel-timeline" role="tabpanel" aria-labelledby="aw-tab-timeline" data-aw-profile-panel="timeline" hidden>
				<?php if ( ! empty( $project['timeline'] ) ) : ?>
					<section class="aw-section aw-panel">
						<div class="aw-section-header">
							<h2><?php echo esc_html( $project['name'] ); ?> Timeline</h2>
							<p class="aw-hint">Editorially maintained project milestones.</p>
						</div>
						<div class="aw-timeline-scroll">
							<ol class="aw-timeline">
								<?php foreach ( $project['timeline'] as $i => $event ) : ?>
									<li class="aw-tl-item">
										<span class="aw-tl-dot" aria-hidden="true"></span>
										<div class="aw-tl-card">
											<span class="aw-tl-date"><?php echo esc_html( ! empty( $event['date'] ) ? wp_date( 'M Y', strtotime( $event['date'] ) ) : '' ); ?></span>
											<p class="aw-tl-title"><?php echo esc_html( $event['title'] ?? '' ); ?></p>
											<?php if ( ! empty( $event['description'] ) ) : ?>
												<p class="aw-tl-desc"><?php echo esc_html( $event['description'] ); ?></p>
											<?php endif; ?>
											<span class="aw-tl-milestone"><?php echo esc_html( sprintf( 'Milestone %d', $i + 1 ) ); ?></span>
										</div>
									</li>
								<?php endforeach; ?>
							</ol>
						</div>
						<span class="aw-tl-badge">AlphaWire editorial</span>
					</section>
				<?php endif; ?>
			</div>
			<div class="aw-profile-tab-panel" id="aw-panel-coverage" role="tabpanel" aria-labelledby="aw-tab-coverage" data-aw-profile-panel="coverage" hidden>
				<?php if ( $coverage_by_type ) : ?>
					<section class="aw-section aw-panel aw-coverage-section" data-aw-coverage-slug="<?php echo esc_attr( $project['slug'] ); ?>" data-aw-coverage-scope="coverage">
						<div class="aw-section-header">
							<h2>Latest from AlphaWire</h2>
							<div class="aw-coverage-filters" role="group" aria-label="Filter AlphaWire coverage">
								<button type="button" class="aw-coverage-filter is-active" data-aw-coverage-filter="all">All</button>
								<?php foreach ( array_keys( $coverage_by_type ) as $type ) : ?>
									<button type="button" class="aw-coverage-filter" data-aw-coverage-filter="<?php echo esc_attr( sanitize_title( $type ) ); ?>"><?php echo esc_html( $type ); ?></button>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="aw-grid">
							<?php foreach ( array_slice( $coverage_items, 0, 6 ) as $item ) : ?>
								<a class="aw-panel-hover aw-coverage-item" data-aw-coverage-type="<?php echo esc_attr( sanitize_title( $item['type'] ) ); ?>" href="<?php echo esc_url( $item['url'] ); ?>">
											<?php if ( ! empty( $item['image'] ) ) : ?>
												<img class="aw-cov-thumb" src="<?php echo esc_url( $item['image'] ); ?>" alt="" />
											<?php else : ?>
												<span class="aw-cov-thumb" aria-hidden="true"></span>
											<?php endif; ?>
											<span class="aw-cov-content">
													<span class="aw-cov-type"><?php echo esc_html( $item['type'] ); ?></span>
												<span class="aw-cov-title"><?php echo esc_html( $item['title'] ); ?></span>
												<?php if ( ! empty( $item['excerpt'] ) ) : ?>
													<span class="aw-cov-excerpt"><?php echo esc_html( wp_strip_all_tags( $item['excerpt'] ) ); ?></span>
												<?php endif; ?>
												<span class="aw-cov-date">
													<?php echo esc_html( $item['date'] ? wp_date( 'M j, Y', strtotime( $item['date'] ) ) : '' ); ?>
													<?php if ( ! empty( $item['readTime'] ) ) : ?>
														· <?php echo ( 'Podcast' === $item['type'] ) ? (int) $item['readTime'] . ' min listen' : (int) $item['readTime'] . ' min read'; ?>
													<?php endif; ?>
												</span>
											</span>
										</a>
							<?php endforeach; ?>
						</div>
						<?php if ( count( $coverage_items ) > 6 ) : ?>
							<button type="button" class="aw-coverage-load-more" data-aw-coverage-load-more>Load more</button>
						<?php endif; ?>
					</section>
				<?php endif; ?>
			</div>
			<div class="aw-profile-tab-panel" id="aw-panel-research" role="tabpanel" aria-labelledby="aw-tab-research" data-aw-profile-panel="research" hidden>
				<?php if ( $research_items ) : ?>
					<section class="aw-section aw-panel aw-coverage-section" data-aw-coverage-slug="<?php echo esc_attr( $project['slug'] ); ?>" data-aw-coverage-scope="research">
						<div class="aw-section-header">
							<h2>AlphaWire Research on <?php echo esc_html( $project['name'] ); ?></h2>
							<div class="aw-coverage-filters" role="group" aria-label="Filter Research">
								<button type="button" class="aw-coverage-filter is-active" data-aw-coverage-filter="all">All</button>
								<?php foreach ( array_keys( $research_by_type ) as $type ) : ?>
									<button type="button" class="aw-coverage-filter" data-aw-coverage-filter="<?php echo esc_attr( sanitize_title( $type ) ); ?>"><?php echo esc_html( $type ); ?></button>
								<?php endforeach; ?>
							</div>
						</div>
						<div class="aw-grid">
							<?php foreach ( array_slice( $research_items, 0, 6 ) as $item ) : ?>
								<a class="aw-panel-hover aw-coverage-item" data-aw-coverage-type="<?php echo esc_attr( sanitize_title( $item['type'] ) ); ?>" href="<?php echo esc_url( $item['url'] ); ?>">
									<?php if ( ! empty( $item['image'] ) ) : ?>
										<img class="aw-cov-thumb" src="<?php echo esc_url( $item['image'] ); ?>" alt="" />
									<?php else : ?>
										<span class="aw-cov-thumb" aria-hidden="true"></span>
									<?php endif; ?>
									<span class="aw-cov-content">
										<span class="aw-cov-type"><?php echo esc_html( $item['type'] ); ?></span>
										<span class="aw-cov-title"><?php echo esc_html( $item['title'] ); ?></span>
										<?php if ( ! empty( $item['excerpt'] ) ) : ?>
											<span class="aw-cov-excerpt"><?php echo esc_html( wp_strip_all_tags( $item['excerpt'] ) ); ?></span>
										<?php endif; ?>
										<span class="aw-cov-date">
											<?php echo esc_html( $item['date'] ? wp_date( 'M j, Y', strtotime( $item['date'] ) ) : '' ); ?>
											<?php if ( ! empty( $item['readTime'] ) ) : ?>
												· <?php echo ( 'Podcast' === $item['type'] ) ? (int) $item['readTime'] . ' min listen' : (int) $item['readTime'] . ' min read'; ?>
											<?php endif; ?>
										</span>
									</span>
								</a>
							<?php endforeach; ?>
						</div>
						<?php if ( count( $research_items ) > 6 ) : ?>
							<button type="button" class="aw-coverage-load-more" data-aw-coverage-load-more>Load more</button>
						<?php endif; ?>
					</section>
				<?php endif; ?>
			</div>

			<div class="aw-profile-tab-panel" id="aw-panel-related" role="tabpanel" aria-labelledby="aw-tab-related" data-aw-profile-panel="related" hidden>
			<?php if ( ! empty( $project['relatedProjects'] ) ) : ?>
				<section class="aw-section">
				<div class="aw-section-header">
					<h2>Related Projects</h2>
				</div>
				<div class="aw-related-grid">
					<?php foreach ( $project['relatedProjects'] as $related ) : ?>
						<a class="aw-panel-hover aw-card" href="<?php echo esc_url( get_permalink( $related['id'] ) ); ?>">
							<?php aw_projects_logo( array( 'ticker' => '', 'name' => $related['name'], 'logo' => get_the_post_thumbnail_url( $related['id'], 'thumbnail' ) ), 38 ); ?>
							<span class="aw-card-body">
								<span class="aw-name"><?php echo esc_html( $related['name'] ); ?></span>
							</span>
						</a>
					<?php endforeach; ?>
				</div>
				</section>
			<?php endif; ?>
			</div>
		</div>

	</div>

	<?php
endwhile;

get_footer();
