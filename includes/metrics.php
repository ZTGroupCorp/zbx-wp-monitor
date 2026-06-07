<?php
/**
 * Colectores de métricas — solo lectura, solo conteos, nada sensible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function ztgrp_monitor_collect_metrics() {
	$integrity = get_option( 'ztgrp_monitor_integrity' );
	if ( ! is_array( $integrity ) ) {
		$integrity = array();
	}

	return array(
		'plugin_version'        => ZTGRP_MONITOR_VERSION,
		'wp_version'            => get_bloginfo( 'version' ),
		'php_version'           => PHP_VERSION,
		'core_updates'          => ztgrp_monitor_count_core_updates(),
		'plugin_updates'        => ztgrp_monitor_count_plugin_updates(),
		'theme_updates'         => ztgrp_monitor_count_theme_updates(),
		'admins'                => ztgrp_monitor_count_admins(),
		'cron_overdue'          => ztgrp_monitor_count_cron_overdue(),
		'autoload_kb'           => ztgrp_monitor_autoload_kb(),
		'checksums_ok'          => isset( $integrity['ok'] ) ? (int) $integrity['ok'] : 1,
		'checksums_bad_count'   => isset( $integrity['bad_count'] ) ? (int) $integrity['bad_count'] : 0,
		'checksums_checked_at'  => isset( $integrity['checked_at'] ) ? (int) $integrity['checked_at'] : 0,
	);
}

/**
 * Updates de core disponibles (transient que WP refresca 2×/día solo).
 */
function ztgrp_monitor_count_core_updates() {
	$t     = get_site_transient( 'update_core' );
	$count = 0;
	if ( is_object( $t ) && ! empty( $t->updates ) && is_array( $t->updates ) ) {
		foreach ( $t->updates as $u ) {
			if ( isset( $u->response ) && 'upgrade' === $u->response ) {
				$count++;
			}
		}
	}
	return $count;
}

function ztgrp_monitor_count_plugin_updates() {
	$t = get_site_transient( 'update_plugins' );
	return ( is_object( $t ) && ! empty( $t->response ) && is_array( $t->response ) ) ? count( $t->response ) : 0;
}

function ztgrp_monitor_count_theme_updates() {
	$t = get_site_transient( 'update_themes' );
	return ( is_object( $t ) && ! empty( $t->response ) && is_array( $t->response ) ) ? count( $t->response ) : 0;
}

/**
 * Cantidad de administradores (trigger en Zabbix es por CAMBIO del valor).
 */
function ztgrp_monitor_count_admins() {
	$q = new WP_User_Query(
		array(
			'role'        => 'administrator',
			'fields'      => 'ID',
			'number'      => 1,
			'count_total' => true,
		)
	);
	return (int) $q->get_total();
}

/**
 * Eventos de WP-Cron atrasados más de 10 minutos.
 */
function ztgrp_monitor_count_cron_overdue() {
	$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
	if ( ! is_array( $crons ) ) {
		return 0;
	}
	$threshold = time() - 600;
	$overdue   = 0;
	foreach ( $crons as $timestamp => $hooks ) {
		if ( ! is_int( $timestamp ) || $timestamp > $threshold ) {
			continue;
		}
		foreach ( (array) $hooks as $events ) {
			$overdue += count( (array) $events );
		}
	}
	return $overdue;
}

/**
 * Peso de las options con autoload, en KB (WP 6.6+ usa varios valores de autoload).
 */
function ztgrp_monitor_autoload_kb() {
	global $wpdb;
	$values = function_exists( 'wp_autoload_values_to_autoload' )
		? wp_autoload_values_to_autoload()
		: array( 'yes' );
	$in    = "'" . implode( "','", array_map( 'esc_sql', $values ) ) . "'";
	$bytes = (int) $wpdb->get_var(
		"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload IN ($in)"
	);
	return (int) round( $bytes / 1024 );
}
