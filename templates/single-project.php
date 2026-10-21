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
			<a href="<?php echo esc_url( home_url( '/' ) ); ?>">Home</a>
			<span>/</span>
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
							<?php
							/**
							 * ClickUp 1amdve27n1c: replaces the plain "✓"
							 * character with Tabler's circle-check icon
							 * (https://tabler.io/icons?icon=circle-check),
							 * fetched from Tabler's own rendered markup so the
							 * path data is exact — outline style, 24x24 grid,
							 * 2px stroke, as the icon ships. The icon already
							 * draws its own ring, so the old wrapper
							 * border/border-radius that drew a second circle
							 * around the "✓" character is gone from
							 * .aw-verified in projects.css; only size/colour
							 * stay.
							 */
							?>
							<span class="aw-verified" title="Verified project">
								<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
									<path stroke="none" d="M0 0h24v24H0z" fill="none" />
									<path d="M3 12a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" />
									<path d="M9 12l2 2l4 -4" />
								</svg>
							</span>
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
					<?php
					/**
					 * ClickUp 1amdve27n8r: this narrative chip row used to sit
					 * here, directly under the description and above the
					 * external links — exactly the "redundant category tag"
					 * the ticket's screenshot circles. Removed from the
					 * header only; the same narratives still show on every
					 * Project page in the Overview tab's "Top Narratives"
					 * panel (see further down this file), and still drive
					 * Directory filtering — this was purely the duplicate,
					 * not the only place the data lives.
					 */
					?>
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
					<span class="aw-badge-text"><?php echo esc_html( ! empty( $market['stale'] ) ? 'Last known price' : 'Live market data' ); ?></span>
				</div>
				<dl>
					<?php if ( $project['ticker'] ) : ?>
						<div class="aw-stat-row">
							<dt>Token</dt>
							<dd><?php echo esc_html( $project['ticker'] ); ?></dd>
						</div>
					<?php endif; ?>
					<div class="aw-stat-row">
						<dt>Price</dt>
						<dd><?php echo esc_html( $market['price'] ?? '—' ); ?> <?php aw_projects_change( $market['change24h'] ?? null ); ?> <span class="aw-change-period">24h</span></dd>
					</div>
					<div class="aw-stat-row">
						<dt>Market Cap</dt>
						<dd><?php echo esc_html( null !== ( $market['marketCapRaw'] ?? null ) ? '$' . aw_projects_compact_number( $market['marketCapRaw'] ) : ( $market['marketCap'] ?? '—' ) ); ?></dd>
					</div>
					<div class="aw-stat-row">
						<dt>24h Volume</dt>
						<dd><?php echo esc_html( null !== ( $market['volume24hRaw'] ?? null ) ? '$' . aw_projects_compact_number( $market['volume24hRaw'] ) : ( $market['volume24h'] ?? '—' ) ); ?></dd>
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
							<dt>Launched <span class="aw-editorial-tag">Editorial</span></dt>
							<dd><?php echo esc_html( $project['launchDate'] ); ?></dd>
						</div>
					<?php endif; ?>
				</dl>
				<?php if ( ! empty( $market['stale'] ) ) : ?>
					<p class="aw-stale-note">Prices shown are the last known values and may be out of date.</p>
				<?php endif; ?>
				<?php if ( ! empty( $market['updatedAt'] ) ) : ?>
					<p class="aw-market-updated">Data as of <?php echo esc_html( wp_date( 'M j, Y g:ia', strtotime( $market['updatedAt'] ) ) ); ?></p>
				<?php endif; ?>
				<div class="aw-panel-divider"></div>
				<span class="aw-badge">Market data · external</span>
				<p class="aw-panel-footnote">Market data from CoinGecko — read only, never edited by AlphaWire editorial. Launch
					date is editorially maintained.</p>
			</div>

			<div class="aw-panel aw-ai-summary-panel" data-aw-report-slug="<?php echo esc_attr( $project['slug'] ); ?>">
				<div class="aw-panel-title-row">
					<h2>AI Project Summary</h2>
					<span class="aw-badge">Beta</span>
				</div>
				<?php if ( 'approved' === $project['aiSummary']['status'] && $project['aiSummary']['text'] ) : ?>
					<p class="aw-ai-summary-text"><?php echo esc_html( $project['aiSummary']['text'] ); ?></p>
					<?php if ( ! empty( $project['aiSummary']['updatedAt'] ) ) : ?>
						<p class="aw-ai-summary-updated">Last updated <?php echo esc_html( wp_date( 'M j, Y', strtotime( $project['aiSummary']['updatedAt'] ) ) ); ?></p>
					<?php endif; ?>
					<?php
					/*
					 * Darian & Andy §11 explicitly calls out that "the
					 * appropriate disclaimer and correction wording must be
					 * agreed before launch". Rather than a hard-coded string,
					 * this reads from Projects → Settings (class-settings.php
					 * OPTION_AI_DISCLAIMER) — the placeholder default there
					 * stands in until Product/Editorial sign off on real
					 * wording, and swapping it then needs no release.
					 */
					?>
					<p class="aw-ai-disclaimer">
						<?php echo esc_html( AlphaWire_Projects_Settings::get_ai_disclaimer() ); ?>
						<button type="button" class="aw-report-issue-trigger" data-aw-report-issue-trigger>Report an issue</button>
					</p>
					<form class="aw-report-form" data-aw-report-form hidden>
						<div class="aw-report-form-head">
							<label class="aw-report-label" for="aw-report-message-<?php echo esc_attr( $project['id'] ); ?>">What's wrong with this summary?</label>
							<button type="button" class="aw-report-close" data-aw-report-close aria-label="Close — don't submit a report">Close</button>
						</div>
						<textarea class="aw-report-textarea" id="aw-report-message-<?php echo esc_attr( $project['id'] ); ?>" data-aw-report-message rows="3" maxlength="1000" required></textarea>
						<input type="text" class="aw-report-hp" name="website" data-aw-report-hp tabindex="-1" autocomplete="off" aria-hidden="true" />
						<div class="aw-report-actions">
							<button type="submit" class="aw-report-submit" data-aw-report-submit>Submit report</button>
							<button type="button" class="aw-report-cancel" data-aw-report-cancel>Cancel</button>
						</div>
						<p class="aw-report-status" data-aw-report-status role="status" aria-live="polite"></p>
					</form>
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
						<?php else : ?>
							<div class="aw-panel aw-empty-panel">
								<h2>Timeline</h2>
								<p class="aw-empty">No milestones published yet.</p>
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
									<dd><?php echo esc_html( $market['price'] ?? '—' ); ?> <?php aw_projects_change( $market['change24h'] ?? null ); ?> <span class="aw-change-period">24h</span></dd>
								</div>
								<div class="aw-stat-row">
									<dt>Market Cap</dt>
									<dd><?php echo esc_html( null !== ( $market['marketCapRaw'] ?? null ) ? '$' . aw_projects_compact_number( $market['marketCapRaw'] ) : ( $market['marketCap'] ?? '—' ) ); ?></dd>
								</div>
								<div class="aw-stat-row">
									<dt>24h Volume</dt>
									<dd><?php echo esc_html( null !== ( $market['volume24hRaw'] ?? null ) ? '$' . aw_projects_compact_number( $market['volume24hRaw'] ) : ( $market['volume24h'] ?? '—' ) ); ?></dd>
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
							<?php if ( ! empty( $market['stale'] ) ) : ?>
								<p class="aw-stale-note">Prices shown are the last known values and may be out of date.</p>
							<?php endif; ?>
							<?php if ( ! empty( $market['updatedAt'] ) ) : ?>
								<p class="aw-market-updated">Data as of <?php echo esc_html( wp_date( 'M j, Y g:ia', strtotime( $market['updatedAt'] ) ) ); ?></p>
							<?php endif; ?>
							<p style="font-size:10px;color:var(--aw-muted);margin-top:10px;">Market data from CoinGecko — read only, never
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

						<?php
						/**
						 * Smart Money (Nansen) — ClickUp 1amdve27ccr "Build
						 * Nansen UI Blocks for AlphaWire Projects". null from
						 * build_payload() means "don't render this panel at
						 * all" (integration disabled, or this Project has no
						 * Nansen chain/token mapped) — same convention as
						 * every other optional panel on this page.
						 *
						 * Admin-only demo preview: appending ?aw_nansen_demo=1
						 * to a Project URL shows the panel with placeholder
						 * numbers even when nothing is really mapped yet, so
						 * stakeholders can review the design before real
						 * Nansen access/legal sign-off lands (explicitly
						 * allowed by the parent ticket: "Development can
						 * begin on the frontend UI using placeholder data").
						 * Gated on manage_options — never shown to a visitor,
						 * never reads real data, never calls Nansen.
						 */
						$smart_money    = $project['smartMoney'];
						$aw_sm_is_demo  = false;
						if ( null === $smart_money && current_user_can( 'manage_options' ) && isset( $_GET['aw_nansen_demo'] ) ) {
							$aw_sm_is_demo = true;
							$smart_money   = array(
								'netflowUsd24h' => 1250000,
								'holdersCount'  => 4821,
								'blurb'         => 'Smart Money wallets added a net $1.25M in exposure over the last 24 hours.',
								'updatedAt'     => current_time( 'mysql' ),
								'stale'         => false,
								'chain'         => 'ethereum',
							);
						}
						?>
						<?php if ( null !== $smart_money ) : ?>
							<div class="aw-panel aw-smart-money-panel<?php echo $aw_sm_is_demo ? ' aw-smart-money-demo' : ''; ?>">
								<div class="aw-panel-title-row">
									<h2>Smart Money</h2>
									<?php if ( $aw_sm_is_demo ) : ?>
										<span class="aw-badge aw-badge-demo">Demo preview — admin only</span>
									<?php elseif ( ! empty( $smart_money['stale'] ) ) : ?>
										<span class="aw-badge">last known data</span>
									<?php else : ?>
										<span class="aw-badge">Nansen · beta</span>
									<?php endif; ?>
								</div>

								<?php if ( null === $smart_money['netflowUsd24h'] && null === $smart_money['holdersCount'] ) : ?>
									<div class="aw-empty-panel">
										<p class="aw-empty">Smart Money data for this project hasn't synced yet — check back within the hour.</p>
									</div>
								<?php else : ?>
									<dl>
										<div class="aw-stat-row">
											<dt>24h Net Flow</dt>
											<dd>
												<?php if ( null !== $smart_money['netflowUsd24h'] ) : ?>
													<?php
													$aw_flow       = (float) $smart_money['netflowUsd24h'];
													$aw_flow_class = $aw_flow > 0 ? 'aw-flow-positive' : ( $aw_flow < 0 ? 'aw-flow-negative' : '' );
													?>
													<span class="<?php echo esc_attr( $aw_flow_class ); ?>"><?php echo esc_html( ( $aw_flow >= 0 ? '+' : '-' ) . '$' . aw_projects_compact_number( abs( $aw_flow ) ) ); ?></span>
												<?php else : ?>
													—
												<?php endif; ?>
											</dd>
										</div>
										<div class="aw-stat-row">
											<dt>Holders</dt>
											<dd><?php echo esc_html( null !== $smart_money['holdersCount'] ? number_format_i18n( $smart_money['holdersCount'] ) : '—' ); ?></dd>
										</div>
										<?php if ( ! empty( $smart_money['chain'] ) ) : ?>
											<div class="aw-stat-row">
												<dt>Chain</dt>
												<dd><?php echo esc_html( ucfirst( $smart_money['chain'] ) ); ?></dd>
											</div>
										<?php endif; ?>
									</dl>
									<?php if ( ! empty( $smart_money['blurb'] ) ) : ?>
										<p class="aw-smart-money-blurb"><?php echo esc_html( $smart_money['blurb'] ); ?></p>
									<?php endif; ?>
									<?php if ( ! empty( $smart_money['stale'] ) && ! $aw_sm_is_demo ) : ?>
										<p class="aw-stale-note">Smart Money data shown is the last known value and may be out of date.</p>
									<?php endif; ?>
									<?php if ( ! empty( $smart_money['updatedAt'] ) ) : ?>
										<p class="aw-market-updated">Data as of <?php echo esc_html( wp_date( 'M j, Y g:ia', strtotime( $smart_money['updatedAt'] ) ) ); ?></p>
									<?php endif; ?>
								<?php endif; ?>

								<p style="font-size:10px;color:var(--aw-muted);margin-top:10px;">
									Smart Money activity data from
									<a href="https://www.nansen.ai/" target="_blank" rel="noreferrer noopener">Nansen</a>
									— read only, never edited by AlphaWire editorial. Experimental feature.
									<?php if ( $aw_sm_is_demo ) : ?>
										Showing placeholder numbers for design review only — visible to admins via <code>?aw_nansen_demo=1</code>, never served to visitors.
									<?php endif; ?>
								</p>
							</div>
						<?php endif; ?>
					</div>

				</div>

				<?php if ( $coverage_by_type ) : ?>
					<section class="aw-section aw-panel aw-coverage-section" data-aw-coverage-slug="<?php echo esc_attr( $project['slug'] ); ?>" data-aw-coverage-scope="coverage">
				<div class="aw-section-header">
					<h2>AlphaWire Coverage</h2>
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
				<?php else : ?>
					<p class="aw-empty">No milestones published yet.</p>
				<?php endif; ?>
			</div>
			<div class="aw-profile-tab-panel" id="aw-panel-coverage" role="tabpanel" aria-labelledby="aw-tab-coverage" data-aw-profile-panel="coverage" hidden>
				<?php if ( $coverage_by_type ) : ?>
					<section class="aw-section aw-panel aw-coverage-section" data-aw-coverage-slug="<?php echo esc_attr( $project['slug'] ); ?>" data-aw-coverage-scope="coverage">
						<div class="aw-section-header">
							<h2>AlphaWire Coverage</h2>
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
						<div class="aw-grid">
							<?php foreach ( $project['relatedProjects'] as $card ) : ?>
								<?php aw_projects_render_card( $card ); ?>
							<?php endforeach; ?>
						</div>
					</section>
				<?php else : ?>
					<p class="aw-empty">No related Projects yet.</p>
				<?php endif; ?>
			</div>
		</div>

	</div>

	<?php
endwhile;

get_footer();
