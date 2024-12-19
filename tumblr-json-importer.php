<?php
/**
 * The Tumblr Theme Garden bootstrap file.
 *
 * @since       1.0.0
 * @version     1.0.0
 * @author      Cupcake Labs
 * @license     GPL-2.0-or-later
 * @package    TumblrThemeGarden
 *
 * @noinspection    ALL
 *
 * @wordpress-plugin
 * Plugin Name:             Tumblr JSON Importer
 * Plugin URI:              https://github.com/Automattic/tumblr-json-importer/
 * Description:             A basic importer for Tumblr JSON files.
 * Version:                 0.1.15
 * Requires at least:       6.5
 * Tested up to:            6.7
 * Requires PHP:            8.2
 * Author:                  Cupcake Labs
 * Author URI:              https://www.automattic.com/
 * License:                 GPLv2 or later
 * License URI:             https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:             tumblr-json-importer
 * Domain Path:             /languages
 **/

defined( 'ABSPATH' ) || exit;

// Don't load the plugin if we're not in the CLI.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/inc/wp-cli.php';
}

/**
 * Register the Tumblr JSON importer postmeta to show in the REST API.
 *
 * @todo This needs auth.
 *
 * @return void
 */
function tumblr_json_importer_meta(): void {
	register_meta(
		'post',
		'_tumblr_data',
		array(
			'type'         => 'string',
			'single'       => true,
			'show_in_rest' => true,
		)
	);
}
add_action( 'rest_api_init', 'tumblr_json_importer_meta' );

/**
 * Add post_content_filtered to REST API responses.
 *
 * @todo This needs auth.
 *
 * @return void
 */
function tumblr_json_importer_add_post_content_filtered_to_rest(): void {
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
add_action( 'rest_api_init', 'tumblr_json_importer_add_post_content_filtered_to_rest' );
