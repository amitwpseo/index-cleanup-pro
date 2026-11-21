<?php
/**
 * URL list table.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class ICP_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'icp_url',
				'plural'   => 'icp_urls',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'         => '<input type="checkbox" />',
			'path'       => 'URL',
			'note'       => 'Note',
			'date_added' => 'Added',
			'lastmod'    => 'Lastmod',
			'hits'       => '410s served',
			'last_gbot'  => 'Googlebot last visit',
			'test'       => 'Last test',
		);
	}

	protected function get_sortable_columns() {
		return array(
			'path'       => array( 'path', false ),
			'date_added' => array( 'date_added', true ),
			'lastmod'    => array( 'lastmod', false ),
			'hits'       => array( 'hits', false ),
			'last_gbot'  => array( 'last_gbot', false ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'icp_delete'  => 'Remove from list',
			'icp_restamp' => 'Re-stamp lastmod (ask for recrawl)',
		);
	}

	protected function get_views() {
		global $wpdb;
		$t       = ICP_DB::urls_table();
		$current = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'all'; // phpcs:ignore
		$base    = admin_url( 'tools.php?page=hacked-site-index-cleaner&tab=urls' );
		$counts  = array(
			'all'      => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ),
			'gbot'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE gbot_hits > 0" ),
			'nogbot'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE gbot_hits = 0" ),
			'failing'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE test_code <> 0 AND test_code <> 410" ),
			'override' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE override = 1" ),
		);
		$labels  = array(
			'all'      => 'All',
			'gbot'     => 'Recrawled by Google',
			'nogbot'   => 'Not recrawled yet',
			'failing'  => 'Test failing',
			'override' => 'Forced (override)',
		);
		$views   = array();
		foreach ( $labels as $k => $l ) {
			$url         = 'all' === $k ? $base : add_query_arg( 'view', $k, $base );
			$cls         = $current === $k ? ' class="current"' : '';
			$views[ $k ] = sprintf( '<a href="%s"%s>%s <span class="count">(%d)</span></a>', esc_url( $url ), $cls, esc_html( $l ), $counts[ $k ] );
		}
		return $views;
	}

	public function prepare_items() {
		global $wpdb;
		$t        = ICP_DB::urls_table();
		$per_page = 50;
		$paged    = $this->get_pagenum();
		$where    = array( '1=1' );
		$args     = array();

		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'all'; // phpcs:ignore
		if ( 'gbot' === $view ) {
			$where[] = 'gbot_hits > 0';
		} elseif ( 'nogbot' === $view ) {
			$where[] = 'gbot_hits = 0';
		} elseif ( 'failing' === $view ) {
			$where[] = 'test_code <> 0 AND test_code <> 410';
		} elseif ( 'override' === $view ) {
			$where[] = 'override = 1';
		}

		$s = isset( $_REQUEST['s'] ) ? trim( wp_unslash( $_REQUEST['s'] ) ) : ''; // phpcs:ignore
		if ( '' !== $s ) {
			$like    = '%' . $wpdb->esc_like( ICP_Util::clean( $s ) ) . '%';
			$where[] = '(path LIKE %s OR note LIKE %s)';
			$args[]  = $like;
			$args[]  = '%' . $wpdb->esc_like( $s ) . '%';
		}

		$allowed = array( 'path', 'date_added', 'lastmod', 'hits', 'last_gbot' );
		$orderby = isset( $_GET['orderby'] ) && in_array( $_GET['orderby'], $allowed, true ) ? $_GET['orderby'] : 'id'; // phpcs:ignore
		$order   = isset( $_GET['order'] ) && 'asc' === strtolower( $_GET['order'] ) ? 'ASC' : 'DESC'; // phpcs:ignore

		$w     = implode( ' AND ', $where );
		$csql  = "SELECT COUNT(*) FROM {$t} WHERE {$w}";
		$total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $csql, $args ) ) : $wpdb->get_var( $csql ) );

		$qargs   = array_merge( $args, array( $per_page, ( $paged - 1 ) * $per_page ) );
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$w} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d", $qargs ) );

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	public function no_items() {
		echo 'No URLs yet. Use the Add / Import tab to add some.';
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item->id );
	}

	protected function column_path( $item ) {
		$url  = ICP_Util::full_url( $item->path );
		$out  = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer"><code>/' . esc_html( $item->path ) . '/</code></a>';
		if ( (int) $item->override ) {
			$out .= ' <span class="icp-badge icp-warn" title="Added despite matching a published page">forced</span>';
		}
		$base    = admin_url( 'tools.php?page=hacked-site-index-cleaner&tab=urls' );
		$del     = wp_nonce_url( add_query_arg( array( 'icp_action' => 'delete_one', 'id' => $item->id ), $base ), 'icp_row_' . $item->id );
		$note    = add_query_arg( array( 'tab' => 'edit', 'id' => $item->id ), admin_url( 'tools.php?page=hacked-site-index-cleaner' ) );
		$actions = array(
			'edit'   => '<a href="' . esc_url( $note ) . '">Edit note</a>',
			'delete' => '<a href="' . esc_url( $del ) . '" class="icp-confirm" data-msg="Remove this URL from the list? It will stop returning 410.">Remove</a>',
		);
		return $out . $this->row_actions( $actions );
	}

	protected function column_note( $item ) {
		return esc_html( $item->note );
	}

	private function dt( $v ) {
		if ( ! $v ) {
			return '<span class="icp-muted">—</span>';
		}
		return esc_html( get_date_from_gmt( $v, 'Y-m-d H:i' ) );
	}

	protected function column_date_added( $item ) {
		return $this->dt( $item->date_added );
	}

	protected function column_lastmod( $item ) {
		return $this->dt( $item->lastmod );
	}

	protected function column_hits( $item ) {
		return number_format_i18n( (int) $item->hits );
	}

	protected function column_last_gbot( $item ) {
		if ( ! $item->last_gbot ) {
			return '<span class="icp-muted">Not yet</span>';
		}
		return $this->dt( $item->last_gbot ) . ' <span class="icp-muted">(' . (int) $item->gbot_hits . '×)</span>';
	}

	protected function column_test( $item ) {
		$code = (int) $item->test_code;
		if ( ! $code && ! $item->test_note ) {
			return '<span class="icp-muted">Not tested</span>';
		}
		$cls = 410 === $code ? 'icp-ok' : ( 'blocked' === substr( $item->test_note, 0, 7 ) ? 'icp-warn' : 'icp-bad' );
		$lab = $code ? $code : 'error';
		return '<span class="icp-badge ' . $cls . '">' . esc_html( $lab ) . '</span> <span class="icp-muted">' . esc_html( $item->test_note ) . '</span>';
	}
}
