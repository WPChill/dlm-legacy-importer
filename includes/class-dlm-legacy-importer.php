<?php

if ( ! defined( 'ABSPATH' ) )
	exit; // Exit if accessed directly

if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;

require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require $class_wp_importer;
}

class DLM_Legacy_Importer extends WP_Importer {

	private $tables;

	/**
	 * Constructor
	 */
	public function __construct() {
		global $wpdb;

		$this->tables = array(
			'files'   => $wpdb->prefix . "download_monitor_files",
			'tax'     => $wpdb->prefix . "download_monitor_taxonomies",
			'rel'     => $wpdb->prefix . "download_monitor_relationships",
			'formats' => $wpdb->prefix . "download_monitor_formats",
			'stats'   => $wpdb->prefix . "download_monitor_stats",
			'log'     => $wpdb->prefix . "download_monitor_log",
			'meta'    => $wpdb->prefix . "download_monitor_file_meta"
		);
	}

	/**
	 * Gets the party started
	 */
	public function dispatch() {
		$this->header();

		$step = empty( $_GET['step'] ) ? 0 : absint( $_GET['step'] );

		switch ( $step ) {
			case 0:
				$this->analyze();
			break;
			case 1:
				check_admin_referer( 'dlm_legacy_import' );
				$this->convert();
			break;
		}

		$this->footer();
	}

