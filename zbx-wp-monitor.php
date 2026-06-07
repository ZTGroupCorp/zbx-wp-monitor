<?php
/**
 * Plugin Name:       ZT Group — Zabbix WP Monitor
 * Plugin URI:        https://github.com/ZTGroupCorp/zbx-wp-monitor
 * Description:       Expone métricas de salud del sitio (updates, integridad del core, admins, cron, autoload) a Zabbix vía REST autenticado por token.
 * Version:           1.0.1
 * Author:            ZT Group
 * Author URI:        https://ztgroupcorp.com
 * License:           GPL-2.0-or-later
 * Update URI:        https://github.com/ZTGroupCorp/zbx-wp-monitor
 * Requires at least: 5.0
 * Requires PHP:      7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ZTGRP_MONITOR_VERSION', '1.0.1' );
define( 'ZTGRP_MONITOR_FILE', __FILE__ );

require_once __DIR__ . '/includes/metrics.php';
require_once __DIR__ . '/includes/integrity.php';

/* -------------------------------------------------------------------------
 * Activación / desactivación
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'ztgrp_monitor_activate' );
register_deactivation_hook( __FILE__, 'ztgrp_monitor_deactivate' );

function ztgrp_monitor_activate() {
	if ( ! get_option( 'ztgrp_monitor_token' ) ) {
		add_option( 'ztgrp_monitor_token', ztgrp_monitor_generate_token(), '', 'no' );
	}
	if ( ! wp_next_scheduled( 'ztgrp_monitor_integrity_run' ) ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'ztgrp_monitor_integrity_run' );
	}
	// Primera corrida de integridad a los 2 minutos para tener datos pronto.
	wp_schedule_single_event( time() + 120, 'ztgrp_monitor_integrity_run' );
}

function ztgrp_monitor_deactivate() {
	wp_clear_scheduled_hook( 'ztgrp_monitor_integrity_run' );
}

function ztgrp_monitor_generate_token() {
	return bin2hex( random_bytes( 32 ) ); // 64 chars hex.
}

/* -------------------------------------------------------------------------
 * REST: GET /wp-json/ztgrp-monitor/v1/status (header X-ZTGRP-Token)
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', 'ztgrp_monitor_register_routes' );

function ztgrp_monitor_register_routes() {
	register_rest_route(
		'ztgrp-monitor/v1',
		'/status',
		array(
			'methods'             => 'GET',
			'callback'            => 'ztgrp_monitor_rest_status',
			'permission_callback' => 'ztgrp_monitor_rest_auth',
			'show_in_index'       => false,
		)
	);
}

function ztgrp_monitor_rest_auth( $request ) {
	$stored = (string) get_option( 'ztgrp_monitor_token' );
	$given  = (string) $request->get_header( 'x-ztgrp-token' );
	if ( '' === $stored || '' === $given ) {
		return false;
	}
	return hash_equals( $stored, $given );
}

function ztgrp_monitor_rest_status() {
	return rest_ensure_response( ztgrp_monitor_collect_metrics() );
}

/* -------------------------------------------------------------------------
 * Página de ajustes: ver/copiar token, regenerar, forzar check de integridad
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'ztgrp_monitor_admin_menu' );

function ztgrp_monitor_admin_menu() {
	add_options_page(
		'Zabbix WP Monitor',
		'Zabbix Monitor',
		'manage_options',
		'zbx-wp-monitor',
		'ztgrp_monitor_settings_page'
	);
}

function ztgrp_monitor_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['ztgrp_regen'] ) && check_admin_referer( 'ztgrp_monitor_regen' ) ) {
		update_option( 'ztgrp_monitor_token', ztgrp_monitor_generate_token(), 'no' );
		echo '<div class="notice notice-success"><p>Token regenerado. Actualizá la macro {$WP.MON.TOKEN} en Zabbix.</p></div>';
	}

	if ( isset( $_POST['ztgrp_runcheck'] ) && check_admin_referer( 'ztgrp_monitor_runcheck' ) ) {
		delete_option( 'ztgrp_monitor_integrity_state' );
		wp_schedule_single_event( time() + 5, 'ztgrp_monitor_integrity_run' );
		echo '<div class="notice notice-success"><p>Check de integridad encolado (corre por WP-Cron en la próxima visita).</p></div>';
	}

	$token     = (string) get_option( 'ztgrp_monitor_token' );
	$endpoint  = rest_url( 'ztgrp-monitor/v1/status' );
	$integrity = get_option( 'ztgrp_monitor_integrity' );
	?>
	<div class="wrap">
		<h1>Zabbix WP Monitor <small>v<?php echo esc_html( ZTGRP_MONITOR_VERSION ); ?></small></h1>

		<h2>Endpoint</h2>
		<p><code><?php echo esc_html( $endpoint ); ?></code></p>
		<p>Auth: header <code>X-ZTGRP-Token</code> con el token de abajo.
			En Zabbix va en la macro secreta <code>{$WP.MON.TOKEN}</code> del host virtual.</p>

		<h2>Token</h2>
		<input type="text" readonly value="<?php echo esc_attr( $token ); ?>"
			style="width: 40em; font-family: monospace;" onclick="this.select();">
		<form method="post" style="display:inline">
			<?php wp_nonce_field( 'ztgrp_monitor_regen' ); ?>
			<button class="button" name="ztgrp_regen" value="1"
				onclick="return confirm('¿Regenerar? El token actual deja de funcionar y hay que actualizar Zabbix.');">
				Regenerar token</button>
		</form>

		<h2>Integridad del core</h2>
		<?php if ( is_array( $integrity ) && ! empty( $integrity['checked_at'] ) ) : ?>
			<p>Último check: <?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $integrity['checked_at'] ) ); ?> UTC —
				<?php if ( ! empty( $integrity['ok'] ) ) : ?>
					<strong style="color:green">OK</strong>
				<?php else : ?>
					<strong style="color:#c00"><?php echo (int) $integrity['bad_count']; ?> archivo(s) con problema</strong>
				<?php endif; ?>
			</p>
			<?php if ( ! empty( $integrity['bad'] ) ) : ?>
				<ul style="font-family:monospace">
					<?php foreach ( $integrity['bad'] as $f ) : ?>
						<li><?php echo esc_html( $f ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		<?php else : ?>
			<p>Todavía no corrió. Corre a diario vía WP-Cron.</p>
		<?php endif; ?>
		<form method="post">
			<?php wp_nonce_field( 'ztgrp_monitor_runcheck' ); ?>
			<button class="button" name="ztgrp_runcheck" value="1">Ejecutar check ahora</button>
		</form>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * Auto-update: Plugin Update Checker → releases de GitHub
 * ---------------------------------------------------------------------- */

if ( PHP_VERSION_ID >= 70200 && file_exists( __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once __DIR__ . '/vendor/plugin-update-checker/plugin-update-checker.php';

	$ztgrp_monitor_uc = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/ZTGroupCorp/zbx-wp-monitor/',
		__FILE__,
		'zbx-wp-monitor'
	);
	$ztgrp_monitor_uc->getVcsApi()->enableReleaseAssets();
}

// Auto-update SIEMPRE activo para este plugin (la flota se actualiza sola).
add_filter( 'auto_update_plugin', 'ztgrp_monitor_force_auto_update', 10, 2 );

function ztgrp_monitor_force_auto_update( $update, $item ) {
	if ( isset( $item->plugin ) && plugin_basename( ZTGRP_MONITOR_FILE ) === $item->plugin ) {
		return true;
	}
	return $update;
}
