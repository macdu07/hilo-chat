<?php
/**
 * Email sent when an agent replies and the visitor is away.
 *
 * Vars: $messages (array), $agent (string), $link (string), $name (string).
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

$brand   = HILO_Settings::brand();
$primary = sanitize_hex_color( HILO_Settings::get( 'color_primary' ) ) ?: '#1f2937';
$accent  = sanitize_hex_color( HILO_Settings::get( 'color_accent' ) ) ?: '#2563eb';
$soft    = sanitize_hex_color( HILO_Settings::get( 'color_soft' ) ) ?: '#f3f4f6';
$logo    = HILO_Settings::get( 'logo_url' );
$site    = wp_parse_url( home_url(), PHP_URL_HOST );
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( $soft ); ?>;font-family:Helvetica,Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:<?php echo esc_attr( $soft ); ?>;padding:32px 12px;">
<tr><td align="center">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;">
		<tr><td style="background:<?php echo esc_attr( $primary ); ?>;padding:22px 28px;">
			<?php if ( $logo ) : ?>
				<img src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( $brand ); ?>" height="32" style="height:32px;width:auto;display:block;">
			<?php else : ?>
				<span style="font-family:Georgia,serif;font-size:21px;letter-spacing:.04em;color:#ffffff;"><?php echo esc_html( $brand ); ?></span>
			<?php endif; ?>
		</td></tr>
		<tr><td style="padding:28px 28px 8px;">
			<p style="margin:0 0 6px;font-size:18px;font-weight:700;">
				<?php
				/* translators: %s: customer name. */
				echo esc_html( $name ? sprintf( __( 'Hi %s,', 'hilo-chat' ), $name ) : __( 'Hi,', 'hilo-chat' ) );
				?>
			</p>
			<p style="margin:0 0 20px;font-size:15px;line-height:1.55;color:#4b5563;">
				<?php
				/* translators: 1: agent name, 2: store name. */
				echo esc_html( sprintf( __( '%1$s replied to your message in the %2$s chat:', 'hilo-chat' ), $agent, $brand ) );
				?>
			</p>
			<?php foreach ( $messages as $m ) : ?>
				<div style="background:<?php echo esc_attr( $soft ); ?>;border-radius:14px 14px 14px 4px;padding:12px 16px;margin:0 0 10px;font-size:15px;line-height:1.5;">
					<?php if ( '' !== $m['body'] ) : ?>
						<?php echo nl2br( esc_html( $m['body'] ) ); ?>
					<?php endif; ?>
					<?php if ( ! empty( $m['attachment']['url'] ) ) : ?>
						<div style="margin-top:8px;"><a href="<?php echo esc_url( $m['attachment']['url'] ); ?>" style="color:<?php echo esc_attr( $accent ); ?>;"><?php esc_html_e( 'View attachment', 'hilo-chat' ); ?></a></div>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</td></tr>
		<tr><td style="padding:12px 28px 32px;">
			<a href="<?php echo esc_url( $link ); ?>" style="display:inline-block;background:<?php echo esc_attr( $primary ); ?>;color:#ffffff;text-decoration:none;padding:13px 24px;border-radius:999px;font-size:15px;font-weight:700;"><?php esc_html_e( 'Continue the conversation', 'hilo-chat' ); ?></a>
			<p style="margin:18px 0 0;font-size:13px;color:#6b7280;line-height:1.5;">
				<?php
				printf(
					/* translators: %s: link to the store. */
					esc_html__( 'You can also reply by opening the chat at %s.', 'hilo-chat' ),
					'<a href="' . esc_url( home_url( '/' ) ) . '" style="color:' . esc_attr( $accent ) . ';">' . esc_html( $site ) . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput
				);
				?>
			</p>
		</td></tr>
	</table>
</td></tr>
</table>
</body>
</html>