	/**
	 * Importer title
	 */
	public function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>' . __( 'Download Monitor Legacy Import' ) . '</h2>';
	}

	/**
	 * Importer footer
	 */
	function footer() {
		echo '</div>';
	}

	/**
	 * Analyse setup
	 */
	function analyze() {
		global $wpdb;

		echo '<div class="narrow">';

		if ( ! class_exists( 'WP_DLM' ) ) {
			echo '<p>' . __( 'Please de-activate the old Download Monitor plugin and activate the new Download Monitor plugin before continuing with the import. This will ensure the required post types and taxonomies exist prior to the migration.' ) . '</p>';
			echo '<p><a class="button" href="' . admin_url( 'plugins.php' ) . '">' . __( 'Manage Plugins &rarr;' ) . '</a></p>';
			echo '</div>';
			return;
		}

		echo '<p>' . __( 'Analyzing Downloads&hellip;' ) . '</p>';

		echo '<ol>';

		$downloads = $wpdb->get_var( "SELECT COUNT( id ) FROM {$this->tables['files']};" );

		printf( '<li>' . __( '<strong>%d</strong> legacy downloads were identified' ) . '</li>', $downloads );

		$count = $wpdb->get_var( "SELECT COUNT( id ) FROM {$this->tables['tax']} WHERE taxonomy = 'tag';" );

		printf( '<li>' . __( '<strong>%d</strong> legacy tags were identified' ) . '</li>', $count );

		$count = $wpdb->get_var( "SELECT COUNT( id ) FROM {$this->tables['tax']} WHERE taxonomy = 'category';" );

		printf( '<li>' . __( '<strong>%d</strong> legacy categories were identified' ) . '</li>', $count );

		print( '<li>' . __( 'Legacy logs and "formats" will not be imported (the new DLM uses template files instead)' ) . '</li>' );

		echo '</ol>';

		echo '<p>' . __( '<strong>Note on shortcodes:</strong> Download shortcodes containing IDs will mismatch the new Download IDs after import. Whilst active, the legacy plugin will try to step in if a legacy ID is called, however it is recommended that you update your content with new IDs to prevent errors. Some legacy shortcodes may no longer function - see the docs for the new shortcodes.' ) . '</p>';
		echo '<p>' . __( '<strong>Note on data:</strong> After import, the old tables <strong>will not be deleted</strong>. When you are happy that the import has been completed successfully, delete the old DLM tables manually. For your reference these are:' ) . '<ol><li><code>' . implode( '</code></li><li><code>', $this->tables ) . '</code></li></ol>'. '</p>';

		if ( $downloads ) {
			?>
			<form name="dlm_legacy_import" id="dlm_legacy_import" action="admin.php?import=dlm_legacy&amp;step=1" method="post">
				<?php wp_nonce_field( 'dlm_legacy_import' ); ?>
				<p class="submit"><input type="submit" name="submit" class="button" value="<?php _e( 'Import Now' ); ?>" style="margin-right: 1em" /> <?php _e('<strong>Please backup your database first</strong>. We are not responsible for any harm or wrong doing this plugin may cause. Users are fully responsible for their own use and data. This plugin is to be used WITHOUT warranty.' ); ?></p>
			</form>
			<?php
		}

		echo '</div>';
	}

	/**
	 * Suspend cache and process downloads
	 */
	function convert() {
		wp_suspend_cache_invalidation( true );

		$this->process_downloads();

		wp_suspend_cache_invalidation( false );
	}

	/**
	 * Import the products
	 */
	function process_downloads() {
		global $wpdb;

		$results = 0;

		if( ! ini_get( 'safe_mode' ) )
			set_time_limit( 600 );

		$downloads = $wpdb->get_results( "SELECT * FROM {$this->tables['files']};" );

		foreach ( $downloads as $legacy_download ) {

			$already_imported = $wpdb->get_var( "
				SELECT ID FROM {$wpdb->posts}
				LEFT JOIN {$wpdb->postmeta} ON {$wpdb->posts}.ID = {$wpdb->postmeta}.post_id
				WHERE meta_key = '_legacy_download_id'
				AND post_status = 'publish'
				AND post_type = 'dlm_download'
				AND meta_value = '" . absint( $legacy_download->id ) . "'
			" );

			if ( $already_imported ) {
				printf( '<p>' . __( '<strong>%s</strong> has already been imported (# %d).' ) . '</p>', $legacy_download->title, $already_imported );
				continue;
			}

			if ( $legacy_download->user ) {
				$user = get_user_by( 'login', $legacy_download->user );
				if ( $user ) {
					$user_id = $user->ID;
				}
			}

			if ( empty( $user_id ) )
				$user_id = get_current_user_id();

			$download = array(
				'post_title'   => $legacy_download->title,
				'post_content' => $legacy_download->file_description,
				'post_date'    => $legacy_download->postDate,
				'post_status'  => 'publish',
				'post_author'  => $user_id,
				'post_type'    => 'dlm_download'
			);

			$download_id = wp_insert_post( $download );

			if ( $download_id ) {
				// Meta
				update_post_meta( $download_id, '_legacy_download_id', $legacy_download->id );
				update_post_meta( $download_id, '_featured', 'no' );
				update_post_meta( $download_id, '_members_only', $legacy_download->members ? 'yes' : 'no' );
				update_post_meta( $download_id, '_download_count', absint( $legacy_download->hits ) );

				// File
				$file = array(
					'post_title'   => 'Download #' . $download_id . ' File Version',
					'post_content' => '',
					'post_status'  => 'publish',
					'post_author'  => $user_id,
					'post_parent'  => $download_id,
					'post_type'    => 'dlm_download_version'
				);

				$file_id = wp_insert_post( $file );

				if ( $file_id ) {
					$urls = array();

					if ( $legacy_download->mirrors ) {
						$urls = explode( "\n", $legacy_download->mirrors );
					}

					$urls = array_filter( array_merge( array( $legacy_download->filename ), (array) $urls ) );

					update_post_meta( $file_id, '_version', $legacy_download->dlversion );
					update_post_meta( $file_id, '_files', $urls );
					update_post_meta( $file_id, '_filesize', '' );
				}

				// Other meta data
				$meta_fields = $wpdb->get_results( $wpdb->prepare( "SELECT meta_name, meta_value FROM {$this->tables['meta']} WHERE download_id = %d;", $legacy_download->id ) );

				foreach ( $meta_fields as $meta ) {
					if ( $meta->meta_name == 'thumbnail' ) {
						$filename = basename( $meta->meta_value );

						$attachment = array(
							'post_title'   => '',
							'post_content' => '',
							'post_status'  => 'inherit',
							'post_parent'  => $download_id
						);

						$attachment_id = $this->process_attachment( $attachment, $meta->meta_value, $download_id );

						if ( ! is_wp_error( $attachment_id ) && $attachment_id ) {
							update_post_meta( $download_id, '_thumbnail_id', $attachment_id );
						}

						continue;
					}

					update_post_meta( $download_id, $meta->meta_name, $meta->meta_value );
				}

				// Categories
				$terms = $wpdb->get_col( $wpdb->prepare(
					"
					SELECT taxonomy.name
					FROM {$this->tables['tax']} as taxonomy
					LEFT JOIN {$this->tables['rel']} as rel ON taxonomy.id = rel.taxonomy_id
					WHERE download_id = %d
					AND taxonomy.taxonomy = 'category'
					", $legacy_download->id ) );

				if ( $terms ) {
					$term_ids = array();

					foreach ( $terms as $term ) {
						$term_obj = term_exists( $term, 'dlm_download_category' );
						$term_id  = $term_obj['term_id'];

						if ( ! $term_id ) {
							$term_obj = wp_insert_term( $term, 'dlm_download_category' );
							$term_id  = $term_obj['term_id'];
						}

						$term_ids[] = $term_id;
					}

					wp_set_post_terms( $download_id, $term_ids, 'dlm_download_category' );
				}

				// Tags
				$terms = $wpdb->get_col( $wpdb->prepare(
					"
					SELECT taxonomy.name
					FROM {$this->tables['tax']} as taxonomy
					LEFT JOIN {$this->tables['rel']} as rel ON taxonomy.id = rel.taxonomy_id
					WHERE download_id = %d
					AND taxonomy.taxonomy = 'tag'
					", $legacy_download->id ) );

				if ( $terms )
					wp_set_post_terms( $download_id, $terms, 'dlm_download_tag' );

				// Success
				printf( '<p>' . __( '<strong>%s</strong> download was imported.' ) . '</p>', $legacy_download->title );

				$results ++;
			}
		}

		printf( '<p>' . __( '<strong>Done:</strong> %d downloads were imported.' ) . '</p>', $results );
	}

	/**
	 * If fetching attachments is enabled then attempt to create a new attachment
	 *
	 * @param array $post Attachment post details from WXR
	 * @param string $url URL to fetch attachment from
	 * @return int|WP_Error Post ID on success, WP_Error otherwise
	 */
	function process_attachment( $post, $url, $post_id ) {

		$attachment_id 		= '';
		$attachment_url 	= '';
		$attachment_file 	= '';
		$upload_dir 		= wp_upload_dir();

		if ( strstr( $url, site_url() ) ) {
			$abs_url 	= str_replace( trailingslashit( site_url() ), trailingslashit( ABSPATH ), $url );
			$new_name 	= wp_unique_filename( $upload_dir['path'], basename( $url ) );
			$new_url 	= trailingslashit( $upload_dir['path'] ) . $new_name;

			if ( copy( $abs_url, $new_url ) ) {
				$url = basename( $new_url );
			}
		}

		if ( ! strstr( $url, 'http' ) ) {

			// Local file
			$attachment_file 	= trailingslashit( $upload_dir['path'] ) . $url;

			// We have the path, check it exists
			if ( file_exists( $attachment_file ) ) {

				$attachment_url 	= str_replace( trailingslashit( ABSPATH ), trailingslashit( site_url() ), $attachment_file );

				if ( $info = wp_check_filetype( $attachment_file ) )
					$post['post_mime_type'] = $info['type'];
				else
					return new WP_Error( 'attachment_processing_error', __('Invalid file type', 'wordpress-importer') );

				$post['guid'] = $attachment_url;

				$attachment_id 		= wp_insert_attachment( $post, $attachment_file, $post_id );

			} else {
				return new WP_Error( 'attachment_processing_error', __('Local image did not exist!', 'wordpress-importer') );
			}

		} else {

			// if the URL is absolute, but does not contain address, then upload it assuming base_site_url
			if ( preg_match( '|^/[\w\W]+$|', $url ) )
				$url = rtrim( site_url(), '/' ) . $url;

			$upload = $this->fetch_remote_file( $url, $post );

			if ( is_wp_error( $upload ) )
				return $upload;

			if ( $info = wp_check_filetype( $upload['file'] ) )
				$post['post_mime_type'] = $info['type'];
			else
				return new WP_Error( 'attachment_processing_error', __('Invalid file type', 'wordpress-importer') );

			$post['guid'] = $upload['url'];

			$attachment_file 	= $upload['file'];
			$attachment_url 	= $upload['url'];

			// as per wp-admin/includes/upload.php
			$attachment_id = wp_insert_attachment( $post, $upload['file'], $post_id );

			unset( $upload );
		}

		if ( ! is_wp_error( $attachment_id ) && $attachment_id > 0 ) {

			wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $attachment_file ) );

			// remap resized image URLs, works by stripping the extension and remapping the URL stub.
			if ( preg_match( '!^image/!', $info['type'] ) ) {
				$parts = pathinfo( $url );
				$name = basename( $parts['basename'], ".{$parts['extension']}" ); // PATHINFO_FILENAME in PHP 5.2

				$parts_new = pathinfo( $attachment_url );
				$name_new = basename( $parts_new['basename'], ".{$parts_new['extension']}" );

				$this->url_remap[$parts['dirname'] . '/' . $name] = $parts_new['dirname'] . '/' . $name_new;
			}

		}

		return $attachment_id;
	}

	/**
	 * Attempt to download a remote file attachment
	 */
	function fetch_remote_file( $url, $post ) {

		// extract the file name and extension from the url
		$file_name 		= basename( $url );
		$wp_filetype 	= wp_check_filetype( $file_name, null );
		$parsed_url 	= @parse_url( $url );

		// Check parsed URL
		if ( ! $parsed_url || ! is_array( $parsed_url ) )
			return false;

		// Ensure url is valid
		$url = str_replace( " ", '%20', $url );

		// Get the file
		$response = wp_remote_get( $url, array(
			'timeout' => 10
		) );

		if ( is_wp_error( $response ) )
			return false;

		// Ensure we have a file name and type
		if ( ! $wp_filetype['type'] ) {

			$headers = wp_remote_retrieve_headers( $response );

			if ( isset( $headers['content-type'] ) && strstr( $headers['content-type'], 'image/' ) ) {

				$file_name = 'image.' . str_replace( 'image/', '', $headers['content-type'] );

			} elseif ( isset( $headers['content-disposition'] ) && strstr( $headers['content-disposition'], 'filename=' ) ) {

				$disposition = end( explode( 'filename=', $headers['content-disposition'] ) );

				$disposition = sanitize_file_name( $disposition );

				$file_name = $disposition;

			}

			unset( $headers );
		}

		// Upload the file
		$upload = wp_upload_bits( $file_name, '', wp_remote_retrieve_body( $response ) );

		if ( $upload['error'] )
			return new WP_Error( 'upload_dir_error', $upload['error'] );

		// Get filesize
		$filesize = filesize( $upload['file'] );

		if ( 0 == $filesize ) {
			@unlink( $upload['file'] );
			unset( $upload );
			return new WP_Error( 'import_file_error', __('Zero size file downloaded', 'wc_csv_import') );
		}

		// keep track of the old and new urls so we can substitute them later
		$this->url_remap[$url] = $upload['url'];

		// keep track of the destination if the remote url is redirected somewhere else
		if ( isset($headers['x-final-location']) && $headers['x-final-location'] != $url )
			$this->url_remap[$headers['x-final-location']] = $upload['url'];

		unset( $response );

		return $upload;
	}

	/**
	 * Decide what the maximum file size for downloaded attachments is.
	 * Default is 0 (unlimited), can be filtered via import_attachment_size_limit
	 *
	 * @return int Maximum attachment file size to import
	 */
	function max_attachment_size() {
		return apply_filters( 'import_attachment_size_limit', 0 );
	}
}
