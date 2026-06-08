<?php
/**
 * Plugin Name:       ZT Zabbix WP Monitor
 * Plugin URI:        https://github.com/ZTGroupCorp/zbx-wp-monitor
 * Description:       Expone métricas de salud del sitio (updates, integridad del core, admins, cron, autoload) a Zabbix vía REST autenticado por token.
 * Version:           1.0.3
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

define( 'ZTGRP_MONITOR_VERSION', '1.0.3' );
define( 'ZTGRP_MONITOR_FILE', __FILE__ );

require_once __DIR__ . '/includes/metrics.php';
require_once __DIR__ . '/includes/integrity.php';

/* -------------------------------------------------------------------------
 * Almacenamiento multisite-aware
 *
 * En una red Multisite el plugin se ACTIVA EN RED (Network Activate) y se
 * comporta como "un host por red": un único token, integridad del core corrida
 * una sola vez (sitio principal) y endpoint en el sitio principal. Estos helpers
 * enrutan a options de red (get/update/delete_site_option) cuando hay multisite,
 * y caen a las options normales en instalaciones single-site.
 * ---------------------------------------------------------------------- */

function ztgrp_monitor_net_get( $key, $default = false ) {
	return is_multisite() ? get_site_option( $key, $default ) : get_option( $key, $default );
}

function ztgrp_monitor_net_set( $key, $value ) {
	return is_multisite() ? update_site_option( $key, $value ) : update_option( $key, $value, false );
}

function ztgrp_monitor_net_del( $key ) {
	return is_multisite() ? delete_site_option( $key ) : delete_option( $key );
}

function ztgrp_monitor_get_token() {
	return (string) ztgrp_monitor_net_get( 'ztgrp_monitor_token' );
}

function ztgrp_monitor_set_token( $token ) {
	ztgrp_monitor_net_set( 'ztgrp_monitor_token', $token );
}

/* -------------------------------------------------------------------------
 * Activación / desactivación
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 'ztgrp_monitor_activate' );
register_deactivation_hook( __FILE__, 'ztgrp_monitor_deactivate' );

function ztgrp_monitor_activate() {
	if ( '' === ztgrp_monitor_get_token() ) {
		ztgrp_monitor_set_token( ztgrp_monitor_generate_token() );
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

/**
 * Migración en upgrade: el hook de activación NO se dispara al actualizar, así
 * que una red que ya venía con el plugin (token guardado por-blog en el sitio
 * principal) necesita mover ese token al storage de red. Idempotente: una vez
 * que existe el token de red, el cuerpo no vuelve a ejecutarse. Costo nulo en
 * single-site (sale en la primera condición).
 */
add_action( 'plugins_loaded', 'ztgrp_monitor_maybe_upgrade' );

function ztgrp_monitor_maybe_upgrade() {
	if ( ! is_multisite() ) {
		return; // En single-site el storage no cambió: nada que migrar.
	}

	// 1. Token: si falta el de red, preservar el previo (vivía en las options del
	//    sitio principal) o generar uno nuevo. Así una red ya operativa no pierde
	//    su token al actualizar.
	if ( '' === (string) get_site_option( 'ztgrp_monitor_token' ) ) {
		$legacy = (string) get_blog_option( get_main_site_id(), 'ztgrp_monitor_token' );
		update_site_option(
			'ztgrp_monitor_token',
			'' !== $legacy ? $legacy : ztgrp_monitor_generate_token()
		);
		if ( is_main_site() && ! wp_next_scheduled( 'ztgrp_monitor_integrity_run' ) ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'ztgrp_monitor_integrity_run' );
			wp_schedule_single_event( time() + 120, 'ztgrp_monitor_integrity_run' );
		}
	}

	// 2. Integridad: si la option de red está vacía, arrastrar el último resultado
	//    del sitio principal para NO reportar un falso OK (el default es ok=1) hasta
	//    que corra el próximo cron. Independiente del token: cubre también una red
	//    que ya saltó a una versión con storage de red pero con la integridad sin migrar.
	if ( false === get_site_option( 'ztgrp_monitor_integrity' ) ) {
		$legacy_integrity = get_blog_option( get_main_site_id(), 'ztgrp_monitor_integrity' );
		if ( is_array( $legacy_integrity ) ) {
			update_site_option( 'ztgrp_monitor_integrity', $legacy_integrity );
		}
	}
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
	$stored = ztgrp_monitor_get_token();
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

