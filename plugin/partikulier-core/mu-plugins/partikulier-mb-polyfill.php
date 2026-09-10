<?php
/**
 * Partikulier — filet multilingue (extension mbstring indisponible).
 *
 * Le theme appelle mb_strlen(), mb_substr(), mb_strtolower(), mb_strrpos() a 47 endroits,
 * sans garde. Quand mbstring n'est pas charge, chacun de ces appels est une ERREUR FATALE :
 * mesuree sur un PHP 8.4 installe sans l'extension — « Call to undefined function
 * mb_strrpos() » dans Partikulier_SEO::limit(), c'est-a-dire sur toute fiche d'annonce dont
 * la meta description depasse la longueur cible. Comme le module de cache enregistrait alors
 * l'ecran de panne (code 200, corps de quelques octets), une seule page bastait a couper un
 * morceau entier du site pendant tout le TTL.
 *
 * Ces fonctions ne remplacent l'extension QUE si elle manque : chaque definition est gardee
 * par function_exists() et l'ensemble est saute si mbstring est charge. Elles travaillent en
 * UTF-8 reel (decodage explicite, pas des octets) pour que les compteurs de longueur et les
 * troncatures de titres restent justes sur le francais comme sur l'arabe.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( extension_loaded( 'mbstring' ) ) {
	return;
}

/* Les constantes de cas sont definies ici, pas dans le bloc mb_convert_case : si
   l'hebergeur fournit mb_convert_case SANS mb_strtolower (config rare mais vue),
   l'autre fonction aurait cherche une constante inexistante. */
if ( ! defined( 'PARTIKULIER_MB_CASE_UPPER' ) ) {
	define( 'PARTIKULIER_MB_CASE_UPPER', 0 );
	define( 'PARTIKULIER_MB_CASE_LOWER', 1 );
	define( 'PARTIKULIER_MB_CASE_TITLE', 2 );
}

