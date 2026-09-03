<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bulk-creates/updates Projects from a CSV — the "add coins the site
 * already covers" on-ramp, so the catalogue doesn't have to be built one
 * Project at a time by hand in wp-admin. Only ever touches this plugin's
 * own fields on this plugin's own post type; never invents a pillar/topic
 * term that doesn't already exist on the site (see match_term()) — a typo
 * in a CSV cell should skip that one column, not silently create taxonomy
 * sprawl.
 */
class AlphaWire_Projects_CSV_Importer {

	const EXPECTED_HEADER = array( 'name', 'ticker', 'coingecko_id', 'launch_date', 'verified', 'category', 'narrative', 'description', 'status' );

	public static function hooks() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_alphawire_projects_import_csv', array( __CLASS__, 'handle_import' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'edit.php?post_type=' . AlphaWire_Projects_Post_Type::POST_TYPE,
			'Import Projects',
			'Import',
			'edit_posts',
			'alphawire-projects-import',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1>Import Projects</h1>

			<?php if ( isset( $_GET['aw_import'] ) ) : ?>
				<?php if ( 'success' === $_GET['aw_import'] ) : ?>
					<div class="notice notice-success">
						<p>
							Created <?php echo (int) ( $_GET['created'] ?? 0 ); ?>,
							updated <?php echo (int) ( $_GET['updated'] ?? 0 ); ?>,
							skipped <?php echo (int) ( $_GET['skipped'] ?? 0 ); ?> row(s).
							<?php if ( (int) ( $_GET['skipped'] ?? 0 ) > 0 ) : ?>
								Check the PHP error log for which rows were skipped and why.
							<?php endif; ?>
						</p>
					</div>
				<?php else : ?>
					<div class="notice notice-error">
						<p><?php echo esc_html( wp_unslash( $_GET['message'] ?? 'Import failed.' ) ); ?></p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<p>Upload a CSV to bulk-create or update Projects. Matching an existing Project is by
				<strong>ticker</strong> first, then by exact name — re-running the same file is safe, it
				updates rather than duplicates.</p>

