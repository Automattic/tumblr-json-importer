<?php
/**
 * The Tumblr JSON importer WP-CLI command.
 *
 * @package Tumblr-JSON-Importer
 */

class Tumblr_JSON_Importer {
	public array $imported = array();

	/**
	 * Run the Tumblr JSON importer.
	 *
	 * This function is the main entry point for the Tumblr JSON importer.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : The path to the Tumblr JSON file to import.
	 *
	 * --dry-run=<true|false>
	 * : Perform a dry run of the import. Posts will not be imported.
	 *
	 * ## EXAMPLES
	 *
	 *    wp tumblr-json-import /path/to/tumblr.json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 *
	 * @return void
	 */
	public function tumblr_json_importer_run( $args, $assoc_args ): void {
		global $wp_filesystem;

		// Parse passed args and ensure defaults are set.
		$assoc_args = wp_parse_args(
			$assoc_args,
			array(
				'dry-run' => 'true',
			)
		);

		// Ensure that the file exists.
		$file_path = $args[0];

		// Initialize the WordPress Filesystem API.
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		WP_Filesystem();

		// Check if the file exists.
		if ( ! $wp_filesystem->exists( $file_path ) ) {
			WP_CLI::error( "The file '{$file_path}' does not exist." );
		}

		// Read the file contents using the WordPress Filesystem API.
		$json_data = $wp_filesystem->get_contents( $file_path );

		// Check if the file could be read.
		if ( false === $json_data ) {
			WP_CLI::error( "Failed to read the file '{$file_path}'." );
		}

		// Decode the JSON data.
		$json_data = json_decode( $json_data, true );

		if ( ! isset( $json_data['data'] ) ) {
			WP_CLI::error( 'Invalid JSON file: Missing "data" key.' );
		}

		$data = $json_data['data'];

		// Check if the JSON is valid.
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			WP_CLI::error( 'Invalid JSON file: ' . json_last_error_msg() );
		}

		// Tell the user how many posts were found.
		WP_CLI::log(
			sprintf(
				'JSON file read successfully. Found %d posts.',
				count( $data['posts'] )
			)
		);

		// Args verified, let's start the import.
		WP_CLI::log( 'Starting Tumblr JSON Importer...' );

		// Ensure that WordPress is in the importing state.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
		define( 'WP_IMPORTING', true );

		// Convert the posts to WordPress posts.
		$wp_posts = array();

		foreach ( $data['posts'] as $tumblr_post ) {
			// Default post data.
			$wp_post_data = array(
				'post_status' => 'publish',
				'post_type'   => 'post',
			);

			// Set the post title as the Tumblr Post title.
			$wp_post_data['post_title'] = isset( $tumblr_post['title'] ) ? $tumblr_post['title'] : '';

			// Set the post date.
			$wp_post_data['post_date']     = gmdate( 'Y-m-d H:i:s', isset( $tumblr_post['publish_time'] ) ? $tumblr_post['publish_time'] : 0 );
			$wp_post_data['post_modified'] = gmdate( 'Y-m-d H:i:s', isset( $tumblr_post['last_modified'] ) ? $tumblr_post['last_modified'] : 0 );

			// Map Tumblr root-level fields to post meta.
			$tumblr_post['meta']['id']           = $tumblr_post['id'];
			$tumblr_post['meta']['tumblelog_id'] = $tumblr_post['tumblelog_id'];
			$tumblr_post['meta']['state']        = $tumblr_post['state'];
			$tumblr_post['meta']['type']         = $tumblr_post['type'];

			// Set the post content and also the filtered (NPF) content.
			$wp_post_data['post_content']          = isset( $tumblr_post['meta']['two'] ) ? $tumblr_post['meta']['two'] : '';
			$wp_post_data['post_content_filtered'] = maybe_serialize( isset( $tumblr_post['meta']['npf_data'] ) ? $tumblr_post['meta']['npf_data'] : array() );

			// Remove the post content from the metadata.
			unset( $tumblr_post['meta']['two'], $tumblr_post['meta']['npf_data'] );

			// Set the post meta.
			$wp_post_data['meta_input'] = array(
				'_tumblr_post_id' => intval( $tumblr_post['id'] ),
				'_tumblr_data'    => maybe_serialize( $tumblr_post['meta'] ),
			);

			// Add the post data to the array.
			$wp_posts[] = $wp_post_data;
		}

		if ( 'true' === $assoc_args['dry-run'] ) {
			WP_CLI::debug( print_r( $wp_posts, true ) );
			WP_CLI::success( 'Dry run complete. No posts were imported. Set --debug to see the generated posts array.' );
			exit;
		}

		// Not a dry run, import the posts.
		$this->imported = array();

		// Loop over each post and figure out if we're inserting or updating.
		foreach ( $wp_posts as $wp_post_data ) {
			// Grab the original Tumblr post ID.
			$tumblr_post_id = intval( $wp_post_data['meta_input']['_tumblr_post_id'] );

			// Meta query to check if the post already exists.
			$results = get_posts(
				array(
					'post_type'      => 'post',
					'posts_per_page' => 1,
					'meta_key'       => '_tumblr_post_id',
					'meta_value'     => $tumblr_post_id,
					'fields'         => 'ids',
				)
			);

			// If the post already exists, update it, otherwise insert it.
			if ( ! empty( $results ) && isset( $results[0] ) ) {
				$this->update_post( $wp_post_data, $results[0] );
			} else {
				$this->insert_post( $wp_post_data );
			}
		}

		// These are only shown if --debug is set.
		WP_CLI::debug( 'Imported Tumblr post IDs:' );
		WP_CLI::debug( print_r( $this->imported, true ) );

		WP_CLI::success( 'Import complete. Imported ' . count( $this->imported ) . ' posts.' );
	}

	/**
	 * Undocumented function
	 *
	 * @param array $wp_post_data
	 * @param int $post_id
	 *
	 * @return void
	 */
	private function update_post( $wp_post_data, $post_id ): void {
		// We should only update a post if something has changed.
	}

	/**
	 * Undocumented function
	 *
	 * @param [type] $wp_post_data
	 *
	 * @return void
	 */
	private function insert_post( $wp_post_data ): void {
		$post_id     = wp_insert_post( $wp_post_data, true, true );
		$tumblr_data = maybe_unserialize( $wp_post_data['meta_input']['_tumblr_data'] );

		WP_CLI::log( 'Importing Tumblr post: ' . $tumblr_data['id'] );

		// Attempt to gracefully handle errors.
		if ( is_wp_error( $post_id ) ) {
			WP_CLI::error(
				sprintf(
					'Failed to import tumblr post: %s. Imported %d posts before this. Error: %s',
					$tumblr_data['id'],
					count( $this->imported ),
					$post_id->get_error_message()
				)
			);
		}

		WP_CLI::log( 'Imported Tumblr post: ' . $tumblr_data['id'] );

		// Add the post ID to the imported array.
		$this->imported[] = $tumblr_data['id'];
	}
}

if ( defined( '\WP_CLI' ) && \WP_CLI ) {
	WP_CLI::add_command( 'tumblr-json-import', array( 'Tumblr_JSON_Importer', 'tumblr_json_importer_run' ) );
}
