<?php
/**
 * Admin screens: Dashboard, URLs, Add / Import, Settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_Admin {

	const SLUG = 'index-cleanup-pro';
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
		self::$hook = add_management_page( 'Index Cleanup Pro', 'Index Cleanup Pro', self::CAP, self::SLUG, array( __CLASS__, 'render' ) );
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
		return 'Index Cleanup Pro ' . esc_html( ICP_VERSION ) . ' &middot; built by <a href="https://bestlocalseoservices.com/" target="_blank" rel="noopener">BLSS – Best Local SEO Services &amp; Development</a>';
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
			list( $code, $note ) = self::test_url( ICP_Util::full_url( $r->path ), $token );
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

	private static function test_url( $url, $token ) {
		$resp = wp_remote_get(
			$url,
			array(
				'timeout'             => 15,
				'redirection'         => 0,
				'limit_response_size' => 8192,
				'user-agent'          => 'IndexCleanupPro-StatusTest/' . ICP_VERSION . '; ' . home_url( '/' ),
				'headers'             => array( 'X-ICP-Test' => $token ),
				'sslverify'           => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
		if ( is_wp_error( $resp ) ) {
			return array( 0, 'error: ' . $resp->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $resp );
		$body = (string) wp_remote_retrieve_body( $resp );
		if ( 410 === $code ) {
			return array( 410, 'OK' );
		}
		if ( $code >= 300 && $code < 400 ) {
			$loc = wp_remote_retrieve_header( $resp, 'location' );
			return array( $code, 'redirects to ' . ( $loc ? $loc : '?' ) . ' — Google keeps redirected URLs' );
		}
		$challenge = preg_match( '/One moment, please|captcha|cf-chl|challenge-platform|Just a moment|imunify|bot verification/i', $body );
		if ( in_array( $code, array( 403, 429, 503 ), true ) || ( 200 === $code && $challenge ) ) {
			return array( $code, 'blocked by host (firewall/bot check) — check this URL in a browser' );
		}
		if ( 404 === $code ) {
			return array( 404, 'returns 404 not 410 — plugin not reached (cache or server rule?)' );
		}
		if ( 200 === $code ) {
			return array( 200, 'page loads (200) — a cached copy or a real page; purge cache and check' );
		}
		return array( $code, 'unexpected status' );
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
			'settings'  => 'Settings',
		);
		echo '<div class="wrap icp-wrap"><h1>Index Cleanup Pro</h1>';
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
				<tr><th scope="row">Uninstall</th><td>
					<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( 1, (int) $s['delete_on_uninstall'] ); ?>> Delete all plugin data when the plugin is deleted</label>
				</td></tr>
			</table>
			<p><button class="button button-primary">Save settings</button></p>
		</form>
		<?php
	}
}
