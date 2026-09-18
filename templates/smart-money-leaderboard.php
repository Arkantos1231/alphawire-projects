<?php
/**
 * Smart Money Leaderboard (/smart-money/) — AlphaWire × Nansen Integration
 * Proposal, Phase 1. Ranks Projects by Smart Money accumulation (Nansen's
 * 24h netflow), not price — the whole point of this page per the proposal:
 * AlphaWire surfaces an insight, it doesn't replicate Nansen's own
 * dashboards.
 *
 * Server-rendered, no client-side API loop — same rule as
 * archive-project.php. Filtering reads plain GET params and runs through
 * AlphaWire_Projects_Smart_Money_REST::query_leaderboard(), which is
 * CACHE-ONLY: nothing on this page ever triggers a live Nansen call.
 * get_header()/get_footer() pull in the theme's real nav, ticker bar and
 * footer, same as every other AlphaWire Projects page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$chain      = isset( $_GET['chain'] ) ? sanitize_title( wp_unslash( $_GET['chain'] ) ) : '';
$category   = isset( $_GET['category'] ) ? sanitize_title( wp_unslash( $_GET['category'] ) ) : '';
$narrative  = isset( $_GET['narrative'] ) ? sanitize_title( wp_unslash( $_GET['narrative'] ) ) : '';
$cap_min    = isset( $_GET['market_cap_min'] ) && '' !== $_GET['market_cap_min'] ? sanitize_text_field( wp_unslash( $_GET['market_cap_min'] ) ) : '';
$cap_max    = isset( $_GET['market_cap_max'] ) && '' !== $_GET['market_cap_max'] ? sanitize_text_field( wp_unslash( $_GET['market_cap_max'] ) ) : '';

$page_url = home_url( '/smart-money/' );

$result = AlphaWire_Projects_Smart_Money_REST::query_leaderboard(
	array(
		'chain'          => $chain,
		'category'       => $category,
		'narrative'      => $narrative,
		'market_cap_min' => $cap_min,
		'market_cap_max' => $cap_max,
	)
);
$rows = $result['rows'];

$has_key = (bool) AlphaWire_Projects_Settings::get_nansen_api_key();

// Mirrors the chain choices in class-fields.php's nansen_chain ACF field —
// kept as a plain list here rather than reading ACF's field config at
// render time, so this page has no ACF dependency to render its filter UI.
// Extend both lists together if a new chain is added.
$chain_choices = array(
	'ethereum'  => 'Ethereum',
	'solana'    => 'Solana',
	'bnb'       => 'BNB Chain',
	'polygon'   => 'Polygon',
	'arbitrum'  => 'Arbitrum',
	'optimism'  => 'Optimism',
	'base'      => 'Base',
	'avalanche' => 'Avalanche',
	'tron'      => 'Tron',
);

$categories = AlphaWire_Projects_Directory_REST::categories( null );
$narratives = AlphaWire_Projects_Directory_REST::narratives( null );
?>

<div class="aw-projects aw-smart-money">

	<main class="aw-directory-main aw-full-width">

		<div class="aw-directory-head">
			<div>
				<h1>Smart Money Leaderboard</h1>
				<p>Which projects Smart Money wallets are accumulating right now, powered by Nansen — ranked by 24h net inflow, not price.</p>
			</div>
		</div>

		<?php if ( ! $has_key ) : ?>

			<section class="aw-section">
				<p class="aw-empty">The Smart Money Leaderboard isn't live yet — a Nansen API key hasn't been added (Projects → Settings → Nansen). The page and its data pipeline are ready; it'll start filling in as soon as a key is added and the next hourly sync runs.</p>
			</section>

		<?php else : ?>

			<form class="aw-filters aw-smart-money-filters" method="get" action="<?php echo esc_url( $page_url ); ?>">
				<label>
					Chain
					<select name="chain">
						<option value="">All chains</option>
						<?php foreach ( $chain_choices as $slug => $label ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $chain, $slug ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<?php if ( $categories ) : ?>
					<label>
						Category
						<select name="category">
							<option value="">All categories</option>
							<?php foreach ( $categories as $c ) : ?>
								<option value="<?php echo esc_attr( $c['slug'] ); ?>" <?php selected( $category, $c['slug'] ); ?>><?php echo esc_html( $c['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>

				<?php if ( $narratives ) : ?>
					<label>
						Narrative
						<select name="narrative">
							<option value="">All narratives</option>
							<?php foreach ( $narratives as $n ) : ?>
								<option value="<?php echo esc_attr( $n['slug'] ); ?>" <?php selected( $narrative, $n['slug'] ); ?>><?php echo esc_html( $n['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				<?php endif; ?>

				<label>
					Min market cap ($)
					<input type="number" name="market_cap_min" value="<?php echo esc_attr( $cap_min ); ?>" placeholder="e.g. 1000000" />
				</label>

				<label>
					Max market cap ($)
					<input type="number" name="market_cap_max" value="<?php echo esc_attr( $cap_max ); ?>" placeholder="e.g. 500000000" />
				</label>

				<button type="submit">Filter</button>
				<a class="aw-hint" href="<?php echo esc_url( $page_url ); ?>">Clear filters</a>
			</form>

			<section class="aw-section">

				<?php if ( ! $rows ) : ?>

					<p class="aw-empty">No projects match yet. Projects need a Nansen chain + token address set (Project edit screen) and at least one completed hourly sync before they show up here.</p>

				<?php else : ?>

					<div class="aw-smart-money-table">
						<div class="aw-smart-money-row aw-smart-money-head">
							<span class="aw-sm-rank">#</span>
							<span class="aw-sm-project">Project</span>
							<span class="aw-sm-chain">Chain</span>
							<span class="aw-sm-price">Price</span>
							<span class="aw-sm-change">24h</span>
							<span class="aw-sm-cap">Market cap</span>
							<span class="aw-sm-flow">Smart Money 24h</span>
							<span class="aw-sm-holders">Holders</span>
						</div>

						<?php foreach ( $rows as $i => $row ) : ?>
							<a class="aw-smart-money-row aw-panel-hover" href="<?php echo esc_url( get_permalink( $row['id'] ) ); ?>">
								<span class="aw-sm-rank"><?php echo (int) ( $i + 1 ); ?></span>
								<span class="aw-sm-project">
									<?php aw_projects_logo( $row, 32 ); ?>
									<span class="aw-sm-project-name">
										<span class="aw-name"><?php echo esc_html( $row['name'] ); ?></span>
										<span class="aw-ticker"><?php echo esc_html( $row['ticker'] ); ?></span>
									</span>
								</span>
								<span class="aw-sm-chain"><?php echo esc_html( ucfirst( $row['chain'] ) ); ?></span>
								<span class="aw-sm-price"><?php echo esc_html( $row['price'] ?? '—' ); ?></span>
								<span class="aw-sm-change"><?php aw_projects_change( $row['change24h'] ); ?></span>
								<span class="aw-sm-cap"><?php echo esc_html( null !== $row['marketCapRaw'] ? '$' . aw_projects_compact_number( $row['marketCapRaw'] ) : '—' ); ?></span>
								<span class="aw-sm-flow">
									<?php
									$flow      = (float) $row['netflowUsd24h'];
									$flow_class = $flow > 0 ? 'up' : ( $flow < 0 ? 'down' : 'flat' );
									$flow_sign  = $flow > 0 ? '+' : '';
									printf(
										'<span class="aw-change %s">%s$%s</span>',
										esc_attr( $flow_class ),
										esc_html( $flow_sign ),
										esc_html( aw_projects_compact_number( abs( $flow ) ) )
									);
									?>
									<?php if ( ! empty( $row['stale'] ) ) : ?>
										<span class="aw-muted aw-sm-stale" title="Last successful sync — the hourly refresh hasn't completed since">stale</span>
									<?php endif; ?>
								</span>
								<span class="aw-sm-holders"><?php echo esc_html( null !== $row['holdersCount'] ? number_format( $row['holdersCount'] ) : '—' ); ?></span>
							</a>
							<?php if ( ! empty( $row['blurb'] ) ) : ?>
								<p class="aw-sm-blurb"><?php echo esc_html( $row['blurb'] ); ?></p>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>

				<?php endif; ?>

			</section>

		<?php endif; ?>

	</main>

</div>

<?php
get_footer();
