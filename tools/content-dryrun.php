<?php
/**
 * Ejecución en seco del detector de posts spam (calibración en sitios reales).
 *
 * NO es parte del plugin ni del zip de release. Se concatena DETRÁS de
 * includes/content.php (ver tools/build-dryrun.sh) y se ejecuta por wp-cli leyendo
 * de stdin, así que no deja archivos en el server:
 *
 *   ssh <host> 'cd <docroot> && wp eval-file - [limite] [pausa_seg]' < content-dryrun.build.php
 *
 * Solo lectura: no escribe options, no agenda cron, no toca posts. Usa el mismo
 * código de puntaje que el plugin. Requiere que el sitio NO tenga instalado el
 * plugin >= 1.2.0 (redeclararía las funciones).
 *
 * Args: limite = cuántos posts revisar por sitio, empezando por los más nuevos
 * (0 = todos); pausa_seg = pausa entre lotes de 200, para no cargar la base (def. 0.2).
 */

// Que nada de content.php quede enganchado a hooks en este proceso.
remove_action( 'init', 'ztgrp_monitor_content_schedule' );
remove_action( 'ztgrp_monitor_content_run', 'ztgrp_monitor_content_run' );

// Sin el plugin activo no existe el helper de storage; con 1.0.x sí.
if ( ! function_exists( 'ztgrp_monitor_net_get' ) ) {
	function ztgrp_monitor_net_get( $key, $default = false ) {
		return is_multisite() ? get_site_option( $key, $default ) : get_option( $key, $default );
	}
}

$zt_limit = isset( $args[0] ) ? max( 0, (int) $args[0] ) : 0;
$zt_pause = isset( $args[1] ) ? max( 0, (float) $args[1] ) : 0.2;
$zt_t0    = microtime( true );

global $wpdb;
$zt_suspects = array();
$zt_near     = array();
$zt_hist     = array();
$zt_scanned  = 0;

foreach ( ztgrp_monitor_content_blog_ids() as $zt_blog ) {
	$zt_switched = is_multisite() && get_current_blog_id() !== $zt_blog;
	if ( $zt_switched ) {
		switch_to_blog( $zt_blog );
	}
	$zt_ctx  = ztgrp_monitor_content_context();
	$zt_cols = "ID, post_author, post_title, post_content, post_type, post_status, post_modified_gmt FROM {$wpdb->posts}";
	$zt_in   = "post_type IN ('" . implode( "','", array_map( 'esc_sql', $zt_ctx['types'] ) ) . "')"
		. " AND post_status IN ('" . implode( "','", array_map( 'esc_sql', $zt_ctx['statuses'] ) ) . "')";

	WP_CLI::log( sprintf( 'Sitio %d %s | locale %s | latino %s | types: %s',
		$zt_blog, home_url(), get_locale(), $zt_ctx['latin'] ? 'si' : 'no', implode( ',', $zt_ctx['types'] ) ) );

	// Del más nuevo al más viejo, para que el límite revise lo reciente.
	$zt_cursor = PHP_INT_MAX;
	$zt_done   = 0;
	while ( true ) {
		$zt_rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT $zt_cols WHERE ID < %d AND $zt_in ORDER BY ID DESC LIMIT %d", $zt_cursor, ZTGRP_MONITOR_CONTENT_BATCH )
		);
		if ( ! $zt_rows ) {
			break;
		}
		foreach ( $zt_rows as $zt_row ) {
			$zt_cursor = (int) $zt_row->ID;
			$zt_s      = ztgrp_monitor_content_score( $zt_row, $zt_ctx );
			foreach ( $zt_s['reasons'] as $zt_r ) {
				$zt_k             = preg_replace( '/^(kw|spam|ext_domains):.*$/', '$1:*', $zt_r );
				$zt_hist[ $zt_k ] = isset( $zt_hist[ $zt_k ] ) ? $zt_hist[ $zt_k ] + 1 : 1;
			}
			$zt_line = array( $zt_blog, (int) $zt_row->ID, $zt_row->post_type . '/' . $zt_row->post_status, $zt_s['score'],
				implode( ' ', $zt_s['reasons'] ), wp_html_excerpt( $zt_row->post_title, 70, '…' ) );
			if ( $zt_s['score'] >= ZTGRP_MONITOR_CONTENT_THRESHOLD ) {
				$zt_suspects[] = $zt_line;
			} elseif ( $zt_s['score'] >= 3 ) {
				$zt_near[] = $zt_line;
			}
			$zt_scanned++;
			$zt_done++;
			if ( $zt_limit && $zt_done >= $zt_limit ) {
				break 2;
			}
		}
		unset( $zt_rows );
		if ( $zt_pause ) {
			usleep( (int) ( $zt_pause * 1000000 ) );
		}
	}
	if ( $zt_switched ) {
		restore_current_blog();
	}
}

$zt_print = function ( $title, $lines, $cap ) {
	WP_CLI::log( '' );
	WP_CLI::log( sprintf( '== %s: %d%s', $title, count( $lines ), count( $lines ) > $cap ? " (se muestran $cap)" : '' ) );
	usort( $lines, function ( $a, $b ) { return $b[3] - $a[3]; } );
	foreach ( array_slice( $lines, 0, $cap ) as $l ) {
		WP_CLI::log( sprintf( '  [%d] #%d %s score=%d | %s | %s', $l[0], $l[1], $l[2], $l[3], $l[4], $l[5] ) );
	}
};
$zt_print( 'SOSPECHOSOS (score >= ' . ZTGRP_MONITOR_CONTENT_THRESHOLD . ')', $zt_suspects, 100 );
$zt_print( 'CASI (score 3-4, para calibrar el umbral)', $zt_near, 50 );

arsort( $zt_hist );
WP_CLI::log( '' );
WP_CLI::log( '== Señales disparadas (en cuántos posts):' );
foreach ( $zt_hist as $zt_k => $zt_n ) {
	WP_CLI::log( sprintf( '  %-20s %d', $zt_k, $zt_n ) );
}
WP_CLI::log( '' );
WP_CLI::log( sprintf( 'Revisados: %d posts en %.1fs | pico de memoria %.0f MB | límite %s | pausa %.1fs',
	$zt_scanned, microtime( true ) - $zt_t0, memory_get_peak_usage( true ) / 1048576, $zt_limit ? $zt_limit : 'todos', $zt_pause ) );
