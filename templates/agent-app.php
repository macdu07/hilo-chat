<?php
/**
 * Agent app shell.
 *
 * Vars: $config, $icons, $assets, $ver.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;
$theme_color = $config['theme']['primary'];
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content">
	<meta name="robots" content="noindex, nofollow">
	<title><?php echo esc_html( $config['brand'] . ' · ' . __( 'Chats', 'hilo-chat' ) ); ?></title>
	<meta name="theme-color" content="<?php echo esc_attr( $theme_color ); ?>">
	<meta name="apple-mobile-web-app-capable" content="yes">
	<meta name="mobile-web-app-capable" content="yes">
	<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
	<meta name="apple-mobile-web-app-title" content="<?php echo esc_attr( $config['brand'] ); ?>">
	<link rel="manifest" href="<?php echo esc_url( HILO_Settings::app_url() . 'manifest' ); ?>">
	<link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( $icons['192'] ); ?>">
	<link rel="apple-touch-icon" href="<?php echo esc_url( $icons['apple'] ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( $assets . 'app.css?ver=' . $ver ); ?>">
	<style>:root{--primary:<?php echo esc_html( $config['theme']['primary'] ); ?>;--accent:<?php echo esc_html( $config['theme']['accent'] ); ?>;--soft:<?php echo esc_html( $config['theme']['soft'] ); ?>;}</style>
	<script>window.HiloAgentConfig = <?php echo wp_json_encode( $config ); ?>;</script>
</head>
<body>
	<div id="app" class="app" data-view="list">
		<div class="boot"><div class="boot-spinner" aria-label="<?php esc_attr_e( 'Loading', 'hilo-chat' ); ?>"></div></div>
	</div>
	<noscript><?php esc_html_e( 'Turn on JavaScript to use the chat app.', 'hilo-chat' ); ?></noscript>
	<script src="<?php echo esc_url( $assets . 'app.js?ver=' . $ver ); ?>" defer></script>
</body>
</html>
