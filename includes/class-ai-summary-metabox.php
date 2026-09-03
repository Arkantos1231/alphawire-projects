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
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="alphawire_projects_generate_summary" />
			<input type="hidden" name="project_id" value="<?php echo esc_attr( $post->ID ); ?>" />
			<?php wp_nonce_field( 'alphawire_generate_summary_' . $post->ID ); ?>
			<?php submit_button( __( 'Generate / refresh draft', 'alphawire-projects' ), 'secondary', 'submit', false ); ?>
		</form>
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
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Could not generate a draft — check Projects → Settings and the PHP error log.', 'alphawire-projects' ) . '</p></div>';
		}
	}
}
