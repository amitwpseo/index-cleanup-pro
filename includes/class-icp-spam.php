<?php
/**
 * Spam score for a URL path or a search phrase (0-100) with reasons.
 * Pure string logic; the result is only a hint, a person always reviews it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ICP_Spam {

	/** Words that almost only appear in hack spam (+40 each). */
	public static function strong_words() {
		$w = array(
			'casino', 'gacor', 'slot88', 'togel', 'viagra', 'cialis', 'levitra', 'kamagra', 'tramadol', 'xanax',
			'porn', 'porno', 'xxx', 'hentai', 'escort', 'poker', 'judi', 'sbobet', 'sportsbook', 'betting',
			'gambling', 'payday', 'pharmacy', 'slotgacor', 'sex', 'nude',
		);
		$custom = (string) ICP_DB::setting( 'spam_words' );
		if ( '' !== trim( $custom ) ) {
			foreach ( preg_split( '/[\s,]+/', function_exists( 'mb_strtolower' ) ? mb_strtolower( $custom, 'UTF-8' ) : strtolower( $custom ), -1, PREG_SPLIT_NO_EMPTY ) as $c ) {
				if ( strlen( $c ) >= 3 ) {
					$w[] = $c;
				}
			}
		}
		return array_values( array_unique( $w ) );
	}

	/** Words that are often spam but can be innocent (+15 each, max 3). */
	public static function medium_words() {
		return array( 'slot', 'slots', 'bet', 'bets', 'bonus', 'jackpot', 'loan', 'pills', 'replica', 'cheap', 'nulled', 'cracked', 'rtp', 'bandar', 'daftar', 'maxwin', 'deposit', 'withdraw', 'parlay', 'mahjong', 'lottery' );
	}

	/**
	 * @return array [ int score, string[] reasons ]
	 */
	public static function score( $text, $is_query = false ) {
		$t = (string) $text;
		$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( $t, 'UTF-8' ) : strtolower( $t );
		if ( '' === trim( $t ) ) {
			return array( 0, array() );
		}
		$score  = 0;
		$why    = array();
		$tokens = preg_split( '/[^\p{L}\p{N}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY );
		$set    = $tokens ? array_flip( $tokens ) : array();

		foreach ( self::strong_words() as $w ) {
			if ( isset( $set[ $w ] ) || ( strlen( $w ) >= 6 && false !== strpos( $t, $w ) ) ) {
				$score += 40;
				$why[]  = $w;
			}
		}
		$med = 0;
		foreach ( self::medium_words() as $w ) {
			if ( isset( $set[ $w ] ) && $med < 3 ) {
				$score += 15;
				$why[]  = $w;
				$med++;
			}
		}

		$loc = function_exists( 'get_locale' ) ? strtolower( (string) get_locale() ) : 'en_us';
		if ( preg_match( '/[\x{3040}-\x{30FF}\x{3400}-\x{4DBF}\x{4E00}-\x{9FFF}\x{AC00}-\x{D7AF}]/u', $t ) && ! preg_match( '/^(ja|zh|ko)/', $loc ) ) {
			$score += 50;
			$why[]  = 'Japanese/Chinese/Korean text';
		}
		if ( preg_match( '/[\x{0400}-\x{04FF}]/u', $t ) && ! preg_match( '/^(ru|uk|bg|sr|be|mk|kk)/', $loc ) ) {
			$score += 30;
			$why[]  = 'Cyrillic text';
		}
		if ( preg_match( '/[\x{0E00}-\x{0E7F}]/u', $t ) && ! preg_match( '/^th/', $loc ) ) {
			$score += 30;
			$why[]  = 'Thai text';
		}
		if ( preg_match( '/[\x{0600}-\x{06FF}]/u', $t ) && ! preg_match( '/^(ar|fa|ur|ps)/', $loc ) ) {
			$score += 30;
			$why[]  = 'Arabic text';
		}
		if ( substr_count( $t, '-' ) >= 5 ) {
			$score += 10;
			$why[]  = 'long hyphen chain';
		}
		if ( preg_match_all( '/\d/', $t ) >= 6 ) {
			$score += 10;
			$why[]  = 'many digits';
		}
		if ( $is_query ) {
			if ( preg_match( '#https?://|www\.|[a-z0-9-]+\.(com|net|org|ru|xyz|top|info|site|online)\b#i', $t ) ) {
				$score += 50;
				$why[]  = 'link in search phrase';
			}
			if ( strlen( $t ) > 120 ) {
				$score += 20;
				$why[]  = 'very long phrase';
			}
		}
		return array( min( 100, $score ), array_slice( array_unique( $why ), 0, 6 ) );
	}

	public static function label( $score ) {
		if ( $score >= 60 ) {
			return array( 'High', 'icp-bad' );
		}
		if ( $score >= 40 ) {
			return array( 'Likely', 'icp-bad' );
		}
		if ( $score >= 20 ) {
			return array( 'Suspicious', 'icp-warn' );
		}
		return array( 'Low', '' );
	}
}
