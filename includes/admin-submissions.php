<?php
/**
 * Forms › Submissions: per form a table of saved submissions, a detail view,
 * deleting and a CSV export.
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

add_action( 'admin_menu', 'frankas_block_forms_submissions_menu' );

function frankas_block_forms_submissions_menu() {
	add_submenu_page(
		'edit.php?post_type=' . FRANKAS_BLOCK_FORMS_POST_TYPE,
		__( 'Submissions', 'frankas-block-forms' ),
		__( 'Submissions', 'frankas-block-forms' ),
		'edit_pages',
		'frankas-block-forms-submissions',
		'frankas_block_forms_submissions_page'
	);
}

class Frankas_Block_Forms_Submissions_Table extends WP_List_Table {

	/** Columns for the first answers of each submission. */
	private const ANSWER_COLUMNS = 3;

	private int $form_id;
	private array $fields;

	public function __construct( int $form_id ) {
		parent::__construct( [ 'singular' => 'submission', 'plural' => 'submissions', 'ajax' => false ] );
		$this->form_id = $form_id;
		$this->fields  = array_slice( frankas_block_forms_get_fields( $form_id ), 0, self::ANSWER_COLUMNS, true );
	}

	public function get_columns() {
		$columns = [ 'cb' => '<input type="checkbox">', 'created_at' => __( 'Received', 'frankas-block-forms' ) ];
		foreach ( $this->fields as $name => $field ) {
			$columns[ 'f_' . $name ] = esc_html( $field['label'] ?: $name );
		}
		$columns['page'] = __( 'Sent from', 'frankas-block-forms' );
		return $columns;
	}

	public function get_bulk_actions() {
		return [ 'delete' => __( 'Delete', 'frankas-block-forms' ) ];
	}

	public function prepare_items() {
		global $wpdb;
		$per_page = 20;
		$page     = $this->get_pagenum();
		$table    = frankas_block_forms_table();
		$total    = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE form_id = %d", $this->form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL

		$this->_column_headers = [ $this->get_columns(), [], [] ];
		$this->items           = frankas_block_forms_get_submissions( $this->form_id, $per_page, ( $page - 1 ) * $per_page );
		$this->set_pagination_args( [ 'total_items' => $total, 'per_page' => $per_page ] );
	}

	public function no_items() {
		esc_html_e( 'No submissions yet.', 'frankas-block-forms' );
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d">', $item->id );
	}

	protected function column_created_at( $item ) {
		$view    = add_query_arg( [ 'submission' => $item->id ], frankas_block_forms_submissions_url( $this->form_id ) );
		$delete  = wp_nonce_url( add_query_arg( [ 'fbf_action' => 'delete', 'ids[]' => $item->id ], frankas_block_forms_submissions_url( $this->form_id ) ), 'bulk-submissions' );
		$actions = [
			'view'   => sprintf( '<a href="%s">%s</a>', esc_url( $view ), esc_html__( 'View', 'frankas-block-forms' ) ),
			'delete' => sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( $delete ), esc_html__( 'Delete', 'frankas-block-forms' ) ),
		];
		return sprintf( '<strong><a href="%s">%s</a></strong>%s', esc_url( $view ), esc_html( frankas_block_forms_local_date( $item->created_at ) ), $this->row_actions( $actions ) );
	}

	protected function column_page( $item ) {
		return $item->page_id && get_post( $item->page_id )
			? sprintf( '<a href="%s">%s</a>', esc_url( get_permalink( $item->page_id ) ), esc_html( get_the_title( $item->page_id ) ) )
			: '—';
	}

	protected function column_default( $item, $column ) {
		$name  = substr( $column, 2 );
		$value = $item->data[ $name ]['value'] ?? '';
		return '' === $value ? '—' : esc_html( wp_trim_words( $value, 12 ) );
	}
}

function frankas_block_forms_local_date( string $gmt ): string {
	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $gmt . ' UTC' ) );
}

add_action( 'admin_init', 'frankas_block_forms_submissions_actions' );

/**
 * Deleting (single or bulk) and the CSV export, before any output.
 */