add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', 'ztgrp_monitor_admin_menu' );

function ztgrp_monitor_admin_menu() {
	if ( is_multisite() ) {
		// Configuración a nivel red: Network Admin → Settings.
		add_submenu_page(
			'settings.php',
			'ZT Zabbix WP Monitor',
			'ZT Zabbix Monitor',
			'manage_network_options',
			'zbx-wp-monitor',
			'ztgrp_monitor_settings_page'
		);
	} else {
		add_options_page(
			'ZT Zabbix WP Monitor',
			'ZT Zabbix Monitor',
			'manage_options',
			'zbx-wp-monitor',
			'ztgrp_monitor_settings_page'
		);
	}
}

function ztgrp_monitor_settings_page() {
	$cap = is_multisite() ? 'manage_network_options' : 'manage_options';
	if ( ! current_user_can( $cap ) ) {
		return;
	}

	if ( isset( $_POST['ztgrp_regen'] ) && check_admin_referer( 'ztgrp_monitor_regen' ) ) {
		ztgrp_monitor_set_token( ztgrp_monitor_generate_token() );
		echo '<div class="notice notice-success"><p>Token regenerado. Actualizá la macro {$WP.MON.TOKEN} en Zabbix.</p></div>';
	}

	if ( isset( $_POST['ztgrp_runcheck'] ) && check_admin_referer( 'ztgrp_monitor_runcheck' ) ) {
		ztgrp_monitor_net_del( 'ztgrp_monitor_integrity_state' );
		wp_schedule_single_event( time() + 5, 'ztgrp_monitor_integrity_run' );
		echo '<div class="notice notice-success"><p>Check de integridad encolado (corre por WP-Cron en la próxima visita).</p></div>';
	}

	$token     = ztgrp_monitor_get_token();
	$endpoint  = is_multisite()
		? get_rest_url( get_main_site_id(), 'ztgrp-monitor/v1/status' )
		: rest_url( 'ztgrp-monitor/v1/status' );
	$integrity = ztgrp_monitor_net_get( 'ztgrp_monitor_integrity' );
	?>
	<div class="wrap">
		<h1>ZT Zabbix WP Monitor <small>v<?php echo esc_html( ZTGRP_MONITOR_VERSION ); ?></small></h1>

		<h2>Endpoint</h2>
		<p><code><?php echo esc_html( $endpoint ); ?></code></p>
		<p>Auth: header <code>X-ZTGRP-Token</code> con el token de abajo.
			En Zabbix va en la macro secreta <code>{$WP.MON.TOKEN}</code> del host virtual.</p>

		<h2>Token</h2>
		<p>
			<input type="text" id="ztgrp-monitor-token" readonly value="<?php echo esc_attr( $token ); ?>"
				style="width: 40em; font-family: monospace;" onclick="this.select();">
			<button type="button" class="button" id="ztgrp-monitor-copy">Copiar al portapapeles</button>
			<span id="ztgrp-monitor-copied" style="color:green; display:none; margin-left:.5em;">¡Copiado!</span>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'ztgrp_monitor_regen' ); ?>
			<button class="button" name="ztgrp_regen" value="1"
				onclick="return confirm('¿Regenerar? El token actual deja de funcionar y hay que actualizar Zabbix.');">
				Regenerar token</button>
		</form>
		<script>
		( function () {
			var btn = document.getElementById( 'ztgrp-monitor-copy' );
			if ( ! btn ) { return; }
			btn.addEventListener( 'click', function () {
				var field = document.getElementById( 'ztgrp-monitor-token' );
				field.select();
				field.setSelectionRange( 0, 99999 );
				var done = function () {
					var ok = document.getElementById( 'ztgrp-monitor-copied' );
					ok.style.display = 'inline';
					setTimeout( function () { ok.style.display = 'none'; }, 2000 );
				};
				if ( navigator.clipboard && navigator.clipboard.writeText ) {
					navigator.clipboard.writeText( field.value ).then( done, function () {
						document.execCommand( 'copy' );
						done();
					} );
				} else {
					document.execCommand( 'copy' );
					done();
				}
			} );
		} )();
		</script>

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
