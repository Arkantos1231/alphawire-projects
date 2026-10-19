<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The wp-admin control surface for AI Summary generation — a button on the
 * Project edit screen, not on the public site, per the "generation and
 * approval are CMS-only actions" rule (BE spec §28).
 */
class AlphaWire_Projects_AI_Summary_Metabox {

	public static function hooks() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	public static function add() {
		add_meta_box(
			'alphawire_ai_summary_actions',
			'AI Summary — Generate',
			array( __CLASS__, 'render' ),
			AlphaWire_Projects_Post_Type::POST_TYPE,
			'side',
			'default'
		);
	}

	public static function render( $post ) {
		if ( ! AlphaWire_Projects_Settings::get_api_key() ) {
			$settings_url = admin_url( 'edit.php?post_type=' . AlphaWire_Projects_Post_Type::POST_TYPE . '&page=alphawire-projects-settings' );
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'No Claude API key configured yet.', 'alphawire-projects' ),
				esc_url( $settings_url ),
				esc_html__( 'Add one in Settings.', 'alphawire-projects' )
			);
			return;
		}

		$status  = function_exists( 'get_field' ) ? get_field( 'ai_summary_status', $post->ID ) : get_post_meta( $post->ID, 'ai_summary_status', true );
		$updated = function_exists( 'get_field' ) ? get_field( 'ai_summary_updated', $post->ID ) : get_post_meta( $post->ID, 'ai_summary_updated', true );

		printf( '<p>%s <strong>%s</strong></p>', esc_html__( 'Status:', 'alphawire-projects' ), esc_html( $status ? $status : 'draft' ) );
		if ( $updated ) {
			printf( '<p>%s %s</p>', esc_html__( 'Last updated:', 'alphawire-projects' ), esc_html( $updated ) );
		}

		$last_error = get_option( 'aw_ai_summary_last_error_' . $post->ID );
		if ( ! empty( $last_error['message'] ) ) {
			printf(
				'<p class="description" style="color:#b32d2e;"><strong>%s</strong> %s%s</p>',
				esc_html__( 'Last error:', 'alphawire-projects' ),
				esc_html( $last_error['message'] ),
				! empty( $last_error['when'] ) ? ' (' . esc_html( $last_error['when'] ) . ')' : ''
			);
		}

		// A meta box renders INSIDE WordPress's own #post edit form — a
		// second, nested <form> here is invalid HTML, and browsers respond
		// to invalid nesting by folding this form's fields into the outer
		// one instead of keeping them separate. In practice that meant
		// clicking this button just re-submitted the whole Update Post
		// form to post.php (silently saving the post) and never reached
		// admin-post.php or generate_draft() at all — no Claude call ever
		// happened, no error, nothing. Fixed the same way the Updater's
		// "Check for updates" link avoids this: a plain nonce'd GET link
		// to admin-post.php, no <form> involved. handle_manual_trigger()
		// reads project_id from $_GET now to match.
		$generate_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'alphawire_projects_generate_summary',
					'project_id' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'alphawire_generate_summary_' . $post->ID
		);

		// "Reject" — sends an existing draft/approved summary back to
		// Draft without regenerating it, so rejecting doesn't require
		// knowing to flip the Status field by hand (the MVP feedback item
		// this closes). Only shown when there's actually something to
		// reject.
		$reject_url = wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'alphawire_projects_reject_summary',
					'project_id' => $post->ID,
				),
				admin_url( 'admin-post.php' )
			),
			'alphawire_reject_summary_' . $post->ID
		);
		?>
		<p>
			<a href="<?php echo esc_url( $generate_url ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Generate / refresh draft', 'alphawire-projects' ); ?>
			</a>
			<?php if ( in_array( $status, array( 'pending', 'approved' ), true ) ) : ?>
				<a href="<?php echo esc_url( $reject_url ); ?>" class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Send this summary back to Draft? The text stays, but it will stop showing as Pending/Approved until it\'s regenerated or re-approved.', 'alphawire-projects' ) ); ?>');">
					<?php esc_html_e( 'Reject (send back to Draft)', 'alphawire-projects' ); ?>
				</a>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php esc_html_e( 'Writes from this Project\'s description, timeline and published AlphaWire coverage only. Always lands as "Pending Review" — never publishes on its own.', 'alphawire-projects' ); ?>
		</p>
		<?php
		self::render_reports( $post );
		self::render_history( $post );
	}

	/**
	 * The "Editorial review" step of the correction workflow (Darian & Andy
	 * §11 / BE ticket §29 / FE ticket §17 "Report Issue interaction") — any
	 * issue a visitor reported against this Project's approved AI Summary
	 * (class-rest-api.php::report_issue()) shows up here for an editor to
	 * act on. This list only tracks review status; the actual fix still
	 * goes through Generate/Reject/Approve above.
	 */
	private static function render_reports( $post ) {
		$reports = get_post_meta( $post->ID, 'ai_summary_reports', true );
		$reports = is_array( $reports ) ? $reports : array();

		$open = array_filter(
			$reports,
			function ( $report ) {
				return empty( $report['status'] ) || 'new' === $report['status'];
			}
		);

		if ( ! $open ) {
			return;
		}

		echo '<hr style="margin:12px 0;" />';
		printf( '<p><strong>%s</strong></p>', esc_html__( 'Reported issues', 'alphawire-projects' ) );

		foreach ( $open as $report ) {
			if ( empty( $report['id'] ) ) {
				continue;
			}

			$mark_url = wp_nonce_url(
				add_query_arg(
					array(
						'action'     => 'alphawire_projects_mark_report_reviewed',
						'project_id' => $post->ID,
						'report_id'  => $report['id'],
					),
					admin_url( 'admin-post.php' )
				),
				'alphawire_mark_report_reviewed_' . $post->ID . '_' . $report['id']
			);

			printf(
				'<div style="margin-bottom:8px;padding:8px;background:#f6f7f7;border-radius:3px;">
					<p style="margin:0 0 4px;">%s</p>
					<p class="description" style="margin:0 0 6px;">%s</p>
					<a href="%s" class="button button-small">%s</a>
				</div>',
				esc_html( $report['message'] ?? '' ),
				esc_html( $report['created'] ?? '' ),
				esc_url( $mark_url ),
				esc_html__( 'Mark reviewed', 'alphawire-projects' )
			);
		}
	}

	/**
	 * Read-only — "where practical, previous approved versions should be
	 * retained" (Darian & Andy §11). Snapshotted automatically whenever a
	 * new draft overwrites a currently-approved summary (see
	 * class-ai-summary-service.php::generate_draft()).
	 */
	private static function render_history( $post ) {
		$history = get_post_meta( $post->ID, 'ai_summary_history', true );
		$history = is_array( $history ) ? $history : array();

		if ( ! $history ) {
			return;
		}

		echo '<details style="margin-top:12px;">';
		printf( '<summary>%s</summary>', esc_html__( 'Previous approved versions', 'alphawire-projects' ) );
		foreach ( array_reverse( $history ) as $entry ) {
			printf(
				'<p class="description" style="margin:6px 0;">%s — <em>%s</em></p>',
				esc_html( $entry['text'] ?? '' ),
				esc_html( $entry['at'] ?? '' )
			);
		}
		echo '</details>';
	}

	public static function notice() {
		if ( ! isset( $_GET['aw_summary'] ) ) {
			return;
		}
		if ( 'success' === $_GET['aw_summary'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'AI summary draft generated — review it below before approving.', 'alphawire-projects' ) . '</p></div>';
		} elseif ( 'rejected' === $_GET['aw_summary'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Summary sent back to Draft.', 'alphawire-projects' ) . '</p></div>';
		} elseif ( 'report_reviewed' === $_GET['aw_summary'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Report marked as reviewed.', 'alphawire-projects' ) . '</p></div>';
		} else {
			$project_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
			$last_error = $project_id ? get_option( 'aw_ai_summary_last_error_' . $project_id ) : null;
			$detail     = ! empty( $last_error['message'] ) ? ' ' . esc_html( $last_error['message'] ) : '';
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not generate a draft.', 'alphawire-projects' ) . $detail . '</p></div>';
		}
	}
}
