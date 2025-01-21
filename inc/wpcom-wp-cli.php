<?php
declare( strict_types = 1 );

namespace CupcakeLabs\API;

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
 *    wp tumblr-json-importer /path/to/tumblr.json
 *
 * @param array $args       Positional arguments.
 * @param array $assoc_args Associative arguments.
 *
 * @return void
 */
function tumblr_json_importer_run( $args, $assoc_args ): void {
	global $wp_filesystem;

	// Parse passed args and ensure defaults are set.
	$assoc_args = wp_parse_args(
		$assoc_args,
		array(
			'dry-run' => 'true',
		)
	);

	// Ensure this can only run on https://cupcakelabsdemo.wordpress.com/
	$blog_id = wpcom_get_blog_id();

	if ( 240546002 !== $blog_id ) {
		\WP_CLI::error( 'Not on Cupcake Labs testing blog, add the following to your argument: --url=https://cupcakelabsdemo.wordpress.com/' );
	}

	// Ensure that the file exists.
	$file_path = $args[0];

	// Initialize the WordPress Filesystem API.
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	WP_Filesystem();

	// Check if the file exists.
	if ( ! $wp_filesystem->exists( $file_path ) ) {
		\WP_CLI::error( "The file '{$file_path}' does not exist." );
	}

	// Read the file contents using the WordPress Filesystem API.
	$json_data = $wp_filesystem->get_contents( $file_path );

	// Check if the file could be read.
	if ( false === $json_data ) {
		\WP_CLI::error( "Failed to read the file '{$file_path}'." );
	}

	// Decode the JSON data.
	$tumblr_posts = json_decode( $json_data, true );

	// Check if the JSON is valid.
	if ( json_last_error() !== JSON_ERROR_NONE ) {
		\WP_CLI::error( 'Invalid JSON file: ' . json_last_error_msg() );
	}

	// Tell the user how many posts were found.
	\WP_CLI::log(
		sprintf(
			'JSON file read successfully. Found %d posts.',
			count( $tumblr_posts )
		)
	);

	// Args verified, let's start the import.
	\WP_CLI::log( 'Starting Tumblr JSON Importer...' );

	// Ensure that WordPress is in the importing state.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
	define( 'WP_IMPORTING', true );

	// Convert the posts to WordPress posts.
	$posts_data = array();

	foreach ( $tumblr_posts as $tumblr_post ) {
		// Default post data.
		$post_data = array(
			'post_status' => 'publish',
			'post_type'   => 'post',
		);

		// Set the post title as the Tumblr Post title.
		$post_data['post_title'] = isset( $tumblr_post['title'] ) ? $tumblr_post['title'] : '';

		// Set the post slug to be the Tumblr post ID.
		$post_data['import_id'] = isset( $tumblr_post['id'] ) ? $tumblr_post['id'] : '';

		// Set the post date.
		$post_data['post_date']     = gmdate( 'Y-m-d H:i:s', isset( $tumblr_post['publish_time'] ) ? $tumblr_post['publish_time'] : '' );
		$post_data['post_modified'] = gmdate( 'Y-m-d H:i:s', isset( $tumblr_post['last_modified'] ) ? $tumblr_post['last_modified'] : '' );

		// Map Tumblr root-level fields to post meta.
		$tumblr_post['meta']['id']           = $tumblr_post['id'];
		$tumblr_post['meta']['tumblelog_id'] = $tumblr_post['tumblelog_id'];
		$tumblr_post['meta']['state']        = $tumblr_post['state'];
		$tumblr_post['meta']['type']         = $tumblr_post['type'];

		// Set the post content and also the filtered (NPF) content.
		$post_data['post_content']          = isset( $tumblr_post['meta']['two'] ) ? $tumblr_post['meta']['two'] : '';
		$post_data['post_content_filtered'] = maybe_serialize( isset( $tumblr_post['meta']['npf_data'] ) ? $tumblr_post['meta']['npf_data'] : array() );

		// Remove the post content from the metadata.
		unset( $tumblr_post['meta']['two'], $tumblr_post['meta']['npf_data'] );

		// Set the post meta.
		$post_data['meta_input'] = array(
			'_tumblr_data' => maybe_serialize( $tumblr_post['meta'] ),
		);

		// Add the post data to the array.
		$posts_data[] = $post_data;
	}

	if ( 'true' === $assoc_args['dry-run'] ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- This command is for dev purposes only.
		\WP_CLI::debug( print_r( $posts_data, true ) );
		\WP_CLI::success( 'Dry run complete. No posts were imported. Set --debug to see the generated posts array.' );
		exit;
	}

	// Not a dry run, import the posts.
	$imported = array();

	// Loop over each post and insert it.
	foreach ( $posts_data as $post_data ) {
		$post_id     = wp_insert_post( $post_data, true, false );
		$tumblr_data = maybe_unserialize( $post_data['meta_input']['_tumblr_data'] );

		\WP_CLI::log( 'Importing Tumblr post: ' . $tumblr_data['id'] );

		// Attempt to gracefully handle errors.
		if ( is_wp_error( $post_id ) ) {
			\WP_CLI::error(
				sprintf(
					'Failed to import tumblr post: %s. Imported %d posts before this. Error: %s',
					$tumblr_data['id'],
					count( $imported ),
					$post_id->get_error_message()
				)
			);
		}

		\WP_CLI::log( 'Imported Tumblr post: ' . $tumblr_data['id'] );

		// Add the post ID to the imported array.
		$imported[] = $tumblr_data['id'];
	}

	\WP_CLI::debug( 'Imported Tumblr post IDs:' );

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- This command is for dev purposes only.
	\WP_CLI::debug( print_r( $imported, true ) );
	\WP_CLI::success( 'Import complete. Imported ' . count( $imported ) . ' posts.' );
}

if ( defined( '\WP_CLI' ) && \WP_CLI ) {
	\WP_CLI::add_command( 'tumblr-json-import', __NAMESPACE__ . '\tumblr_json_importer_run' );
}

/**
 * Add post_content_filtered to REST API responses.
 *
 * @todo This needs auth.
 *
 * @return void
 */
function posts_api_extensions(): void {
	/**
	 * Only run the following code for https://cupcakelabsdemo.wordpress.com/ or REST Requests
	 * that call sites/240546002 in the request.
	 */
	if ( empty( $GLOBALS['wp']->query_vars['rest_route'] ) || ! strpos( $GLOBALS['wp']->query_vars['rest_route'], 'sites/240546002' ) ) {
		return;
	}

	register_meta(
		'post',
		'_tumblr_data',
		array(
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => true,
		)
	);

	register_rest_field(
		'post',
		'content_filtered',
		array(
			'get_callback' => function ( $post_data ) {
				return get_post_field( 'post_content_filtered', $post_data['id'] );
			},
			'schema'       => array(
				'description' => __( 'The post content filtered.', 'tumblr-json-importer' ),
				'type'        => 'string',
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\posts_api_extensions' );
