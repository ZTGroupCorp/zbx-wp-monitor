<?php
/**
 * Métricas de rendimiento (1.3.0) — solo lectura: base de datos (`db_*`), caches de
 * PHP y de objetos (`cache_*`) y latencia (`lat_*`). Recursos del servidor (CPU, RAM,
 * disco) quedan fuera: los mide el agente Zabbix del host.
 *
 * Lo que no se puede medir sale como null (no 0), para que Zabbix distinga "no
 * disponible" de "cero". En multisite todo refleja el sitio principal, igual que
 * `autoload_kb`, salvo `db_size_mb` / `db_overhead_mb`, que suman la red entera.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'ztgrp_monitor_db_refresh', 'ztgrp_monitor_db_refresh' );

/**
 * Métricas de base de datos, leídas del último resultado guardado. La medición NO
 * corre dentro del endpoint: en elimpulso (17 GB, 116k revisiones) tarda ~2,4 s y
 * se comería el timeout del HTTP agent de Zabbix. Si el resultado tiene más de una
 * hora o es de otra versión del plugin, se agenda un refresco por WP-Cron y se
 * devuelve el anterior; sin resultado todavía, null con `db_checked_at` = 0.
 */
function ztgrp_monitor_db_metrics() {
	$data = ztgrp_monitor_net_get( 'ztgrp_monitor_db' );
	$have = is_array( $data ) && isset( $data['db_checked_at'] );

	if ( ! $have
		|| (int) $data['db_checked_at'] < time() - HOUR_IN_SECONDS
		|| ! isset( $data['version'] ) || ZTGRP_MONITOR_VERSION !== $data['version'] ) {
		if ( ! wp_next_scheduled( 'ztgrp_monitor_db_refresh' ) ) {
			wp_schedule_single_event( time(), 'ztgrp_monitor_db_refresh' );
		}
	}

	$out = array(
		'db_size_mb'            => null,
		'db_overhead_mb'        => null,
		'db_revisions'          => null,
		'db_expired_transients' => null,
		'db_options_rows'       => null,
		'db_checked_at'         => 0,
	);
	if ( $have ) {
		foreach ( $out as $k => $v ) {
			if ( array_key_exists( $k, $data ) ) {
				$out[ $k ] = $data[ $k ];
			}
		}
	}
	return $out;
}

/**
 * Evento de WP-Cron: mide y guarda (option de red en multisite).
 */
function ztgrp_monitor_db_refresh() {
	$data            = ztgrp_monitor_db_measure();
	$data['version'] = ZTGRP_MONITOR_VERSION;
	ztgrp_monitor_net_set( 'ztgrp_monitor_db', $data );
}

function ztgrp_monitor_db_measure() {
	global $wpdb;

	$prev_suppress = $wpdb->suppress_errors( true );

	// Tablas de esta instalación (prefijo de red): en hosts compartidos el mismo
	// schema puede tener otras instalaciones o apps. El "_" del prefijo es comodín
	// en LIKE, por eso esc_like.
	$like   = $wpdb->esc_like( $wpdb->base_prefix ) . '%';
	$tables = $wpdb->get_results(
		$wpdb->prepare(
			'SELECT ENGINE AS e, DATA_LENGTH AS d, INDEX_LENGTH AS i, DATA_FREE AS f
			   FROM information_schema.TABLES
			  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s AND TABLE_TYPE = %s',
			$like,
			'BASE TABLE'
		)
	);

	$size_mb     = null;
	$overhead_mb = null;
	if ( is_array( $tables ) && $tables ) {
		$size = 0;
		$free = 0;
		// Con innodb_file_per_table=0 las tablas InnoDB viven en el tablespace
		// compartido y cada una reporta el DATA_FREE de todo ibdata: sumarlo
		// multiplicaría el mismo espacio libre. En ese caso solo cuenta MyISAM/Aria.
		$per_table = (int) $wpdb->get_var( 'SELECT @@innodb_file_per_table' );
		foreach ( $tables as $t ) {
			$size += (float) $t->d + (float) $t->i;
			if ( 'InnoDB' !== $t->e || 1 === $per_table ) {
				$free += (float) $t->f;
			}
		}
		$size_mb     = round( $size / 1048576, 1 );
		$overhead_mb = round( $free / 1048576, 1 );
	}

	// Conteos del sitio principal (prefijo explícito: en cron o CLI el blog actual
	// puede ser otro).
	$main    = is_multisite() ? $wpdb->get_blog_prefix( get_main_site_id() ) : $wpdb->prefix;
	$posts   = $main . 'posts';
	$options = $main . 'options';

	$revisions = $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM `$posts` WHERE post_type = %s", 'revision' )
	);
	// Transients vencidos que nadie borró. Con object cache externo los transients
	// no pasan por la tabla y el valor es 0 (correcto: no ocupan la BD).
	$expired = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM `$options` WHERE option_name LIKE %s AND option_value < %d",
			$wpdb->esc_like( '_transient_timeout_' ) . '%',
			time()
		)
	);
	$opt_rows = $wpdb->get_var( "SELECT COUNT(*) FROM `$options`" );

	$wpdb->suppress_errors( $prev_suppress );

	return array(
		'db_size_mb'            => $size_mb,
		'db_overhead_mb'        => $overhead_mb,
		'db_revisions'          => null === $revisions ? null : (int) $revisions,
		'db_expired_transients' => null === $expired ? null : (int) $expired,
		'db_options_rows'       => null === $opt_rows ? null : (int) $opt_rows,
		'db_checked_at'         => time(),
	);
}

