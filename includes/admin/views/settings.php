<?php
/**
 * Settings page: connection + product sync settings.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

$slh_user   = get_current_user_id();
$slh_errors = get_transient( 'slh_settings_errors_' . $slh_user );
$slh_input  = get_transient( 'slh_settings_input_' . $slh_user );
delete_transient( 'slh_settings_errors_' . $slh_user );
delete_transient( 'slh_settings_input_' . $slh_user );
$slh_errors = is_array( $slh_errors ) ? $slh_errors : array();

$slh_s     = SLH_Settings::all();
$slh_conn  = SLH_Settings::connection();
$slh_token = SLH_Settings::get_token();

/**
 * Value to show in a field: the rejected input after a failed save, else the saved value.
 */
$slh_val = function ( $key ) use ( $slh_s, $slh_input, $slh_errors ) {
	if ( isset( $slh_errors[ $key ], $slh_input[ $key ] ) ) {
		return (string) $slh_input[ $key ];
	}
	return isset( $slh_s[ $key ] ) ? (string) $slh_s[ $key ] : '';
};

/**
 * Prints the hint or the error message under a field.
 */
$slh_hint = function ( $key, $hint ) use ( $slh_errors ) {
	$text = isset( $slh_errors[ $key ] ) ? $slh_errors[ $key ] : $hint;
	echo '<span class="slh-field__hint">' . esc_html( $text ) . '</span>';
};
$slh_err_class = function ( $key ) use ( $slh_errors ) {
	return isset( $slh_errors[ $key ] ) ? ' slh-field--error' : '';
};
$slh_currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
?>
<header class="slh-page-head">
	<h1 class="slh-page-title"><?php esc_html_e( 'تنظیمات باسلام‌هاب', 'salamhub' ); ?></h1>
</header>

<?php SLH_Admin::print_notice(); ?>

