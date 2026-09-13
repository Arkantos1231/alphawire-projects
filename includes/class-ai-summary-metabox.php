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
				esc_html__( 'No OpenAI API key configured yet.', 'alphawire-projects' ),
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
		// admin-post.php or generate_draft() at all — no OpenAI call ever
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
		?>
		<p>
			<a href="<?php echo esc_url( $generate_url ); ?>" class="button button-secondary">
				<?php esc_html_e( 'Generate / refresh draft', 'alphawire-projects' ); ?>
			</a>
		</p>
		<p class="description">
			<?php esc_html_e( 'Writes from this Project\'s description, timeline and published AlphaWire coverage only. Always lands as "Pending Review" — never publishes on its own.', 'alphawire-projects' ); ?>
		</p>
		<?php
	}

	public static function notice() {
		if ( ! isset( $_GET['aw_summary'] ) ) {
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
