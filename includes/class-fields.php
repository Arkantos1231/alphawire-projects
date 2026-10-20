<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Project fields, as ACF field groups — the site's own established pattern
 * for structured content (Podcast Fields, Testimonial Fields, User Fields
 * all work this way already, per the live site audit).
 *
 * Guarded: if ACF isn't active in an environment, registration is skipped
 * rather than fataling — the CPT still works, these fields just won't show
 * in wp-admin until ACF is on.
 */
class AlphaWire_Projects_Fields {

	public static function register() {
		if ( ! function_exists( 'acf_add_local_field_group' ) ) {
			return;
		}

		self::register_identity_group();
		self::register_ai_summary_group();
		self::register_timeline_group();
	}

	private static function register_identity_group() {
		acf_add_local_field_group(
			array(
				'key'      => 'group_alphawire_project_identity',
				'title'    => 'Project — Identity & Links',
				'fields'   => array(
					array(
						'key'   => 'field_aw_ticker',
						'label' => 'Ticker',
						'name'  => 'ticker',
						'type'  => 'text',
					),
					array(
						'key'   => 'field_aw_verified',
						'label' => 'Verified',
						'name'  => 'verified',
						'type'  => 'true_false',
						'ui'    => 1,
					),
					array(
						'key'          => 'field_aw_coingecko_id',
						'label'        => 'CoinGecko ID',
						'name'         => 'coingecko_id',
						'type'         => 'text',
						'instructions' => 'The slug CoinGecko uses for this asset (e.g. "hyperliquid"). Leave empty if not mapped yet — Key Stats will just show as unavailable rather than break.',
					),
					// Nansen — Smart Money Leaderboard (Intelligence proposal, Phase 1).
					// Same "graceful if unmapped" pattern as coingecko_id above: both of
					// these are optional, and class-smart-money-service.php just skips a
					// Project during its hourly sync when either is empty rather than
					// erroring. This select isn't the full ~37-chain list Nansen supports
					// (their docs don't publish one exhaustive list) — it covers the
					// chains AlphaWire actually lists Projects on today; add a chain here
					// the moment a Project needs one that's missing.
					array(
						'key'           => 'field_aw_nansen_chain',
						'label'         => 'Nansen chain',
						'name'          => 'nansen_chain',
						'type'          => 'select',
						'choices'       => array(
							''          => '— Not mapped —',
							'ethereum'  => 'Ethereum',
							'solana'    => 'Solana',
							'bnb'       => 'BNB Chain',
							'polygon'   => 'Polygon',
							'arbitrum'  => 'Arbitrum',
							'optimism'  => 'Optimism',
							'base'      => 'Base',
							'avalanche' => 'Avalanche',
							'tron'      => 'Tron',
						),
						'default_value' => '',
						'allow_null'    => 1,
						'instructions'  => 'The chain Nansen tracks this token\'s Smart Money activity on. Leave empty if not mapped yet — the Leaderboard just skips this project rather than break. Needs Token address (below) too.',
					),
					array(
						'key'          => 'field_aw_nansen_token_address',
						'label'        => 'Nansen token address',
						'name'         => 'nansen_token_address',
						'type'         => 'text',
						'instructions' => 'The token\'s contract address on the chain selected above (0x... for EVM chains, base58 for Solana). Both this and Nansen chain must be set for this Project to appear on the Smart Money Leaderboard.',
					),
					array(
						'key'        => 'field_aw_links',
						'label'      => 'External links',
						'name'       => 'links',
						'type'       => 'repeater',
						'layout'     => 'table',
						'sub_fields' => array(
							array(
								'key'     => 'field_aw_link_label',
								'label'   => 'Label',
								'name'    => 'label',
								'type'    => 'select',
								'choices' => array(
									'Website'        => 'Website',
									'X'              => 'X',
									'Discord'        => 'Discord',
									'Docs'           => 'Docs',
									'Explorer'       => 'Explorer',
									'Whitepaper'     => 'Whitepaper',
									'GitHub'         => 'GitHub',
									'Network Status' => 'Network Status',
								),
							),
							array(
								'key'   => 'field_aw_link_url',
								'label' => 'URL',
								'name'  => 'url',
								'type'  => 'url',
							),
						),
					),
					array(
						'key'   => 'field_aw_launch_date',
						'label' => 'Launch date',
						'name'  => 'launch_date',
						'type'  => 'date_picker',
					),
					array(
						'key'          => 'field_aw_trending_order',
						'label'        => 'Trending order',
						'name'         => 'trending_order',
						'type'         => 'number',
						'instructions' => 'Lower = higher in Trending. Leave empty to exclude. Editorial, not algorithmic — see build plan §8.',
					),
					array(
						'key'   => 'field_aw_editors_pick',
						'label' => "Editor's Pick",
						'name'  => 'editors_pick',
						'type'  => 'true_false',
						'ui'    => 1,
					),
					array(
						'key'          => 'field_aw_related_projects',
						'label'        => 'Related projects',
						'name'         => 'related_projects',
						'type'         => 'relationship',
						'post_type'    => array( AlphaWire_Projects_Post_Type::POST_TYPE ),
						'filters'      => array( 'search' ),
						'instructions' => "Shown on this Project's Related tab, and also listed as items on its Research tab.",
					),
					array(
						'key'          => 'field_aw_related_news',
						'label'        => 'Research — News',
						'name'         => 'related_news',
						'type'         => 'relationship',
						'post_type'    => array( 'news' ),
						'filters'       => array( 'search' ),
						'instructions' => 'Manually curated. Select News posts to feature on this Project\'s Research tab. This is separate from AlphaWire Coverage, which is automatic based on shared Pillar/Topic terms.',
					),
					array(
						'key'          => 'field_aw_related_podcasts',
						'label'        => 'Research — Podcasts',
						'name'         => 'related_podcasts',
						'type'         => 'relationship',
						'post_type'    => array( 'podcast' ),
						'filters'       => array( 'search' ),
						'instructions' => 'Manually curated. Select Podcast posts to feature on this Project\'s Research tab. This is separate from AlphaWire Coverage, which is automatic based on shared Pillar/Topic terms.',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => AlphaWire_Projects_Post_Type::POST_TYPE,
						),
					),
				),
			)
		);
	}

	private static function register_ai_summary_group() {
		acf_add_local_field_group(
			array(
				'key'      => 'group_alphawire_project_ai_summary',
				'title'    => 'Project — AI Summary',
				'fields'   => array(
					array(
						'key'           => 'field_aw_ai_status',
						'label'         => 'Status',
						'name'          => 'ai_summary_status',
						'type'          => 'select',
						'choices'       => array(
							'draft'    => 'Draft',
							'pending'  => 'Pending Review',
							'approved' => 'Approved / Published',
							'rejected' => 'Rejected',
						),
						'default_value' => 'draft',
						'instructions'  => 'Mirrors the Market Summaries AI workflow already in production: only "Approved" is ever shown on the public profile.',
					),
					array(
						'key'   => 'field_aw_ai_text',
						'label' => 'Summary text',
						'name'  => 'ai_summary_text',
						'type'  => 'textarea',
					),
					array(
						'key'   => 'field_aw_ai_updated',
						'label' => 'Last updated',
						'name'  => 'ai_summary_updated',
						'type'  => 'date_time_picker',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => AlphaWire_Projects_Post_Type::POST_TYPE,
						),
					),
				),
			)
		);
	}

	private static function register_timeline_group() {
		acf_add_local_field_group(
			array(
				'key'      => 'group_alphawire_project_timeline',
				'title'    => 'Project — Timeline',
				'fields'   => array(
					array(
						'key'        => 'field_aw_timeline',
						'label'      => 'Timeline',
						'name'       => 'timeline',
						'type'       => 'repeater',
						'layout'     => 'block',
						'button_label' => 'Add milestone',
						'sub_fields' => array(
							array(
								'key'            => 'field_aw_timeline_date',
								'label'          => 'Date',
								'name'           => 'date',
								'type'           => 'date_picker',
								'display_format' => 'd/m/Y',
								// ACF's default return_format for date_picker is also
								// 'd/m/Y' — a slash-separated string, which PHP's
								// strtotime() (used in templates/single-project.php to
								// render the Timeline) parses as US m/d/Y, not d/m/Y.
								// That silently shifted dates by up to several weeks
								// (10/09 read as Oct 9 instead of 10 Sept — the MVP
								// feedback item this closes). 'Y-m-d' is unambiguous
								// for strtotime() and doesn't require a data migration:
								// ACF always stores the value as Ymd regardless of this
								// setting, which only controls what get_field() returns.
								'return_format'  => 'Y-m-d',
							),
							array(
								'key'   => 'field_aw_timeline_title',
								'label' => 'Title',
								'name'  => 'title',
								'type'  => 'text',
							),
							array(
								'key'   => 'field_aw_timeline_description',
								'label' => 'Description',
								'name'  => 'description',
								'type'  => 'textarea',
								'rows'  => 2,
							),
						),
						'instructions' => 'AlphaWire-owned editorial data — ordering here controls the order shown on the Timeline tab.',
					),
				),
				'location' => array(
					array(
						array(
							'param'    => 'post_type',
							'operator' => '==',
							'value'    => AlphaWire_Projects_Post_Type::POST_TYPE,
						),
					),
				),
			)
		);
	}
}
