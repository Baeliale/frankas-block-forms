<?php
/**
 * Saved submissions: one row per submission in their own table. Each row
 * keeps the labels as they were when it was sent, so later changes to the
 * form don't change old submissions. No IP addresses are stored.
 */

defined( 'ABSPATH' ) || exit;

const FRANKAS_BLOCK_FORMS_DB_VERSION = '1';

function frankas_block_forms_table(): string {
	global $wpdb;
	return $wpdb->prefix . 'frankas_block_forms_submissions';
}

function frankas_block_forms_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table   = frankas_block_forms_table();
	$charset = $wpdb->get_charset_collate();

	dbDelta( "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		form_id bigint(20) unsigned NOT NULL,
		page_id bigint(20) unsigned NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		data longtext NOT NULL,
		PRIMARY KEY  (id),
		KEY form_created (form_id, created_at)
	) {$charset};" );

	update_option( 'frankas_block_forms_db_version', FRANKAS_BLOCK_FORMS_DB_VERSION );
}

/**
 * Also creates the table when the plugin was copied in rather than activated.
 */
function frankas_block_forms_maybe_install() {
	if ( get_option( 'frankas_block_forms_db_version' ) !== FRANKAS_BLOCK_FORMS_DB_VERSION ) {
		frankas_block_forms_install();
	}
}

/**
 * @param array $data [ name => [ 'label' => …, 'value' => … ], … ]
 * @return int Submission ID, or 0 on failure.
 */
function frankas_block_forms_insert_submission( int $form_id, int $page_id, array $data ): int {
	global $wpdb;
	$ok = $wpdb->insert( frankas_block_forms_table(), [
		'form_id'    => $form_id,
		'page_id'    => $page_id,
		'created_at' => current_time( 'mysql', true ),
		'data'       => wp_json_encode( $data ),
	], [ '%d', '%d', '%s', '%s' ] );
	return $ok ? (int) $wpdb->insert_id : 0;
}

/**
 * Submissions of one form, newest first.
 *
 * @return object[] Rows with data decoded to an array.
 */
function frankas_block_forms_get_submissions( int $form_id, int $limit = 20, int $offset = 0 ): array {
	global $wpdb;
	$table = frankas_block_forms_table();
	$sql   = $limit > 0
		? $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %d ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d", $form_id, $limit, $offset )
		: $wpdb->prepare( "SELECT * FROM {$table} WHERE form_id = %d ORDER BY created_at DESC, id DESC", $form_id );
	return array_map( 'frankas_block_forms_decode_submission', $wpdb->get_results( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL
}

function frankas_block_forms_get_submission( int $id ): ?object {
	global $wpdb;
	$table = frankas_block_forms_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	return $row ? frankas_block_forms_decode_submission( $row ) : null;
}

function frankas_block_forms_decode_submission( object $row ): object {
	$row->data = json_decode( $row->data, true ) ?: [];
	return $row;
}

/**
 * Number of submissions per form, for the given forms.
 *
 * @return array<int,int>
 */
function frankas_block_forms_count_submissions( array $form_ids ): array {
	global $wpdb;
	$form_ids = array_filter( array_map( 'intval', $form_ids ) );
	if ( ! $form_ids ) {
		return [];
	}
	$table = frankas_block_forms_table();
	$in    = implode( ',', $form_ids );
	$rows  = $wpdb->get_results( "SELECT form_id, COUNT(*) AS n FROM {$table} WHERE form_id IN ({$in}) GROUP BY form_id" ); // phpcs:ignore WordPress.DB.PreparedSQL
	return array_map( 'intval', wp_list_pluck( $rows, 'n', 'form_id' ) );
}

function frankas_block_forms_delete_submissions( array $ids ): int {
	global $wpdb;
	$ids = array_filter( array_map( 'intval', $ids ) );
	if ( ! $ids ) {
		return 0;
	}
	$table = frankas_block_forms_table();
	return (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
}

add_action( 'before_delete_post', 'frankas_block_forms_delete_form_submissions' );

/**
 * Submissions go when their form is deleted for good (not when it's binned).
 */
function frankas_block_forms_delete_form_submissions( int $post_id ) {
	global $wpdb;
	if ( FRANKAS_BLOCK_FORMS_POST_TYPE === get_post_type( $post_id ) ) {
		$wpdb->delete( frankas_block_forms_table(), [ 'form_id' => $post_id ], [ '%d' ] );
	}
}
