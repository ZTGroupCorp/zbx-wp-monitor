<?php
/**
 * Limpieza al desinstalar: no dejar token ni resultados en la base.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ztgrp_monitor_token' );
delete_option( 'ztgrp_monitor_integrity' );
delete_option( 'ztgrp_monitor_integrity_state' );
delete_site_transient( 'ztgrp_monitor_checksums' );
