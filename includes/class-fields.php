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
						'key'       => 'field_aw_related_projects',
						'label'     => 'Related projects',
						'name'      => 'related_projects',
						'type'      => 'relationship',
						'post_type' => array( AlphaWire_Projects_Post_Type::POST_TYPE ),
						'filters'   => array( 'search' ),
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
								'key'   => 'field_aw_timeline_date',
								'label' => 'Date',
								'name'  => 'date',
								'type'  => 'date_picker',
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
