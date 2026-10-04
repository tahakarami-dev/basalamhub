<?php
/**
 * Admin pages for bulk send, category mapping and price rules, plus their endpoints.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_Admin_Tools {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_post_slh_save_category_map', array( __CLASS__, 'handle_save_map' ) );
		add_action( 'admin_post_slh_save_price_rules', array( __CLASS__, 'handle_save_prices' ) );
		add_action( 'wp_ajax_slh_bulk_start', array( __CLASS__, 'ajax_bulk_start' ) );
		add_action( 'wp_ajax_slh_bulk_status', array( __CLASS__, 'ajax_bulk_status' ) );
		add_action( 'wp_ajax_slh_bulk_cancel', array( __CLASS__, 'ajax_bulk_cancel' ) );
		add_action( 'wp_ajax_slh_bulk_count', array( __CLASS__, 'ajax_bulk_count' ) );
		add_action( 'wp_ajax_slh_link_start', array( __CLASS__, 'ajax_link_start' ) );
		add_action( 'wp_ajax_slh_link_status', array( __CLASS__, 'ajax_link_status' ) );
		add_action( 'wp_ajax_slh_link_approve', array( __CLASS__, 'ajax_link_approve' ) );
		add_action( 'wp_ajax_slh_categories_refresh', array( __CLASS__, 'ajax_categories_refresh' ) );
		add_action( 'wp_ajax_slh_category_attributes', array( __CLASS__, 'ajax_category_attributes' ) );
		add_filter( 'bulk_actions-edit-product', array( __CLASS__, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-product', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
	}

	/**
	 * Submenu pages (called from SLH_Admin::menu to keep the order).
	 */
	public static function add_pages() {
		add_submenu_page( 'salamhub', __( 'ارسال گروهی', 'salamhub' ), __( 'ارسال گروهی', 'salamhub' ), SLH_Admin::CAP, 'salamhub-bulk', array( __CLASS__, 'page_bulk' ) );
		add_submenu_page( 'salamhub', __( 'اتصال محصولات غرفه', 'salamhub' ), __( 'اتصال محصولات غرفه', 'salamhub' ), SLH_Admin::CAP, 'salamhub-link', array( __CLASS__, 'page_link' ) );
		add_submenu_page( 'salamhub', __( 'نگاشت دسته‌ها', 'salamhub' ), __( 'نگاشت دسته‌ها', 'salamhub' ), SLH_Admin::CAP, 'salamhub-categories', array( __CLASS__, 'page_categories' ) );
		add_submenu_page( 'salamhub', __( 'قوانین قیمت', 'salamhub' ), __( 'قوانین قیمت', 'salamhub' ), SLH_Admin::CAP, 'salamhub-pricing', array( __CLASS__, 'page_pricing' ) );
	}

	/**
	 * @param string $view View.
	 * @param string $slug Page slug.
	 */
	private static function render( $view, $slug ) {
		SLH_App::render( $view, $slug );
	}

	/** Bulk page. */
	public static function page_bulk() {
		self::render( 'bulk', 'salamhub-bulk' );
	}

	/** Linking existing booth products. */
	public static function page_link() {
		self::render( 'link', 'salamhub-link' );
	}

	/** Category mapping page. */
	public static function page_categories() {
		self::render( 'categories', 'salamhub-categories' );
	}

	/** Price rules page. */
	public static function page_pricing() {
		self::render( 'pricing', 'salamhub-pricing' );
	}

	/**
	 * WooCommerce categories in tree order with depth: [[WP_Term, depth], …].
	 *
	 * @return array[]
	 */
	public static function wc_category_tree() {
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$by_parent = array();
		foreach ( $terms as $t ) {
			$by_parent[ (int) $t->parent ][] = $t;
		}
		$out  = array();
		$walk = function ( $parent, $depth ) use ( &$walk, &$out, $by_parent ) {
			foreach ( isset( $by_parent[ $parent ] ) ? $by_parent[ $parent ] : array() as $t ) {
				$out[] = array( $t, $depth );
				$walk( (int) $t->term_id, $depth + 1 );
			}
		};
		$walk( 0, 0 );
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Forms
	 * ------------------------------------------------------------------ */

	/**
	 * Saves the category mapping.
	 */
	public static function handle_save_map() {
		if ( ! current_user_can( SLH_Admin::CAP ) ) {
			wp_die( esc_html__( 'دسترسی کافی نداری.', 'salamhub' ) );
		}
		check_admin_referer( 'slh_save_category_map' );
		$input  = isset( $_POST['map'] ) ? (array) wp_unslash( $_POST['map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in save_map().
		$errors = SLH_Categories::save_map( $input );
		if ( $errors ) {
			set_transient( 'slh_map_errors_' . get_current_user_id(), array( 'errors' => $errors, 'input' => $input ), 300 );
			$notice = array( 'type' => 'error', 'text' => __( 'بعضی ردیف‌ها ذخیره نشدند. پیام کنار هر ردیف را ببین.', 'salamhub' ) );
		} else {
			$notice = array( 'type' => 'success', 'text' => __( 'نگاشت دسته‌ها ذخیره شد. از ارسال بعدی هر محصول اعمال می‌شود.', 'salamhub' ) );
		}
		set_transient( 'slh_notice_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=salamhub-categories' ) );
		exit;
	}

	/**
	 * Saves price rules.
	 */
	public static function handle_save_prices() {
		if ( ! current_user_can( SLH_Admin::CAP ) ) {
			wp_die( esc_html__( 'دسترسی کافی نداری.', 'salamhub' ) );
		}
		check_admin_referer( 'slh_save_price_rules' );
		$input  = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated in SLH_Price_Rules::save().
		$errors = SLH_Price_Rules::save( $input );
		if ( $errors ) {
			set_transient( 'slh_price_errors_' . get_current_user_id(), array( 'errors' => $errors, 'input' => $input ), 300 );
			$notice = array( 'type' => 'error', 'text' => __( 'بعضی قانون‌ها درست نبودند و ذخیره نشدند. پیام کنار هر ردیف را ببین.', 'salamhub' ) );
		} else {
			$notice = array( 'type' => 'success', 'text' => __( 'قوانین قیمت ذخیره شد. پیش‌نمایش پایین صفحه را ببین. روی محصولات متصل از به‌روزرسانی بعدی‌شان اعمال می‌شود.', 'salamhub' ) );
		}
		set_transient( 'slh_notice_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=salamhub-pricing' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Product list bulk action
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $actions Actions.
	 * @return array
	 */
	public static function register_bulk_action( $actions ) {
		$actions['slh_send'] = __( 'ارسال به باسلام', 'salamhub' );
		return $actions;
	}

	/**
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Post IDs.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect, $action, $ids ) {
		if ( 'slh_send' !== $action || ! current_user_can( SLH_Admin::CAP ) ) {
			return $redirect;
		}
		$result = SLH_Bulk::start( array( 'scope' => 'ids', 'ids' => $ids ) );
		$notice = is_wp_error( $result )
			? array( 'type' => 'error', 'text' => $result->get_error_message() )
			/* translators: %s: count */
			: array( 'type' => 'success', 'text' => sprintf( __( '%s محصول در صف ارسال قرار گرفت. پیشرفت را همین‌جا ببین؛ می‌توانی صفحه را ببندی.', 'salamhub' ), slh_fa_number( $result['total'] ) ) );
		set_transient( 'slh_notice_' . get_current_user_id(), $notice, 60 );
		return admin_url( 'admin.php?page=salamhub-bulk' );
	}

	/* ---------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------ */

	/**
	 * Nonce + capability check.
	 */
	private static function guard() {
		if ( ! current_user_can( SLH_Admin::CAP ) || ! check_ajax_referer( 'slh_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست کاری منقضی شده. صفحه را تازه کن و دوباره امتحان کن.', 'salamhub' ) ), 403 );
		}
	}

	/**
	 * Starts a bulk send from the bulk page.
	 */
	public static function ajax_bulk_start() {
		self::guard();
		$result = SLH_Bulk::start(
			array(
				'scope'   => isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'unsent',
				'term_id' => isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0,
			)
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( self::status_payload() );
	}

	/**
	 * Light progress poll.
	 */
	public static function ajax_bulk_status() {
		self::guard();
		wp_send_json_success( self::status_payload() );
	}

	/**
	 * Stops the running batch.
	 */
	public static function ajax_bulk_cancel() {
		self::guard();
		$n = SLH_Bulk::cancel();
		/* translators: %s: count */
		wp_send_json_success( array_merge( self::status_payload(), array( 'message' => sprintf( __( 'متوقف شد؛ %s محصول از صف خارج شد. محصولاتی که ارسال شده بودند در باسلام می‌مانند.', 'salamhub' ), slh_fa_number( $n ) ) ) ) );
	}

	/**
	 * Candidate counts for the selected category.
	 */
	public static function ajax_bulk_count() {
		self::guard();
		$c = SLH_Bulk::count_candidates( isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0 );
		wp_send_json_success( array( 'all' => $c['all'], 'unsent' => $c['unsent'], 'all_fa' => slh_fa_number( $c['all'] ), 'unsent_fa' => slh_fa_number( $c['unsent'] ) ) );
	}

	/**
	 * @return array Progress for the JS poller, including rendered HTML.
	 */
	public static function status_payload() {
		$batch    = SLH_Bulk::current();
		$progress = SLH_Bulk::progress( $batch );
		ob_start();
		self::progress_html( $batch, $progress );
		return array(
			'running'  => $batch && 'running' === $batch['status'],
			'progress' => $progress,
			'html'     => ob_get_clean(),
		);
	}

	/**
	 * Renders the QueueProgress component.
	 *
	 * @param array|null $batch    Batch.
	 * @param array      $progress Progress.
	 */
	public static function progress_html( $batch, array $progress ) {
		if ( ! $batch ) {
			return;
		}
		$running = 'running' === $batch['status'];
		$titles  = array(
			'running'   => __( 'ارسال گروهی به باسلام', 'salamhub' ),
			'done'      => __( 'ارسال گروهی تمام شد', 'salamhub' ),
			'cancelled' => __( 'ارسال گروهی متوقف شد', 'salamhub' ),
		);
		$error_url = admin_url( 'admin.php?page=salamhub-logs&level=error&unresolved=1' );
		?>
		<div class="slh-progress">
			<div class="slh-progress__row">
				<span><?php echo esc_html( $titles[ $batch['status'] ] ); ?></span>
				<span class="slh-progress__count">
					<?php
					/* translators: 1: processed, 2: total */
					echo esc_html( sprintf( __( '%1$s از %2$s', 'salamhub' ), slh_fa_number( $progress['total'] - $progress['waiting'] ), slh_fa_number( $progress['total'] ) ) );
					?>
				</span>
			</div>
			<div class="slh-progress__track" role="progressbar" aria-valuenow="<?php echo esc_attr( $progress['percent'] ); ?>" aria-valuemin="0" aria-valuemax="100">
				<div class="slh-progress__bar" style="width:<?php echo esc_attr( $progress['percent'] ); ?>%"></div>
			</div>
			<div class="slh-progress__legend">
				<?php /* translators: %s: count */ ?>
				<span><?php echo esc_html( sprintf( __( '%s ارسال شد', 'salamhub' ), slh_fa_number( $progress['done'] ) ) ); ?></span>
				<?php if ( $progress['failed'] ) : ?>
					<?php /* translators: %s: count */ ?>
					<a href="<?php echo esc_url( $error_url ); ?>"><?php echo esc_html( sprintf( __( '%s خطا', 'salamhub' ), slh_fa_number( $progress['failed'] ) ) ); ?></a>
				<?php else : ?>
					<span><?php esc_html_e( '۰ خطا', 'salamhub' ); ?></span>
				<?php endif; ?>
				<?php if ( $progress['waiting'] ) : ?>
					<?php /* translators: %s: count */ ?>
					<span><?php echo esc_html( sprintf( __( '%s در صف', 'salamhub' ), slh_fa_number( $progress['waiting'] ) ) ); ?></span>
				<?php endif; ?>
				<?php if ( $progress['skipped'] ) : ?>
					<?php /* translators: %s: count */ ?>
					<span><?php echo esc_html( sprintf( __( '%s ارسال نشد (متوقف یا حذف‌شده)', 'salamhub' ), slh_fa_number( $progress['skipped'] ) ) ); ?></span>
				<?php endif; ?>
				<?php if ( $running && $progress['waiting'] === $progress['total'] ) : ?>
					<span><?php esc_html_e( 'شروع پردازش ممکن است تا یک دقیقه طول بکشد. می‌توانی این صفحه را ببندی.', 'salamhub' ); ?></span>
				<?php elseif ( $running ) : ?>
					<span><?php esc_html_e( 'می‌توانی این صفحه را ببندی؛ ارسال در پس‌زمینه ادامه دارد.', 'salamhub' ); ?></span>
				<?php elseif ( $batch['ended_at'] ) : ?>
					<span><?php echo esc_html( slh_format_time( $batch['ended_at'] ) ); ?></span>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Starts fetching + matching the booth's products.
	 */
	public static function ajax_link_start() {
		self::guard();
		$r = SLH_Linker::start();
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		wp_send_json_success( self::link_status_payload() );
	}

	/**
	 * Light poll for the linking job.
	 */
	public static function ajax_link_status() {
		self::guard();
		wp_send_json_success( self::link_status_payload() );
	}

	/**
	 * @return array
	 */
	public static function link_status_payload() {
		$s = SLH_Linker::state();
		ob_start();
		self::link_progress_html( $s );
		return array( 'running' => SLH_Linker::is_running(), 'status' => $s['status'], 'html' => ob_get_clean() );
	}

	/**
	 * Progress of the linking job.
	 *
	 * @param array $s State.
	 */
	public static function link_progress_html( array $s ) {
		if ( 'fetching' === $s['status'] ) {
			$pct = $s['total_pages'] ? (int) floor( 100 * $s['page'] / $s['total_pages'] ) : 5;
			/* translators: %s: count */
			$text = sprintf( __( 'در حال دریافت محصولات غرفه از باسلام… %s محصول تا الان', 'salamhub' ), slh_fa_number( $s['fetched'] ) );
		} elseif ( 'matching' === $s['status'] ) {
			$pct = $s['total'] ? (int) floor( 100 * $s['matched'] / $s['total'] ) : 50;
			/* translators: 1: matched, 2: total */
			$text = sprintf( __( 'در حال تطبیق با محصولات سایت… %1$s از %2$s', 'salamhub' ), slh_fa_number( $s['matched'] ), slh_fa_number( $s['total'] ) );
		} else {
			return;
		}
		?>
		<div class="slh-progress">
			<div class="slh-progress__row"><span><?php echo esc_html( $text ); ?></span></div>
			<div class="slh-progress__track" role="progressbar" aria-valuenow="<?php echo esc_attr( $pct ); ?>" aria-valuemin="0" aria-valuemax="100"><div class="slh-progress__bar" style="width:<?php echo esc_attr( max( 3, $pct ) ); ?>%"></div></div>
			<div class="slh-progress__legend"><span><?php esc_html_e( 'می‌توانی این صفحه را ببندی؛ کار در پس‌زمینه ادامه دارد. تا تأیید تو چیزی متصل نمی‌شود.', 'salamhub' ); ?></span></div>
		</div>
		<?php
	}

	/**
	 * Approves pairs from the preview.
	 */
	public static function ajax_link_approve() {
		self::guard();
		$pairs = array();
		$raw   = isset( $_POST['pairs'] ) ? json_decode( sanitize_text_field( wp_unslash( $_POST['pairs'] ) ), true ) : array();
		foreach ( is_array( $raw ) ? $raw : array() as $basalam_id => $wc_id ) {
			if ( (int) $basalam_id > 0 && (int) $wc_id > 0 ) {
				$pairs[ (int) $basalam_id ] = (int) $wc_id;
			}
		}
		if ( ! empty( $_POST['all_certain'] ) ) {
			global $wpdb;
			foreach ( $wpdb->get_results( 'SELECT basalam_id, match_wc_id FROM ' . SLH_Linker::table() . " WHERE match_status = 'certain'" ) as $r ) { // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$pairs[ (int) $r->basalam_id ] = (int) $r->match_wc_id;
			}
		}
		if ( ! $pairs ) {
			wp_send_json_error( array( 'message' => __( 'هیچ جفتی انتخاب نشده.', 'salamhub' ) ) );
		}
		$result = SLH_Linker::approve( $pairs, ! empty( $_POST['push'] ) );
		/* translators: %s: count */
		$msg = sprintf( __( '%s محصول متصل شد.', 'salamhub' ), slh_fa_number( $result['linked'] ) );
		if ( $result['errors'] ) {
			$msg .= ' ' . implode( ' ', array_slice( $result['errors'], 0, 3 ) );
		}
		wp_send_json_success( array( 'message' => $msg, 'linked' => $result['linked'] ) );
	}

	/**
	 * Downloads the Basalam category tree.
	 */
	public static function ajax_categories_refresh() {
		self::guard();
		try {
			$n = SLH_Categories::refresh();
			/* translators: %s: count */
			wp_send_json_success( array( 'message' => sprintf( __( '%s دسته‌ی باسلام دریافت شد.', 'salamhub' ), slh_fa_number( $n ) ) ) );
		} catch ( SLH_Api_Error $e ) {
			wp_send_json_error( array( 'message' => trim( $e->getMessage() . ' ' . $e->reason . ' ' . $e->suggestion ) ) );
		}
	}

	/**
	 * Attribute fields for one mapping row.
	 */
	public static function ajax_category_attributes() {
		self::guard();
		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$raw     = isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '';
		$cat_id  = preg_match( '/(\d+)\)?\s*$/u', $raw, $m ) ? (int) $m[1] : 0;
		if ( ! $cat_id ) {
			wp_send_json_error( array( 'message' => __( 'اول دسته‌ی باسلام را انتخاب کن.', 'salamhub' ) ) );
		}
		try {
			$attrs = SLH_Categories::attributes( $cat_id, true );
		} catch ( SLH_Api_Error $e ) {
			wp_send_json_error( array( 'message' => trim( $e->getMessage() . ' ' . $e->reason ) ) );
		}
		$map = SLH_Categories::map();
		ob_start();
		self::attribute_fields( $term_id, (array) $attrs, isset( $map[ $term_id ]['attrs'] ) ? $map[ $term_id ]['attrs'] : array() );
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/**
	 * Default-value inputs for a category's required attributes.
	 *
	 * @param int   $term_id WooCommerce term.
	 * @param array $attrs   Attributes (SLH_Categories::attributes format).
	 * @param array $values  Saved defaults.
	 */
	public static function attribute_fields( $term_id, array $attrs, array $values ) {
		$required = array_filter(
			$attrs,
			function ( $a ) {
				return $a['required'];
			}
		);
		if ( ! $required ) {
			echo '<p class="slh-field__hint">' . esc_html__( 'این دسته ویژگی اجباری ندارد.', 'salamhub' ) . '</p>';
			return;
		}
		echo '<p class="slh-field__hint">' . esc_html__( 'ویژگی‌های اجباری این دسته. اگر محصول ویژگی ووکامرسی با همین نام داشته باشد، مقدار خود محصول استفاده می‌شود؛ وگرنه این مقدار پیش‌فرض.', 'salamhub' ) . '</p>';
		echo '<div class="slh-attr-grid">';
		foreach ( $required as $a ) {
			$name  = 'map[' . (int) $term_id . '][attrs][' . (int) $a['id'] . ']';
			$value = isset( $values[ $a['id'] ] ) ? (string) $values[ $a['id'] ] : '';
			echo '<label class="slh-field">';
			echo '<span class="slh-field__label">' . esc_html( $a['title'] . ( $a['unit'] ? ' (' . $a['unit'] . ')' : '' ) ) . '</span>';
			if ( $a['options'] ) {
				echo '<select class="slh-field__select" name="' . esc_attr( $name ) . '"><option value="">' . esc_html__( '— از خود محصول —', 'salamhub' ) . '</option>';
				foreach ( $a['options'] as $opt_id => $opt_title ) {
					echo '<option value="' . esc_attr( $opt_id ) . '" ' . selected( $value, (string) $opt_id, false ) . '>' . esc_html( $opt_title ) . '</option>';
				}
				echo '</select>';
			} else {
				echo '<input class="slh-field__input" type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
			}
			echo '</label>';
		}
		echo '</div>';
	}
}
