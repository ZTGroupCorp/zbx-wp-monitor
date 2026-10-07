<?php
/**
 * Contenido sospechoso (posts spam) — job diario WP-Cron, por lotes con el mismo
 * esquema de cursor que la integridad del core. Solo lectura: detecta y reporta,
 * nunca modifica ni borra posts.
 *
 * Qué revisa en cada corrida, por sitio:
 *  1. posts con ID mayor a la última marca. Por ID y no por fecha: el spam
 *     inyectado suele venir con fecha vieja para no asomar en el home. La primera
 *     corrida barre desde ID 0 (encuentra spam preexistente);
 *  2. posts viejos modificados desde la corrida anterior (spam metido editando
 *     un post legítimo);
 *  3. los sospechosos ya registrados, para que se caigan solos si se borraron,
 *     se limpiaron o se marcaron revisados.
 *
 * Un post es sospechoso si su puntaje llega a ZTGRP_MONITOR_CONTENT_THRESHOLD
 * (ver ztgrp_monitor_content_score()). El registro persiste entre corridas: el
 * sospechoso alerta hasta que se revisa, no solo el día que apareció.
 *
 * Multisite: el contenido es por subsitio, así que el sitio principal recorre
 * todos los sitios de la red con switch_to_blog().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZTGRP_MONITOR_CONTENT_THRESHOLD', 5 );
define( 'ZTGRP_MONITOR_CONTENT_BATCH', 200 );
define( 'ZTGRP_MONITOR_CONTENT_MODIFIED_CAP', 500 );
define( 'ZTGRP_MONITOR_CONTENT_LIST_CAP', 20 );
define( 'ZTGRP_MONITOR_CONTENT_ACK_CAP', 1000 );

add_action( 'ztgrp_monitor_content_run', 'ztgrp_monitor_content_run' );
add_action( 'init', 'ztgrp_monitor_content_schedule' );

/**
 * Agenda el job diario si falta. Va en init y no solo en la activación porque el
 * auto-update de la flota NO dispara el hook de activación: sin esto, los sitios
 * que suben desde 1.0.x nunca correrían el job. Costo: una lectura del array de
 * cron (autoload) por request.
 */
function ztgrp_monitor_content_schedule() {
	if ( is_multisite() && ! is_main_site() ) {
		return;
	}
	if ( ! wp_next_scheduled( 'ztgrp_monitor_content_run' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'ztgrp_monitor_content_run' );
		wp_schedule_single_event( time() + 300, 'ztgrp_monitor_content_run' );
	}
}

function ztgrp_monitor_content_run() {
	if ( is_multisite() && ! is_main_site() ) {
		return;
	}

	$result = ztgrp_monitor_content_result();
	$state  = ztgrp_monitor_net_get( 'ztgrp_monitor_content_state' );

	// Corrida nueva (o arrastrada de otra versión del plugin: el criterio de
	// puntaje pudo cambiar, así que se arranca de cero con las marcas guardadas).
	if ( ! is_array( $state ) || ! isset( $state['plugin_version'], $state['queue'] )
		|| ZTGRP_MONITOR_VERSION !== $state['plugin_version'] ) {
		$blogs    = ztgrp_monitor_content_blog_ids();
		$suspects = array();
		foreach ( $result['suspects'] as $key => $entry ) {
			if ( in_array( (int) $entry['blog'], $blogs, true ) ) {
				$suspects[ $key ] = $entry;
			}
		}
		$state = array(
			'plugin_version' => ZTGRP_MONITOR_VERSION,
			'started_at'     => time(),
			'since'          => $result['checked_at'] ? $result['checked_at'] - HOUR_IN_SECONDS : 0,
			'queue'          => $blogs,
			'blog'           => 0,
			'phase'          => '',
			'cursor'         => 0,
			'start_mark'     => null,
			'marks'          => $result['marks'],
			'suspects'       => $suspects,
			'new'            => 0,
			'baseline'       => false,
		);
	}

	$deadline = time() + ZTGRP_MONITOR_BATCH_SECONDS;
	while ( time() < $deadline ) {
		if ( ! $state['blog'] ) {
			if ( empty( $state['queue'] ) ) {
				ztgrp_monitor_content_finish( $state, $result );
				return;
			}
			$state['blog']       = (int) array_shift( $state['queue'] );
			$state['phase']      = 'new';
			$state['start_mark'] = isset( $state['marks'][ $state['blog'] ] ) ? (int) $state['marks'][ $state['blog'] ] : null;
			$state['cursor']     = (int) $state['start_mark'];
			if ( null === $state['start_mark'] ) {
				$state['baseline'] = true; // Sitio sin marca: barrido completo, no cuenta como "nuevos".
			}
		}

		$switched = is_multisite() && get_current_blog_id() !== $state['blog'];
		if ( $switched ) {
			switch_to_blog( $state['blog'] );
		}
		ztgrp_monitor_content_step( $state );
		if ( $switched ) {
			restore_current_blog();
		}
	}

	// No terminó: guardar estado y continuar en ~1 min.
	ztgrp_monitor_net_set( 'ztgrp_monitor_content_state', $state );
	wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'ztgrp_monitor_content_run' );
}

