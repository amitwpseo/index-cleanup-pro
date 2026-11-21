<?php
/**
 * Admin screens: Dashboard, URLs, Add / Import, Settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_Admin {

	const SLUG = 'hacked-site-index-cleaner';
	const CAP  = 'manage_options';

	private static $hook = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_icp_test_batch', array( __CLASS__, 'ajax_test_batch' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( ICP_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">Dashboard</a>' );
		return $links;
	}

	public static function url( $tab = 'dashboard', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'tools.php' ) );
	}

	public static function menu() {
		self::$hook = add_management_page( 'Hacked Site Index Cleaner', 'Index Cleaner', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function assets( $hook ) {
		if ( $hook !== self::$hook ) {
			return;
		}
		wp_enqueue_style( 'icp-admin', ICP_URL . 'assets/admin.css', array(), ICP_VERSION );
		wp_enqueue_script( 'icp-admin', ICP_URL . 'assets/admin.js', array(), ICP_VERSION, true );
		wp_localize_script(
			'icp-admin',
			'ICP',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'icp_test' ),
			)
		);
		add_filter( 'admin_footer_text', array( __CLASS__, 'footer_credit' ) );
	}

	public static function footer_credit() {
		return 'Hacked Site Index Cleaner ' . esc_html( ICP_VERSION ) . ' &middot; built by <a href="https://bestlocalseoservices.com/" target="_blank" rel="noopener">BLSS – Best Local SEO Services &amp; Development</a>';
	}

	/* ------------------------------------------------------------------ */
	/* Notices                                                             */
	/* ------------------------------------------------------------------ */

	private static function notice( $msg, $type = 'success' ) {
		set_transient( 'icp_notice_' . get_current_user_id(), array( $msg, $type ), 60 );
	}

	private static function show_notice() {
		$k = 'icp_notice_' . get_current_user_id();
		$n = get_transient( $k );
		if ( $n ) {
			delete_transient( $k );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $n[1] ), wp_kses_post( $n[0] ) );
		}
	}

	private static function redirect( $tab, $args = array() ) {
		wp_safe_redirect( self::url( $tab, $args ) );
		exit;
	}

	private static function purge_note() {
		$p = ICP_Cache::purge_all();
		return $p ? ' Page cache purged: ' . esc_html( implode( ', ', $p ) ) . '.' : '';
	}

	/* ------------------------------------------------------------------ */
	/* Actions (POST/GET handlers, run before output)                      */
	/* ------------------------------------------------------------------ */

	public static function handle_actions() {
		if ( empty( $_REQUEST['page'] ) || self::SLUG !== $_REQUEST['page'] ) { // phpcs:ignore
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}

		// Exports.
		if ( isset( $_GET['icp_export'] ) ) { // phpcs:ignore
			check_admin_referer( 'icp_export' );
			self::export( sanitize_key( $_GET['icp_export'] ) ); // phpcs:ignore
		}

		// Single delete.
		if ( isset( $_GET['icp_action'] ) && 'delete_one' === $_GET['icp_action'] && ! empty( $_GET['id'] ) ) { // phpcs:ignore
			$id = absint( $_GET['id'] ); // phpcs:ignore
			check_admin_referer( 'icp_row_' . $id );
			ICP_DB::delete_ids( array( $id ) );
			self::notice( 'URL removed from the list.' . self::purge_note() );
			self::redirect( 'urls' );
		}

		// Rules: toggle / delete.
		if ( isset( $_GET['icp_action'] ) && in_array( $_GET['icp_action'], array( 'rule_delete', 'rule_toggle' ), true ) && ! empty( $_GET['id'] ) ) { // phpcs:ignore
			$id = absint( $_GET['id'] ); // phpcs:ignore
			check_admin_referer( 'icp_rule_' . $id );
			if ( 'rule_delete' === $_GET['icp_action'] ) { // phpcs:ignore
				ICP_Rules::delete( $id );
				self::notice( 'Rule deleted.' . self::purge_note() );
			} else {
				ICP_Rules::toggle( $id );
				self::notice( 'Rule updated.' . self::purge_note() );
			}
			self::redirect( 'rules' );
		}

		// Bulk actions from the list table (top or bottom selector).
		$bulk = '';
		if ( isset( $_POST['action'] ) && '-1' !== $_POST['action'] ) { // phpcs:ignore
			$bulk = sanitize_key( $_POST['action'] ); // phpcs:ignore
		} elseif ( isset( $_POST['action2'] ) && '-1' !== $_POST['action2'] ) { // phpcs:ignore
			$bulk = sanitize_key( $_POST['action2'] ); // phpcs:ignore
		}
		if ( in_array( $bulk, array( 'icp_delete', 'icp_restamp' ), true ) ) {
			check_admin_referer( 'bulk-icp_urls' );
			$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array(); // phpcs:ignore
			if ( ! $ids ) {
				self::notice( 'Nothing selected.', 'warning' );
			} elseif ( 'icp_delete' === $bulk ) {
				$n = ICP_DB::delete_ids( $ids );
				self::notice( sprintf( '%d URL(s) removed from the list.', $n ) . self::purge_note() );
			} else {
				$n = ICP_DB::restamp_ids( $ids );
				self::notice( sprintf( '%d URL(s) re-stamped. Their sitemap &lt;lastmod&gt; is now today, which asks Google to recrawl them.', $n ) );
			}
			self::redirect( 'urls' );
		}

		if ( empty( $_POST['icp_do'] ) ) { // phpcs:ignore
			return;
		}
		$do = sanitize_key( $_POST['icp_do'] ); // phpcs:ignore

		switch ( $do ) {
			case 'clear_all':
				check_admin_referer( 'icp_clear_all' );
				ICP_DB::delete_all();
				self::notice( 'All URLs and hit logs deleted.' . self::purge_note() );
				self::redirect( 'urls' );
				break;

			case 'preview':
				check_admin_referer( 'icp_import' );
				$content = isset( $_POST['icp_paste'] ) ? wp_unslash( $_POST['icp_paste'] ) : ''; // phpcs:ignore
				if ( ! empty( $_FILES['icp_file']['tmp_name'] ) && is_uploaded_file( $_FILES['icp_file']['tmp_name'] ) ) { // phpcs:ignore
					if ( $_FILES['icp_file']['size'] > 10 * MB_IN_BYTES ) { // phpcs:ignore
						self::notice( 'File is larger than 10 MB.', 'error' );
						self::redirect( 'import' );
					}
					$content .= "\n" . file_get_contents( $_FILES['icp_file']['tmp_name'] ); // phpcs:ignore
				}
				$raw = ICP_Import::extract( $content );
				if ( ! $raw ) {
					self::notice( 'No URLs found. Paste one URL per line or upload a CSV/TXT/XML file.', 'error' );
					self::redirect( 'import' );
				}
				$res         = ICP_Import::classify( $raw );
				$res['note'] = isset( $_POST['icp_note'] ) ? sanitize_text_field( wp_unslash( $_POST['icp_note'] ) ) : ''; // phpcs:ignore
				$res['capped'] = count( $raw ) >= ICP_Import::MAX_LINES;
				set_transient( ICP_Import::transient_key(), $res, HOUR_IN_SECONDS );
				self::redirect( 'import', array( 'step' => 'preview' ) );
				break;

			case 'confirm':
				check_admin_referer( 'icp_confirm' );
				$res = get_transient( ICP_Import::transient_key() );
				if ( ! $res ) {
					self::notice( 'The preview expired. Please import again.', 'error' );
					self::redirect( 'import' );
				}
				$force = isset( $_POST['force'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['force'] ) ) : array(); // phpcs:ignore
				$force = array_flip( $force );
				$items = array();
				foreach ( $res['new'] as $p ) {
					$items[] = array( 'path' => $p, 'override' => 0 );
				}
				$forced = 0;
				foreach ( $res['conflicts'] as $c ) {
					if ( isset( $force[ md5( $c['path'] ) ] ) ) {
						$items[] = array( 'path' => $c['path'], 'override' => 1 );
						$forced++;
					}
				}
				$n = ICP_DB::insert_paths( $items, $res['note'] );
				delete_transient( ICP_Import::transient_key() );
				$msg = sprintf( '%d URL(s) added.', $n );
				if ( $forced ) {
					$msg .= sprintf( ' %d of them were forced despite matching a published page.', $forced );
				}
				self::notice( $msg . self::purge_note() );
				self::redirect( 'urls' );
				break;

			case 'cancel':
				check_admin_referer( 'icp_confirm' );
				delete_transient( ICP_Import::transient_key() );
				self::redirect( 'import' );
				break;

			case 'rule_preview':
			case 'rule_add':
				check_admin_referer( 'icp_rule' );
				$type = isset( $_POST['rtype'] ) ? sanitize_key( $_POST['rtype'] ) : ''; // phpcs:ignore
				$raw  = isset( $_POST['pattern'] ) ? wp_unslash( $_POST['pattern'] ) : ''; // phpcs:ignore
				list( $ok, $pat ) = ICP_Rules::normalize( $type, $raw );
				if ( ! $ok ) {
					self::notice( esc_html( $pat ), 'error' );
					self::redirect( 'rules' );
				}
				if ( 'rule_add' === $do ) {
					if ( ICP_Rules::exists( $type, $pat ) ) {
						self::notice( 'That rule already exists.', 'warning' );
						self::redirect( 'rules' );
					}
					ICP_Rules::add( $type, $pat, isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '' ); // phpcs:ignore
					self::notice( 'Rule added. Matching URLs now return 410 (real pages are always skipped).' . self::purge_note() );
					self::redirect( 'rules' );
				}
				$pv   = ICP_Rules::preview( $type, $pat );
				$list = function ( $arr ) {
					return $arr ? ' <code>' . implode( '</code>, <code>', array_map( 'esc_html', $arr ) ) . '</code>' : '';
				};
				$msg  = '<strong>Preview for ' . esc_html( self::rule_label( $type ) ) . ' <code>' . esc_html( $pat ) . '</code></strong><br>';
				$msg .= sprintf( '• %d URL(s) in the 404 log would be removed.%s<br>', $pv['log']['n'], $list( $pv['log']['sample'] ) );
				$msg .= sprintf( '• %d URL(s) already on your list match.<br>', $pv['list']['n'] );
				if ( $pv['real']['n'] ) {
					$msg .= sprintf( '• <strong>%d real published page(s) match</strong> and will be left alone:%s<br>', $pv['real']['n'], $list( $pv['real']['sample'] ) );
					$msg .= 'If that looks wrong, make the pattern more specific.';
				} else {
					$msg .= '• No published pages match' . ( $pv['real']['capped'] ? ' (first 3,000 checked).' : '.' );
				}
				self::notice( $msg, $pv['real']['n'] ? 'warning' : 'info' );
				self::redirect( 'rules', array( 'rt' => $type, 'rp' => rawurlencode( $pat ) ) );
				break;

			case 'monitor_act':
				check_admin_referer( 'icp_monitor' );
				$act = isset( $_POST['m_act'] ) ? sanitize_key( $_POST['m_act'] ) : ''; // phpcs:ignore
				$min = isset( $_POST['min'] ) ? max( 0, min( 100, absint( $_POST['min'] ) ) ) : 40; // phpcs:ignore
				if ( 'clear' === $act ) {
					ICP_Monitor::clear();
					self::notice( '404 log cleared.' );
					self::redirect( 'monitor' );
				}
				$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array(); // phpcs:ignore
				if ( 'add_score' === $act ) {
					$ids = ICP_Monitor::ids_by_score( $min );
				}
				if ( ! $ids ) {
					self::notice( 'Nothing selected.', 'warning' );
					self::redirect( 'monitor', array( 'min' => $min ) );
				}
				if ( 'ignore' === $act ) {
					$n = ICP_Monitor::ignore_ids( $ids );
					self::notice( sprintf( '%d URL(s) hidden from this list.', $n ) );
				} else {
					$res   = ICP_Import::classify( ICP_Monitor::paths_for_ids( $ids ) );
					$items = array();
					foreach ( $res['new'] as $path ) {
						$items[] = array( 'path' => $path, 'override' => 0 );
					}
					$n = $items ? ICP_DB::insert_paths( $items, '404 monitor' ) : 0;
					ICP_Monitor::delete_ids( $ids );
					self::notice( sprintf( '%d URL(s) added to the dead list. %d skipped (already listed, protected or real pages).', $n, max( 0, count( $ids ) - $n ) ) . self::purge_note() );
				}
				self::redirect( 'monitor', array( 'min' => $min ) );
				break;

			case 'retest_now':
				check_admin_referer( 'icp_retest' );
				if ( ICP_Tester::start( true ) ) {
					self::notice( 'Background re-test started. It runs in small batches through WP-Cron; this page shows progress.' );
				} else {
					self::notice( 'There are no URLs to test.', 'warning' );
				}
				self::redirect( 'dashboard' );
				break;

			case 'test_alert':
				check_admin_referer( 'icp_retest' );
				$ok = ICP_Tester::send_alert( 0, (int) ICP_DB::stats()->total, true );
				self::notice( $ok ? 'Test email sent to ' . esc_html( ICP_Tester::alert_address() ) . '.' : 'WordPress could not send the email. Check your site\'s mail setup (an SMTP plugin usually fixes this).', $ok ? 'success' : 'error' );
				self::redirect( 'settings' );
				break;

			case 'save_note':
				$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore
				check_admin_referer( 'icp_note_' . $id );
				ICP_DB::update_note( $id, isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '' ); // phpcs:ignore
				self::notice( 'Note saved.' );
				self::redirect( 'urls' );
				break;

			case 'save_settings':
				check_admin_referer( 'icp_settings' );
				$until = isset( $_POST['sitemap_until'] ) ? sanitize_text_field( wp_unslash( $_POST['sitemap_until'] ) ) : ''; // phpcs:ignore
				if ( '' !== $until && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $until ) ) {
					$until = ICP_DB::setting( 'sitemap_until' );
				}
				$ip = isset( $_POST['ip_source'] ) ? sanitize_key( $_POST['ip_source'] ) : 'remote_addr'; // phpcs:ignore
				ICP_DB::update_settings(
					array(
						'sitemap_enabled'     => empty( $_POST['sitemap_enabled'] ) ? 0 : 1, // phpcs:ignore
						'sitemap_until'       => $until,
						'log_retention_days'  => max( 1, min( 365, isset( $_POST['log_retention_days'] ) ? absint( $_POST['log_retention_days'] ) : 30 ) ), // phpcs:ignore
						'ip_source'           => in_array( $ip, array( 'remote_addr', 'cf', 'xff' ), true ) ? $ip : 'remote_addr',
						'purge_cache'         => empty( $_POST['purge_cache'] ) ? 0 : 1, // phpcs:ignore
						'delete_on_uninstall' => empty( $_POST['delete_on_uninstall'] ) ? 0 : 1, // phpcs:ignore
						'monitor_enabled'     => empty( $_POST['monitor_enabled'] ) ? 0 : 1, // phpcs:ignore
						'search_mode'         => in_array( isset( $_POST['search_mode'] ) ? sanitize_key( $_POST['search_mode'] ) : '', array( 'off', 'spam', 'all' ), true ) ? sanitize_key( $_POST['search_mode'] ) : 'spam', // phpcs:ignore
						'search_threshold'    => max( 20, min( 100, isset( $_POST['search_threshold'] ) ? absint( $_POST['search_threshold'] ) : 40 ) ), // phpcs:ignore
						'spam_words'          => isset( $_POST['spam_words'] ) ? sanitize_textarea_field( wp_unslash( $_POST['spam_words'] ) ) : '', // phpcs:ignore
						'retest_enabled'      => empty( $_POST['retest_enabled'] ) ? 0 : 1, // phpcs:ignore
						'alert_enabled'       => empty( $_POST['alert_enabled'] ) ? 0 : 1, // phpcs:ignore
						'alert_email'         => isset( $_POST['alert_email'] ) ? sanitize_email( wp_unslash( $_POST['alert_email'] ) ) : '', // phpcs:ignore
					)
				);
				self::notice( 'Settings saved.' );
				self::redirect( 'settings' );
				break;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Export                                                              */
	/* ------------------------------------------------------------------ */

	private static function export( $type ) {
		global $wpdb;
		$t    = ICP_DB::urls_table();
		$host = sanitize_file_name( ICP_Util::site_host() );
		$date = gmdate( 'Y-m-d' );
		nocache_headers();

		if ( 'removals' === $type ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="removals-' . $host . '-' . $date . '.txt"' );
			$offset = 0;
			do {
				$rows = $wpdb->get_col( $wpdb->prepare( "SELECT path FROM {$t} ORDER BY hits DESC, id ASC LIMIT 2000 OFFSET %d", $offset ) );
				foreach ( $rows as $p ) {
					echo ICP_Util::full_url( $p ) . "\r\n"; // phpcs:ignore
				}
				$offset += 2000;
			} while ( count( $rows ) === 2000 );
			exit;
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="index-cleanup-' . $host . '-' . $date . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv( $out, array( 'URL', 'Note', 'Forced', 'Date added (UTC)', 'Lastmod (UTC)', '410s served', 'Last hit (UTC)', 'Googlebot visits', 'Last Googlebot (UTC)', 'Last test code', 'Last test note', 'Last tested (UTC)' ) );
		$offset = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id ASC LIMIT 2000 OFFSET %d", $offset ) );
			foreach ( $rows as $r ) {
				fputcsv(
					$out,
					array(
						ICP_Util::full_url( $r->path ),
						self::csv_safe( $r->note ),
						$r->override ? 'yes' : '',
						$r->date_added,
						$r->lastmod,
						$r->hits,
						$r->last_hit,
						$r->gbot_hits,
						$r->last_gbot,
						$r->test_code ? $r->test_code : '',
						self::csv_safe( $r->test_note ),
						$r->test_at,
					)
				);
			}
			$offset += 2000;
		} while ( count( $rows ) === 2000 );
		fclose( $out );
		exit;
	}

	/** Block spreadsheet formula injection. */
	private static function csv_safe( $v ) {
		$v = (string) $v;
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@' ), true ) ) ? "'" . $v : $v;
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: status tester                                                 */
	/* ------------------------------------------------------------------ */

	public static function ajax_test_batch() {
		check_ajax_referer( 'icp_test', 'nonce' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( 'forbidden', 403 );
		}
		global $wpdb;
		$t     = ICP_DB::urls_table();
		$after = isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0; // phpcs:ignore
		$scope = isset( $_POST['scope'] ) ? sanitize_key( $_POST['scope'] ) : 'all'; // phpcs:ignore
		$where = 'all' === $scope ? '' : ' AND test_code <> 410';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE 1=1{$where}" );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT id, path FROM {$t} WHERE id > %d{$where} ORDER BY id ASC LIMIT 8", $after ) );

		$token   = (string) ICP_DB::setting( 'test_token' );
		$results = array( 'ok' => 0, 'bad' => 0, 'blocked' => 0 );
		$last    = $after;
		foreach ( $rows as $r ) {
			list( $code, $note ) = ICP_Tester::test_url( ICP_Util::full_url( $r->path ), $token );
			ICP_DB::save_test( $r->id, $code, $note );
			if ( 410 === $code ) {
				$results['ok']++;
			} elseif ( 0 === strpos( $note, 'blocked' ) ) {
				$results['blocked']++;
			} else {
				$results['bad']++;
			}
			$last = (int) $r->id;
		}
		wp_send_json_success(
			array(
				'after'   => $last,
				'done'    => count( $rows ) < 8,
				'count'   => count( $rows ),
				'total'   => $total,
				'results' => $results,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'dashboard'; // phpcs:ignore
		$tabs = array(
			'dashboard' => 'Dashboard',
			'urls'      => 'URLs',
			'import'    => 'Add / Import',
			'rules'     => 'Rules',
			'monitor'   => '404 Monitor',
			'settings'  => 'Settings',
		);
		echo '<div class="wrap icp-wrap"><h1>Hacked Site Index Cleaner</h1>';
		self::show_notice();
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $l ) {
			$active = ( $tab === $k || ( 'edit' === $tab && 'urls' === $k ) ) ? ' nav-tab-active' : '';
			echo '<a class="nav-tab' . esc_attr( $active ) . '" href="' . esc_url( self::url( $k ) ) . '">' . esc_html( $l ) . '</a>';
		}
		echo '</nav><div class="icp-body">';
		switch ( $tab ) {
			case 'urls':
				self::tab_urls();
				break;
			case 'import':
				self::tab_import();
				break;
			case 'rules':
				self::tab_rules();
				break;
			case 'monitor':
				self::tab_monitor();
				break;
			case 'settings':
				self::tab_settings();
				break;
			case 'edit':
				self::tab_edit();
				break;
			default:
				self::tab_dashboard();
		}
		echo '</div></div>';
	}

	private static function tab_dashboard() {
		$st     = ICP_DB::stats();
		$total  = (int) $st->total;
		$seen   = (int) $st->gbot_seen;
		$pct    = $total ? round( 100 * $seen / $total, 1 ) : 0;
		$s      = ICP_DB::settings();
		$sm_on  = ICP_Frontend::sitemap_active();
		$sm_url = home_url( '/dead-urls.xml' );
		$exp    = wp_nonce_url( self::url( 'dashboard', array( 'icp_export' => 'csv' ) ), 'icp_export' );
		$rem    = wp_nonce_url( self::url( 'dashboard', array( 'icp_export' => 'removals' ) ), 'icp_export' );
		?>
		<div class="icp-cards">
			<div class="icp-card"><span class="icp-num"><?php echo number_format_i18n( $total ); ?></span><span class="icp-lab">URLs on the list</span></div>
			<div class="icp-card"><span class="icp-num"><?php echo number_format_i18n( (int) $st->hits ); ?></span><span class="icp-lab">410 responses served</span></div>
			<div class="icp-card"><span class="icp-num"><?php echo number_format_i18n( $seen ); ?> <small>/ <?php echo number_format_i18n( $total ); ?></small></span><span class="icp-lab">Recrawled by verified Googlebot</span>
				<div class="icp-bar"><span style="width:<?php echo esc_attr( $pct ); ?>%"></span></div><span class="icp-muted"><?php echo esc_html( $pct ); ?>%</span></div>
			<div class="icp-card"><span class="icp-num"><?php echo number_format_i18n( (int) $st->test_ok ); ?> <small>OK</small> · <?php echo number_format_i18n( (int) $st->test_bad ); ?> <small>issues</small></span><span class="icp-lab">Last status test (<?php echo number_format_i18n( (int) $st->untested ); ?> untested)</span></div>
		</div>

		<div class="icp-grid">
			<div class="icp-panel">
				<h2>Status test</h2>
				<p>Requests each URL from this server and checks it really returns <strong>410</strong>. Test requests are not counted as hits.</p>
				<p>
					<button type="button" class="button button-primary icp-test" data-scope="all" <?php disabled( 0, $total ); ?>>Test all URLs</button>
					<button type="button" class="button icp-test" data-scope="failing" <?php disabled( 0, $total ); ?>>Re-test non-410 only</button>
				</p>
				<div class="icp-progress" hidden><div class="icp-bar"><span></span></div><p class="icp-progress-text"></p></div>
				<p class="icp-muted">If many URLs show <em>blocked by host</em>, the hosting firewall is challenging the server's own requests. That doesn't mean Google is blocked — open a few URLs in a private browser window to confirm.</p>
			</div>

			<div class="icp-panel">
				<h2>Dead-URL sitemap</h2>
				<?php if ( $sm_on ) : ?>
					<p><span class="icp-badge icp-ok">Active</span> until <?php echo esc_html( $s['sitemap_until'] ? $s['sitemap_until'] : 'no end date' ); ?></p>
					<p><a href="<?php echo esc_url( $sm_url ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( $sm_url ); ?></code></a></p>
					<ol class="icp-steps">
						<li>Search Console → <strong>Sitemaps</strong> → add <code>dead-urls.xml</code> → Submit.</li>
						<li>Google recrawls the URLs, sees 410 and drops them. Watch <strong>Indexing → Pages</strong> and the Googlebot counter here.</li>
						<li>After the end date the sitemap switches off. Then remove it in Search Console.</li>
					</ol>
				<?php else : ?>
					<p><span class="icp-badge icp-warn">Off</span> <?php echo $s['sitemap_enabled'] ? 'End date has passed.' : 'Disabled in Settings.'; ?> Remove <code>dead-urls.xml</code> from Search Console if it is still listed there.</p>
				<?php endif; ?>
				<p class="icp-muted">Do not add these URLs to robots.txt — Google must be able to crawl them to see the 410.</p>
			</div>

			<div class="icp-panel">
				<h2>Automatic re-test</h2>
				<?php
				$last = get_option( ICP_Tester::LAST );
				$next = wp_next_scheduled( 'icp_weekly_retest' );
				$stt  = get_option( ICP_Tester::STATE );
				if ( ICP_Tester::running() && is_array( $stt ) ) {
					printf( '<p><span class="icp-badge icp-warn">Running</span> %s of %s tested…</p>', esc_html( number_format_i18n( (int) $stt['done'] ) ), esc_html( number_format_i18n( (int) $stt['total'] ) ) );
				} elseif ( is_array( $last ) ) {
					printf(
						'<p>Last run %s: <strong>%s OK</strong>, <strong>%s</strong> issue(s), %s blocked by host.</p>',
						esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', (int) $last['at'] ), 'Y-m-d H:i' ) ),
						esc_html( number_format_i18n( (int) $last['ok'] ) ),
						esc_html( number_format_i18n( (int) $last['bad'] ) ),
						esc_html( number_format_i18n( (int) $last['blocked'] ) )
					);
				} else {
					echo '<p class="icp-muted">No automatic run yet.</p>';
				}
				?>
				<p class="icp-muted"><?php echo $s['retest_enabled'] ? 'Weekly' . ( $next ? ', next ' . esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ), 'Y-m-d H:i' ) ) : '' ) . '. An email is sent if a URL stops returning 410.' : 'Turned off in Settings.'; ?></p>
				<form method="post" action="<?php echo esc_url( self::url( 'dashboard' ) ); ?>">
					<?php wp_nonce_field( 'icp_retest' ); ?>
					<button name="icp_do" value="retest_now" class="button" <?php disabled( 0, $total ); ?>>Run background re-test now</button>
				</form>
			</div>

			<div class="icp-panel">
				<h2>Protection extras</h2>
				<?php $mc = ICP_Monitor::counts(); ?>
				<ul class="icp-list">
					<li><strong><?php echo number_format_i18n( ICP_Rules::count() ); ?></strong> pattern rule(s) · <a href="<?php echo esc_url( self::url( 'rules' ) ); ?>">manage</a></li>
					<li><strong><?php echo number_format_i18n( (int) $mc->fresh ); ?></strong> new 404 URLs logged, <strong><?php echo number_format_i18n( (int) $mc->likely ); ?></strong> look like spam · <a href="<?php echo esc_url( self::url( 'monitor' ) ); ?>">review</a></li>
					<li><strong><?php echo number_format_i18n( ICP_Search::blocked_count() ); ?></strong> spam searches blocked (mode: <?php echo esc_html( $s['search_mode'] ); ?>)</li>
				</ul>
			</div>

			<div class="icp-panel">
				<h2>Export</h2>
				<p><a class="button" href="<?php echo esc_url( $exp ); ?>">Download full report (CSV)</a></p>
				<p><a class="button" href="<?php echo esc_url( $rem ); ?>">Download URL list for the Removals tool (TXT)</a></p>
				<p class="icp-muted">Removals tool: Search Console → Removals → New request. It hides a URL for ~6 months while the 410 removes it for good. Sorted by most-hit first.</p>
			</div>
		</div>

		<div class="icp-panel">
			<h2>Recent hits</h2>
			<?php
			$hits = ICP_DB::recent_hits( 25 );
			if ( ! $hits ) {
				echo '<p class="icp-muted">No hits yet.</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th>Time</th><th>URL</th><th>Visitor</th></tr></thead><tbody>';
				foreach ( $hits as $h ) {
					$who = $h->is_gbot ? '<span class="icp-badge icp-ok">Googlebot (verified)</span>' : esc_html( wp_trim_words( $h->user_agent, 12, '…' ) );
					echo '<tr><td>' . esc_html( get_date_from_gmt( $h->hit_at, 'Y-m-d H:i' ) ) . '</td><td><code>/' . esc_html( (string) $h->path ) . '/</code></td><td>' . $who . '</td></tr>'; // phpcs:ignore
				}
				echo '</tbody></table>';
			}
			?>
		</div>
		<?php
	}

	private static function tab_urls() {
		$table = new ICP_List_Table();
		$table->prepare_items();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="urls">';
		if ( ! empty( $_GET['view'] ) ) { // phpcs:ignore
			echo '<input type="hidden" name="view" value="' . esc_attr( sanitize_key( $_GET['view'] ) ) . '">'; // phpcs:ignore
		}
		$table->views();
		$table->search_box( 'Search URLs', 'icp-search' );
		echo '</form>';

		echo '<form method="post" action="' . esc_url( self::url( 'urls' ) ) . '">';
		$table->display();
		echo '</form>';

		echo '<hr><form method="post" action="' . esc_url( self::url( 'urls' ) ) . '" class="icp-danger">';
		wp_nonce_field( 'icp_clear_all' );
		echo '<input type="hidden" name="icp_do" value="clear_all">';
		echo '<button type="submit" class="button button-link-delete icp-confirm" data-msg="Delete ALL URLs and hit logs? They will stop returning 410. This cannot be undone.">Clear entire list</button>';
		echo '</form>';
	}

	private static function tab_edit() {
		$id  = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore
		$row = ICP_DB::get_row( $id );
		if ( ! $row ) {
			echo '<p>URL not found.</p>';
			return;
		}
		?>
		<h2>Edit note</h2>
		<p><code>/<?php echo esc_html( $row->path ); ?>/</code></p>
		<form method="post" action="<?php echo esc_url( self::url( 'edit', array( 'id' => $id ) ) ); ?>">
			<?php wp_nonce_field( 'icp_note_' . $id ); ?>
			<input type="hidden" name="icp_do" value="save_note"><input type="hidden" name="id" value="<?php echo (int) $id; ?>">
			<p><input type="text" name="note" class="regular-text" maxlength="255" value="<?php echo esc_attr( $row->note ); ?>"></p>
			<p><button class="button button-primary">Save</button> <a class="button" href="<?php echo esc_url( self::url( 'urls' ) ); ?>">Cancel</a></p>
		</form>
		<?php
	}

	private static function tab_import() {
		$step = isset( $_GET['step'] ) ? sanitize_key( $_GET['step'] ) : ''; // phpcs:ignore
		$res  = get_transient( ICP_Import::transient_key() );
		if ( 'preview' === $step && $res ) {
			self::render_preview( $res );
			return;
		}
		?>
		<div class="icp-panel">
			<h2>Add URLs</h2>
			<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( self::url( 'import' ) ); ?>">
				<?php wp_nonce_field( 'icp_import' ); ?>
				<input type="hidden" name="icp_do" value="preview">
				<p><label for="icp_paste"><strong>Paste URLs or paths</strong> — one per line. Full URLs (any domain) or paths like <code>/old-page/</code>.</label></p>
				<textarea id="icp_paste" name="icp_paste" rows="10" class="large-text code" placeholder="https://example.com/spam-page-1/&#10;/spam-page-2/"></textarea>
				<p><label for="icp_file"><strong>…and/or upload a file</strong> (CSV, TXT or XML sitemap, max 10 MB, up to <?php echo number_format_i18n( ICP_Import::MAX_LINES ); ?> URLs). Search Console exports work as-is — the URL column is found automatically.</label></p>
				<p><input type="file" id="icp_file" name="icp_file" accept=".csv,.txt,.tsv,.xml,text/csv,text/plain,text/xml,application/xml"></p>
				<p><label for="icp_note"><strong>Note for this batch</strong> (optional)</label><br><input type="text" id="icp_note" name="icp_note" class="regular-text" maxlength="255" placeholder="e.g. Casino spam — GSC export Nov 2026"></p>
				<p><button class="button button-primary">Check URLs</button> <span class="icp-muted">Nothing is added until you confirm on the next screen.</span></p>
			</form>
		</div>
		<div class="icp-panel">
			<h2>Safety rules</h2>
			<ul class="icp-list">
				<li>URLs that match a <strong>published page, post or product</strong> are skipped by default. You can force them on the next screen — but if a spam page exists as a real post, delete that post instead.</li>
				<li>The homepage, WordPress system paths, the REST API and WooCommerce cart/checkout/account/shop can never be added.</li>
				<li>Matching is exact. <code>/old-page/</code> does not affect <code>/old-page/child/</code>. Query strings are ignored.</li>
			</ul>
		</div>
		<?php
	}

	private static function render_preview( $res ) {
		$new   = count( $res['new'] );
		$conf  = count( $res['conflicts'] );
		$prot  = count( $res['protected'] );
		?>
		<div class="icp-panel">
			<h2>Check before importing</h2>
			<?php if ( ! empty( $res['capped'] ) ) : ?>
				<div class="notice notice-warning inline"><p>Only the first <?php echo number_format_i18n( ICP_Import::MAX_LINES ); ?> lines were read. Import the rest in another batch.</p></div>
			<?php endif; ?>
			<?php if ( $res['foreign_hosts'] ) : ?>
				<div class="notice notice-warning inline"><p><strong>Different domain:</strong>
				<?php
				$parts = array();
				foreach ( $res['foreign_hosts'] as $h => $n ) {
					$parts[] = esc_html( $h ) . ' (' . (int) $n . ')';
				}
				echo implode( ', ', $parts ); // phpcs:ignore
				?>
				. Only the path is kept and applied to <strong><?php echo esc_html( ICP_Util::site_host() ); ?></strong>. Make sure this list is for this site.</p></div>
			<?php endif; ?>
			<table class="widefat icp-summary">
				<tr><td>Lines read</td><td><?php echo number_format_i18n( (int) $res['total'] ); ?></td></tr>
				<tr><td><strong>Will be added</strong></td><td><strong><?php echo number_format_i18n( $new ); ?></strong></td></tr>
				<tr><td>Duplicates / already on the list</td><td><?php echo number_format_i18n( (int) $res['duplicates'] ); ?></td></tr>
				<tr><td>Match a published page (skipped unless forced)</td><td><?php echo number_format_i18n( $conf ); ?></td></tr>
				<tr><td>Protected paths (never added)</td><td><?php echo number_format_i18n( $prot ); ?></td></tr>
				<tr><td>Invalid lines</td><td><?php echo number_format_i18n( (int) $res['invalid'] ); ?></td></tr>
			</table>

			<form method="post" action="<?php echo esc_url( self::url( 'import' ) ); ?>">
				<?php wp_nonce_field( 'icp_confirm' ); ?>

				<?php if ( $conf ) : ?>
					<h3>Published content that matches (real-page guard)</h3>
					<p>These URLs load real content on this site. They are <strong>skipped</strong>. Tick one only if you are sure it is spam — better: delete the spam post itself.</p>
					<table class="widefat striped">
						<thead><tr><th>Force 410</th><th>URL</th><th>Title</th><th>Type</th><th>Author</th><th>Published</th></tr></thead>
						<tbody>
						<?php foreach ( $res['conflicts'] as $c ) : ?>
							<tr>
								<td><input type="checkbox" name="force[]" value="<?php echo esc_attr( md5( $c['path'] ) ); ?>"></td>
								<td><a href="<?php echo esc_url( ICP_Util::full_url( $c['path'] ) ); ?>" target="_blank" rel="noopener"><code>/<?php echo esc_html( $c['path'] ); ?>/</code></a></td>
								<td><a href="<?php echo esc_url( get_edit_post_link( $c['id'] ) ); ?>"><?php echo esc_html( $c['title'] ); ?></a></td>
								<td><?php echo esc_html( $c['type'] ); ?></td>
								<td><?php echo esc_html( $c['author'] ); ?></td>
								<td><?php echo esc_html( $c['date'] ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<?php if ( $prot ) : ?>
					<details><summary>Protected paths (<?php echo (int) $prot; ?>)</summary><ul class="icp-list">
					<?php foreach ( array_slice( $res['protected'], 0, 200 ) as $p ) : ?>
						<li><code>/<?php echo esc_html( $p['path'] ); ?></code> — <?php echo esc_html( $p['reason'] ); ?></li>
					<?php endforeach; ?>
					</ul></details>
				<?php endif; ?>

				<?php if ( $new ) : ?>
					<details><summary>Preview of URLs to add (first 100)</summary><ul class="icp-list">
					<?php foreach ( array_slice( $res['new'], 0, 100 ) as $p ) : ?>
						<li><code>/<?php echo esc_html( $p ); ?>/</code></li>
					<?php endforeach; ?>
					</ul></details>
				<?php endif; ?>

				<p class="icp-actions">
					<button name="icp_do" value="confirm" class="button button-primary" <?php disabled( 0, $new + $conf ); ?>>Import</button>
					<button name="icp_do" value="cancel" class="button">Cancel</button>
				</p>
			</form>
		</div>
		<?php
	}


	private static function rule_label( $type ) {
		$l = array( 'prefix' => 'starts with', 'contains' => 'contains', 'regex' => 'regex' );
		return isset( $l[ $type ] ) ? $l[ $type ] : $type;
	}

	private static function tab_rules() {
		$rt = isset( $_GET['rt'] ) ? sanitize_key( $_GET['rt'] ) : 'prefix'; // phpcs:ignore
		$rp = isset( $_GET['rp'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['rp'] ) ) ) : ''; // phpcs:ignore
		?>
		<div class="icp-panel">
			<h2>Add a rule</h2>
			<p>One rule removes a whole group of spam URLs, including ones you have not found yet. Matching URLs return <strong>410 Gone</strong>. Real published pages and protected paths are always skipped.</p>
			<form method="post" action="<?php echo esc_url( self::url( 'rules' ) ); ?>">
				<?php wp_nonce_field( 'icp_rule' ); ?>
				<p>
					<select name="rtype">
						<option value="prefix" <?php selected( 'prefix', $rt ); ?>>URL starts with</option>
						<option value="contains" <?php selected( 'contains', $rt ); ?>>URL contains</option>
						<option value="regex" <?php selected( 'regex', $rt ); ?>>Regular expression</option>
					</select>
					<input type="text" name="pattern" value="<?php echo esc_attr( $rp ); ?>" class="regular-text" placeholder="category/giochi/" required>
					<input type="text" name="note" class="regular-text" placeholder="Note (optional)" maxlength="255">
				</p>
				<p>
					<button name="icp_do" value="rule_preview" class="button">Preview</button>
					<button name="icp_do" value="rule_add" class="button button-primary">Add rule</button>
				</p>
			</form>
			<details>
				<summary>Examples</summary>
				<ul class="icp-list">
					<li><strong>Starts with</strong> <code>category/giochi/</code> – everything under that folder.</li>
					<li><strong>Contains</strong> <code>casino</code> – any URL with that word in it.</li>
					<li><strong>Regex</strong> <code>^[a-z]{2}-[0-9]{4,}$</code> – advanced; applied to the path without slashes, case-insensitive.</li>
				</ul>
				<p class="icp-muted">Rules are checked only for URLs that are not already on your list. Use <strong>Preview</strong> first: it shows what is matched in the 404 log and which real pages would be protected. Rule matches are not in <code>dead-urls.xml</code>; add the actual URLs from the 404 Monitor or an import if you want Google to be nudged to recrawl them.</p>
			</details>
		</div>

		<div class="icp-panel">
			<h2>Your rules</h2>
			<?php
			$rules = ICP_Rules::all();
			if ( ! $rules ) {
				echo '<p class="icp-muted">No rules yet.</p>';
			} else {
				echo '<table class="widefat striped"><thead><tr><th>Type</th><th>Pattern</th><th>Note</th><th>410s served</th><th>Last hit</th><th>Status</th><th></th></tr></thead><tbody>';
				foreach ( $rules as $r ) {
					$tg = wp_nonce_url( self::url( 'rules', array( 'icp_action' => 'rule_toggle', 'id' => $r->id ) ), 'icp_rule_' . $r->id );
					$dl = wp_nonce_url( self::url( 'rules', array( 'icp_action' => 'rule_delete', 'id' => $r->id ) ), 'icp_rule_' . $r->id );
					printf(
						'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td><a href="%s">%s</a> | <a href="%s" onclick="return confirm(\'Delete this rule?\')">Delete</a></td></tr>',
						esc_html( self::rule_label( $r->rtype ) ),
						esc_html( $r->pattern ),
						esc_html( $r->note ),
						esc_html( number_format_i18n( (int) $r->hits ) ),
						esc_html( $r->last_hit ? get_date_from_gmt( $r->last_hit, 'Y-m-d H:i' ) : '–' ),
						$r->active ? '<span class="icp-badge icp-ok">Active</span>' : '<span class="icp-badge icp-warn">Paused</span>',
						esc_url( $tg ),
						$r->active ? 'Pause' : 'Resume',
						esc_url( $dl )
					); // phpcs:ignore
				}
				echo '</tbody></table>';
			}
			?>
		</div>
		<?php
	}

	private static function tab_monitor() {
		$min = isset( $_GET['min'] ) ? max( 0, min( 100, absint( $_GET['min'] ) ) ) : 40; // phpcs:ignore
		$c   = ICP_Monitor::counts();
		$on  = (int) ICP_DB::setting( 'monitor_enabled' );
		$rows = ICP_Monitor::rows( $min, 200 );
		?>
		<div class="icp-panel">
			<h2>404 Monitor</h2>
			<p>Lists addresses that visitors and bots requested but that do not exist, with a spam score. Spam URLs missing from your Search Console export often show up here. Review the list, then add the real spam to the dead list.</p>
			<p>
				<?php echo $on ? '<span class="icp-badge icp-ok">Logging</span>' : '<span class="icp-badge icp-warn">Off</span> (enable in Settings)'; ?>
				&nbsp;<?php echo esc_html( number_format_i18n( (int) $c->fresh ) ); ?> new URLs logged · <?php echo esc_html( number_format_i18n( (int) $c->likely ) ); ?> with score 40+
			</p>
			<form method="get" action="<?php echo esc_url( admin_url( 'tools.php' ) ); ?>">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>"><input type="hidden" name="tab" value="monitor">
				Show score at least <input type="number" name="min" min="0" max="100" value="<?php echo (int) $min; ?>" class="small-text">
				<button class="button">Filter</button>
				<span class="icp-muted">0 = everything · 20+ suspicious · 40+ likely spam · 60+ high</span>
			</form>
		</div>

		<form method="post" action="<?php echo esc_url( self::url( 'monitor' ) ); ?>" class="icp-panel">
			<?php wp_nonce_field( 'icp_monitor' ); ?>
			<input type="hidden" name="icp_do" value="monitor_act">
			<input type="hidden" name="min" value="<?php echo (int) $min; ?>">
			<?php if ( ! $rows ) : ?>
				<p class="icp-muted">Nothing at this score level yet. Real 404s appear here as visitors and bots hit them.</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><td class="check-column"><input type="checkbox" onclick="jQuery('.icp-m-cb').prop('checked',this.checked)"></td><th>URL</th><th>Score</th><th>Why</th><th>Hits</th><th>Last seen</th><th>Visitor</th></tr></thead>
					<tbody>
					<?php
					foreach ( $rows as $r ) {
						list( $lab, $cls ) = ICP_Spam::label( (int) $r->score );
						printf(
							'<tr><th class="check-column"><input type="checkbox" class="icp-m-cb" name="ids[]" value="%d"></th><td><code>/%s/</code></td><td><span class="icp-badge %s">%d %s</span></td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
							(int) $r->id,
							esc_html( $r->path ),
							esc_attr( $cls ),
							(int) $r->score,
							esc_html( $lab ),
							esc_html( $r->reasons ),
							esc_html( number_format_i18n( (int) $r->hits ) ),
							esc_html( get_date_from_gmt( $r->last_seen, 'Y-m-d H:i' ) ),
							esc_html( wp_trim_words( $r->last_ua, 6, '…' ) )
						); // phpcs:ignore
					}
					?>
					</tbody>
				</table>
				<p class="icp-muted">Showing the top 200 for this filter.</p>
			<?php endif; ?>
			<p class="icp-actions">
				<button name="m_act" value="add" class="button button-primary" <?php disabled( ! $rows ); ?>>Add selected to dead list</button>
				<button name="m_act" value="ignore" class="button" <?php disabled( ! $rows ); ?>>Hide selected (not spam)</button>
				<button name="m_act" value="add_score" class="button" onclick="return confirm('Add ALL logged URLs with score <?php echo (int) $min; ?> or more to the dead list?')">Add everything with score <?php echo (int) $min; ?>+</button>
				<button name="m_act" value="clear" class="button" onclick="return confirm('Clear the whole 404 log?')">Clear log</button>
			</p>
			<p class="icp-muted">Added URLs go through the same safety checks as an import (protected paths and real pages are skipped).</p>
		</form>
		<?php
	}

	private static function tab_settings() {
		$s = ICP_DB::settings();
		?>
		<form method="post" action="<?php echo esc_url( self::url( 'settings' ) ); ?>" class="icp-panel">
			<?php wp_nonce_field( 'icp_settings' ); ?>
			<input type="hidden" name="icp_do" value="save_settings">
			<table class="form-table" role="presentation">
				<tr><th scope="row">Dead-URL sitemap</th><td>
					<label><input type="checkbox" name="sitemap_enabled" value="1" <?php checked( 1, (int) $s['sitemap_enabled'] ); ?>> Serve <code>/dead-urls.xml</code></label>
					<p class="description">Lists every URL with a &lt;lastmod&gt; date so Google recrawls them sooner. Over 50,000 URLs it becomes an index of <code>dead-urls-1.xml</code>, <code>dead-urls-2.xml</code>…</p>
				</td></tr>
				<tr><th scope="row"><label for="sitemap_until">Sitemap end date</label></th><td>
					<input type="date" id="sitemap_until" name="sitemap_until" value="<?php echo esc_attr( $s['sitemap_until'] ); ?>">
					<p class="description">The sitemap switches itself off after this date (UTC). Leave empty for no end date. 3–4 weeks is usually enough.</p>
				</td></tr>
				<tr><th scope="row"><label for="log_retention_days">Keep hit log for</label></th><td>
					<input type="number" min="1" max="365" id="log_retention_days" name="log_retention_days" value="<?php echo (int) $s['log_retention_days']; ?>" class="small-text"> days
					<p class="description">Per-URL counters are kept; only the detailed log is pruned. IP addresses are stored only as a salted hash.</p>
				</td></tr>
				<tr><th scope="row">Visitor IP source</th><td>
					<fieldset>
						<label><input type="radio" name="ip_source" value="remote_addr" <?php checked( 'remote_addr', $s['ip_source'] ); ?>> Direct connection (default)</label><br>
						<label><input type="radio" name="ip_source" value="cf" <?php checked( 'cf', $s['ip_source'] ); ?>> Cloudflare (<code>CF-Connecting-IP</code>)</label><br>
						<label><input type="radio" name="ip_source" value="xff" <?php checked( 'xff', $s['ip_source'] ); ?>> Proxy / load balancer (<code>X-Forwarded-For</code>)</label>
					</fieldset>
					<p class="description">Used only to verify Googlebot. Change it only if the site is behind Cloudflare or a proxy, otherwise Googlebot can't be verified.</p>
				</td></tr>
				<tr><th scope="row">Page cache</th><td>
					<label><input type="checkbox" name="purge_cache" value="1" <?php checked( 1, (int) $s['purge_cache'] ); ?>> Purge the page cache when the list changes</label>
					<p class="description">Supports WP Fastest Cache, LiteSpeed Cache, W3 Total Cache, WP Rocket and WP Super Cache. Needed so cached copies of listed URLs stop being served.</p>
				</td></tr>
				<tr><th scope="row">404 monitor</th><td>
					<label><input type="checkbox" name="monitor_enabled" value="1" <?php checked( 1, (int) $s['monitor_enabled'] ); ?>> Log URLs that return 404 and score them for spam</label>
					<p class="description">Stores the path, hit count and visitor agent (no IP address). Capped at <?php echo (int) $s['monitor_max']; ?> URLs; low-score entries are removed after 90 days.</p>
				</td></tr>
				<tr><th scope="row">Search spam</th><td>
					<fieldset>
						<label><input type="radio" name="search_mode" value="off" <?php checked( 'off', $s['search_mode'] ); ?>> Off</label><br>
						<label><input type="radio" name="search_mode" value="spam" <?php checked( 'spam', $s['search_mode'] ); ?>> Block spam search phrases with 410 + noindex (recommended)</label><br>
						<label><input type="radio" name="search_mode" value="all" <?php checked( 'all', $s['search_mode'] ); ?>> Also set <code>noindex</code> on every search results page</label>
					</fieldset>
					<p>Spam score needed to block: <input type="number" min="20" max="100" name="search_threshold" value="<?php echo (int) $s['search_threshold']; ?>" class="small-text"> <span class="description">40 = one clear spam word (casino, viagra…) or Japanese/Chinese text on a non-Japanese site. Lower blocks more.</span></p>
					<p><label for="spam_words">Extra spam words (comma or space separated)</label><br><textarea id="spam_words" name="spam_words" rows="2" class="large-text"><?php echo esc_textarea( $s['spam_words'] ); ?></textarea></p>
					<p class="description">Normal visitor searches are not affected. Googlebot sees a 410 for spam phrases, which removes them from the index.</p>
				</td></tr>
				<tr><th scope="row">Weekly re-test &amp; alert</th><td>
					<label><input type="checkbox" name="retest_enabled" value="1" <?php checked( 1, (int) $s['retest_enabled'] ); ?>> Re-test every listed URL once a week in the background</label><br>
					<label><input type="checkbox" name="alert_enabled" value="1" <?php checked( 1, (int) $s['alert_enabled'] ); ?>> Email me if any URL stops returning 410</label><br>
					<p><label for="alert_email">Send alerts to</label> <input type="email" id="alert_email" name="alert_email" value="<?php echo esc_attr( $s['alert_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" class="regular-text"></p>
					<p class="description">Uses WP-Cron. Leave the address empty to use the site admin email.</p>
				</td></tr>
				<tr><th scope="row">Uninstall</th><td>
					<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( 1, (int) $s['delete_on_uninstall'] ); ?>> Delete all plugin data when the plugin is deleted</label>
				</td></tr>
			</table>
			<p><button class="button button-primary">Save settings</button></p>
		</form>
		<form method="post" action="<?php echo esc_url( self::url( 'settings' ) ); ?>" class="icp-panel">
			<?php wp_nonce_field( 'icp_retest' ); ?>
			<button name="icp_do" value="test_alert" class="button">Send a test alert email</button>
			<span class="icp-muted">to <?php echo esc_html( ICP_Tester::alert_address() ); ?> (save settings first if you changed the address)</span>
		</form>
		<?php
	}
}