<section class="slh-card slh-section">
	<div class="slh-card__head">
		<h2 class="slh-card__title"><?php esc_html_e( 'اتصال به باسلام', 'salamhub' ); ?></h2>
		<?php echo SLH_Settings::is_connected() ? slh_badge( 'synced' ) : slh_badge( 'error' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<?php if ( SLH_Settings::is_connected() ) : ?>
		<p class="slh-card__meta">
			<?php
			/* translators: 1: booth title, 2: booth id, 3: relative time */
			echo esc_html( sprintf( __( 'غرفه‌ی «%1$s» · شناسه %2$s · آخرین بررسی %3$s', 'salamhub' ), $slh_conn['vendor_title'], $slh_conn['vendor_id'], slh_time_ago( $slh_conn['checked_at'] ) ) );
			?>
		</p>
	<?php elseif ( null === $slh_token ) : ?>
		<p class="slh-alert slh-alert--error"><?php esc_html_e( 'توکن ذخیره‌شده قابل خواندن نیست، چون کلیدهای امنیتی وردپرس عوض شده‌اند. توکن را دوباره وارد کن.', 'salamhub' ); ?></p>
	<?php elseif ( $slh_conn['message'] ) : ?>
		<p class="slh-alert slh-alert--error"><?php echo esc_html( $slh_conn['message'] ); ?></p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slh-form">
		<?php wp_nonce_field( 'slh_save_settings' ); ?>
		<input type="hidden" name="action" value="slh_save_settings">
		<input type="hidden" name="slh_section" value="connection">
		<label class="slh-field">
			<span class="slh-field__label"><?php esc_html_e( 'توکن دسترسی باسلام', 'salamhub' ); ?></span>
			<input class="slh-field__input slh-field__ltr" type="password" name="slh_token" autocomplete="off" spellcheck="false"
				placeholder="<?php echo esc_attr( $slh_token ? SLH_Crypto::mask( $slh_token ) : '' ); ?>">
			<span class="slh-field__hint">
				<?php esc_html_e( 'از پنل توسعه‌دهندگان باسلام › توکن‌ها، یک توکن شخصی با دسترسی «محصولات غرفه» و «سفارش‌های غرفه» بساز. رمزنگاری‌شده ذخیره می‌شود و دیگر کامل نمایش داده نمی‌شود.', 'salamhub' ); ?>
				<a href="https://developers.basalam.com/panel/tokens" target="_blank" rel="noopener"><?php esc_html_e( 'رفتن به پنل', 'salamhub' ); ?></a>
			</span>
		</label>
		<div class="slh-card__foot">
			<button type="submit" class="slh-btn slh-btn--primary"><?php echo $slh_token ? esc_html__( 'ذخیره‌ی توکن جدید و تست اتصال', 'salamhub' ) : esc_html__( 'ذخیره و تست اتصال', 'salamhub' ); ?></button>
			<?php if ( $slh_token ) : ?>
				<button type="button" class="slh-btn" data-slh-test><?php esc_html_e( 'تست اتصال', 'salamhub' ); ?></button>
			<?php endif; ?>
		</div>
		<p class="slh-field__hint" data-slh-test-result aria-live="polite"></p>
	</form>

	<?php if ( SLH_Settings::has_token() ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slh-disconnect" data-slh-confirm="disconnect">
			<?php wp_nonce_field( 'slh_disconnect' ); ?>
			<input type="hidden" name="action" value="slh_disconnect">
			<button type="submit" class="slh-btn slh-btn--danger"><?php esc_html_e( 'قطع اتصال', 'salamhub' ); ?></button>
		</form>
	<?php endif; ?>
</section>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="slh-form">
	<?php wp_nonce_field( 'slh_save_settings' ); ?>
	<input type="hidden" name="action" value="slh_save_settings">
	<input type="hidden" name="slh_section" value="products">

	<section class="slh-card slh-section">
		<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'ارسال محصول', 'salamhub' ); ?></h2></div>
		<div class="slh-form-grid">
			<label class="slh-field<?php echo esc_attr( $slh_err_class( 'default_category_id' ) ); ?>">
				<span class="slh-field__label"><?php esc_html_e( 'دسته‌ی پیش‌فرض باسلام (شناسه)', 'salamhub' ); ?></span>
				<input class="slh-field__input slh-field__ltr" type="text" inputmode="numeric" name="default_category_id" value="<?php echo esc_attr( $slh_val( 'default_category_id' ) ); ?>">
				<?php $slh_hint( 'default_category_id', __( 'اختیاری. فقط برای محصولی که دسته‌اش در «نگاشت دسته‌ها» نگاشت نشده. خالی بماند، چنین محصولی ارسال نمی‌شود و دلیلش در لاگ می‌آید.', 'salamhub' ) ); ?>
			</label>

			<label class="slh-field<?php echo esc_attr( $slh_err_class( 'preparation_days' ) ); ?>">
				<span class="slh-field__label"><?php esc_html_e( 'زمان آماده‌سازی (روز)', 'salamhub' ); ?></span>
				<input class="slh-field__input" type="number" min="0" max="60" name="preparation_days" value="<?php echo esc_attr( $slh_val( 'preparation_days' ) ); ?>">
				<?php $slh_hint( 'preparation_days', __( 'چند روز طول می‌کشد سفارش را آماده‌ی ارسال کنی.', 'salamhub' ) ); ?>
			</label>

			<label class="slh-field">
				<span class="slh-field__label"><?php esc_html_e( 'وضعیت محصول جدید در باسلام', 'salamhub' ); ?></span>
				<select class="slh-field__select" name="create_status">
					<option value="<?php echo esc_attr( SLH_Settings::BASALAM_STATUS_PUBLISHED ); ?>" <?php selected( (int) $slh_s['create_status'], SLH_Settings::BASALAM_STATUS_PUBLISHED ); ?>><?php esc_html_e( 'منتشرشده', 'salamhub' ); ?></option>
					<option value="<?php echo esc_attr( SLH_Settings::BASALAM_STATUS_UNPUBLISHED ); ?>" <?php selected( (int) $slh_s['create_status'], SLH_Settings::BASALAM_STATUS_UNPUBLISHED ); ?>><?php esc_html_e( 'منتشرنشده (پیش‌نویس)', 'salamhub' ); ?></option>
				</select>
				<span class="slh-field__hint"><?php esc_html_e( 'فقط موقع ساخت اعمال می‌شود؛ وضعیت انتشار محصولات موجود در باسلام دست نمی‌خورد.', 'salamhub' ); ?></span>
			</label>

			<label class="slh-field">
				<span class="slh-field__label"><?php esc_html_e( 'واحد قیمت‌های سایت', 'salamhub' ); ?></span>
				<select class="slh-field__select" name="price_unit">
					<option value="auto" <?php selected( $slh_s['price_unit'], 'auto' ); ?>>
						<?php
						/* translators: %s: currency code */
						echo esc_html( sprintf( __( 'خودکار از ووکامرس (%s)', 'salamhub' ), $slh_currency ) );
						?>
					</option>
					<option value="irt" <?php selected( $slh_s['price_unit'], 'irt' ); ?>><?php esc_html_e( 'تومان', 'salamhub' ); ?></option>
					<option value="irr" <?php selected( $slh_s['price_unit'], 'irr' ); ?>><?php esc_html_e( 'ریال', 'salamhub' ); ?></option>
				</select>
				<span class="slh-field__hint"><?php esc_html_e( 'قیمت در باسلام به ریال ثبت می‌شود؛ قیمت تومانی خودکار ×۱۰ می‌شود.', 'salamhub' ); ?></span>
			</label>
		</div>
	</section>

	<section class="slh-card slh-section">
		<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'وزن و موجودی', 'salamhub' ); ?></h2></div>
		<div class="slh-form-grid">
			<label class="slh-field<?php echo esc_attr( $slh_err_class( 'default_weight' ) ); ?>">
				<span class="slh-field__label"><?php esc_html_e( 'وزن پیش‌فرض (گرم)', 'salamhub' ); ?></span>
				<input class="slh-field__input" type="number" min="1" name="default_weight" value="<?php echo esc_attr( $slh_val( 'default_weight' ) ); ?>">
				<?php $slh_hint( 'default_weight', __( 'برای محصولی که در ووکامرس وزن ندارد. باسلام هزینه‌ی ارسال را با وزن حساب می‌کند.', 'salamhub' ) ); ?>
			</label>
			<label class="slh-field<?php echo esc_attr( $slh_err_class( 'packaging_weight' ) ); ?>">
				<span class="slh-field__label"><?php esc_html_e( 'وزن بسته‌بندی (گرم)', 'salamhub' ); ?></span>
				<input class="slh-field__input" type="number" min="0" name="packaging_weight" value="<?php echo esc_attr( $slh_val( 'packaging_weight' ) ); ?>">
				<?php $slh_hint( 'packaging_weight', __( 'به وزن هر محصول اضافه می‌شود تا «وزن با بسته‌بندی» باسلام ساخته شود.', 'salamhub' ) ); ?>
			</label>
			<label class="slh-field<?php echo esc_attr( $slh_err_class( 'unmanaged_stock' ) ); ?>">
				<span class="slh-field__label"><?php esc_html_e( 'موجودی برای محصولات بدون مدیریت موجودی', 'salamhub' ); ?></span>
				<input class="slh-field__input" type="number" min="0" name="unmanaged_stock" value="<?php echo esc_attr( $slh_val( 'unmanaged_stock' ) ); ?>">
				<?php $slh_hint( 'unmanaged_stock', __( 'باسلام عدد موجودی می‌خواهد. عدد کم امن‌تر است: جلوی فروش کالای ناموجود را می‌گیرد.', 'salamhub' ) ); ?>
			</label>
		</div>
	</section>

	<section class="slh-card slh-section" id="slh-orders-settings">
		<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'سفارش‌های باسلام', 'salamhub' ); ?></h2></div>
		<label class="slh-checkbox">
			<input type="checkbox" name="orders_enabled" value="1" <?php checked( (int) $slh_s['orders_enabled'], 1 ); ?>>
			<?php esc_html_e( 'سفارش‌های باسلام خودکار در ووکامرس ثبت شوند', 'salamhub' ); ?>
		</label>
		<label class="slh-checkbox">
			<input type="checkbox" name="orders_auto_confirm" value="1" <?php checked( (int) $slh_s['orders_auto_confirm'], 1 ); ?>>
			<?php esc_html_e( 'سفارش جدید بلافاصله در باسلام «تأیید» شود (وضعیت: در حال آماده‌سازی)', 'salamhub' ); ?>
		</label>
		<div class="slh-form-grid">
			<label class="slh-field<?php echo esc_attr( $slh_err_class( 'orders_interval' ) ); ?>">
				<span class="slh-field__label"><?php esc_html_e( 'بررسی سفارش‌ها هر چند دقیقه', 'salamhub' ); ?></span>
				<input class="slh-field__input" type="number" min="2" max="60" name="orders_interval" value="<?php echo esc_attr( $slh_val( 'orders_interval' ) ); ?>">
				<?php $slh_hint( 'orders_interval', __( 'هر بار سفارش‌های تازه و تغییر وضعیت سفارش‌های باز خوانده می‌شود. ۵ دقیقه برای بیشتر فروشگاه‌ها مناسب است.', 'salamhub' ) ); ?>
			</label>
			<label class="slh-field<?php echo esc_attr( $slh_err_class( 'orders_import_days' ) ); ?>">
				<span class="slh-field__label"><?php esc_html_e( 'سفارش‌های چند روز گذشته وارد شوند', 'salamhub' ); ?></span>
				<input class="slh-field__input" type="number" min="0" max="30" name="orders_import_days" value="<?php echo esc_attr( $slh_val( 'orders_import_days' ) ); ?>">
				<?php $slh_hint( 'orders_import_days', __( 'فقط در اولین دریافت اعمال می‌شود. صفر یعنی فقط سفارش‌هایی که از این به بعد می‌آیند.', 'salamhub' ) ); ?>
			</label>
		</div>
	</section>

	<section class="slh-card slh-section" id="slh-stock-settings">
		<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'موجودی دوطرفه', 'salamhub' ); ?></h2></div>
		<fieldset class="slh-fieldset">
			<legend class="slh-field__label"><?php esc_html_e( 'مرجع موجودی', 'salamhub' ); ?></legend>
			<label class="slh-radio">
				<input type="radio" name="stock_reference" value="site" <?php checked( $slh_s['stock_reference'], 'site' ); ?>>
				<span><strong><?php esc_html_e( 'سایت (پیشنهادی)', 'salamhub' ); ?></strong> — <?php esc_html_e( 'موجودی را در ووکامرس مدیریت می‌کنی. هر فروش در سایت یا باسلام از موجودی سایت کم می‌شود و عدد جدید به باسلام می‌رود.', 'salamhub' ); ?></span>
			</label>
			<label class="slh-radio">
				<input type="radio" name="stock_reference" value="basalam" <?php checked( $slh_s['stock_reference'], 'basalam' ); ?>>
				<span><strong><?php esc_html_e( 'باسلام', 'salamhub' ); ?></strong> — <?php esc_html_e( 'موجودی را در پنل باسلام مدیریت می‌کنی. هر ساعت موجودی باسلام در سایت نوشته می‌شود و فروش سایت از موجودی باسلام کم می‌شود.', 'salamhub' ); ?></span>
			</label>
		</fieldset>
		<label class="slh-field<?php echo esc_attr( $slh_err_class( 'safety_stock' ) ); ?>">
			<span class="slh-field__label"><?php esc_html_e( 'موجودی اطمینان', 'salamhub' ); ?></span>
			<input class="slh-field__input" type="number" min="0" name="safety_stock" value="<?php echo esc_attr( $slh_val( 'safety_stock' ) ); ?>">
			<?php $slh_hint( 'safety_stock', __( 'این تعداد از هر کالا در باسلام نمایش داده نمی‌شود تا اگر هم‌زمان در سایت و باسلام فروش رفت، بیش‌فروشی نشود. مثلاً موجودی ۱۰ و اطمینان ۲ یعنی باسلام ۸ می‌بیند. برای هر محصول جدا هم در تب «انبار» محصول قابل تغییر است.', 'salamhub' ) ); ?>
		</label>
	</section>

	<section class="slh-card slh-section">
		<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'به‌روزرسانی خودکار', 'salamhub' ); ?></h2></div>
		<label class="slh-checkbox">
			<input type="checkbox" name="auto_update" value="1" <?php checked( (int) $slh_s['auto_update'], 1 ); ?>>
			<?php esc_html_e( 'بعد از ذخیره‌ی محصول متصل در ووکامرس، همان محصول در باسلام به‌روز شود', 'salamhub' ); ?>
		</label>
		<label class="slh-checkbox">
			<input type="checkbox" name="auto_send_new" value="1" <?php checked( (int) $slh_s['auto_send_new'], 1 ); ?>>
			<?php esc_html_e( 'محصولات جدید هنگام انتشار خودکار به باسلام ارسال شوند', 'salamhub' ); ?>
		</label>

		<fieldset class="slh-fieldset">
			<legend class="slh-field__label"><?php esc_html_e( 'کدام فیلدها در به‌روزرسانی ارسال شوند؟', 'salamhub' ); ?></legend>
			<div class="slh-checkbox-grid">
				<?php foreach ( SLH_Settings::field_groups() as $slh_key => $slh_label ) : ?>
					<label class="slh-checkbox">
						<input type="checkbox" name="sync_fields[]" value="<?php echo esc_attr( $slh_key ); ?>" <?php checked( in_array( $slh_key, (array) $slh_s['sync_fields'], true ) ); ?>>
						<?php echo esc_html( $slh_label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
			<span class="slh-field__hint"><?php esc_html_e( 'فیلدی که تیک ندارد در باسلام دست نمی‌خورد؛ مثلاً اگر فقط قیمت و موجودی تیک داشته باشد، توضیحاتی که در باسلام نوشته‌ای حفظ می‌شود. موقع ساخت اولیه همه‌ی فیلدها ارسال می‌شوند.', 'salamhub' ); ?></span>
		</fieldset>

		<label class="slh-field<?php echo esc_attr( $slh_err_class( 'log_retention_days' ) ); ?>">
			<span class="slh-field__label"><?php esc_html_e( 'نگهداری لاگ (روز)', 'salamhub' ); ?></span>
			<input class="slh-field__input" type="number" min="7" max="365" name="log_retention_days" value="<?php echo esc_attr( $slh_val( 'log_retention_days' ) ); ?>">
			<?php $slh_hint( 'log_retention_days', __( 'لاگ‌های قدیمی‌تر روزانه پاک می‌شوند تا دیتابیس سبک بماند.', 'salamhub' ) ); ?>
		</label>

		<div class="slh-card__foot">
			<button type="submit" class="slh-btn"><?php esc_html_e( 'ذخیره‌ی تنظیمات', 'salamhub' ); ?></button>
		</div>
	</section>
</form>
