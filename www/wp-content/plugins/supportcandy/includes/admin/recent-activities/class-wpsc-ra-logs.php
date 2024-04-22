<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly!
}

if ( ! class_exists( 'WPSC_RA_Logs' ) ) :

	final class WPSC_RA_Logs {

		/**
		 * Initialize this class
		 */
		public static function init() {

			add_action( 'wp_ajax_wpsc_get_recent_activities', array( __CLASS__, 'get_recent_activities' ) );
		}

		/**
		 * Admin submenu layout
		 *
		 * @return void
		 */
		public static function layout() {
			?>
			<div class="wrap">
				<hr class="wp-header-end">
				<div id="wpsc-container">
					<div class="wpsc-setting-header">
						<h2><?php esc_attr_e( 'Recent Activities', 'supportcandy' ); ?></h2>
					</div>
					<div class="wpsc-setting-section-body">
						<table class="wpsc_recent_activities">
							<thead class="wpsc-ra-logs-thead">
								<tr class="wpsc-ra-logs-tr">
									<th class="wpsc-ra-logs-th"></th>
								</tr>
							</thead>
						</table>
						<script>
							jQuery(document).ready(function() {
								jQuery('.wpsc_recent_activities').dataTable({
									processing: true,
									serverSide: true,
									serverMethod: 'post',
									ajax: { 
										url: supportcandy.ajax_url,
										data: {
											'action': 'wpsc_get_recent_activities',
											'_ajax_nonce': '<?php echo esc_attr( wp_create_nonce( 'wpsc_get_recent_activities' ) ); ?>'
										}
									},
									'columns': [
										{ data: 'log' },
									],
									'bDestroy': true,
									'searching': false,
									'ordering': false,
									'bLengthChange': false,
									pageLength: 20,
									columnDefs: [ 
										{ targets: '_all', className: 'dt-left' },
									],
									language: supportcandy.translations.datatables
								});
							});
						</script>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Get all recent activities
		 *
		 * @return void
		 */
		public static function get_recent_activities() {

			if ( check_ajax_referer( 'wpsc_get_recent_activities', '_ajax_nonce', false ) != 1 ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			if ( ! WPSC_Functions::is_site_admin() ) {
				wp_send_json_error( 'Unauthorized request!', 401 );
			}

			$search     = isset( $_POST['search'] ) && isset( $_POST['search']['value'] ) ? sanitize_text_field( wp_unslash( $_POST['search']['value'] ) ) : '';
			$draw       = isset( $_POST['draw'] ) ? intval( $_POST['draw'] ) : 1;
			$start      = isset( $_POST['start'] ) ? intval( $_POST['start'] ) : 1;
			$rowperpage = isset( $_POST['length'] ) ? intval( $_POST['length'] ) : 20;
			$page_no    = ( $start / $rowperpage ) + 1;

			$now = new DateTime();

			$log_str = '';
			$logs = self::get_activity_logs( $rowperpage, $page_no );
			?>
			<?php
			foreach ( $logs['results'] as $log ) {
				$log_str = '';
				$time_ago = WPSC_Functions::date_interval_highest_unit_ago( $log->date_created->diff( $now ) );
				if ( $log->type == 'report' ) {
					$log_str = '<span class="wpsc-ra-log-desc">' . $log->customer->name . ' created a ticket <a href="' . admin_url( 'admin.php?page=wpsc-tickets&section=ticket-list&id=' . esc_attr( $log->ticket->id ) ) . '">#' . $log->ticket->id . ' ' . esc_attr( $log->ticket->subject ) . '</a></span>
								<span class="wpsc-ra-log-time">' . $time_ago . '</span>';
				} elseif ( $log->type == 'reply' ) {
					$log_str = '<span class="wpsc-ra-log-desc">' . $log->customer->name . ' replied to ticket <a href="' . admin_url( 'admin.php?page=wpsc-tickets&section=ticket-list&id=' . esc_attr( $log->ticket->id ) ) . '">#' . $log->ticket->id . ' ' . esc_attr( $log->ticket->subject ) . '</a></span>
								<span class="wpsc-ra-log-time">' . $time_ago . '</span>';
				} elseif ( $log->type == 'note' ) {
					$log_str = '<span class="wpsc-ra-log-desc">' . $log->customer->name . ' added a note to ticket <a href="' . admin_url( 'admin.php?page=wpsc-tickets&section=ticket-list&id=' . esc_attr( $log->ticket->id ) ) . '">#' . $log->ticket->id . ' ' . esc_attr( $log->ticket->subject ) . '</a></span>
								<span class="wpsc-ra-log-time">' . $time_ago . '</span>';
				} elseif ( $log->type == 'log' ) {
					if ( ! $log->customer->id ) {
						continue;
					}
					$body = json_decode( $log->body );
					$is_json = ( json_last_error() == JSON_ERROR_NONE ) ? true : false;
					if ( $is_json ) {
						$cf = WPSC_Custom_Field::get_cf_by_slug( $body->slug );
						if ( ! $cf ) {
							continue;
						}
						$log_str = '<span class="wpsc-ra-log-desc">' . $cf->type::print_activity( $cf, $log, $body, '0' ) . '</span>
								<span class="wpsc-ra-log-time">' . $time_ago . '</span>';
					}
				}
				$data[] = array(
					'log' => '<div class="wpsc-ra-user-details">
								<div class="wpsc-ra-avatar">' . get_avatar( $log->customer->email, 35 ) . '</div>
								<div class="wpsc-ra-log-details">' . $log_str . '</div>
							</div>',
				);
			}

			$response = array(
				'draw'                 => intval( $draw ),
				'iTotalRecords'        => $logs['total_items'],
				'iTotalDisplayRecords' => $logs['total_items'],
				'data'                 => $data,
			);

			wp_send_json( $response );
		}

		/**
		 * Get activity logs
		 *
		 * @param int $rowperpage - items per page count.
		 * @param int $page_no - page number.
		 * @param int $customer_id - customer id.
		 * @return array
		 */
		public static function get_activity_logs( $rowperpage, $page_no, $customer_id = 'all' ) {

			$gs = get_option( 'wpsc-db-gs-settings' );
			$current_user = WPSC_Current_User::$current_user;

			$type = array();
			if ( in_array( 'report', $gs['allowed-recent-activity-logs'] ) ) {
				$type[] = 'report';
			}
			if ( in_array( 'reply', $gs['allowed-recent-activity-logs'] ) ) {
				$type[] = 'reply';
			}
			if ( in_array( 'note', $gs['allowed-recent-activity-logs'] ) ) {
				$type[] = 'note';
			}

			$meta_query = array(
				'relation' => 'OR',
				array(
					'relation' => 'AND',
					array(
						'slug'    => 'is_active',
						'compare' => '=',
						'val'     => '1',
					),
					array(
						'slug'    => 'type',
						'compare' => 'IN',
						'val'     => $type,
					),
				),
			);
			if ( $customer_id === 'all' ) {
				$meta_query[0][] = array(
					'slug'    => 'customer',
					'compare' => 'NOT IN',
					'val'     => array( '0' ),
				);
			} else {

				$meta_query[0][] = array(
					'slug'    => 'customer',
					'compare' => 'IN',
					'val'     => array( $customer_id ),
				);
			}

			$log_meta = array();
			$cust_fields = array_diff( $gs['allowed-recent-activity-logs'], array( 'report', 'reply', 'note' ) );
			if ( $cust_fields ) {
				$cust_fields = "'" . implode( "','", $cust_fields ) . "'";
				$log_meta = array(
					array(
						'relation' => 'AND',
						array(
							'slug'    => 'type',
							'compare' => '=',
							'val'     => 'log',
						),
						array(
							'slug'    => 'custom_query',
							'compare' => '=',
							'val'     => 'JSON_VALID(body)',
						),
						array(
							'slug'    => 'custom_query',
							'compare' => '=',
							'val'     => "JSON_EXTRACT(body, '$.slug') IN (" . $cust_fields . ')',
						),
					),
				);
				if ( $customer_id === 'all' ) {
					$log_meta[0][] = array(
						'slug'    => 'customer',
						'compare' => 'NOT IN',
						'val'     => array( '0' ),
					);
				} else {

					$log_meta[0][] = array(
						'slug'    => 'customer',
						'compare' => 'IN',
						'val'     => array( $customer_id ),
					);
				}
			}
			$meta_query = array_merge( $meta_query, $log_meta );

			$logs = WPSC_Thread::find(
				array(
					'items_per_page' => $rowperpage,
					'page_no'        => $page_no,
					'order'          => 'DESC',
					'order_by'       => 'date_created',
					'meta_query'     => $meta_query,
				)
			);

			return $logs;
		}
	}
endif;
WPSC_RA_Logs::init();
