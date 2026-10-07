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
		add_action( 'admin_post_alphawire_projects_reject_summary', array( __CLASS__, 'handle_reject' ) );
		add_action( 'admin_post_alphawire_projects_clear_reports', array( __CLASS__, 'handle_clear_reports' ) );
	}

	/**
	 * Engineering review §9 editor actions: Review, Edit, Reject,
	 * Regenerate, Approve. Reject was missing (staging feedback #15). It
	 * keeps the text for reference but takes it off the public page —
	 * only "approved" is ever rendered — until it's regenerated or fixed.
	 */
	public static function handle_reject() {
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
		if ( ! $project_id
			|| ! current_user_can( 'edit_post', $project_id )
			|| ! check_admin_referer( 'alphawire_reject_summary_' . $project_id )
		) {
			wp_die( esc_html__( 'You are not authorised to do this.', 'alphawire-projects' ), 403 );
		}

		if ( function_exists( 'update_field' ) ) {
			update_field( 'ai_summary_status', 'rejected', $project_id );
		} else {
			update_post_meta( $project_id, 'ai_summary_status', 'rejected' );
		}

		wp_safe_redirect( add_query_arg( 'aw_summary', 'rejected', get_edit_post_link( $project_id, 'raw' ) ) );
		exit;
	}

	public static function handle_clear_reports() {
		$project_id = isset( $_GET['project_id'] ) ? (int) $_GET['project_id'] : 0;
		if ( ! $project_id
			|| ! current_user_can( 'edit_post', $project_id )
			|| ! check_admin_referer( 'alphawire_clear_reports_' . $project_id )
		) {
			wp_die( esc_html__( 'You are not authorised to do this.', 'alphawire-projects' ), 403 );
		}

		delete_post_meta( $project_id, AlphaWire_Projects_REST::REPORTS_META_KEY );

		wp_safe_redirect( add_query_arg( 'aw_summary', 'reports_cleared', get_edit_post_link( $project_id, 'raw' ) ) );
		exit;
	}

	private static function action_url( $action, $nonce_action, $project_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => $action,
					'project_id' => $project_id,
				),
				admin_url( 'admin-post.php' )
			),
			$nonce_action . $project_id
		);
	}

	public static function add() {
		add_meta_box(
			'alphawire_ai_summary_actions',
			'AI Summary — Review',
			array( __CLASS__, 'render' ),
			AlphaWire_Projects_Post_Type::POST_TYPE,
			'side',
			'default'
		);
	}

	public static function render( $post ) {
		$status  = function_exists( 'get_field' ) ? get_field( 'ai_summary_status', $post->ID ) : get_post_meta( $post->ID, 'ai_summary_status', true );
		$updated = function_exists( 'get_field' ) ? get_field( 'ai_summary_updated', $post->ID ) : get_post_meta( $post->ID, 'ai_summary_updated', true );
		$text    = function_exists( 'get_field' ) ? get_field( 'ai_summary_text', $post->ID ) : get_post_meta( $post->ID, 'ai_summary_text', true );

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
		// nested <form> here is invalid HTML and the browser folds it into
		// the outer one, so every action is a plain nonce'd GET link to
		// admin-post.php (same reason as the Updater's "Check for updates").
		echo '<p>';
		if ( AlphaWire_Projects_Settings::get_api_key() ) {
			printf(
				'<a href="%s" class="button button-secondary">%s</a> ',
				esc_url( self::action_url( 'alphawire_projects_generate_summary', 'alphawire_generate_summary_', $post->ID ) ),
				$text ? esc_html__( 'Regenerate draft', 'alphawire-projects' ) : esc_html__( 'Generate draft', 'alphawire-projects' )
			);
		}
		if ( $text && 'rejected' !== $status ) {
			printf(
				'<a href="%s" class="button button-link-delete" onclick="return confirm(%s);">%s</a>',
				esc_url( self::action_url( 'alphawire_projects_reject_summary', 'alphawire_reject_summary_', $post->ID ) ),
				esc_attr( wp_json_encode( __( 'Reject this summary? It will be hidden from the Project page until it is regenerated or approved again.', 'alphawire-projects' ) ) ),
				esc_html__( 'Reject', 'alphawire-projects' )
			);
		}
		echo '</p>';

		if ( ! AlphaWire_Projects_Settings::get_api_key() ) {
			$settings_url = admin_url( 'edit.php?post_type=' . AlphaWire_Projects_Post_Type::POST_TYPE . '&page=alphawire-projects-settings' );
			printf(
				'<p class="description">%s <a href="%s">%s</a></p>',
				esc_html__( 'Generate / Regenerate needs a Claude API key.', 'alphawire-projects' ),
				esc_url( $settings_url ),
				esc_html__( 'Add one in Settings.', 'alphawire-projects' )
			);
		}
		?>
		<p class="description">
			<?php esc_html_e( 'Edit the text and set Status to "Approved / Published" in the AI Project Summary fields to publish it. A new draft always lands as "Pending Review" and is written from this Project\'s description, timeline and linked AlphaWire coverage only.', 'alphawire-projects' ); ?>
		</p>
		<?php
		$reports = get_post_meta( $post->ID, AlphaWire_Projects_REST::REPORTS_META_KEY, true );
		if ( is_array( $reports ) && $reports ) {
			printf( '<hr /><p><strong>%s</strong></p><ul>', esc_html( sprintf( _n( '%d reader report', '%d reader reports', count( $reports ), 'alphawire-projects' ), count( $reports ) ) ) );
			foreach ( array_slice( $reports, 0, 5 ) as $report ) {
				printf(
					'<li style="margin-bottom:8px;"><em>%s</em><br />%s</li>',
					esc_html( $report['when'] ?? '' ),
					esc_html( $report['message'] ?? '' )
				);
			}
			echo '</ul>';
			printf(
				'<p><a href="%s">%s</a></p>',
				esc_url( self::action_url( 'alphawire_projects_clear_reports', 'alphawire_clear_reports_', $post->ID ) ),
				esc_html__( 'Mark all as handled', 'alphawire-projects' )
			);
		}
	}

	public static function notice() {
		if ( ! isset( $_GET['aw_summary'] ) ) {
			return;
		}
		if ( 'rejected' === $_GET['aw_summary'] ) {
			echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'AI summary rejected — it is no longer shown on the Project page.', 'alphawire-projects' ) . '</p></div>';
			return;
		}
		if ( 'reports_cleared' === $_GET['aw_summary'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Reader reports marked as handled.', 'alphawire-projects' ) . '</p></div>';
			return;
		}
		if ( 'success' === $_GET['aw_summary'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'AI summary draft generated — review it below before approving.', 'alphawire-projects' ) . '</p></div>';
		} else {
			$project_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
			$last_error = $project_id ? get_option( 'aw_ai_summary_last_error_' . $project_id ) : null;
			$detail     = ! empty( $last_error['message'] ) ? ' ' . esc_html( $last_error['message'] ) : '';
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not generate a draft.', 'alphawire-projects' ) . $detail . '</p></div>';
		}
	}
}