			<p>Columns, in this order (a header row is required, extra/missing trailing columns are fine):</p>
			<table class="widefat striped" style="max-width:900px;">
				<thead>
					<tr>
						<?php foreach ( self::EXPECTED_HEADER as $col ) : ?>
							<th><code><?php echo esc_html( $col ); ?></code></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<tr>
						<td>Bitcoin</td>
						<td>BTC</td>
						<td>bitcoin</td>
						<td>2009-01-03</td>
						<td>yes</td>
						<td><em>(pillar term, optional)</em></td>
						<td><em>(topic term, optional)</em></td>
						<td>Short editorial description…</td>
						<td>draft</td>
					</tr>
				</tbody>
			</table>
			<p class="description">
				<code>coingecko_id</code> is what makes Key Stats / market data work — it's the slug CoinGecko
				uses for that asset (e.g. <code>bitcoin</code>, <code>ethereum</code>, <code>solana</code>).
				<code>category</code>/<code>narrative</code> only apply if a Pillar/Narrative term with that
				exact name already exists on the site — this importer never creates new taxonomy terms.
				<code>status</code> is <code>publish</code> or <code>draft</code> (default <code>draft</code> if
				left blank) and only applies when a Project is newly created — updating an existing Project
				never changes its current status.
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
				<input type="hidden" name="action" value="alphawire_projects_import_csv" />
				<?php wp_nonce_field( 'alphawire_projects_import_csv' ); ?>
				<input type="file" name="aw_csv_file" accept=".csv,text/csv" required />
				<?php submit_button( 'Import CSV' ); ?>
			</form>
		</div>
		<?php
	}

	public static function handle_import() {
		if ( ! current_user_can( 'edit_posts' ) || ! check_admin_referer( 'alphawire_projects_import_csv' ) ) {
			wp_die( esc_html__( 'You are not authorised to do this.', 'alphawire-projects' ), 403 );
		}

		if ( empty( $_FILES['aw_csv_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['aw_csv_file']['tmp_name'] ) ) {
			self::redirect( 'error', array( 'message' => rawurlencode( 'No file received.' ) ) );
		}

		$handle = fopen( $_FILES['aw_csv_file']['tmp_name'], 'r' );
		if ( ! $handle ) {
			self::redirect( 'error', array( 'message' => rawurlencode( 'Could not read the uploaded file.' ) ) );
		}

		$header = fgetcsv( $handle );
		if ( ! $header ) {
			fclose( $handle );
			self::redirect( 'error', array( 'message' => rawurlencode( 'The file looks empty.' ) ) );
		}
		$header = array_map( function ( $h ) {
			return strtolower( trim( $h ) );
		}, $header );

		$created = 0;
		$updated = 0;
		$skipped = 0;

		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( count( array_filter( $row, function ( $v ) { return '' !== trim( (string) $v ); } ) ) === 0 ) {
				continue; // blank line
			}
			$data = array();
			foreach ( $header as $i => $key ) {
				$data[ $key ] = isset( $row[ $i ] ) ? trim( $row[ $i ] ) : '';
			}

			$result = self::import_row( $data );
			if ( 'created' === $result ) {
				++$created;
			} elseif ( 'updated' === $result ) {
				++$updated;
			} else {
				++$skipped;
				error_log( '[AlphaWire Projects] CSV import skipped row: ' . wp_json_encode( $data ) . ' — ' . $result );
			}
		}

		fclose( $handle );

		self::redirect(
			'success',
			array(
				'created' => $created,
				'updated' => $updated,
				'skipped' => $skipped,
			)
		);
	}

	/**
	 * @return string 'created' | 'updated' | a skip reason string
	 */
	private static function import_row( array $data ) {
		$name = $data['name'] ?? '';
		if ( '' === $name ) {
			return 'missing name';
		}

		$ticker = $data['ticker'] ?? '';
		$post_id = 0;

		if ( '' !== $ticker ) {
			$existing = get_posts(
				array(
					'post_type'      => AlphaWire_Projects_Post_Type::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'     => 'ticker',
							'value'   => $ticker,
							'compare' => '=',
						),
					),
				)
			);
			if ( $existing ) {
				$post_id = $existing[0];
			}
		}

		if ( ! $post_id ) {
			$existing = get_page_by_title( $name, OBJECT, AlphaWire_Projects_Post_Type::POST_TYPE );
			if ( $existing ) {
				$post_id = $existing->ID;
			}
		}

		$is_new = ! $post_id;

		if ( $is_new ) {
			$status = strtolower( $data['status'] ?? '' );
			$status = in_array( $status, array( 'publish', 'draft' ), true ) ? $status : 'draft';

			$post_id = wp_insert_post(
				array(
					'post_type'    => AlphaWire_Projects_Post_Type::POST_TYPE,
					'post_title'   => $name,
					'post_excerpt' => $data['description'] ?? '',
					'post_status'  => $status,
				),
				true
			);
			if ( is_wp_error( $post_id ) ) {
				return $post_id->get_error_message();
			}
		} elseif ( ! empty( $data['description'] ) ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => $data['description'],
				)
			);
		}

		self::set_field( 'ticker', $ticker, $post_id );
		self::set_field( 'coingecko_id', $data['coingecko_id'] ?? '', $post_id );

		if ( '' !== ( $data['launch_date'] ?? '' ) ) {
			$ymd = str_replace( '-', '', $data['launch_date'] );
			if ( preg_match( '/^\d{8}$/', $ymd ) ) {
				self::set_field( 'launch_date', $ymd, $post_id );
			}
		}

		if ( '' !== ( $data['verified'] ?? '' ) ) {
			$truthy = in_array( strtolower( $data['verified'] ), array( '1', 'yes', 'true', 'y' ), true );
			self::set_field( 'verified', $truthy ? 1 : 0, $post_id );
		}

		if ( ! empty( $data['category'] ) ) {
			self::assign_term_if_exists( $post_id, 'pillar', $data['category'] );
		}
		if ( ! empty( $data['narrative'] ) ) {
			self::assign_term_if_exists( $post_id, 'topic', $data['narrative'] );
		}

		return $is_new ? 'created' : 'updated';
	}

	private static function set_field( $name, $value, $post_id ) {
		if ( '' === $value ) {
			return;
		}
		if ( function_exists( 'update_field' ) ) {
			update_field( $name, $value, $post_id );
		} else {
			update_post_meta( $post_id, $name, $value );
		}
	}

	/**
	 * Only attaches a term that already exists on the site — this importer
	 * is not the place to invent new Pillar/Narrative taxonomy values from
	 * whatever a CSV cell happens to say.
	 */
	private static function assign_term_if_exists( $post_id, $taxonomy, $term_name ) {
		$term = get_term_by( 'name', $term_name, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
			wp_set_object_terms( $post_id, array( (int) $term->term_id ), $taxonomy, false );
		}
	}

	private static function redirect( $status, array $extra = array() ) {
		$url = add_query_arg(
			array_merge( array( 'aw_import' => $status ), $extra ),
			admin_url( 'edit.php?post_type=' . AlphaWire_Projects_Post_Type::POST_TYPE . '&page=alphawire-projects-import' )
		);
		wp_safe_redirect( $url );
		exit;
	}
}