/**
 * Un lote de la fase en curso del sitio actual (ya switcheado).
 */
function ztgrp_monitor_content_step( &$state ) {
	global $wpdb;

	$ctx  = ztgrp_monitor_content_context();
	$cols = "ID, post_author, post_title, post_content, post_type, post_status, post_modified_gmt FROM {$wpdb->posts}";
	$in   = "post_type IN ('" . implode( "','", array_map( 'esc_sql', $ctx['types'] ) ) . "')"
		. " AND post_status IN ('" . implode( "','", array_map( 'esc_sql', $ctx['statuses'] ) ) . "')";

	if ( 'new' === $state['phase'] ) {
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT $cols WHERE ID > %d AND $in ORDER BY ID ASC LIMIT %d", $state['cursor'], ZTGRP_MONITOR_CONTENT_BATCH )
		);
		foreach ( (array) $rows as $row ) {
			ztgrp_monitor_content_evaluate( $state, $row, $ctx );
			$state['cursor'] = (int) $row->ID;
			if ( null !== $state['start_mark'] ) {
				$state['new']++;
			}
		}
		if ( count( (array) $rows ) < ZTGRP_MONITOR_CONTENT_BATCH ) {
			$state['phase'] = 'modified';
		}
		return;
	}

	if ( 'modified' === $state['phase'] ) {
		if ( null !== $state['start_mark'] && $state['since'] ) {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT $cols WHERE ID <= %d AND post_modified_gmt >= %s AND $in ORDER BY post_modified_gmt DESC LIMIT %d",
					$state['start_mark'],
					gmdate( 'Y-m-d H:i:s', $state['since'] ),
					ZTGRP_MONITOR_CONTENT_MODIFIED_CAP
				)
			);
			foreach ( (array) $rows as $row ) {
				ztgrp_monitor_content_evaluate( $state, $row, $ctx );
			}
		}
		$state['phase'] = 'recheck';
		return;
	}

	// recheck: sospechosos de este sitio que esta corrida todavía no re-evaluó.
	foreach ( $state['suspects'] as $key => $entry ) {
		if ( (int) $entry['blog'] !== $state['blog'] || $entry['run'] === $state['started_at'] ) {
			continue;
		}
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT $cols WHERE ID = %d AND $in", $entry['id'] ) );
		if ( $row ) {
			ztgrp_monitor_content_evaluate( $state, $row, $ctx );
		} else {
			unset( $state['suspects'][ $key ] ); // Borrado, a la papelera o fuera de alcance.
		}
	}

	$state['marks'][ $state['blog'] ] = $state['cursor'];
	$state['blog']                    = 0;
}

/**
 * Registra o descarta un post según su puntaje y el ack vigente.
 */
function ztgrp_monitor_content_evaluate( &$state, $row, $ctx ) {
	$key = $state['blog'] . ':' . $row->ID;

	if ( isset( $ctx['ack'][ $key ] ) && $ctx['ack'][ $key ] === $row->post_modified_gmt ) {
		unset( $state['suspects'][ $key ] ); // Revisado y sin cambios desde entonces.
		return;
	}

	$scored = ztgrp_monitor_content_score( $row, $ctx );
	if ( $scored['score'] < ZTGRP_MONITOR_CONTENT_THRESHOLD ) {
		unset( $state['suspects'][ $key ] );
		return;
	}

	$state['suspects'][ $key ] = array(
		'blog'     => $state['blog'],
		'id'       => (int) $row->ID,
		'type'     => (string) $row->post_type,
		'status'   => (string) $row->post_status,
		'score'    => $scored['score'],
		'reasons'  => $scored['reasons'],
		'modified' => (string) $row->post_modified_gmt,
		'run'      => $state['started_at'],
	);
}

