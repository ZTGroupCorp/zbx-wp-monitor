<?php
/**
 * Integridad del core — job diario WP-Cron, por lotes para no exceder
 * max_execution_time: cada corrida procesa hasta ~10s y se re-agenda sola
 * con un cursor hasta terminar.
 *
 * Mismo criterio que la Fase B (wrapper wp-cli):
 *  - se ignora wp-content/ por completo (themes/plugins bundled cambian legítimamente),
 *  - readme.html / license.txt AUSENTES son benignos (hardening común),
 *  - cualquier otro mismatch o ausencia cuenta como bad.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZTGRP_MONITOR_BATCH_SECONDS', 10 );
define( 'ZTGRP_MONITOR_BAD_LIST_CAP', 20 );

add_action( 'ztgrp_monitor_integrity_run', 'ztgrp_monitor_integrity_run' );

function ztgrp_monitor_integrity_run() {
	$wp_version = get_bloginfo( 'version' );
	$checksums  = ztgrp_monitor_get_checksums( $wp_version );

	if ( ! is_array( $checksums ) || empty( $checksums ) ) {
		// API de wp.org inaccesible: no inventar resultado; reintentar en 1h.
		// checked_at queda viejo → el item "edad del check" lo delata en Zabbix.
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'ztgrp_monitor_integrity_run' );
		return;
	}

	$files = array_keys( $checksums );
	$state = get_option( 'ztgrp_monitor_integrity_state' );
	if ( ! is_array( $state ) || ! isset( $state['cursor'] ) || $state['wp_version'] !== $wp_version ) {
		$state = array(
			'wp_version' => $wp_version,
			'cursor'     => 0,
			'bad'        => array(),
			'bad_count'  => 0,
		);
	}

	$total    = count( $files );
	$deadline = time() + ZTGRP_MONITOR_BATCH_SECONDS;

	while ( $state['cursor'] < $total && time() < $deadline ) {
		$file = $files[ $state['cursor'] ];
		$state['cursor']++;

		// wp-content no se verifica (igual que wp-cli verify-checksums).
		if ( 0 === strpos( $file, 'wp-content/' ) ) {
			continue;
		}

		$path = ABSPATH . $file;
		if ( ! file_exists( $path ) ) {
			$base = basename( $file );
			if ( 'readme.html' === $base || 'license.txt' === $base ) {
				continue; // Benigno: borrados a propósito (hardening).
			}
			ztgrp_monitor_integrity_flag( $state, $file . ' (ausente)' );
			continue;
		}

		$md5 = md5_file( $path );
		if ( false !== $md5 && ! hash_equals( $checksums[ $file ], $md5 ) ) {
			ztgrp_monitor_integrity_flag( $state, $file );
		}
	}

	if ( $state['cursor'] < $total ) {
		// No terminó: guardar cursor y continuar en ~1 min.
		update_option( 'ztgrp_monitor_integrity_state', $state, false );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'ztgrp_monitor_integrity_run' );
		return;
	}

	update_option(
		'ztgrp_monitor_integrity',
		array(
			'ok'         => empty( $state['bad_count'] ) ? 1 : 0,
			'bad_count'  => (int) $state['bad_count'],
			'bad'        => $state['bad'],
			'checked_at' => time(),
			'wp_version' => $wp_version,
		),
		false
	);
	delete_option( 'ztgrp_monitor_integrity_state' );
}

function ztgrp_monitor_integrity_flag( &$state, $entry ) {
	$state['bad_count']++;
	if ( count( $state['bad'] ) < ZTGRP_MONITOR_BAD_LIST_CAP ) {
		$state['bad'][] = $entry;
	}
}

/**
 * Checksums oficiales de wp.org, cacheados 24h (se consultan por lotes).
 * Locale del sitio primero; fallback a en_US (es lo que hace wp-cli).
 */
function ztgrp_monitor_get_checksums( $wp_version ) {
	$cached = get_site_transient( 'ztgrp_monitor_checksums' );
	if ( is_array( $cached ) && isset( $cached['version'], $cached['sums'] ) && $cached['version'] === $wp_version ) {
		return $cached['sums'];
	}

	if ( ! function_exists( 'get_core_checksums' ) ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}

	$sums = get_core_checksums( $wp_version, get_locale() );
	if ( ! is_array( $sums ) && 'en_US' !== get_locale() ) {
		$sums = get_core_checksums( $wp_version, 'en_US' );
	}
	if ( ! is_array( $sums ) ) {
		return false;
	}

	set_site_transient(
		'ztgrp_monitor_checksums',
		array(
			'version' => $wp_version,
			'sums'    => $sums,
		),
		DAY_IN_SECONDS
	);
	return $sums;
}