function frankas_block_forms_submissions_actions() {
	// phpcs:disable WordPress.Security.NonceVerification -- checked below per action.
	if ( 'frankas-block-forms-submissions' !== ( $_GET['page'] ?? '' ) || ! current_user_can( 'edit_pages' ) ) {
		return;
	}
	$form_id = absint( $_REQUEST['form'] ?? 0 );
	$action  = sanitize_key( $_REQUEST['fbf_action'] ?? '' );
	if ( 'delete' !== $action && in_array( 'delete', [ $_REQUEST['action'] ?? '', $_REQUEST['action2'] ?? '' ], true ) ) {
		$action = 'delete'; // Bulk action from the list table.
	}
	// phpcs:enable

	if ( 'delete' === $action ) {
		check_admin_referer( 'bulk-submissions' );
		$ids     = array_map( 'absint', (array) ( $_REQUEST['ids'] ?? [] ) );
		$deleted = frankas_block_forms_delete_submissions( $ids );
		wp_safe_redirect( add_query_arg( 'deleted', $deleted, frankas_block_forms_submissions_url( $form_id ) ) );
		exit;
	}

	if ( 'export' === $action ) {
		check_admin_referer( 'frankas_block_forms_export_' . $form_id );
		frankas_block_forms_export_csv( $form_id );
		exit;
	}
}

/**
 * All submissions of a form as a CSV download. Columns are the form's
 * current fields plus any older ones found in the submissions.
 */
function frankas_block_forms_export_csv( int $form_id ) {
	$submissions = frankas_block_forms_get_submissions( $form_id, 0 );
	$columns     = wp_list_pluck( frankas_block_forms_get_fields( $form_id ), 'label' );
	foreach ( $submissions as $submission ) {
		foreach ( $submission->data as $name => $answer ) {
			$columns[ $name ] ??= $answer['label'];
		}
	}

	$filename = sanitize_file_name( get_the_title( $form_id ) . '-' . gmdate( 'Y-m-d' ) . '.csv' );
	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // So Excel reads the file as UTF-8.
	fputcsv( $out, array_merge( [ __( 'Received', 'frankas-block-forms' ) ], array_values( $columns ), [ __( 'Sent from', 'frankas-block-forms' ) ] ) );
	foreach ( $submissions as $submission ) {
		$row = [ frankas_block_forms_local_date( $submission->created_at ) ];
		foreach ( array_keys( $columns ) as $name ) {
			$row[] = frankas_block_forms_csv_safe( (string) ( $submission->data[ $name ]['value'] ?? '' ) );
		}
		$row[] = $submission->page_id ? get_permalink( $submission->page_id ) : '';
		fputcsv( $out, $row );
	}
	fclose( $out );
}

/**
 * Stop spreadsheet programs from running answers that look like formulas.
 */
function frankas_block_forms_csv_safe( string $value ): string {
	return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
}

