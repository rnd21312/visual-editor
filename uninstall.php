<?php
/**
 * Removes everything the plugin stored when it is deleted (not when it is only deactivated).
 *
 * @package VisualEditor
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'sve_document', 'sve_draft', 'sve_revisions', 'sve_activity', 'sve_api', 'sve_oauth', 'sve_live_jobs', 'sve_live_seen' ) as $sve_option ) {
	delete_option( $sve_option );
}

global $wpdb;
// phpcs:disable WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_sve\_%' OR option_name LIKE '\_transient\_timeout\_sve\_%'" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_sve_source','_sve_mode','_sve_trusted','_sve_source_draft','_sve_seo','_sve_target','_sve_conditions','_sve_replace')" );
// phpcs:enable

foreach ( array( 'sve_template', 'sve_submission' ) as $sve_type ) {
	foreach ( get_posts( array( 'post_type' => $sve_type, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $sve_id ) {
		wp_delete_post( (int) $sve_id, true );
	}
}

$sve_uploads = wp_upload_dir( null, false );
$sve_dir     = $sve_uploads['basedir'] . '/sve';
if ( is_dir( $sve_dir ) ) {
	foreach ( (array) glob( $sve_dir . '/islands/*.js' ) as $sve_file ) {
		wp_delete_file( (string) $sve_file );
	}
	@rmdir( $sve_dir . '/islands' ); // phpcs:ignore
	@rmdir( $sve_dir ); // phpcs:ignore
}
