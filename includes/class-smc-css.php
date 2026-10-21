<?php
defined( 'ABSPATH' ) || exit;

/**
 * Gắn phạm vi của từng bài vào CSS do AI viết, để CSS của bài này không lọt sang bài khác hay ra theme.
 * ".smc-card h3" thành ".smc-p12.smc-p12.smc-p12 .smc-card h3" (lặp class để thắng CSS của theme và phần reset).
 */
class SMC_CSS {

	const PASS_THROUGH = array( 'keyframes', '-webkit-keyframes', 'font-face', 'property', 'counter-style', 'page' );
	const RECURSE      = array( 'media', 'supports', 'container', 'layer', 'starting-style' );

	public static function scope_class( $post_id ) {
		$c = '.smc-p' . (int) $post_id;
		return $c . $c . $c;
	}

	public static function scope( $css, $post_id ) {
		$css = preg_replace( '#/\*.*?\*/#s', '', (string) $css );
		$css = str_ireplace( array( '</style', '<!--', '-->' ), '', $css );
		return trim( self::walk( $css, self::scope_class( $post_id ) ) );
	}

	private static function walk( $css, $scope ) {
		$out = '';
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			$j = self::scan( $css, $i, '{;' );
			if ( $j >= $len ) {
				break;
			}
			$prelude = trim( substr( $css, $i, $j - $i ) );
			if ( ';' === $css[ $j ] ) {
				// @import, @charset...: không cho phép trong CSS của bài.
				$i = $j + 1;
				continue;
			}
			$end  = self::match_brace( $css, $j );
			$body = substr( $css, $j + 1, $end - $j - 1 );
			$i    = $end + 1;
			if ( '' === $prelude ) {
				continue;
			}
			if ( '@' === $prelude[0] ) {
				$name = strtolower( (string) strtok( substr( $prelude, 1 ), " \t\r\n(" ) );
				if ( in_array( $name, self::RECURSE, true ) ) {
					$out .= $prelude . '{' . self::walk( $body, $scope ) . '}';
				} elseif ( in_array( $name, self::PASS_THROUGH, true ) ) {
					$out .= $prelude . '{' . $body . '}';
				}
				continue;
			}
			$out .= self::scope_selectors( $prelude, $scope ) . '{' . trim( $body ) . '}';
		}
		return $out;
	}

	private static function scope_selectors( $prelude, $scope ) {
		$parts = array();
		$len   = strlen( $prelude );
		$i     = 0;
		while ( $i <= $len ) {
			$j   = self::scan( $prelude, $i, ',' );
			$sel = trim( substr( $prelude, $i, $j - $i ) );
			$i   = $j + 1;
			if ( '' === $sel ) {
				continue;
			}
			if ( preg_match( '/^(:root|html|body|\.smc-root)(?![\w-])/i', $sel, $m ) ) {
				$parts[] = $scope . substr( $sel, strlen( $m[0] ) );
			} else {
				$parts[] = $scope . ' ' . $sel;
			}
		}
		return implode( ',', $parts );
	}

	/** Vị trí ký tự đầu tiên thuộc $stops nằm ngoài chuỗi và ngoài ngoặc () []. */
	private static function scan( $css, $i, $stops ) {
		$len   = strlen( $css );
		$depth = 0;
		for ( ; $i < $len; $i++ ) {
			$c = $css[ $i ];
			if ( '\\' === $c ) {
				$i++;
			} elseif ( '"' === $c || "'" === $c ) {
				$i = self::skip_string( $css, $i );
			} elseif ( '(' === $c || '[' === $c ) {
				$depth++;
			} elseif ( ')' === $c || ']' === $c ) {
				$depth = max( 0, $depth - 1 );
			} elseif ( 0 === $depth && false !== strpos( $stops, $c ) ) {
				return $i;
			}
		}
		return $len;
	}

	private static function skip_string( $css, $i ) {
		$len   = strlen( $css );
		$quote = $css[ $i ];
		for ( $i++; $i < $len; $i++ ) {
			if ( '\\' === $css[ $i ] ) {
				$i++;
			} elseif ( $css[ $i ] === $quote ) {
				return $i;
			}
		}
		return $len;
	}

	/** Vị trí dấu } khớp với dấu { tại $open. */
	private static function match_brace( $css, $open ) {
		$len   = strlen( $css );
		$depth = 0;
		for ( $i = $open; $i < $len; $i++ ) {
			$c = $css[ $i ];
			if ( '\\' === $c ) {
				$i++;
			} elseif ( '"' === $c || "'" === $c ) {
				$i = self::skip_string( $css, $i );
			} elseif ( '{' === $c ) {
				$depth++;
			} elseif ( '}' === $c ) {
				$depth--;
				if ( 0 === $depth ) {
					return $i;
				}
			}
		}
		return $len;
	}
}