function frankas_block_forms_submissions_page() {
	$forms   = get_posts( [
		'post_type'      => FRANKAS_BLOCK_FORMS_POST_TYPE,
		'post_status'    => [ 'publish', 'draft', 'private' ],
		'posts_per_page' => -1,
		'orderby'        => 'title',
		'order'          => 'ASC',
		'meta_query'     => frankas_block_forms_template_meta_query( false ), // phpcs:ignore WordPress.DB.SlowDBQuery
	] );
	$form_id = absint( $_GET['form'] ?? 0 ) ?: ( $forms[0]->ID ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	$single  = absint( $_GET['submission'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Submissions', 'frankas-block-forms' ); ?></h1>
		<?php if ( $form_id && ! $single ) : ?>
			<a class="page-title-action" href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'fbf_action', 'export', frankas_block_forms_submissions_url( $form_id ) ), 'frankas_block_forms_export_' . $form_id ) ); ?>"><?php esc_html_e( 'Export as CSV', 'frankas-block-forms' ); ?></a>
		<?php endif; ?>
		<hr class="wp-header-end">

		<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
			<div class="notice notice-success is-dismissible"><p>
				<?php
				$deleted = absint( $_GET['deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification
				/* translators: %d: number of deleted submissions */
				echo esc_html( sprintf( _n( '%d submission deleted.', '%d submissions deleted.', $deleted, 'frankas-block-forms' ), $deleted ) );
				?>
			</p></div>
		<?php endif; ?>

		<?php if ( ! $forms ) : ?>
			<p><?php esc_html_e( 'There are no forms yet.', 'frankas-block-forms' ); ?></p>
		<?php elseif ( $single ) : ?>
			<?php frankas_block_forms_submission_detail( $single, $form_id ); ?>
		<?php else : ?>
			<form method="get" class="fbf-submissions-form-select">
				<input type="hidden" name="post_type" value="<?php echo esc_attr( FRANKAS_BLOCK_FORMS_POST_TYPE ); ?>">
				<input type="hidden" name="page" value="frankas-block-forms-submissions">
				<label for="fbf-form-select"><?php esc_html_e( 'Form', 'frankas-block-forms' ); ?></label>
				<select id="fbf-form-select" name="form" onchange="this.form.submit()">
					<?php foreach ( $forms as $form ) : ?>
						<option value="<?php echo esc_attr( $form->ID ); ?>"<?php selected( $form_id, $form->ID ); ?>><?php echo esc_html( $form->post_title ?: __( '(no name)', 'frankas-block-forms' ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<noscript><?php submit_button( __( 'Show', 'frankas-block-forms' ), 'secondary', '', false ); ?></noscript>
				<a href="<?php echo esc_url( get_edit_post_link( $form_id ) ); ?>"><?php esc_html_e( 'Edit form', 'frankas-block-forms' ); ?></a>
			</form>

			<?php if ( ! frankas_block_forms_get_settings( $form_id )['storeSubmissions'] ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'This form does not save submissions; they are only e-mailed. Turn on “Save submissions” in the form settings to keep them here.', 'frankas-block-forms' ); ?></p></div>
			<?php endif; ?>

			<form method="post">
				<input type="hidden" name="form" value="<?php echo esc_attr( $form_id ); ?>">
				<?php
				$table = new Frankas_Block_Forms_Submissions_Table( $form_id );
				$table->prepare_items();
				$table->display();
				?>
			</form>
		<?php endif; ?>
	</div>
	<style>
		.fbf-submissions-form-select { display: flex; gap: 8px; align-items: center; margin: 16px 0; }
		.fbf-submission th { width: 220px; }
	</style>
	<?php
}

function frankas_block_forms_submission_detail( int $id, int $form_id ) {
	$submission = frankas_block_forms_get_submission( $id );
	$back       = frankas_block_forms_submissions_url( $submission->form_id ?? $form_id );

	if ( ! $submission ) {
		echo '<p>' . esc_html__( 'This submission no longer exists.', 'frankas-block-forms' ) . '</p>';
		printf( '<p><a href="%s">%s</a></p>', esc_url( $back ), esc_html__( '← All submissions', 'frankas-block-forms' ) );
		return;
	}
	?>
	<p><a href="<?php echo esc_url( $back ); ?>"><?php esc_html_e( '← All submissions', 'frankas-block-forms' ); ?></a></p>
	<h2><?php echo esc_html( get_the_title( $submission->form_id ) ); ?> · <?php echo esc_html( frankas_block_forms_local_date( $submission->created_at ) ); ?></h2>
	<table class="widefat striped fbf-submission">
		<tbody>
			<?php foreach ( $submission->data as $name => $answer ) : ?>
				<tr>
					<th scope="row"><?php echo esc_html( $answer['label'] ?: $name ); ?></th>
					<td><?php echo '' === $answer['value'] ? '—' : nl2br( esc_html( $answer['value'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			<?php if ( $submission->page_id && get_post( $submission->page_id ) ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Sent from', 'frankas-block-forms' ); ?></th>
					<td><a href="<?php echo esc_url( get_permalink( $submission->page_id ) ); ?>"><?php echo esc_html( get_the_title( $submission->page_id ) ); ?></a></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
	<p>
		<a class="button button-link-delete" href="<?php echo esc_url( wp_nonce_url( add_query_arg( [ 'fbf_action' => 'delete', 'ids[]' => $submission->id ], $back ), 'bulk-submissions' ) ); ?>"><?php esc_html_e( 'Delete submission', 'frankas-block-forms' ); ?></a>
	</p>
	<?php
}