function ztgrp_monitor_content_finish( $state, $result ) {
	$history = $result['history'];
	$new     = 0;
	// Línea base contra la que Zabbix compara content_new_24h: el promedio de las
	// corridas ANTERIORES (sin la actual, que es la que se está evaluando).
	$avg = $history ? round( array_sum( $history ) / count( $history ), 1 ) : 0;
	// Con algún sitio en barrido completo no hay "nuevos del día" comparables.
	if ( ! $state['baseline'] ) {
		$new       = (int) $state['new'];
		$history[] = $new;
		$history   = array_slice( $history, -30 );
	}

	uasort( $state['suspects'], 'ztgrp_monitor_content_sort' );

	ztgrp_monitor_net_set(
		'ztgrp_monitor_content',
		array(
			'checked_at' => time(),
			'suspects'   => $state['suspects'],
			'new_24h'    => $new,
			'avg_30d'    => $avg,
			'history'    => $history,
			'marks'      => $state['marks'],
		)
	);
	ztgrp_monitor_net_del( 'ztgrp_monitor_content_state' );
}

function ztgrp_monitor_content_sort( $a, $b ) {
	return $b['score'] - $a['score'];
}

/**
 * Resultado guardado, normalizado (sin corrida previa: todo vacío).
 */
function ztgrp_monitor_content_result() {
	$r = ztgrp_monitor_net_get( 'ztgrp_monitor_content' );
	$r = is_array( $r ) ? $r : array();
	return array(
		'checked_at' => isset( $r['checked_at'] ) ? (int) $r['checked_at'] : 0,
		'suspects'   => isset( $r['suspects'] ) && is_array( $r['suspects'] ) ? $r['suspects'] : array(),
		'new_24h'    => isset( $r['new_24h'] ) ? (int) $r['new_24h'] : 0,
		'avg_30d'    => isset( $r['avg_30d'] ) ? (float) $r['avg_30d'] : 0,
		'history'    => isset( $r['history'] ) && is_array( $r['history'] ) ? $r['history'] : array(),
		'marks'      => isset( $r['marks'] ) && is_array( $r['marks'] ) ? $r['marks'] : array(),
	);
}

/**
 * Claves content_* del endpoint. Sin títulos ni contenido: ID, tipo, estado,
 * puntaje y motivos alcanzan para abrir el post desde la alerta.
 */
function ztgrp_monitor_content_metrics() {
	$r    = ztgrp_monitor_content_result();
	$list = array();
	foreach ( array_slice( $r['suspects'], 0, ZTGRP_MONITOR_CONTENT_LIST_CAP ) as $e ) {
		$list[] = array(
			'blog'    => (int) $e['blog'],
			'id'      => (int) $e['id'],
			'type'    => (string) $e['type'],
			'status'  => (string) $e['status'],
			'score'   => (int) $e['score'],
			'reasons' => array_values( array_map( 'strval', (array) $e['reasons'] ) ),
		);
	}
	return array(
		'content_suspect_count' => count( $r['suspects'] ),
		'content_suspect'       => $list,
		'content_new_24h'       => $r['new_24h'],
		'content_new_avg_30d'   => $r['avg_30d'],
		'content_checked_at'    => $r['checked_at'],
	);
}

function ztgrp_monitor_content_blog_ids() {
	if ( ! is_multisite() ) {
		return array( get_current_blog_id() );
	}
	return array_map(
		'intval',
		get_sites(
			array(
				'fields'   => 'ids',
				'number'   => 1000,
				'archived' => 0,
				'deleted'  => 0,
				'spam'     => 0,
			)
		)
	);
}

/* -------------------------------------------------------------------------
 * Puntaje
 * ---------------------------------------------------------------------- */

