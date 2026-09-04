<?php
/**
 * "My Collections" (/projects/collections/) — not part of the original
 * build plan (Phase 3 explicitly excluded "Create Collection"); added
 * afterwards at product's request, on top of the site's existing reader
 * login (Thirdweb Auth SSO → a real WP user) rather than new auth. See
 * includes/class-collections.php for the storage/REST side.
 *
 * Routed via the aw_projects_view=collections query var registered in
 * class-post-type.php's top_rules(), served through template_include in
 * class-templates.php (this post type has no single/archive template
 * context to hang a filter off of here — it isn't a Project itself).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$archive_url = get_post_type_archive_link( AlphaWire_Projects_Post_Type::POST_TYPE );
?>

<div class="aw-projects aw-directory">
	<main class="aw-directory-main aw-collections-page">

		<div class="aw-directory-head">
			<div>
				<h1>My Collections</h1>
				<p>Projects you've saved, organized into your own lists.</p>
			</div>
		</div>

		<?php if ( ! is_user_logged_in() ) : ?>

			<section class="aw-section">
				<p class="aw-empty">
					<a href="<?php echo esc_url( wp_login_url( home_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/' ) ) ); ?>">Log in</a>
					to create and view your collections.
				</p>
			</section>

		<?php else : ?>

			<div class="aw-collections-toolbar">
				<button type="button" class="aw-btn" data-aw-new-collection>+ New collection</button>
			</div>

			<?php
			$collections = AlphaWire_Projects_Collections::get_all_public( get_current_user_id() );
			if ( ! $collections ) :
				?>
				<section class="aw-section">
					<p class="aw-empty">You haven't created a collection yet. Browse the
						<a href="<?php echo esc_url( $archive_url ); ?>">Directory</a> and tap the star on any Project
						to start one.</p>
				</section>
			<?php else : ?>
				<?php foreach ( $collections as $collection ) : ?>
					<section class="aw-section aw-collection-group" data-aw-collection-id="<?php echo esc_attr( $collection['id'] ); ?>">
						<div class="aw-section-header">
							<h2><?php echo esc_html( $collection['name'] ); ?>
								<span class="aw-muted">(<?php echo count( $collection['project_ids'] ?? array() ); ?>)</span>
							</h2>
							<div class="aw-collection-actions">
								<button type="button" class="aw-hint" data-aw-rename-collection data-id="<?php echo esc_attr( $collection['id'] ); ?>" data-name="<?php echo esc_attr( $collection['name'] ); ?>">Rename</button>
								<button type="button" class="aw-hint" data-aw-delete-collection data-id="<?php echo esc_attr( $collection['id'] ); ?>">Delete</button>
							</div>
						</div>
						<?php $cards = AlphaWire_Projects_Collections::get_cards( $collection['project_ids'] ?? array() ); ?>
						<?php if ( $cards ) : ?>
							<div class="aw-grid">
								<?php foreach ( $cards as $card ) : ?>
									<?php aw_projects_render_card( $card ); ?>
								<?php endforeach; ?>
							</div>
						<?php else : ?>
							<p class="aw-empty">Nothing saved here yet.</p>
						<?php endif; ?>
					</section>
				<?php endforeach; ?>
			<?php endif; ?>

			<?php aw_projects_render_collection_modal(); ?>

		<?php endif; ?>

	</main>
</div>

<?php get_footer();