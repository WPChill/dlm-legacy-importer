<?php
/*
Plugin Name: Download Monitor Legacy Importer
Plugin URI: http://mikejolley.com/projects/download-monitor/add-ons/legacy-importer/
Description: Converts downloads from the legacy 3.0.x versions to the new Download Monitor format (which uses post types). Go to Tools > Import to get importing.
Version: 1.0.0
Author: Mike Jolley
Author URI: http://mikejolley.com
Requires at least: 3.5
Tested up to: 3.5

	Copyright: © 2013 Mike Jolley.
	License: GNU General Public License v3.0
	License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

if ( ! defined( 'ABSPATH' ) )
	exit; // Exit if accessed directly

add_filter( 'dlm_shortcode_download_id', 'wp_dlm_legacy_ids' );

function wp_dlm_legacy_ids( $id ) {
	global $wpdb;

	$download_exists = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID = %d AND post_type = 'dlm_download';", absint( $id ) ) );

	if ( ! $download_exists ) {
		$legacy_download = $wpdb->get_var( $wpdb->prepare( "
			SELECT post_id FROM {$wpdb->postmeta}
			LEFT JOIN {$wpdb->posts} ON {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID
			WHERE meta_key = '_legacy_download_id'
			AND meta_value = %d
			AND post_type = 'dlm_download'
			AND post_status = 'publish'
			", absint( $id ) ) );

		if ( $legacy_download )
			return $legacy_download;
	}

	return $id;
}

if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;
/**
 * WP_DLM_Legacy class.
 */
class WP_DLM_Legacy {

	/**
	 * __construct function.
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'register_importer' ) );
	}

	/**
	 * register_importer function.
	 *
	 * @access public
	 * @return void
	 */
	public function register_importer() {
		register_importer( 'dlm_legacy', 'Download Monitor Legacy Importer', __( 'Convert downloads from the leagacy DLM plugin to the new version (which uses post tyes).' ), array( $this, 'do_import' ) );
	}

	/**
	 * Load import class and start the import
	 */
	public function do_import() {
		include( 'includes/class-dlm-legacy-importer.php' );

		$importer = new DLM_Legacy_Importer();
		$importer->dispatch();
	}
}

new WP_DLM_Legacy();