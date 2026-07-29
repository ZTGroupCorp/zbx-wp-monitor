<?php
/**
 * Integridad del core — job diario WP-Cron, por lotes para no exceder
 * max_execution_time: cada corrida procesa hasta ~10s y se re-agenda sola
 * con un cursor hasta terminar.
 *
 * Mismo criterio que la Fase B (wrapper wp-cli):
 *  - se ignora wp-content/ por completo (themes/plugins bundled cambian legítimamente),
 *  - readme.html / license.txt se ignoran siempre (ausentes por hardening o
 *    modificados: son texto sin rol ejecutable),
 *  - el locale contra el que se comparan los checksums es el del PAQUETE del core
 *    ($wp_local_package), no el del sitio (WPLANG) — ver
 *    ztgrp_monitor_core_package_locale(),
 *  - cualquier otro mismatch o ausencia cuenta como bad.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZTGRP_MONITOR_BATCH_SECONDS', 10 );
define( 'ZTGRP_MONITOR_BAD_LIST_CAP', 20 );

add_action( 'ztgrp_monitor_integrity_run', 'ztgrp_monitor_integrity_run' );

function ztgrp_monitor_integrity_run() {
	// En multisite el core es uno solo para toda la red: lo verifica únicamente
	// el sitio principal. Los subsitios no re-checan los mismos archivos.
	if ( is_multisite() && ! is_main_site() ) {
		return;
	}

	$wp_version = get_bloginfo( 'version' );
	$checksums  = ztgrp_monitor_get_checksums( $wp_version );

	if ( ! is_array( $checksums ) || empty( $checksums ) ) {
		// API de wp.org inaccesible: no inventar resultado; reintentar en 1h.
		// checked_at queda viejo → el item "edad del check" lo delata en Zabbix.
		wp_schedule_single_event( time() + HOUR_IN_SECONDS, 'ztgrp_monitor_integrity_run' );
		return;
	}

	$files = array_keys( $checksums );

	// El barrido en curso solo se retoma si sigue siendo comparable: misma versión
	// de WP, mismo locale de paquete y misma versión del plugin (un upgrade puede
	// cambiar el criterio de qué cuenta como bad, así que se re-barre desde cero
	// en lugar de arrastrar hallazgos con el criterio viejo).
	$locale = ztgrp_monitor_core_package_locale();
	$state  = ztgrp_monitor_net_get( 'ztgrp_monitor_integrity_state' );
	if ( ! is_array( $state )
		|| ! isset( $state['cursor'], $state['wp_version'], $state['locale'], $state['plugin_version'] )
		|| $state['wp_version'] !== $wp_version
		|| $state['locale'] !== $locale
		|| $state['plugin_version'] !== ZTGRP_MONITOR_VERSION ) {
		$state = array(
			'wp_version'     => $wp_version,
			'locale'         => $locale,
			'plugin_version' => ZTGRP_MONITOR_VERSION,
			'cursor'         => 0,
			'bad'            => array(),
			'bad_count'      => 0,
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

		// Benignos incondicionales: borrados a propósito (hardening) o editados
		// (aviso legal / branding). Archivos de texto sin rol ejecutable, así que
		// la exclusión va ANTES de distinguir ausencia de mismatch: dentro de la
		// rama de ausencia dejaba pasar como "bad" un license.txt modificado.
		$base = basename( $file );
		if ( 'readme.html' === $base || 'license.txt' === $base ) {
			continue;
		}

		$path = ABSPATH . $file;
		if ( ! file_exists( $path ) ) {
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
		ztgrp_monitor_net_set( 'ztgrp_monitor_integrity_state', $state );
		wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'ztgrp_monitor_integrity_run' );
		return;
	}

	ztgrp_monitor_net_set(
		'ztgrp_monitor_integrity',
		array(
			'ok'         => empty( $state['bad_count'] ) ? 1 : 0,
			'bad_count'  => (int) $state['bad_count'],
			'bad'        => $state['bad'],
			'checked_at' => time(),
			'wp_version' => $wp_version,
			'locale'     => $locale, // Locale del paquete usado para comparar (diagnóstico).
		)
	);
	ztgrp_monitor_net_del( 'ztgrp_monitor_integrity_state' );
}

function ztgrp_monitor_integrity_flag( &$state, $entry ) {
	$state['bad_count']++;
	if ( count( $state['bad'] ) < ZTGRP_MONITOR_BAD_LIST_CAP ) {
		$state['bad'][] = $entry;
	}
}

/**
 * Locale del PAQUETE del core instalado (no el del sitio).
 *
 * Los paquetes localizados de WordPress definen $wp_local_package dentro de
 * wp-includes/version.php; el paquete en_US no define esa variable. get_locale()
 * en cambio sale de la opción WPLANG, que es preferencia de idioma del sitio y
 * puede no coincidir con los archivos en disco (típico: sitio en es_ES corriendo
 * archivos en_US). Cuando no coinciden, comparar contra los checksums del locale
 * equivocado hace fallar exactamente un archivo, wp-includes/version.php, que es
 * el único que difiere entre paquetes fuera de wp-content/ — falso positivo.
 * wp-cli verify-checksums usa esta misma fuente, de ahí que discrepara del plugin.
 */
function ztgrp_monitor_core_package_locale() {
	global $wp_local_package;
	return ! empty( $wp_local_package ) ? (string) $wp_local_package : 'en_US';
}

/**
 * Checksums oficiales de wp.org, cacheados 24h (se consultan por lotes).
 * Locale del paquete primero; fallback a en_US si la API no responde para ese
 * locale (es lo que hace wp-cli).
 */
function ztgrp_monitor_get_checksums( $wp_version ) {
	$locale = ztgrp_monitor_core_package_locale();

	// El locale entra en la validación del cache: si cambió (paquete distinto o
	// arrastre de un cache viejo con el locale equivocado) hay que re-pedirlos.
	$cached = get_site_transient( 'ztgrp_monitor_checksums' );
	if ( is_array( $cached ) && isset( $cached['version'], $cached['sums'], $cached['locale'] )
		&& $cached['version'] === $wp_version && $cached['locale'] === $locale ) {
		return $cached['sums'];
	}

	if ( ! function_exists( 'get_core_checksums' ) ) {
		require_once ABSPATH . 'wp-admin/includes/update.php';
	}

	$sums = get_core_checksums( $wp_version, $locale );
	if ( ! is_array( $sums ) && 'en_US' !== $locale ) {
		$sums = get_core_checksums( $wp_version, 'en_US' );
	}
	if ( ! is_array( $sums ) ) {
		return false;
	}

	set_site_transient(
		'ztgrp_monitor_checksums',
		array(
			'version' => $wp_version,
			'locale'  => $locale,
			'sums'    => $sums,
		),
		DAY_IN_SECONDS
	);
	return $sums;
}
