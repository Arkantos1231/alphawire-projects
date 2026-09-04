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
				<div class="aw-profile-save"><?php aw_projects_save_button( $project['id'] ); ?></div>
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
				<?php if ( ! empty( $project['timeline'] ) ) : ?>
					<section class="aw-section aw-panel">
				<div class="aw-section-header">
					<h2><?php echo esc_html( $project['name'] ); ?> Timeline</h2>
					<p class="aw-hint">Editorially maintained project milestones.</p>
				</div>
				<ol class="aw-timeline">
					<?php foreach ( array_reverse( $project['timeline'] ) as $event ) : ?>
						<li>
							<span class="aw-tl-date"><?php echo esc_html( $event['date'] ?? '' ); ?></span>
							<p class="aw-tl-title"><?php echo esc_html( $event['title'] ?? '' ); ?></p>
							<?php if ( ! empty( $event['description'] ) ) : ?>
								<p class="aw-tl-desc"><?php echo esc_html( $event['description'] ); ?></p>
							<?php endif; ?>
						</li>
					<?php endforeach; ?>
				</ol>
					</section>
				<?php endif; ?>

				<?php if ( $coverage_by_type ) : ?>
					<section class="aw-section">
				<div class="aw-section-header">
					<h2>Latest from AlphaWire</h2>
					<p class="aw-hint">Coverage tagged to <?php echo esc_html( $project['name'] ); ?> appears here automatically.</p>
				</div>
				<?php foreach ( $coverage_by_type as $type => $items ) : ?>
					<div class="aw-coverage-group aw-section" style="margin-top:16px;">
						<h3><?php echo esc_html( $type ); ?></h3>
						<div class="aw-grid">
							<?php foreach ( $items as $item ) : ?>
								<a class="aw-panel-hover aw-coverage-item" href="<?php echo esc_url( $item['url'] ); ?>">
									<span class="aw-cov-thumb" <?php echo $item['image'] ? 'style="background-image:url(' . esc_url( $item['image'] ) . ')"' : ''; ?>></span>
									<span>
										<span class="aw-cov-title"><?php echo esc_html( $item['title'] ); ?></span>
										<?php if ( ! empty( $item['excerpt'] ) ) : ?>
											<span class="aw-cov-excerpt"><?php echo esc_html( wp_strip_all_tags( $item['excerpt'] ) ); ?></span>
										<?php endif; ?>
										<span class="aw-cov-date"><?php echo esc_html( $item['date'] ?? '' ); ?></span>
									</span>
								</a>
							<?php endforeach; ?>
						</div>
					</div>
				<?php endforeach; ?>
					</section>
				<?php endif; ?>
			</div>

			<div class="aw-profile-tab-panel" id="aw-panel-timeline" role="tabpanel" aria-labelledby="aw-tab-timeline" data-aw-profile-panel="timeline" hidden></div>
			<div class="aw-profile-tab-panel" id="aw-panel-coverage" role="tabpanel" aria-labelledby="aw-tab-coverage" data-aw-profile-panel="coverage" hidden></div>
			<div class="aw-profile-tab-panel" id="aw-panel-research" role="tabpanel" aria-labelledby="aw-tab-research" data-aw-profile-panel="research" hidden></div>

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

if ( is_user_logged_in() ) {
	aw_projects_render_collection_modal();
}

get_footer();
