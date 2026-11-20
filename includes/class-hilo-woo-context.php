<?php
/**
 * WooCommerce customer context for agents.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builds cart snapshots and customer order history.
 */
class HILO_Woo_Context {

	/**
	 * Builds a context snapshot from the visitor request.
	 *
	 * @param WP_REST_Request $request Request with page, title, ref.
	 * @param array           $current Existing context.
	 * @return array
	 */
	public static function snapshot( $request, array $current = array() ) {
		$ctx  = $current;
		$page = esc_url_raw( (string) $request->get_param( 'page' ) );
		if ( $page && 0 === strpos( $page, home_url() ) ) {
			$ctx['page'] = array(
				'url'   => $page,
				'title' => wp_html_excerpt( sanitize_text_field( (string) $request->get_param( 'title' ) ), 140 ),
				'at'    => time(),
			);
			$history   = isset( $ctx['pages'] ) && is_array( $ctx['pages'] ) ? $ctx['pages'] : array();
			$last      = end( $history );
			if ( ! $last || $last['url'] !== $page ) {
				$history[] = $ctx['page'];
			}
			$ctx['pages'] = array_slice( $history, -8 );
		}
		$ref = esc_url_raw( (string) $request->get_param( 'ref' ) );
		if ( $ref && empty( $ctx['referrer'] ) && 0 !== strpos( $ref, home_url() ) ) {
			$ctx['referrer'] = $ref;
		}

		$cart = self::cart();
		if ( null !== $cart ) {
			$ctx['cart'] = $cart;
		}
		return $ctx;
	}

	/**
	 * Current cart summary, or null when unavailable.
	 *
	 * @return array|null
	 */
	private static function cart() {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		try {
			if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
				wc_load_cart();
			}
			if ( ! WC()->cart ) {
				return null;
			}
			$items = array();
			foreach ( WC()->cart->get_cart() as $item ) {
				$product = isset( $item['data'] ) ? $item['data'] : null;
				if ( ! $product instanceof WC_Product ) {
					continue;
				}
				$image   = wp_get_attachment_image_url( $product->get_image_id(), 'thumbnail' );
				$items[] = array(
					'name'  => wp_strip_all_tags( $product->get_name() ),
					'qty'   => (int) $item['quantity'],
					'total' => html_entity_decode( wp_strip_all_tags( wc_price( (float) $item['line_total'] + (float) $item['line_tax'] ) ) ),
					'url'   => $product->get_permalink(),
					'image' => $image ? $image : '',
					'sku'   => $product->get_sku(),
				);
			}
			return array(
				'items' => array_slice( $items, 0, 20 ),
				'total' => html_entity_decode( wp_strip_all_tags( WC()->cart->get_total() ) ),
				'at'    => time(),
			);
		} catch ( Throwable $e ) {
			return null;
		}
	}

	/**
	 * Customer profile and recent orders.
	 *
	 * @param object $c Conversation.
	 * @return array
	 */
	public static function customer( $c ) {
		$out = array(
			'registered' => false,
			'orders'     => array(),
		);

		$args = array(
			'limit'   => 5,
			'orderby' => 'date',
			'order'   => 'DESC',
			'type'    => 'shop_order',
		);
		if ( $c->user_id ) {
			$user = get_userdata( $c->user_id );
			if ( $user ) {
				$customer          = new WC_Customer( $user->ID );
				$out['registered'] = true;
				$out['since']      = strtotime( $user->user_registered );
				$out['orderCount'] = (int) $customer->get_order_count();
				$out['totalSpent'] = html_entity_decode( wp_strip_all_tags( wc_price( (float) $customer->get_total_spent() ) ) );
				$out['phone']      = $customer->get_billing_phone();
				$out['city']       = trim( $customer->get_billing_city() . ', ' . $customer->get_billing_state(), ', ' );
				$out['profileUrl'] = admin_url( 'user-edit.php?user_id=' . $user->ID );
			}
			$args['customer_id'] = (int) $c->user_id;
		} elseif ( is_email( $c->email ) ) {
			$args['billing_email'] = $c->email;
		} else {
			return $out;
		}

		$orders = wc_get_orders( $args );
		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = $item->get_quantity() . '× ' . $item->get_name();
			}
			$out['orders'][] = array(
				'number'  => $order->get_order_number(),
				'status'  => wc_get_order_status_name( $order->get_status() ),
				'slug'    => $order->get_status(),
				'total'   => html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ) ),
				'date'    => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
				'items'   => array_slice( $items, 0, 6 ),
				'editUrl' => $order->get_edit_order_url(),
			);
			if ( empty( $out['phone'] ) && $order->get_billing_phone() ) {
				$out['phone'] = $order->get_billing_phone();
			}
		}
		return $out;
	}
}
