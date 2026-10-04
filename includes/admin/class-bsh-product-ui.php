<?php
/**
 * Product screens: the BasalamHub box on the edit page and the status column in the list.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Product_UI {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'add_meta_boxes_product', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_product', array( __CLASS__, 'save_box' ), 5, 1 );
		add_filter( 'manage_edit-product_columns', array( __CLASS__, 'add_column' ), 20 );
		add_action( 'manage_product_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_action( 'wp_ajax_bsh_send_product', array( __CLASS__, 'ajax_send' ) );
		add_action( 'wp_ajax_bsh_product_status', array( __CLASS__, 'ajax_status' ) );
	}

	/**
	 * Registers the side box.
	 */
	public static function add_box() {
		add_meta_box( 'bsh-product-box', __( 'باسلام‌هاب · باسلام', 'basalamhub' ), array( __CLASS__, 'render_box' ), 'product', 'side', 'high' );
	}

	/**
	 * @param WP_Post $post Post.
	 */
	public static function render_box( $post ) {
		$product = wc_get_product( $post->ID );
		$link    = BSH_Links::get( 'product', $post->ID );
		$cat     = $product ? $product->get_meta( '_bsh_category_id', true ) : '';
		$prep    = $product ? $product->get_meta( '_bsh_preparation_days', true ) : '';
		$resolved = $product ? BSH_Categories::resolve( $product )['category_id'] : 0;
		wp_nonce_field( 'bsh_product_box', 'bsh_product_box_nonce' );
		?>
		<div class="bsh-root bsh-product-box" data-product-id="<?php echo esc_attr( $post->ID ); ?>">
			<div class="bsh-product-box__status" data-bsh-status>
				<?php echo self::status_html( $post->ID, $link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside. ?>
			</div>

			<?php if ( ! BSH_Settings::is_connected() ) : ?>
				<p class="bsh-field__hint">
					<?php esc_html_e( 'هنوز به باسلام وصل نشده‌ای.', 'basalamhub' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-settings' ) ); ?>"><?php esc_html_e( 'اتصال در تنظیمات', 'basalamhub' ); ?></a>
				</p>
			<?php else : ?>
				<p>
					<button type="button" class="bsh-btn" data-bsh-send>
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<?php echo $link && $link->basalam_id ? esc_html__( 'به‌روزرسانی در باسلام', 'basalamhub' ) : esc_html__( 'ارسال به باسلام', 'basalamhub' ); ?>
					</button>
				</p>
				<p class="bsh-field__hint" data-bsh-message aria-live="polite"></p>
			<?php endif; ?>

			<label class="bsh-field">
				<span class="bsh-field__label"><?php esc_html_e( 'شناسه‌ی دسته‌ی باسلام', 'basalamhub' ); ?></span>
				<input class="bsh-field__input bsh-field__ltr" type="text" inputmode="numeric" name="bsh_category_id" value="<?php echo esc_attr( $cat ); ?>" placeholder="<?php echo esc_attr( (string) ( $resolved ? $resolved : BSH_Settings::get( 'default_category_id' ) ) ); ?>">
				<span class="bsh-field__hint">
					<?php
					echo esc_html(
						$resolved
							/* translators: %s: Basalam category */
							? sprintf( __( 'خالی بماند، از نگاشت دسته‌ها: %s', 'basalamhub' ), BSH_Categories::label( $resolved ) )
							: __( 'خالی بماند، از «نگاشت دسته‌ها» یا دسته‌ی پیش‌فرض استفاده می‌شود.', 'basalamhub' )
					);
					?>
				</span>
			</label>
			<label class="bsh-field">
				<span class="bsh-field__label"><?php esc_html_e( 'زمان آماده‌سازی (روز)', 'basalamhub' ); ?></span>
				<input class="bsh-field__input" type="number" min="0" max="60" name="bsh_preparation_days" value="<?php echo esc_attr( $prep ); ?>" placeholder="<?php echo esc_attr( (string) BSH_Settings::get( 'preparation_days' ) ); ?>">
			</label>
		</div>
		<?php
	}

	/**
	 * Status block shared by the box and the AJAX poller.
	 *
	 * @param int         $product_id Product.
	 * @param object|null $link       Link row.
	 * @return string HTML.
	 */
	public static function status_html( $product_id, $link ) {
		if ( ! $link ) {
			return '<p class="bsh-card__meta">' . esc_html__( 'هنوز به باسلام ارسال نشده.', 'basalamhub' ) . '</p>';
		}
		$log_url = $link->last_log_id ? admin_url( 'admin.php?page=basalamhub-logs&object_id=' . (int) $product_id ) : '';
		$html    = '<p>' . bsh_badge( $link->sync_status, 'error' === $link->sync_status ? $log_url : '' ) . '</p>';

		if ( $link->basalam_id ) {
			$conn = BSH_Settings::connection();
			$id   = esc_html( (string) $link->basalam_id );
			if ( $conn['vendor_identifier'] ) {
				$url = 'https://basalam.com/' . rawurlencode( $conn['vendor_identifier'] ) . '/product/' . (int) $link->basalam_id;
				$id  = '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $id . '</a>';
			}
			/* translators: %s: Basalam product id */
			$html .= '<p class="bsh-card__meta">' . sprintf( esc_html__( 'شناسه در باسلام: %s', 'basalamhub' ), $id ) . '</p>';
		}
		if ( $link->last_synced_at ) {
			/* translators: %s: relative time */
			$html .= '<p class="bsh-card__meta">' . esc_html( sprintf( __( 'آخرین همگام‌سازی: %s', 'basalamhub' ), bsh_time_ago( $link->last_synced_at ) ) ) . '</p>';
		}
		if ( 'error' === $link->sync_status && $link->last_error ) {
			$html .= '<p class="bsh-product-box__error">' . esc_html( $link->last_error ) . ( $log_url ? ' <a href="' . esc_url( $log_url ) . '">' . esc_html__( 'جزئیات و راه‌حل', 'basalamhub' ) . '</a>' : '' ) . '</p>';
		}
		return $html;
	}

	/**
	 * Saves the box fields. The product itself is queued by the woocommerce_update_product hook.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function save_box( $post_id ) {
		if ( ! isset( $_POST['bsh_product_box_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bsh_product_box_nonce'] ) ), 'bsh_product_box' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_product', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$digits = array( '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9' );
		foreach ( array( 'bsh_category_id' => '_bsh_category_id', 'bsh_preparation_days' => '_bsh_preparation_days' ) as $field => $meta ) {
			$value = isset( $_POST[ $field ] ) ? strtr( trim( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ), $digits ) : '';
			if ( '' === $value || ! ctype_digit( $value ) ) {
				delete_post_meta( $post_id, $meta );
			} else {
				update_post_meta( $post_id, $meta, (int) $value );
			}
		}
	}

	/**
	 * @param array $columns Columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'price' === $key ) {
				$out['bsh_status'] = __( 'باسلام', 'basalamhub' );
			}
		}
		if ( ! isset( $out['bsh_status'] ) ) {
			$out['bsh_status'] = __( 'باسلام', 'basalamhub' );
		}
		return $out;
	}

	/** @var array<int,object>|null Links for the current list page, fetched in one query. */
	private static $page_links = null;

	/**
	 * @param string $column  Column.
	 * @param int    $post_id Post.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'bsh_status' !== $column ) {
			return;
		}
		if ( null === self::$page_links ) {
			global $wp_query;
			$ids              = $wp_query && $wp_query->posts ? wp_list_pluck( $wp_query->posts, 'ID' ) : array( $post_id );
			self::$page_links = BSH_Links::get_many( 'product', $ids );
		}
		$link = isset( self::$page_links[ $post_id ] ) ? self::$page_links[ $post_id ] : null;
		if ( ! $link ) {
			echo '<span class="bsh-muted">—</span>';
			return;
		}
		$url = 'error' === $link->sync_status ? admin_url( 'admin.php?page=basalamhub-logs&object_id=' . (int) $post_id ) : '';
		echo '<span class="bsh-root">' . bsh_badge( $link->sync_status, $url ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in bsh_badge().
	}

	/**
	 * @return int Product ID from the AJAX request after permission checks.
	 */
	private static function ajax_product_id() {
		$id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		if ( ! check_ajax_referer( 'bsh_admin', 'nonce', false ) || ! $id || ! current_user_can( 'edit_product', $id ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست کاری منقضی شده. صفحه را تازه کن و دوباره امتحان کن.', 'basalamhub' ) ), 403 );
		}
		return $id;
	}

	/**
	 * "Send to Basalam" button: queue only, never call the API from the browser request.
	 */
	public static function ajax_send() {
		$id = self::ajax_product_id();
		if ( ! BSH_Settings::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'اول در باسلام‌هاب › تنظیمات به باسلام وصل شو.', 'basalamhub' ) ) );
		}
		BSH_Queue::reset_attempts( 'product_' . $id );
		BSH_Queue::enqueue_product( $id, true );
		wp_send_json_success(
			array(
				'message' => __( 'در صف قرار گرفت. نتیجه همین‌جا نمایش داده می‌شود؛ می‌توانی صفحه را هم ببندی.', 'basalamhub' ),
				'html'    => self::status_html( $id, BSH_Links::get( 'product', $id ) ),
			)
		);
	}

	/**
	 * Lightweight status poll for the box.
	 */
	public static function ajax_status() {
		$id   = self::ajax_product_id();
		$link = BSH_Links::get( 'product', $id );
		wp_send_json_success(
			array(
				'status' => $link ? $link->sync_status : '',
				'html'   => self::status_html( $id, $link ),
			)
		);
	}
}