/**
 * Contexto del sitio actual para puntuar (se arma una vez por lote).
 */
function ztgrp_monitor_content_context() {
	$allow = ztgrp_monitor_content_allow();

	$hosts = array();
	foreach ( array( home_url(), site_url() ) as $url ) {
		$h = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' !== $h ) {
			$hosts[] = preg_replace( '/^www\./', '', $h );
		}
	}

	$locale = (string) get_option( 'WPLANG' );
	if ( '' === $locale ) {
		$locale = get_locale();
	}
	$lang = strtolower( substr( $locale, 0, 2 ) );

	$types = array_values( array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) ) );

	return array(
		'types'    => $types ? $types : array( 'post', 'page' ),
		'statuses' => array( 'publish', 'future', 'draft', 'pending', 'private' ),
		'hosts'    => array_unique( $hosts ),
		'domains'  => array_merge( $allow['domains'], ztgrp_monitor_content_default_domains() ),
		'keywords' => ztgrp_monitor_content_keywords( $allow['words'] ),
		// Sitios en alfabeto latino: CJK/cirílico/etc. en masa es señal de spam.
		'latin'    => ! in_array( $lang, array( 'ru', 'uk', 'bg', 'sr', 'mk', 'be', 'kk', 'zh', 'ja', 'ko', 'th', 'ar', 'he', 'fa', 'ur', 'hi', 'el' ), true ),
		'ack'      => ztgrp_monitor_content_acks(),
	);
}

/**
 * Dos listas, calibradas con hispanicprwire (sitio de prensa: 8 de 8 falsos
 * positivos con keywords sueltas como "viagra" o "casino" en notas legítimas):
 *
 *  - spam: frases que solo usa el spam ("slot gacor", "buy cialis", "viagra sin
 *    receta"). Fragmentos de regex. Una sola alcanza el umbral.
 *  - topic: palabras del tema, que un sitio de noticias o de prensa usa
 *    legítimamente. +1 cada una con tope de 3: solas nunca llegan al umbral, solo
 *    suman a una señal técnica (link oculto, autor inexistente...).
 *
 * Filtro `ztgrp_monitor_content_keywords` para ajustar ambas listas.
 */
function ztgrp_monitor_content_keywords( $ignore ) {
	$drugs = 'viagra|cialis|levitra|kamagra|sildenafil|tadalafil|tramadol|xanax|phentermine|oxycodone';
	$lists = apply_filters(
		'ztgrp_monitor_content_keywords',
		array(
			'spam'  => array(
				'slot\s+gacor', 'situs\s+slot', 'judi\s+online', 'judi\s+bola', 'togel', 'maxwin', 'sbobet',
				'(?:buy|cheap|order|generic|discount|comprar|compra)\s+(?:' . $drugs . ')',
				'(?:' . $drugs . ')\s+(?:without\s+(?:a\s+)?prescription|no\s+prescription|sin\s+receta|for\s+sale)',
				'replica\s+watches', 'cheap\s+jerseys', 'essay\s+writing\s+service',
				'buy\s+(?:instagram\s+|tiktok\s+|youtube\s+)?(?:followers|likes|views)',
			),
			'topic' => array(
				'casino', 'online casino', 'poker online', 'slot online', 'betting', 'sportsbook',
				'porn', 'porno', 'xxx', 'escort', 'escorts', 'sex cams', 'hentai', 'onlyfans leaks',
				'payday loans', 'crypto airdrop', 'pragmatic play', 'cbd gummies', 'keto gummies',
				'viagra', 'cialis', 'levitra', 'kamagra', 'sildenafil', 'tadalafil', 'tramadol',
				'xanax', 'phentermine', 'oxycodone',
			),
		)
	);

	$ignore = array_map( 'strtolower', $ignore );
	$out    = array( 'ignore' => $ignore );

	if ( ! empty( $lists['spam'] ) ) {
		$out['spam'] = ztgrp_monitor_content_kw_regex( (array) $lists['spam'] );
	}
	// Las palabras de la allowlist salen de la lista de tema; las frases de spam
	// se filtran por match en el puntaje (ver ztgrp_monitor_content_score()).
	$topic = array_diff( array_map( 'strtolower', (array) $lists['topic'] ), $ignore );
	if ( $topic ) {
		$alts = array();
		foreach ( $topic as $w ) {
			$alts[] = str_replace( ' ', '\s+', preg_quote( $w, '/' ) );
		}
		$out['topic'] = ztgrp_monitor_content_kw_regex( $alts );
	}
	return $out;
}

