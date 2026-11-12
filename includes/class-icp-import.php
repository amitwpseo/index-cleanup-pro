<?php
/**
 * Parse pasted text / uploaded files and classify URLs before import.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_Import {

	const MAX_LINES = 20000;

	/** Extract raw URL/path strings from text, CSV, TSV or XML sitemap content. */
	public static function extract( $content ) {
		$content = (string) $content;
		// Strip UTF-8 / UTF-16 BOM and convert UTF-16 (Excel "Unicode text").
		if ( 0 === strpos( $content, "\xFF\xFE" ) || 0 === strpos( $content, "\xFE\xFF" ) ) {
			if ( function_exists( 'mb_convert_encoding' ) ) {
				$content = mb_convert_encoding( $content, 'UTF-8', 'UTF-16' );
			}
		}
		$content = preg_replace( '/^\xEF\xBB\xBF/', '', $content );

		// XML sitemap.
		if ( false !== stripos( $content, '<loc>' ) ) {
			preg_match_all( '#<loc>\s*(.*?)\s*</loc>#is', $content, $m );
			return array_slice( array_map( 'html_entity_decode', $m[1] ), 0, self::MAX_LINES );
		}

		$lines = preg_split( '/\r\n|\r|\n/', $content );
		$lines = array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
		if ( ! $lines ) {
			return array();
		}
		$lines = array_slice( $lines, 0, self::MAX_LINES + 1 );

		// Delimiter detection on the first line.
		$first = $lines[0];
		$delim = null;
		foreach ( array( "\t", ',', ';' ) as $d ) {
			if ( false !== strpos( $first, $d ) ) {
				$delim = $d;
				break;
			}
		}

		$col   = null;
		$start = 0;
		if ( null !== $delim ) {
			$head = str_getcsv( $first, $delim );
			foreach ( $head as $i => $h ) {
				$h = strtolower( trim( $h ) );
				if ( preg_match( '/^(url|urls|address|page|pages|top pages|landing page|link|loc|path)$/', $h ) || false !== strpos( $h, 'url' ) ) {
					$col   = $i;
					$start = 1;
					break;
				}
			}
		} elseif ( preg_match( '/^(url|urls|address|page|pages|top pages|link|path)$/i', $first ) ) {
			$start = 1;
		}

		$out = array();
		for ( $i = $start, $n = count( $lines ); $i < $n; $i++ ) {
			$line = $lines[ $i ];
			if ( null === $delim ) {
				$out[] = $line;
				continue;
			}
			$cells = str_getcsv( $line, $delim );
			if ( null !== $col ) {
				if ( isset( $cells[ $col ] ) ) {
					$out[] = $cells[ $col ];
				}
				continue;
			}
			foreach ( $cells as $c ) {
				$c = trim( $c );
				if ( preg_match( '#^(https?://|/)#i', $c ) ) {
					$out[] = $c;
					break;
				}
			}
		}
		return array_slice( $out, 0, self::MAX_LINES );
	}

	/**
	 * Classify raw inputs.
	 * Returns array with keys: new, conflicts, protected, duplicates, invalid, foreign_hosts, total.
	 */
	public static function classify( array $raw ) {
		$res = array(
			'total'         => count( $raw ),
			'new'           => array(),
			'conflicts'     => array(),
			'protected'     => array(),
			'duplicates'    => 0,
			'invalid'       => 0,
			'foreign_hosts' => array(),
		);

		$seen = array();
		foreach ( $raw as $r ) {
			$n = ICP_Util::normalize_input( $r );
			$p = $n['path'];
			if ( ! ICP_Util::host_matches( $n['host'] ) ) {
				$res['foreign_hosts'][ $n['host'] ] = isset( $res['foreign_hosts'][ $n['host'] ] ) ? $res['foreign_hosts'][ $n['host'] ] + 1 : 1;
			}
			if ( '' === $p && '' !== trim( (string) $r ) && ! preg_match( '#^(https?://[^/]+)?/?$#i', trim( (string) $r ) ) ) {
				$res['invalid']++;
				continue;
			}
			if ( preg_match( '/[\x00-\x1F\x7F]/', $p ) || strlen( $p ) > 2000 || ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $p, 'UTF-8' ) ) ) {
				$res['invalid']++;
				continue;
			}
			if ( isset( $seen[ $p ] ) ) {
				$res['duplicates']++;
				continue;
			}
			$seen[ $p ] = true;

			$why = ICP_Util::is_protected( $p );
			if ( $why ) {
				$res['protected'][] = array(
					'path'   => $p,
					'reason' => $why,
				);
				continue;
			}
			$res['new'][] = $p;
		}

		// Already on the list.
		$hashes = array();
		foreach ( $res['new'] as $p ) {
			$hashes[ md5( $p ) ] = $p;
		}
		$exist = ICP_DB::existing_hashes( array_keys( $hashes ) );
		if ( $exist ) {
			$keep = array();
			foreach ( $res['new'] as $p ) {
				if ( isset( $exist[ md5( $p ) ] ) ) {
					$res['duplicates']++;
				} else {
					$keep[] = $p;
				}
			}
			$res['new'] = $keep;
		}

		// Real-page guard.
		$keep = array();
		foreach ( $res['new'] as $p ) {
			$post = ICP_Util::find_conflict( $p );
			if ( $post ) {
				$res['conflicts'][] = array(
					'path'   => $p,
					'id'     => $post->ID,
					'title'  => get_the_title( $post ),
					'type'   => $post->post_type,
					'author' => get_the_author_meta( 'user_login', $post->post_author ),
					'date'   => get_the_date( 'Y-m-d', $post ),
				);
			} else {
				$keep[] = $p;
			}
		}
		$res['new'] = $keep;

		return $res;
	}

	public static function transient_key() {
		return 'icp_import_' . get_current_user_id();
	}
}
