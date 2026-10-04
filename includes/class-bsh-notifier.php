<?php
/**
 * Telegram and Bale notifications: new Basalam order, important errors, and orders found by
 * the nightly reconciliation. Every message is sent from the background queue (never inside
 * a page load), retried on failure, and can be turned off per channel and per event.
 *
 * Telegram's API is often unreachable from hosts inside Iran; Bale's is not. A relay URL
 * for Telegram can be set for such hosts.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Notifier {

	const OPTION = 'bsh_notify';
	const HOOK   = 'bsh_notify_send';

	/** Errors per hour before further error alerts are held back (no flood). */
	const ERROR_CAP = 10;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'bsh_logged', array( __CLASS__, 'on_logged' ), 10, 3 );
		add_action( self::HOOK, array( __CLASS__, 'handle_send' ), 10, 2 );
	}

	/**
	 * @return array
	 */
	public static function channels() {
		return array(
			'bale'     => array(
				'label' => __( 'بله', 'basalamhub' ),
				'base'  => 'https://tapi.bale.ai',
				'bot'   => '@BotFather',
			),
			'telegram' => array(
				'label' => __( 'تلگرام', 'basalamhub' ),
				'base'  => 'https://api.telegram.org',
				'bot'   => '@BotFather',
			),
		);
	}

	/**
	 * Saved settings (tokens still encrypted).
	 *
	 * @return array
	 */
	public static function settings() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		foreach ( array_keys( self::channels() ) as $ch ) {
			$s[ $ch ] = wp_parse_args( isset( $s[ $ch ] ) ? (array) $s[ $ch ] : array(), array( 'enabled' => 0, 'token' => '', 'chat_id' => '', 'chat_title' => '', 'api_base' => '' ) );
		}
		$s['events'] = wp_parse_args( isset( $s['events'] ) ? (array) $s['events'] : array(), array( 'new_order' => 1, 'errors' => 1, 'reconcile' => 1 ) );
		return $s;
	}

	/**
	 * @param string $channel Channel.
	 * @return string Plain bot token or ''.
	 */
	public static function token( $channel ) {
		$s = self::settings();
		return $s[ $channel ]['token'] ? (string) BSH_Crypto::decrypt( $s[ $channel ]['token'] ) : '';
	}

	/**
	 * @return string[] Channels that are on and complete.
	 */
	public static function active_channels() {
		$s   = self::settings();
		$out = array();
		foreach ( array_keys( self::channels() ) as $ch ) {
			if ( $s[ $ch ]['enabled'] && $s[ $ch ]['token'] && '' !== (string) $s[ $ch ]['chat_id'] ) {
				$out[] = $ch;
			}
		}
		return $out;
	}

	/**
	 * Validates and saves the notifications form.
	 *
	 * @param array $input Raw input.
	 * @return array<string,string> Field errors.
	 */
	public static function save( array $input ) {
		$s      = self::settings();
		$errors = array();
		foreach ( array_keys( self::channels() ) as $ch ) {
			$in    = isset( $input[ $ch ] ) ? (array) $input[ $ch ] : array();
			$token = isset( $in['token'] ) ? trim( (string) $in['token'] ) : '';
			if ( '' !== $token ) {
				if ( ! preg_match( '/^\d+:[A-Za-z0-9_-]{20,}$/', $token ) ) {
					$errors[ $ch . '_token' ] = __( 'توکن ربات این شکلی است: 123456789:AAH…  از پیام BotFather کامل کپی کن.', 'basalamhub' );
				} else {
					$enc = BSH_Crypto::encrypt( $token );
					if ( $enc ) {
						$s[ $ch ]['token'] = $enc;
					}
				}
			}
			if ( ! empty( $in['forget_token'] ) ) {
				$s[ $ch ]['token'] = '';
			}
			$chat = isset( $in['chat_id'] ) ? trim( strtr( (string) $in['chat_id'], array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' ) ) ) : '';
			if ( '' !== $chat && ! preg_match( '/^(-?\d{3,20}|@[A-Za-z0-9_]{4,})$/', $chat ) ) {
				$errors[ $ch . '_chat_id' ] = __( 'شناسه‌ی گفتگو عدد است (برای گروه با منفی شروع می‌شود) یا نام کانال با @. دکمه‌ی «پیدا کردن شناسه» را بزن.', 'basalamhub' );
			} else {
				if ( $chat !== (string) $s[ $ch ]['chat_id'] ) {
					$s[ $ch ]['chat_title'] = '';
				}
				$s[ $ch ]['chat_id'] = $chat;
			}
			$base = isset( $in['api_base'] ) ? trim( (string) $in['api_base'] ) : '';
			if ( '' !== $base && ! wp_http_validate_url( $base ) ) {
				$errors[ $ch . '_api_base' ] = __( 'آدرس واسط باید یک آدرس کامل https باشد.', 'basalamhub' );
			} else {
				$s[ $ch ]['api_base'] = $base ? untrailingslashit( esc_url_raw( $base ) ) : '';
			}
			$s[ $ch ]['enabled'] = empty( $in['enabled'] ) ? 0 : 1;
		}
		foreach ( array( 'new_order', 'errors', 'reconcile' ) as $ev ) {
			$s['events'][ $ev ] = empty( $input['events'][ $ev ] ) ? 0 : 1;
		}
		update_option( self::OPTION, $s, false );
		return $errors;
	}

	/* ---------------------------------------------------------------------
	 * Which log entries become messages
	 * ------------------------------------------------------------------ */

	/**
	 * bsh_logged action.
	 *
	 * @param int    $id    Log ID.
	 * @param string $level Level.
	 * @param array  $entry Entry.
	 */
	public static function on_logged( $id, $level, $entry ) {
		$event = isset( $entry['event'] ) ? (string) $entry['event'] : '';
		if ( 0 === strpos( $event, 'notify_' ) || ! self::active_channels() ) {
			return; // Never alert about alerts.
		}
		$events = self::settings()['events'];
		$text   = '';
		if ( 'order_imported' === $event && $events['new_order'] ) {
			$text = self::order_text( isset( $entry['object_id'] ) ? (int) $entry['object_id'] : 0 );
		} elseif ( 'reconcile_missing' === $event && $events['reconcile'] ) {
			$text = "🔁 " . __( 'تطبیق شبانه‌ی باسلام‌هاب', 'basalamhub' ) . "\n" . $entry['message'] . "\n" . ( isset( $entry['reason'] ) ? $entry['reason'] : '' );
		} elseif ( 'error' === $level && $events['errors'] && self::error_allowed( $entry ) ) {
			$text = "⚠️ " . __( 'خطا در باسلام‌هاب', 'basalamhub' ) . "\n" . ( isset( $entry['title'] ) ? $entry['title'] : '' ) . "\n" . ( isset( $entry['message'] ) ? $entry['message'] : '' );
			if ( ! empty( $entry['suggestion'] ) ) {
				$text .= "\n" . __( 'راه‌حل:', 'basalamhub' ) . ' ' . $entry['suggestion'];
			}
			$text .= "\n" . admin_url( 'admin.php?page=basalamhub-logs&level=error&unresolved=1' );
		}
		if ( '' === trim( $text ) ) {
			return;
		}
		$text = self::site_prefix() . $text;
		foreach ( self::active_channels() as $ch ) {
			as_enqueue_async_action( self::HOOK, array( 'channel' => $ch, 'text' => mb_substr( $text, 0, 3500 ) ), BSH_Queue::GROUP );
		}
	}

	/**
	 * The same error (event + object) at most once per 6 hours, and at most ERROR_CAP per hour.
	 *
	 * @param array $entry Entry.
	 * @return bool
	 */
	private static function error_allowed( array $entry ) {
		$key = 'bsh_ntf_' . md5( ( isset( $entry['event'] ) ? $entry['event'] : '' ) . '|' . ( isset( $entry['object_type'] ) ? $entry['object_type'] : '' ) . '|' . ( isset( $entry['object_id'] ) ? $entry['object_id'] : '' ) );
		if ( get_transient( $key ) ) {
			return false;
		}
		$hour  = 'bsh_ntf_count_' . gmdate( 'YmdH' );
		$count = (int) get_transient( $hour );
		if ( $count >= self::ERROR_CAP ) {
			return false;
		}
		set_transient( $key, 1, 6 * HOUR_IN_SECONDS );
		set_transient( $hour, $count + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * @return string "[Shop name] " so one bot can serve several shops.
	 */
	private static function site_prefix() {
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return $name ? '[' . $name . "]\n" : '';
	}

	/**
	 * @param int $order_id WooCommerce order.
	 * @return string
	 */
	public static function order_text( $order_id ) {
		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return '';
		}
		$lines   = array();
		$lines[] = '🛒 ' . __( 'سفارش جدید باسلام', 'basalamhub' );
		/* translators: 1: order number, 2: parcel id */
		$lines[] = sprintf( __( 'سفارش %1$s (باسلام #%2$s)', 'basalamhub' ), $order->get_order_number(), $order->get_meta( BSH_Order_Sync::META_PARCEL ) );
		foreach ( $order->get_items() as $item ) {
			$lines[] = '• ' . $item->get_name() . ' × ' . bsh_fa_digits( $item->get_quantity() );
		}
		$lines[] = __( 'مبلغ:', 'basalamhub' ) . ' ' . html_entity_decode( wp_strip_all_tags( wc_price( $order->get_total(), array( 'currency' => $order->get_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
		$who     = trim( $order->get_formatted_billing_full_name() . '، ' . $order->get_shipping_city(), '، ' );
		if ( $who ) {
			$lines[] = __( 'گیرنده:', 'basalamhub' ) . ' ' . $who;
		}
		$lines[] = $order->get_edit_order_url();
		return implode( "\n", $lines );
	}

	/* ---------------------------------------------------------------------
	 * Sending
	 * ------------------------------------------------------------------ */

	/**
	 * Queue callback.
	 *
	 * @param string $channel Channel.
	 * @param string $text    Text.
	 */
	public static function handle_send( $channel, $text ) {
		$result = self::send( (string) $channel, (string) $text );
		if ( true === $result ) {
			BSH_Queue::reset_attempts( 'notify_' . md5( $channel . $text ) );
			return;
		}
		if ( $result['retryable'] && false !== BSH_Queue::retry_later( self::HOOK, array( 'channel' => $channel, 'text' => $text ), 'notify_' . md5( $channel . $text ), 60 ) ) {
			return;
		}
		$labels = self::channels();
		BSH_Logger::log(
			array(
				'level'       => 'warning',
				'event'       => 'notify_failed',
				'object_type' => 'system',
				/* translators: %s: channel */
				'title'       => sprintf( __( 'اعلان %s', 'basalamhub' ), isset( $labels[ $channel ] ) ? $labels[ $channel ]['label'] : $channel ),
				'message'     => __( 'پیام اعلان فرستاده نشد.', 'basalamhub' ),
				'reason'      => $result['message'],
				'suggestion'  => $result['suggestion'],
			)
		);
	}

	/**
	 * Sends one message right now.
	 *
	 * @param string $channel bale|telegram.
	 * @param string $text    Text.
	 * @return true|array{message:string, suggestion:string, retryable:bool}
	 */
	public static function send( $channel, $text ) {
		$s = self::settings();
		if ( ! isset( $s[ $channel ] ) || ! $s[ $channel ]['token'] || '' === (string) $s[ $channel ]['chat_id'] ) {
			return array( 'message' => __( 'توکن ربات یا شناسه‌ی گفتگو وارد نشده.', 'basalamhub' ), 'suggestion' => __( 'در باسلام‌هاب › اعلان‌ها کامل کن.', 'basalamhub' ), 'retryable' => false );
		}
		$res = self::call( $channel, 'sendMessage', array( 'chat_id' => $s[ $channel ]['chat_id'], 'text' => $text, 'disable_web_page_preview' => true ) );
		return is_array( $res ) && isset( $res['ok'] ) && true === $res['ok'] ? true : self::explain( $channel, $res );
	}

	/**
	 * The chat that last wrote to the bot (for «پیدا کردن شناسه»).
	 *
	 * @param string $channel Channel.
	 * @return array{id:string, title:string}|array{message:string, suggestion:string, retryable:bool}
	 */
	public static function find_chat( $channel ) {
		$res = self::call( $channel, 'getUpdates', array( 'limit' => 50 ) );
		if ( ! is_array( $res ) || empty( $res['ok'] ) ) {
			return self::explain( $channel, $res );
		}
		$found = null;
		foreach ( (array) $res['result'] as $u ) {
			foreach ( array( 'message', 'channel_post', 'my_chat_member' ) as $k ) {
				if ( ! empty( $u[ $k ]['chat']['id'] ) ) {
					$c     = $u[ $k ]['chat'];
					$title = isset( $c['title'] ) ? $c['title'] : trim( ( isset( $c['first_name'] ) ? $c['first_name'] : '' ) . ' ' . ( isset( $c['last_name'] ) ? $c['last_name'] : '' ) );
					$found = array( 'id' => (string) $c['id'], 'title' => $title ? $title : ( isset( $c['username'] ) ? '@' . $c['username'] : '' ) );
				}
			}
		}
		if ( ! $found ) {
			$labels = self::channels();
			return array(
				'message'    => __( 'هنوز پیامی به ربات نرسیده.', 'basalamhub' ),
				/* translators: %s: channel */
				'suggestion' => sprintf( __( 'در %s ربات خودت را باز کن، «/start» یا هر پیامی بفرست (برای گروه: ربات را عضو گروه کن و در گروه پیام بده)، بعد دوباره این دکمه را بزن.', 'basalamhub' ), $labels[ $channel ]['label'] ),
				'retryable'  => false,
			);
		}
		$s                           = self::settings();
		$s[ $channel ]['chat_id']    = $found['id'];
		$s[ $channel ]['chat_title'] = $found['title'];
		update_option( self::OPTION, $s, false );
		return $found;
	}

	/**
	 * @param string $channel Channel.
	 * @param string $method  Bot API method.
	 * @param array  $body    Body.
	 * @return array|WP_Error Decoded response (also for HTTP errors) or WP_Error on network failure.
	 */
	private static function call( $channel, $method, array $body ) {
		$s     = self::settings();
		$chs   = self::channels();
		$token = self::token( $channel );
		if ( ! $token ) {
			return new WP_Error( 'no_token', 'no token' );
		}
		$base = $s[ $channel ]['api_base'] ? $s[ $channel ]['api_base'] : $chs[ $channel ]['base'];
		/**
		 * Lets tests and relays change a channel's Bot API base URL.
		 *
		 * @param string $base    Base URL.
		 * @param string $channel Channel.
		 */
		$base = (string) apply_filters( 'bsh_notify_api_base', $base, $channel );
		$res  = wp_remote_post(
			$base . '/bot' . $token . '/' . $method,
			array(
				'timeout' => 15,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$data = is_array( $data ) ? $data : array( 'ok' => false );
		$data['_http'] = (int) wp_remote_retrieve_response_code( $res );
		return $data;
	}

	/**
	 * Persian explanation of a failed Bot API call.
	 *
	 * @param string         $channel Channel.
	 * @param array|WP_Error $res     Response.
	 * @return array{message:string, suggestion:string, retryable:bool}
	 */
	private static function explain( $channel, $res ) {
		if ( is_wp_error( $res ) ) {
			if ( 'no_token' === $res->get_error_code() ) {
				return array( 'message' => __( 'توکن ربات وارد نشده یا قابل خواندن نیست.', 'basalamhub' ), 'suggestion' => __( 'توکن را دوباره وارد و ذخیره کن.', 'basalamhub' ), 'retryable' => false );
			}
			return array(
				'message'    => 'telegram' === $channel ? __( 'سرور سایت به تلگرام دسترسی ندارد.', 'basalamhub' ) : __( 'سرور سایت به بله وصل نشد.', 'basalamhub' ),
				'suggestion' => 'telegram' === $channel
					? __( 'روی بیشتر هاست‌های ایران تلگرام باز نیست. از بله استفاده کن، یا آدرس یک واسط (relay) را در «آدرس واسط API» وارد کن.', 'basalamhub' )
					: __( 'چند دقیقه‌ی دیگر خودکار دوباره تلاش می‌شود. اگر ادامه داشت، از هاستینگ بپرس آیا دسترسی خروجی به tapi.bale.ai باز است.', 'basalamhub' ),
				'retryable'  => true,
			);
		}
		$code = isset( $res['_http'] ) ? (int) $res['_http'] : 0;
		$desc = isset( $res['description'] ) ? (string) $res['description'] : '';
		if ( 401 === $code || 404 === $code ) {
			return array( 'message' => __( 'توکن ربات درست نیست.', 'basalamhub' ), 'suggestion' => __( 'توکن را دوباره از BotFather کپی کن و ذخیره کن.', 'basalamhub' ), 'retryable' => false );
		}
		if ( 400 === $code || 403 === $code ) {
			return array(
				'message'    => __( 'ربات نمی‌تواند به این گفتگو پیام بدهد.', 'basalamhub' ) . ( $desc ? ' (' . $desc . ')' : '' ),
				'suggestion' => __( 'شناسه‌ی گفتگو را با «پیدا کردن شناسه» دوباره بگیر. اگر ربات را بلاک کرده‌ای یا از گروه حذف شده، دوباره اضافه‌اش کن.', 'basalamhub' ),
				'retryable'  => false,
			);
		}
		if ( 429 === $code ) {
			return array( 'message' => __( 'پیام‌رسان گفت کمی صبر کن.', 'basalamhub' ), 'suggestion' => __( 'خودکار دوباره تلاش می‌شود.', 'basalamhub' ), 'retryable' => true );
		}
		return array( 'message' => __( 'پیام‌رسان پاسخ نامعلومی داد.', 'basalamhub' ) . ( $desc ? ' (' . $desc . ')' : '' ), 'suggestion' => __( 'خودکار دوباره تلاش می‌شود.', 'basalamhub' ), 'retryable' => $code >= 500 || 0 === $code );
	}
}
