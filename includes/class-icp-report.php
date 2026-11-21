<?php
/**
 * Client progress report: daily snapshots, trend, printable/PDF page and
 * scheduled email (weekly, monthly or quarterly).
 *
 * The report shows results only: no IP addresses and no technical error details,
 * so it is safe to send to a client.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_Report {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'icp_snap';
	}

	/* ------------------------------------------------------------------ */
	/* Snapshots (one row per day)                                         */
	/* ------------------------------------------------------------------ */

	public static function snapshot() {
		global $wpdb;
		$st = ICP_DB::stats();
		if ( ! $st || ! (int) $st->total ) {
			return;
		}
		$wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				'INSERT INTO ' . self::table() . ' (snap_date, total, hits, gbot_seen, test_ok, test_bad) VALUES (%s,%d,%d,%d,%d,%d)
				 ON DUPLICATE KEY UPDATE total = VALUES(total), hits = VALUES(hits), gbot_seen = VALUES(gbot_seen), test_ok = VALUES(test_ok), test_bad = VALUES(test_bad)',
				gmdate( 'Y-m-d' ),
				(int) $st->total,
				(int) $st->hits,
				(int) $st->gbot_seen,
				(int) $st->test_ok,
				(int) $st->test_bad
			)
		);
	}

	public static function history( $days = 400 ) {
		global $wpdb;
		$from = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE snap_date >= %s ORDER BY snap_date ASC', $from ), ARRAY_A ); // phpcs:ignore
	}

	/** Daily job: save today's numbers and send the scheduled email if it is due. */
	public static function daily() {
		self::snapshot();
		self::maybe_send();
	}

	/* ------------------------------------------------------------------ */
	/* Data                                                                */
	/* ------------------------------------------------------------------ */

	public static function options() {
		$s = ICP_DB::settings();
		return array(
			'prepared_for' => (string) $s['report_prepared_for'],
			'brand'        => ! empty( $s['report_brand'] ),
			'company'      => '' !== trim( (string) $s['report_company'] ) ? (string) $s['report_company'] : 'BLSS – Best Local SEO Services & Development',
			'logo'         => (string) $s['report_logo'],
			'site'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'          => home_url( '/' ),
		);
	}

	/** Live numbers plus history, ready to render. */
	public static function data() {
		$st = ICP_DB::stats();
		return array(
			'total'     => (int) $st->total,
			'hits'      => (int) $st->hits,
			'seen'      => (int) $st->gbot_seen,
			'test_ok'   => (int) $st->test_ok,
			'test_bad'  => (int) $st->test_bad,
			'untested'  => (int) $st->untested,
			'history'   => self::history(),
			'generated' => gmdate( 'Y-m-d' ),
		);
	}

	/**
	 * Weekly rows from daily snapshots: the last snapshot of each ISO week.
	 * @return array[] each [ date, total, hits, seen, pct ]
	 */
	public static function weekly( array $history, $limit = 12 ) {
		$weeks = array();
		foreach ( $history as $h ) {
			$ts  = strtotime( $h['snap_date'] . ' 00:00:00 UTC' );
			$key = gmdate( 'o-W', $ts );
			$weeks[ $key ] = array(
				'date'  => $h['snap_date'],
				'total' => (int) $h['total'],
				'hits'  => (int) $h['hits'],
				'seen'  => (int) $h['gbot_seen'],
				'pct'   => (int) $h['total'] ? round( 100 * (int) $h['gbot_seen'] / (int) $h['total'], 1 ) : 0,
			);
		}
		return array_slice( array_values( $weeks ), -$limit );
	}

	public static function summary( array $d ) {
		$pct = $d['total'] ? round( 100 * $d['seen'] / $d['total'], 1 ) : 0;
		$out = sprintf(
			'We are serving a permanent "removed" answer (HTTP 410) for %s spam URLs left behind by the hack. Google has revisited %s of them so far (%s%%). Each time Google rechecks a page and sees the 410, it drops that page from search results. Google decides when it comes back, so this usually takes several weeks.',
			number_format_i18n( $d['total'] ),
			number_format_i18n( $d['seen'] ),
			$pct
		);
		$w = self::weekly( $d['history'], 52 );
		if ( count( $w ) >= 2 ) {
			$first = $w[0];
			$last  = $w[ count( $w ) - 1 ];
			if ( $last['seen'] > $first['seen'] ) {
				$out .= sprintf( ' Since %s, Google revisits have grown from %s to %s.', $first['date'], number_format_i18n( $first['seen'] ), number_format_i18n( $last['seen'] ) );
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* SVG line chart (pure)                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array  $points [ [ label, value ], ... ]
	 * @param string $color  hex colour
	 * @param float  $max    fixed axis maximum, or 0 for automatic
	 */
	public static function chart( array $points, $color, $max = 0, $suffix = '' ) {
		$w = 420;
		$h = 150;
		$l = 38;
		$r = 14;
		$t = 14;
		$b = 26;
		$n = count( $points );
		if ( $n < 2 ) {
			return '<div style="padding:28px 12px;text-align:center;color:#6b7280;font-size:13px;border:1px dashed #d1d5db;border-radius:8px">The trend line appears after the second daily snapshot.</div>';
		}
		$vals = array_map( 'floatval', array_column( $points, 1 ) );
		$top  = $max > 0 ? $max : max( 1, max( $vals ) );
		$pw   = $w - $l - $r;
		$ph   = $h - $t - $b;
		$xy   = array();
		foreach ( $vals as $i => $v ) {
			$x    = $l + $pw * $i / ( $n - 1 );
			$y    = $t + $ph - $ph * min( $v, $top ) / $top;
			$xy[] = array( round( $x, 1 ), round( $y, 1 ) );
		}
		$line = implode( ' ', array_map( function ( $p ) {
			return $p[0] . ',' . $p[1];
		}, $xy ) );
		$area = $l . ',' . ( $t + $ph ) . ' ' . $line . ' ' . ( $l + $pw ) . ',' . ( $t + $ph );
		$id   = 'g' . substr( md5( $color . $line ), 0, 6 );

		$svg  = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" width="100%" role="img" xmlns="http://www.w3.org/2000/svg" style="display:block;max-width:100%;height:auto">';
		$svg .= '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="' . $color . '" stop-opacity=".22"/><stop offset="1" stop-color="' . $color . '" stop-opacity="0"/></linearGradient></defs>';
		foreach ( array( 0, .5, 1 ) as $g ) {
			$gy   = round( $t + $ph - $ph * $g, 1 );
			$lab  = $top * $g;
			$lab  = $lab >= 1000 ? round( $lab / 1000, 1 ) . 'k' : ( floor( $lab ) == $lab ? (string) (int) $lab : (string) round( $lab, 1 ) );
			$svg .= '<line x1="' . $l . '" y1="' . $gy . '" x2="' . ( $w - $r ) . '" y2="' . $gy . '" stroke="#e5e7eb" stroke-width="1"/>';
			$svg .= '<text x="' . ( $l - 6 ) . '" y="' . ( $gy + 4 ) . '" text-anchor="end" font-size="10" fill="#6b7280" font-family="system-ui,sans-serif">' . esc_html( $lab . $suffix ) . '</text>';
		}
		$svg .= '<polygon points="' . $area . '" fill="url(#' . $id . ')"/>';
		$svg .= '<polyline points="' . $line . '" fill="none" stroke="' . $color . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';
		$last = $xy[ $n - 1 ];
		$svg .= '<circle cx="' . $last[0] . '" cy="' . $last[1] . '" r="4" fill="#fff" stroke="' . $color . '" stroke-width="2.5"/>';
		$svg .= '<text x="' . $l . '" y="' . ( $h - 8 ) . '" font-size="10" fill="#6b7280" font-family="system-ui,sans-serif">' . esc_html( $points[0][0] ) . '</text>';
		$svg .= '<text x="' . ( $w - $r ) . '" y="' . ( $h - 8 ) . '" text-anchor="end" font-size="10" fill="#6b7280" font-family="system-ui,sans-serif">' . esc_html( $points[ $n - 1 ][0] ) . '</text>';
		$svg .= '</svg>';
		return $svg;
	}

	/* ------------------------------------------------------------------ */
	/* HTML (inline styles only, so it also works in email)                */
	/* ------------------------------------------------------------------ */

	/**
	 * @param array  $d    result of data()
	 * @param array  $o    result of options()
	 * @param string $mode 'page' (screen/print, with charts) or 'email' (no SVG)
	 */
	public static function render( array $d, array $o, $mode = 'page' ) {
		$font = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";
		$pct  = $d['total'] ? round( 100 * $d['seen'] / $d['total'], 1 ) : 0;
		$week = self::weekly( $d['history'], 12 );

		if ( $d['test_bad'] > 0 ) {
			$check = array( number_format_i18n( $d['test_bad'] ) . ' to review', '#b45309', 'Some URLs did not return 410 in the latest check and are being looked at.' );
		} elseif ( $d['untested'] > 0 ) {
			$check = array( 'Pending', '#6b7280', 'A full status check has not been completed yet.' );
		} else {
			$check = array( 'All confirmed', '#15803d', 'Every listed URL returned 410 in the latest check.' );
		}

		$card = function ( $label, $value, $sub, $color ) use ( $font ) {
			return '<td style="width:25%;padding:0 6px;vertical-align:top"><div style="background:#fff;border:1px solid #e5e7eb;border-top:3px solid ' . $color . ';border-radius:10px;padding:14px 14px 12px;min-height:112px">'
				. '<div style="font:600 11px/1.2 ' . $font . ';text-transform:uppercase;letter-spacing:.06em;color:#6b7280">' . esc_html( $label ) . '</div>'
				. '<div style="font:700 26px/1.25 ' . $font . ';color:#111827;margin-top:6px">' . $value . '</div>'
				. '<div style="font:400 12px/1.4 ' . $font . ';color:#6b7280;margin-top:2px">' . esc_html( $sub ) . '</div></div></td>';
		};

		$html  = '<div style="background:#f3f4f6;padding:24px 12px;font-family:' . $font . ';color:#111827"><div style="max-width:760px;margin:0 auto;background:#f9fafb;border-radius:14px;overflow:hidden;border:1px solid #e5e7eb">';

		// Header.
		$html .= '<div style="background:#0f2a43;background-image:linear-gradient(135deg,#0f2a43,#1d4e89);color:#fff;padding:26px 28px">';
		if ( $o['brand'] && $o['logo'] ) {
			$html .= '<img src="' . esc_url( $o['logo'] ) . '" alt="" style="max-height:34px;max-width:200px;margin-bottom:12px;display:block">';
		}
		$html .= '<div style="font:600 12px/1 ' . $font . ';letter-spacing:.12em;text-transform:uppercase;opacity:.75">Search cleanup progress report</div>';
		$html .= '<div style="font:700 24px/1.25 ' . $font . ';margin-top:8px">' . esc_html( $o['site'] ) . '</div>';
		$meta  = 'Report date: ' . esc_html( $d['generated'] );
		if ( '' !== $o['prepared_for'] ) {
			$meta .= ' &nbsp;·&nbsp; Prepared for ' . esc_html( $o['prepared_for'] );
		}
		$html .= '<div style="font:400 13px/1.4 ' . $font . ';opacity:.8;margin-top:6px">' . $meta . '</div></div>';

		$html .= '<div style="padding:22px 22px 8px">';

		// KPI cards.
		$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:separate;margin:0 -6px;width:calc(100% + 12px)"><tr>';
		$html .= $card( 'URLs on cleanup list', esc_html( number_format_i18n( $d['total'] ) ), 'spam addresses being removed', '#2563eb' );
		$html .= $card( 'Removed answers served', esc_html( number_format_i18n( $d['hits'] ) ), 'times a visitor or bot got "410 Gone"', '#7c3aed' );
		$html .= $card( 'Revisited by Google', esc_html( number_format_i18n( $d['seen'] ) ) . ' <span style="font-size:14px;color:#6b7280;font-weight:500">(' . esc_html( $pct ) . '%)</span>', 'confirmed Google visits', '#0891b2' );
		$html .= $card( 'Latest check', '<span style="font-size:18px;color:' . $check[1] . '">' . esc_html( $check[0] ) . '</span>', $check[2], $check[1] );
		$html .= '</tr></table>';

		// Summary.
		$html .= '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin:16px 0;font:400 14px/1.6 ' . $font . ';color:#374151"><div style="font:700 13px/1 ' . $font . ';color:#111827;margin-bottom:8px">In plain words</div>' . esc_html( self::summary( $d ) ) . '</div>';

		// Charts (page only).
		if ( 'page' === $mode ) {
			$p1 = array();
			$p2 = array();
			foreach ( $week as $w ) {
				$lab  = gmdate( 'M j', strtotime( $w['date'] . ' UTC' ) );
				$p1[] = array( $lab, $w['pct'] );
				$p2[] = array( $lab, $w['hits'] );
			}
			$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="margin:0 -6px;width:calc(100% + 12px)"><tr>';
			$html .= '<td style="width:50%;padding:0 6px;vertical-align:top"><div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px"><div style="font:700 13px/1 ' . $font . ';margin-bottom:10px">Google revisits (% of URLs)</div>' . self::chart( $p1, '#0891b2', 100, '%' ) . '</div></td>';
			$html .= '<td style="width:50%;padding:0 6px;vertical-align:top"><div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px"><div style="font:700 13px/1 ' . $font . ';margin-bottom:10px">Removed answers served (total)</div>' . self::chart( $p2, '#7c3aed', 0, '' ) . '</div></td>';
			$html .= '</tr></table>';
		}

		// Weekly table.
		$html .= '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;margin:16px 0;overflow:hidden"><div style="padding:14px 16px 6px;font:700 13px/1 ' . $font . '">Week by week</div>';
		if ( $week ) {
			$html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font:400 13px/1.4 ' . $font . '"><tr style="background:#f9fafb;color:#6b7280;text-align:right"><th style="text-align:left;padding:8px 16px;font-weight:600">Week ending</th><th style="padding:8px 12px;font-weight:600">URLs listed</th><th style="padding:8px 12px;font-weight:600">Removed answers</th><th style="padding:8px 12px;font-weight:600">Revisited by Google</th><th style="padding:8px 16px;font-weight:600">%</th></tr>';
			foreach ( array_reverse( $week ) as $w ) {
				$html .= '<tr style="border-top:1px solid #f1f5f9;text-align:right"><td style="text-align:left;padding:8px 16px">' . esc_html( $w['date'] ) . '</td><td style="padding:8px 12px">' . esc_html( number_format_i18n( $w['total'] ) ) . '</td><td style="padding:8px 12px">' . esc_html( number_format_i18n( $w['hits'] ) ) . '</td><td style="padding:8px 12px">' . esc_html( number_format_i18n( $w['seen'] ) ) . '</td><td style="padding:8px 16px;font-weight:600">' . esc_html( $w['pct'] ) . '%</td></tr>';
			}
			$html .= '</table>';
		} else {
			$html .= '<div style="padding:6px 16px 16px;font:400 13px/1.5 ' . $font . ';color:#6b7280">History starts with the first daily snapshot.</div>';
		}
		$html .= '</div>';

		// Next steps.
		$html .= '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px 18px;margin:0 0 16px;font:400 13px/1.6 ' . $font . ';color:#374151"><div style="font:700 13px/1 ' . $font . ';color:#111827;margin-bottom:8px">What happens next</div>'
			. '1. Google keeps revisiting the removed addresses and drops them from search results as it confirms each one.<br>'
			. '2. We keep the removal active and monitor progress. If anything stops working you are alerted.<br>'
			. '3. After a few weeks the temporary list given to Google is switched off. The removed addresses stay removed.</div>';

		$html .= '</div>';

		// Footer.
		$html .= '<div style="padding:14px 22px 20px;border-top:1px solid #e5e7eb;font:400 12px/1.5 ' . $font . ';color:#6b7280;text-align:center">';
		if ( $o['brand'] ) {
			$html .= 'Prepared by <strong style="color:#374151">' . esc_html( $o['company'] ) . '</strong><br>';
		}
		$html .= 'Generated by Hacked Site Index Cleaner · ' . esc_html( $o['url'] ) . '</div>';

		$html .= '</div></div>';
		return $html;
	}

	/** Full standalone page for printing / "Save as PDF". */
	public static function print_page() {
		$body = self::render( self::data(), self::options(), 'page' );
		$o    = self::options();
		return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Search cleanup report – ' . esc_html( $o['site'] ) . '</title>'
			. '<style>@page{margin:12mm}body{margin:0;background:#f3f4f6}.bar{position:sticky;top:0;background:#111827;color:#fff;padding:10px 16px;font:14px system-ui,sans-serif;display:flex;gap:12px;align-items:center;justify-content:space-between}.bar button{background:#2563eb;color:#fff;border:0;border-radius:6px;padding:8px 14px;font:600 13px system-ui;cursor:pointer}@media print{.bar{display:none}body{background:#fff}*{-webkit-print-color-adjust:exact;print-color-adjust:exact}}</style></head><body>'
			. '<div class="bar"><span>Choose “Save as PDF” as the printer to get a PDF file.</span><button onclick="window.print()">Print / Save as PDF</button></div>'
			. $body . '</body></html>';
	}

	/* ------------------------------------------------------------------ */
	/* Email                                                               */
	/* ------------------------------------------------------------------ */

	public static function frequencies() {
		return array(
			'weekly'    => array( 'Weekly', 7 ),
			'monthly'   => array( 'Monthly', 30 ),
			'quarterly' => array( 'Quarterly', 90 ),
		);
	}

	public static function html_content_type() {
		return 'text/html';
	}

	public static function send( $to ) {
		$to = sanitize_email( (string) $to );
		if ( ! $to ) {
			return false;
		}
		$o    = self::options();
		$html = self::render( self::data(), $o, 'email' );
		add_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		$ok = wp_mail( $to, 'Search cleanup report – ' . $o['site'], $html );
		remove_filter( 'wp_mail_content_type', array( __CLASS__, 'html_content_type' ) );
		return $ok;
	}

	public static function recipient() {
		$to = sanitize_email( (string) ICP_DB::setting( 'report_email_to' ) );
		return $to ? $to : get_option( 'admin_email' );
	}

	public static function next_due() {
		$f    = (string) ICP_DB::setting( 'report_email_freq' );
		$freq = self::frequencies();
		$days = isset( $freq[ $f ] ) ? $freq[ $f ][1] : 30;
		$last = (int) get_option( 'icp_report_last_sent', 0 );
		return $last ? $last + $days * DAY_IN_SECONDS : 0;
	}

	public static function maybe_send() {
		if ( ! ICP_DB::setting( 'report_email_enabled' ) ) {
			return;
		}
		$due = self::next_due();
		if ( ! $due ) { // first run after enabling: start the clock, send next period.
			update_option( 'icp_report_last_sent', time(), false );
			return;
		}
		if ( time() >= $due && ICP_DB::stats()->total ) {
			if ( self::send( self::recipient() ) ) {
				update_option( 'icp_report_last_sent', time(), false );
			}
		}
	}
}