/**
 * Caches: object cache persistente, OPcache y límites de PHP. Se mide en la
 * petición del endpoint (PHP-FPM / módulo web); desde CLI OPcache suele estar apagado.
 */
function ztgrp_monitor_cache_metrics() {
	$opcache  = null;
	$hit_rate = null;
	$used_pct = null;

	if ( function_exists( 'opcache_get_status' ) ) {
		// Con opcache.restrict_api la llamada devuelve false con un warning.
		$st = @opcache_get_status( false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( is_array( $st ) ) {
			$opcache = empty( $st['opcache_enabled'] ) ? 0 : 1;
			if ( $opcache && isset( $st['opcache_statistics']['opcache_hit_rate'] ) ) {
				$hit_rate = round( (float) $st['opcache_statistics']['opcache_hit_rate'], 2 );
			}
			if ( $opcache && isset( $st['memory_usage']['used_memory'], $st['memory_usage']['free_memory'] ) ) {
				$used  = (float) $st['memory_usage']['used_memory'];
				$total = $used + (float) $st['memory_usage']['free_memory']
					+ ( isset( $st['memory_usage']['wasted_memory'] ) ? (float) $st['memory_usage']['wasted_memory'] : 0 );
				$used_pct = $total > 0 ? round( $used / $total * 100, 1 ) : null;
			}
		} else {
			$opcache = (int) ini_get( 'opcache.enable' ) ? null : 0;
		}
	} else {
		$opcache = 0;
	}

	$mem = trim( (string) ini_get( 'memory_limit' ) );
	if ( '-1' === $mem ) {
		$mem_mb = -1;
	} elseif ( '' === $mem ) {
		$mem_mb = null;
	} else {
		$mem_mb = (int) round( wp_convert_hr_to_bytes( $mem ) / 1048576 );
	}

	return array(
		'cache_object'           => wp_using_ext_object_cache() ? 1 : 0,
		'cache_opcache'          => $opcache,
		'cache_opcache_hit_rate' => $hit_rate,
		'cache_opcache_used_pct' => $used_pct,
		'cache_php_mem_limit_mb' => $mem_mb,
		'cache_php_max_exec'     => (int) ini_get( 'max_execution_time' ),
	);
}

/**
 * Latencia de la base: ida y vuelta de un SELECT 1, en ms.
 */
function ztgrp_monitor_latency_db_ms() {
	global $wpdb;
	$prev = $wpdb->suppress_errors( true );
	$t0   = microtime( true );
	$ok   = $wpdb->get_var( 'SELECT 1' );
	$ms   = ( microtime( true ) - $t0 ) * 1000;
	$wpdb->suppress_errors( $prev );
	return '1' === (string) $ok ? round( $ms, 2 ) : null;
}

/**
 * Tiempo desde que PHP recibió la petición hasta este punto (carga de WordPress,
 * plugins y armado de las métricas), en ms. Se llama al final del colector.
 */
function ztgrp_monitor_latency_req_ms() {
	if ( empty( $_SERVER['REQUEST_TIME_FLOAT'] ) ) {
		return null;
	}
	return round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000, 1 );
}
