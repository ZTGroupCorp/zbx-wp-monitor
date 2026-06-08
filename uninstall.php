<?php
/**
 * Limpieza al desinstalar: no dejar token ni resultados en la base.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$ztgrp_keys = array(
	'ztgrp_monitor_token',
	'ztgrp_monitor_integrity',
	'ztgrp_monitor_integrity_state',
);

// En multisite el plugin guarda en options de RED (Network Activate → un host por red).
if ( is_multisite() ) {
	foreach ( $ztgrp_keys as $ztgrp_key ) {
		delete_site_option( $ztgrp_key );
	}
} else {
	foreach ( $ztgrp_keys as $ztgrp_key ) {
		delete_option( $ztgrp_key );
	}
}

delete_site_transient( 'ztgrp_monitor_checksums' );