/**
 * Límites de palabra Unicode: "casino" no matchea dentro de "casinoteca".
 */
function ztgrp_monitor_content_kw_regex( $alts ) {
	return '/(?<![\p{L}\p{N}])(' . implode( '|', $alts ) . ')(?![\p{L}\p{N}])/iu';
}

/**
 * Dominios externos que no cuentan para "muchos dominios externos" (embeds y
 * referencias habituales). Se suman a la allowlist del sitio.
 */
function ztgrp_monitor_content_default_domains() {
	return apply_filters(
		'ztgrp_monitor_content_default_domains',
		array(
			'youtube.com', 'youtu.be', 'vimeo.com', 'instagram.com', 'facebook.com', 'twitter.com',
			'x.com', 'tiktok.com', 'wikipedia.org', 'google.com', 'goo.gl', 'wa.me', 'whatsapp.com',
			'linkedin.com', 'wordpress.org', 'gravatar.com',
		)
	);
}

/**
 * Puntaje de un post. Devuelve array( score, reasons ).
 */
function ztgrp_monitor_content_score( $row, $ctx ) {
	$title   = (string) $row->post_title;
	$content = (string) $row->post_content;
	$score   = 0;
	$reasons = array();

	$kw  = $ctx['keywords'];
	$all = $title . "\n" . $content;

	// 1a. Frases de spam: cada una distinta alcanza el umbral por sí sola.
	if ( isset( $kw['spam'] ) && preg_match_all( $kw['spam'], $all, $m ) ) {
		foreach ( array_diff( ztgrp_monitor_content_norm_kw( $m[1] ), $kw['ignore'] ) as $phrase ) {
			$score    += ZTGRP_MONITOR_CONTENT_THRESHOLD;
			$reasons[] = 'spam:' . $phrase;
		}
	}

	// 1b. Palabras de tema: +1 cada una, tope 3 (solas no llegan al umbral).
	if ( isset( $kw['topic'] ) && preg_match_all( $kw['topic'], $all, $m ) ) {
		$topic = ztgrp_monitor_content_norm_kw( $m[1] );
		$score += min( 3, count( $topic ) );
		foreach ( $topic as $word ) {
			$reasons[] = 'kw:' . $word;
		}
	}

	// 2. Links ocultos con CSS (técnica clásica de spam SEO).
	if ( ztgrp_monitor_content_has_hidden_link( $content ) ) {
		$score    += 4;
		$reasons[] = 'hidden_link';
	}

	// 3. Código: la ofuscación dentro de un post no tiene uso legítimo (alcanza
	//    sola); un <script> que no es de un embed conocido suma.
	if ( preg_match( '/\beval\s*\(|\batob\s*\(|document\.write\s*\(|String\.fromCharCode|base64_decode/i', $content ) ) {
		$score    += ZTGRP_MONITOR_CONTENT_THRESHOLD;
		$reasons[] = 'code:obfuscated';
	} elseif ( ztgrp_monitor_content_has_foreign_script( $content ) ) {
		$score    += 3;
		$reasons[] = 'code:script';
	}

	// 4. Autor inexistente: WordPress reasigna o borra los posts al borrar un
	//    usuario, así que un huérfano suele ser una inserción directa por SQL.
	if ( ! ztgrp_monitor_content_author_exists( (int) $row->post_author ) ) {
		$score    += 4;
		$reasons[] = 'author_missing';
	}

	// 5. Muchos dominios externos distintos (peso bajo: los comunicados de prensa
	//    legítimos suelen tener decenas de links).
	$ext = ztgrp_monitor_content_external_domains( $content, $ctx );
	if ( $ext >= 25 ) {
		$score    += 2;
		$reasons[] = 'ext_domains:' . $ext;
	} elseif ( $ext >= 10 ) {
		$score    += 1;
		$reasons[] = 'ext_domains:' . $ext;
	}

	// 6. Alfabeto ajeno al sitio (≥20% de las letras, con texto suficiente).
	if ( $ctx['latin'] ) {
		$text    = wp_strip_all_tags( $title . ' ' . $content );
		$letters = (int) preg_match_all( '/\p{L}/u', $text );
		$foreign = (int) preg_match_all( '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}\p{Cyrillic}\p{Thai}\p{Arabic}\p{Hebrew}\p{Devanagari}]/u', $text );
		if ( $letters >= 50 && $foreign / $letters >= 0.2 ) {
			$score    += 4;
			$reasons[] = 'foreign_script';
		}
	}

	return array(
		'score'   => $score,
		'reasons' => $reasons,
	);
}