if ( ! function_exists( 'partikulier_mb_ordinals' ) ) {

	/**
	 * Ordres de code des caracteres d'une chaine UTF-8.
	 *
	 * @param string $s Entree.
	 * @return int[]
	 */
	function partikulier_mb_ordinals( $s ) {
		$out = array();
		$s   = (string) $s;
		$n   = strlen( $s );
		$i   = 0;
		while ( $i < $n ) {
			$c = ord( $s[ $i ] );
			if ( $c < 0x80 ) {
				$out[] = $c;
				++$i;
				continue;
			}
			if ( $c >= 0xF0 ) {
				$need = 3;
			} elseif ( $c >= 0xE0 ) {
				$need = 2;
			} elseif ( $c >= 0xC0 ) {
				$need = 1;
			} else {
				$out[] = 0xFFFD;
				++$i;
				continue;
			}
			if ( $i + $need >= $n ) {
				$out[] = 0xFFFD;
				++$i;
				continue;
			}
			$cp = $c & ( 0x7F >> $need );
			for ( $k = 1; $k <= $need; ++$k ) {
				$cp = ( $cp << 6 ) | ( ord( $s[ $i + $k ] ) & 0x3F );
			}
			$out[] = $cp;
			$i    += $need + 1;
		}
		return $out;
	}

	/**
	 * Encode un ordre de code en UTF-8.
	 *
	 * @param int $cp Ordre de code.
	 * @return string
	 */
	function partikulier_mb_chr( $cp ) {
		$cp = max( 0, (int) $cp );
		if ( $cp < 0x80 ) {
			return chr( $cp );
		}
		if ( $cp < 0x800 ) {
			return chr( 0xC0 | ( $cp >> 6 ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		if ( $cp < 0x10000 ) {
			return chr( 0xE0 | ( $cp >> 12 ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
		}
		return chr( 0xF0 | ( $cp >> 18 ) ) . chr( 0x80 | ( ( $cp >> 12 ) & 0x3F ) ) . chr( 0x80 | ( ( $cp >> 6 ) & 0x3F ) ) . chr( 0x80 | ( $cp & 0x3F ) );
	}

	/**
	 * Passe un ordre de code en minuscule (ASCII + Latin-1 + Grec + cyrillique).
	 *
	 * @param int $cp Ordre de code.
	 * @return int
	 */
	function partikulier_mb_lower( $cp ) {
		if ( ( $cp >= 0x0041 && $cp <= 0x005A ) || ( $cp >= 0x00C0 && $cp <= 0x00DE && 0x00D7 !== $cp ) ) {
			return $cp + 0x20;
		}
		if ( $cp >= 0x0391 && $cp <= 0x03A9 ) {
			return $cp + 0x20;
		}
		if ( $cp >= 0x0410 && $cp <= 0x042F ) {
			return $cp + 0x20;
		}
		if ( class_exists( 'IntlChar' ) ) {
			$try = IntlChar::tolower( $cp );
			if ( is_int( $try ) && $try >= 0 ) {
				return $try;
			}
		}
		return $cp;
	}

	/**
	 * Passe un ordre de code en majuscule (mêmes familles).
	 *
	 * @param int $cp Ordre de code.
	 * @return int
	 */
	function partikulier_mb_upper( $cp ) {
		if ( ( $cp >= 0x0061 && $cp <= 0x007A ) || ( $cp >= 0x00E0 && $cp <= 0x00FE && 0x00F7 !== $cp && 0x00DF !== $cp ) ) {
			return $cp - 0x20;
		}
		if ( $cp >= 0x03B1 && $cp <= 0x03C9 ) {
			return $cp - 0x20;
		}
		if ( $cp >= 0x0430 && $cp <= 0x044F ) {
			return $cp - 0x20;
		}
		if ( class_exists( 'IntlChar' ) ) {
			$try = IntlChar::toupper( $cp );
			if ( is_int( $try ) && $try >= 0 ) {
				return $try;
			}
		}
		return $cp;
	}
}

if ( ! function_exists( 'mb_strlen' ) ) {
	function mb_strlen( $string, $encoding = null ) { // phpcs:ignore WordPress.NamingConventions
		return count( partikulier_mb_ordinals( (string) $string ) );
	}
}

if ( ! function_exists( 'mb_substr' ) ) {
	function mb_substr( $string, $start, $length = null, $encoding = null ) { // phpcs:ignore WordPress.NamingConventions
		$ords = partikulier_mb_ordinals( (string) $string );
		$n    = count( $ords );
		$a    = (int) $start;
		if ( $a < 0 ) {
			$a += $n;
		}
		$a = max( 0, min( $a, $n ) );
		if ( null === $length ) {
			$b = $n;
		} elseif ( $length < 0 ) {
			$b = max( $a, $n + (int) $length );
		} else {
			$b = min( $n, $a + (int) $length );
		}
		$out = '';
		for ( $i = $a; $i < $b; ++$i ) {
			$out .= partikulier_mb_chr( $ords[ $i ] );
		}
		return $out;
	}
}

if ( ! function_exists( 'mb_strpos' ) ) {
	function mb_strpos( $haystack, $needle, $offset = 0, $encoding = null ) { // phpcs:ignore WordPress.NamingConventions
		$h    = partikulier_mb_ordinals( (string) $haystack );
		$ne   = partikulier_mb_ordinals( (string) $needle );
		$m    = count( $ne );
		$total = count( $h );
		if ( 0 === $m ) {
			return 0;
		}
		for ( $i = max( 0, (int) $offset ); $i + $m <= $total; ++$i ) {
			$ok = true;
			for ( $k = 0; $k < $m; ++$k ) {
				if ( $h[ $i + $k ] !== $ne[ $k ] ) {
					$ok = false;
					break;
				}
			}
			if ( $ok ) {
				return $i;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'mb_strrpos' ) ) {
	function mb_strrpos( $haystack, $needle, $offset = 0, $encoding = null ) { // phpcs:ignore WordPress.NamingConventions
		$found = false;
		$at    = max( 0, (int) $offset );
		while ( false !== ( $at = mb_strpos( (string) $haystack, (string) $needle, $at ) ) ) {
			$found = $at;
			++$at;
		}
		return $found;
	}
}

if ( ! function_exists( 'mb_substr_count' ) ) {
	function mb_substr_count( $haystack, $needle, $encoding = null ) { // phpcs:ignore WordPress.NamingConventions
		$c  = 0;
		$at = 0;
		while ( false !== ( $at = mb_strpos( (string) $haystack, (string) $needle, $at ) ) ) {
			++$c;
			++$at;
		}
		return $c;
	}
}

if ( ! function_exists( 'mb_convert_case' ) ) {
	function mb_convert_case( $string, $mode, $encoding = null ) { // phpcs:ignore WordPress.NamingConventions, PHPCompatibility
		$ords = partikulier_mb_ordinals( (string) $string );
		$out  = '';
		$prev = 0;
		foreach ( $ords as $cp ) {
			if ( PARTIKULIER_MB_CASE_LOWER === $mode ) {
				$cp = partikulier_mb_lower( $cp );
			} elseif ( PARTIKULIER_MB_CASE_UPPER === $mode ) {
				$cp = partikulier_mb_upper( $cp );
			} else {
				$debut_mot = ( 0 === $prev || (bool) preg_match( '/[\s\(\[\{"\'\/\x{2019}\x{2018}-]/u', partikulier_mb_chr( $prev ) ) );
				$cp        = $debut_mot ? partikulier_mb_upper( $cp ) : partikulier_mb_lower( $cp );
			}
			$out .= partikulier_mb_chr( $cp );
			$prev = $cp;
		}
		return $out;
	}
}

if ( ! function_exists( 'mb_strtolower' ) ) {
	function mb_strtolower( $string, $encoding = null ) { // phpcs:ignore WordPress.NamingConventions
		return mb_convert_case( (string) $string, PARTIKULIER_MB_CASE_LOWER );
	}
}

if ( ! function_exists( 'mb_strtoupper' ) ) {
	function mb_strtoupper( $string, $encoding = null ) { // phpcs:ignore WordPress.NamingConventions
		return mb_convert_case( (string) $string, PARTIKULIER_MB_CASE_UPPER );
	}
}

if ( ! function_exists( 'mb_convert_encoding' ) ) {
	function mb_convert_encoding( $string, $to_encoding, $from_encoding = null ) { // phpcs:ignore WordPress.NamingConventions, PHPCompatibility
		$s = (string) $string;
		if ( preg_match( '/HTML-ENTITIES/i', (string) $to_encoding ) ) {
			return html_entity_decode( $s, ENT_QUOTES, 'UTF-8' );
		}
		if ( preg_match( '/^UTF-?8$/i', (string) $to_encoding ) ) {
			return $s;
		}
		return $s;
	}
}
