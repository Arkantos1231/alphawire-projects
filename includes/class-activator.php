<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AlphaWire_Projects_Activator {

	public static function activate() {
		AlphaWire_Projects_Post_Type::register();
		AlphaWire_Projects_Taxonomies::register_for_project();
		AlphaWire_Projects_Taxonomies::seed_missing_terms();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( AlphaWire_Projects_Market_Data_Service::CRON_HOOK );
		flush_rewrite_rules();
	}
}