/**
 * Matches de keywords normalizados (minúsculas, espacios simples) y sin repetir.
 */
function ztgrp_monitor_content_norm_kw( $matches ) {
	$out = array();
	foreach ( $matches as $kw ) {
		$out[] = preg_replace( '/\s+/u', ' ', strtolower( $kw ) );
	}
	return array_values( array_unique( $out ) );
}

function ztgrp_monitor_content_has_hidden_link( $content ) {
	$re = '/<(a|div|span|p|font|section)\b[^>]*\bstyle\s*=\s*["\'][^"\']*?(?:display\s*:\s*none|visibility\s*:\s*hidden|font-size\s*:\s*0(?![.\d])|(?:left|top|text-indent)\s*:\s*-\d{3,}px)/i';
	if ( ! preg_match_all( $re, $content, $m, PREG_OFFSET_CAPTURE ) ) {
		return false;
	}
	foreach ( $m[0] as $i => $match ) {
		if ( 'a' === strtolower( $m[1][ $i ][0] ) ) {
			return true; // El propio link está oculto.
		}
		// Contenedor oculto: ¿hay un link poco después de la apertura?
		if ( false !== stripos( substr( $content, $match[1], 1500 ), '<a ' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * <script> que no viene de un embed conocido (Instagram, X, TikTok, Facebook...),
 * que los sitios de la flota usan legítimamente dentro de los posts.
 */
function ztgrp_monitor_content_has_foreign_script( $content ) {
	if ( ! preg_match_all( '/<script\b([^>]*)>/i', $content, $m ) ) {
		return false;
	}
	$embeds = '/\bsrc\s*=\s*["\']?(?:https?:)?\/\/(?:www\.)?(?:instagram\.com|platform\.twitter\.com|tiktok\.com|connect\.facebook\.net|embedr\.flickr\.com|platform\.linkedin\.com|w\.soundcloud\.com)\//i';
	foreach ( $m[1] as $attrs ) {
		if ( ! preg_match( $embeds, $attrs ) ) {
			return true;
		}
	}
	return false;
}

function ztgrp_monitor_content_author_exists( $user_id ) {
	static $cache = array();
	if ( $user_id <= 0 ) {
		return false;
	}
	if ( ! isset( $cache[ $user_id ] ) ) {
		$cache[ $user_id ] = false !== get_userdata( $user_id );
	}
	return $cache[ $user_id ];
}

function ztgrp_monitor_content_external_domains( $content, $ctx ) {
	if ( ! preg_match_all( '/<a\s[^>]*\bhref\s*=\s*["\']?(?:https?:)?\/\/([^\/"\'\s>:?#]+)/i', $content, $m ) ) {
		return 0;
	}
	$domains = array();
	foreach ( $m[1] as $host ) {
		$host = preg_replace( '/^www\./', '', strtolower( $host ) );
		if ( in_array( $host, $ctx['hosts'], true ) || ztgrp_monitor_content_domain_allowed( $host, $ctx['domains'] ) ) {
			continue;
		}
		$domains[ $host ] = true;
	}
	return count( $domains );
}

function ztgrp_monitor_content_domain_allowed( $host, $allowed ) {
	foreach ( $allowed as $d ) {
		if ( $host === $d || substr( $host, -strlen( '.' . $d ) ) === '.' . $d ) {
			return true;
		}
	}
	return false;
}

/* -------------------------------------------------------------------------
 * Allowlist y revisados (options del plugin; nunca se tocan los posts)
 * ---------------------------------------------------------------------- */

function ztgrp_monitor_content_allow() {
	$a = ztgrp_monitor_net_get( 'ztgrp_monitor_content_allow' );
	return array(
		'domains' => isset( $a['domains'] ) && is_array( $a['domains'] ) ? $a['domains'] : array(),
		'words'   => isset( $a['words'] ) && is_array( $a['words'] ) ? $a['words'] : array(),
	);
}

function ztgrp_monitor_content_acks() {
	$a = ztgrp_monitor_net_get( 'ztgrp_monitor_content_ack' );
	return is_array( $a ) ? $a : array();
}

/**
 * Marca un sospechoso como revisado: deja de alertar mientras el post no cambie
 * (el ack guarda su post_modified_gmt; si se re-edita, se vuelve a evaluar).
 * Lo saca del resultado ya, sin esperar a la próxima corrida.
 */
function ztgrp_monitor_content_ack( $key ) {
	$result = ztgrp_monitor_content_result();
	if ( ! isset( $result['suspects'][ $key ] ) ) {
		return;
	}
	$acks         = ztgrp_monitor_content_acks();
	$acks[ $key ] = $result['suspects'][ $key ]['modified'];
	ztgrp_monitor_net_set( 'ztgrp_monitor_content_ack', array_slice( $acks, -ZTGRP_MONITOR_CONTENT_ACK_CAP, null, true ) );

	unset( $result['suspects'][ $key ] );
	ztgrp_monitor_net_set( 'ztgrp_monitor_content', $result );

	// Si hay una corrida a medias, que tampoco lo arrastre.
	$state = ztgrp_monitor_net_get( 'ztgrp_monitor_content_state' );
	if ( is_array( $state ) && isset( $state['suspects'][ $key ] ) ) {
		unset( $state['suspects'][ $key ] );
		ztgrp_monitor_net_set( 'ztgrp_monitor_content_state', $state );
	}
}

/* -------------------------------------------------------------------------
 * Sección de la página de ajustes
 * ---------------------------------------------------------------------- */

function ztgrp_monitor_content_handle_post() {
	if ( isset( $_POST['ztgrp_content_ack'] ) && check_admin_referer( 'ztgrp_monitor_content_ack' ) ) {
		ztgrp_monitor_content_ack( sanitize_text_field( wp_unslash( $_POST['ztgrp_content_ack'] ) ) );
		echo '<div class="notice notice-success"><p>Post marcado como revisado. Si se vuelve a editar, se re-evalúa.</p></div>';
	}

	if ( isset( $_POST['ztgrp_content_allow'] ) && check_admin_referer( 'ztgrp_monitor_content_allow' ) ) {
		$split = function ( $field ) {
			$raw = isset( $_POST[ $field ] ) ? (string) wp_unslash( $_POST[ $field ] ) : '';
			$out = array();
			foreach ( preg_split( '/[\r\n,]+/', $raw ) as $v ) {
				$v = strtolower( trim( sanitize_text_field( $v ) ) );
				if ( '' !== $v ) {
					$out[] = $v;
				}
			}
			return array_values( array_unique( $out ) );
		};
		$domains = array();
		foreach ( $split( 'ztgrp_allow_domains' ) as $d ) {
			$domains[] = preg_replace( '/^(?:https?:\/\/)?(?:www\.)?([^\/]+).*$/', '$1', $d );
		}
		ztgrp_monitor_net_set(
			'ztgrp_monitor_content_allow',
			array(
				'domains' => array_values( array_unique( $domains ) ),
				'words'   => $split( 'ztgrp_allow_words' ),
			)
		);
		// La allowlist cambia el puntaje de TODOS los posts (en ambos sentidos: quitar
		// una palabra debe volver a marcar lo que ignoraba), así que se re-barre
		// completo en vez de seguir incremental.
		$result          = ztgrp_monitor_content_result();
		$result['marks'] = array();
		ztgrp_monitor_net_set( 'ztgrp_monitor_content', $result );
		ztgrp_monitor_net_del( 'ztgrp_monitor_content_state' );
		wp_schedule_single_event( time() + 5, 'ztgrp_monitor_content_run' );
		echo '<div class="notice notice-success"><p>Allowlist guardada. Se re-revisan todos los posts en la próxima corrida de WP-Cron.</p></div>';
	}

	if ( isset( $_POST['ztgrp_content_run'] ) && check_admin_referer( 'ztgrp_monitor_content_run' ) ) {
		ztgrp_monitor_net_del( 'ztgrp_monitor_content_state' );
		wp_schedule_single_event( time() + 5, 'ztgrp_monitor_content_run' );
		echo '<div class="notice notice-success"><p>Revisión de contenido encolada (corre por WP-Cron en la próxima visita).</p></div>';
	}
}

function ztgrp_monitor_content_admin_section() {
	$r     = ztgrp_monitor_content_result();
	$allow = ztgrp_monitor_content_allow();
	?>
	<h2>Contenido sospechoso (posts spam)</h2>
	<?php if ( $r['checked_at'] ) : ?>
		<p>Última revisión: <?php echo esc_html( gmdate( 'Y-m-d H:i', $r['checked_at'] ) ); ?> UTC —
			<?php if ( ! $r['suspects'] ) : ?>
				<strong style="color:green">sin sospechosos</strong>
			<?php else : ?>
				<strong style="color:#c00"><?php echo count( $r['suspects'] ); ?> post(s) sospechoso(s)</strong>
			<?php endif; ?>
			· nuevos en la última corrida: <?php echo (int) $r['new_24h']; ?></p>
	<?php else : ?>
		<p>Todavía no corrió. Corre a diario vía WP-Cron; la primera vez revisa todos los posts.</p>
	<?php endif; ?>

	<?php if ( $r['suspects'] ) : ?>
		<table class="widefat striped" style="max-width:60em">
			<thead><tr><th>Post</th><th>Tipo / estado</th><th>Puntaje</th><th>Motivos</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( array_slice( $r['suspects'], 0, 100, true ) as $key => $e ) : ?>
				<?php
				$blog  = (int) $e['blog'];
				$post  = is_multisite() ? get_blog_post( $blog, (int) $e['id'] ) : get_post( (int) $e['id'] );
				$title = $post ? $post->post_title : '';
				$edit  = is_multisite()
					? get_admin_url( $blog, 'post.php?post=' . (int) $e['id'] . '&action=edit' )
					: admin_url( 'post.php?post=' . (int) $e['id'] . '&action=edit' );
				?>
				<tr>
					<td><a href="<?php echo esc_url( $edit ); ?>">#<?php echo (int) $e['id']; ?></a>
						<?php echo is_multisite() ? '(sitio ' . (int) $blog . ')' : ''; ?>
						<?php echo esc_html( wp_html_excerpt( $title, 60, '…' ) ); ?></td>
					<td><?php echo esc_html( $e['type'] . ' / ' . $e['status'] ); ?></td>
					<td><?php echo (int) $e['score']; ?></td>
					<td style="font-family:monospace"><?php echo esc_html( implode( ', ', (array) $e['reasons'] ) ); ?></td>
					<td>
						<form method="post" style="margin:0">
							<?php wp_nonce_field( 'ztgrp_monitor_content_ack' ); ?>
							<button class="button button-small" name="ztgrp_content_ack" value="<?php echo esc_attr( $key ); ?>">Marcar revisado</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<form method="post">
		<?php wp_nonce_field( 'ztgrp_monitor_content_run' ); ?>
		<p><button class="button" name="ztgrp_content_run" value="1">Ejecutar revisión ahora</button></p>
	</form>

	<h3>Allowlist</h3>
	<form method="post">
		<?php wp_nonce_field( 'ztgrp_monitor_content_allow' ); ?>
		<p><label>Dominios externos permitidos (uno por línea; incluye subdominios):<br>
			<textarea name="ztgrp_allow_domains" rows="4" cols="50"><?php echo esc_textarea( implode( "\n", $allow['domains'] ) ); ?></textarea></label></p>
		<p><label>Palabras clave a ignorar en este sitio (una por línea, ej.: <code>casino</code> en un sitio de viajes):<br>
			<textarea name="ztgrp_allow_words" rows="4" cols="50"><?php echo esc_textarea( implode( "\n", $allow['words'] ) ); ?></textarea></label></p>
		<p><button class="button" name="ztgrp_content_allow" value="1">Guardar allowlist</button></p>
	</form>
	<?php
}
