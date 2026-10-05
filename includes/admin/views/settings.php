<?php
/**
 * Settings page: connection + product sync settings.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

$bsh_user   = get_current_user_id();
$bsh_errors = get_transient( 'bsh_settings_errors_' . $bsh_user );
$bsh_input  = get_transient( 'bsh_settings_input_' . $bsh_user );
delete_transient( 'bsh_settings_errors_' . $bsh_user );
delete_transient( 'bsh_settings_input_' . $bsh_user );
$bsh_errors = is_array( $bsh_errors ) ? $bsh_errors : array();

$bsh_s     = BSH_Settings::all();
$bsh_conn  = BSH_Settings::connection();
$bsh_token = BSH_Settings::get_token();

/**
 * Value to show in a field: the rejected input after a failed save, else the saved value.
 */
$bsh_val = function ( $key ) use ( $bsh_s, $bsh_input, $bsh_errors ) {
	if ( isset( $bsh_errors[ $key ], $bsh_input[ $key ] ) ) {
		return (string) $bsh_input[ $key ];
	}
	return isset( $bsh_s[ $key ] ) ? (string) $bsh_s[ $key ] : '';
};

/**
 * Prints the hint or the error message under a field.
 */
$bsh_hint      = function ( $key, $hint ) use ( $bsh_errors ) {
	$text = isset( $bsh_errors[ $key ] ) ? $bsh_errors[ $key ] : $hint;
	echo '<span class="bsh-field__hint">' . esc_html( $text ) . '</span>';
};
$bsh_err_class = function ( $key ) use ( $bsh_errors ) {
	return isset( $bsh_errors[ $key ] ) ? ' bsh-field--error' : '';
};
$bsh_currency  = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
?>
<header class="bsh-page-head">
	<h1 class="bsh-page-title"><?php esc_html_e( 'تنظیمات باسلام‌هاب', 'basalamhub' ); ?></h1>
</header>

<?php BSH_Admin::print_notice(); ?>

<section class="bsh-card bsh-section">
	<div class="bsh-card__head">
		<h2 class="bsh-card__title"><?php esc_html_e( 'اتصال به باسلام', 'basalamhub' ); ?></h2>
		<?php echo BSH_Settings::is_connected() ? bsh_badge( 'synced' ) : bsh_badge( 'error' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<?php if ( BSH_Settings::is_connected() ) : ?>
		<p class="bsh-card__meta">
			<?php
			/* translators: 1: booth title, 2: booth id, 3: relative time */
			echo esc_html( sprintf( __( 'غرفه‌ی «%1$s» · شناسه %2$s · آخرین بررسی %3$s', 'basalamhub' ), $bsh_conn['vendor_title'], $bsh_conn['vendor_id'], bsh_time_ago( $bsh_conn['checked_at'] ) ) );
			?>
		</p>
	<?php elseif ( null === $bsh_token ) : ?>
		<p class="bsh-alert bsh-alert--error"><?php esc_html_e( 'توکن ذخیره‌شده قابل خواندن نیست، چون کلیدهای امنیتی وردپرس عوض شده‌اند. توکن را دوباره وارد کن.', 'basalamhub' ); ?></p>
	<?php elseif ( $bsh_conn['message'] ) : ?>
		<p class="bsh-alert bsh-alert--error"><?php echo esc_html( $bsh_conn['message'] ); ?></p>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bsh-form">
		<?php wp_nonce_field( 'bsh_save_settings' ); ?>
		<input type="hidden" name="action" value="bsh_save_settings">
		<input type="hidden" name="bsh_section" value="connection">
		<label class="bsh-field">
			<span class="bsh-field__label"><?php esc_html_e( 'توکن دسترسی باسلام', 'basalamhub' ); ?></span>
			<input class="bsh-field__input bsh-field__ltr" type="password" name="bsh_token" autocomplete="off" spellcheck="false"
				placeholder="<?php echo esc_attr( $bsh_token ? BSH_Crypto::mask( $bsh_token ) : '' ); ?>">
			<span class="bsh-field__hint">
				<?php esc_html_e( 'از پنل توسعه‌دهندگان باسلام › توکن‌ها، یک توکن شخصی با دسترسی «محصولات غرفه» و «سفارش‌های غرفه» بساز. رمزنگاری‌شده ذخیره می‌شود و دیگر کامل نمایش داده نمی‌شود.', 'basalamhub' ); ?>
				<a href="https://developers.basalam.com/panel/tokens" target="_blank" rel="noopener"><?php esc_html_e( 'رفتن به پنل', 'basalamhub' ); ?></a>
			</span>
		</label>
		<div class="bsh-card__foot">
			<button type="submit" class="bsh-btn bsh-btn--primary"><?php echo $bsh_token ? esc_html__( 'ذخیره‌ی توکن جدید و تست اتصال', 'basalamhub' ) : esc_html__( 'ذخیره و تست اتصال', 'basalamhub' ); ?></button>
			<?php if ( $bsh_token ) : ?>
				<button type="button" class="bsh-btn" data-bsh-test><?php esc_html_e( 'تست اتصال', 'basalamhub' ); ?></button>
			<?php endif; ?>
		</div>
		<p class="bsh-field__hint" data-bsh-test-result aria-live="polite"></p>
	</form>

	<?php if ( BSH_Settings::has_token() ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bsh-disconnect" data-bsh-confirm="disconnect">
			<?php wp_nonce_field( 'bsh_disconnect' ); ?>
			<input type="hidden" name="action" value="bsh_disconnect">
			<button type="submit" class="bsh-btn bsh-btn--danger"><?php esc_html_e( 'قطع اتصال', 'basalamhub' ); ?></button>
		</form>
	<?php endif; ?>
</section>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bsh-form">
	<?php wp_nonce_field( 'bsh_save_settings' ); ?>
	<input type="hidden" name="action" value="bsh_save_settings">
	<input type="hidden" name="bsh_section" value="products">

	<section class="bsh-card bsh-section">
		<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'ارسال محصول', 'basalamhub' ); ?></h2></div>
		<div class="bsh-form-grid">
			<label class="bsh-field<?php echo esc_attr( $bsh_err_class( 'default_category_id' ) ); ?>">
				<span class="bsh-field__label"><?php esc_html_e( 'دسته‌ی پیش‌فرض باسلام (شناسه)', 'basalamhub' ); ?></span>
				<input class="bsh-field__input bsh-field__ltr" type="text" inputmode="numeric" name="default_category_id" value="<?php echo esc_attr( $bsh_val( 'default_category_id' ) ); ?>">
				<?php $bsh_hint( 'default_category_id', __( 'اختیاری. فقط برای محصولی که دسته‌اش در «نگاشت دسته‌ها» نگاشت نشده. خالی بماند، چنین محصولی ارسال نمی‌شود و دلیلش در لاگ می‌آید.', 'basalamhub' ) ); ?>
			</label>

			<label class="bsh-field<?php echo esc_attr( $bsh_err_class( 'preparation_days' ) ); ?>">
				<span class="bsh-field__label"><?php esc_html_e( 'زمان آماده‌سازی (روز)', 'basalamhub' ); ?></span>
				<input class="bsh-field__input" type="number" min="0" max="60" name="preparation_days" value="<?php echo esc_attr( $bsh_val( 'preparation_days' ) ); ?>">
				<?php $bsh_hint( 'preparation_days', __( 'چند روز طول می‌کشد سفارش را آماده‌ی ارسال کنی.', 'basalamhub' ) ); ?>
			</label>

			<label class="bsh-field">
				<span class="bsh-field__label"><?php esc_html_e( 'وضعیت محصول جدید در باسلام', 'basalamhub' ); ?></span>
				<select class="bsh-field__select" name="create_status">
					<option value="<?php echo esc_attr( BSH_Settings::BASALAM_STATUS_PUBLISHED ); ?>" <?php selected( (int) $bsh_s['create_status'], BSH_Settings::BASALAM_STATUS_PUBLISHED ); ?>><?php esc_html_e( 'منتشرشده', 'basalamhub' ); ?></option>
					<option value="<?php echo esc_attr( BSH_Settings::BASALAM_STATUS_UNPUBLISHED ); ?>" <?php selected( (int) $bsh_s['create_status'], BSH_Settings::BASALAM_STATUS_UNPUBLISHED ); ?>><?php esc_html_e( 'منتشرنشده (پیش‌نویس)', 'basalamhub' ); ?></option>
				</select>
				<span class="bsh-field__hint"><?php esc_html_e( 'فقط موقع ساخت اعمال می‌شود؛ وضعیت انتشار محصولات موجود در باسلام دست نمی‌خورد.', 'basalamhub' ); ?></span>
			</label>

			<label class="bsh-field">
				<span class="bsh-field__label"><?php esc_html_e( 'واحد قیمت‌های سایت', 'basalamhub' ); ?></span>
				<select class="bsh-field__select" name="price_unit">
					<option value="auto" <?php selected( $bsh_s['price_unit'], 'auto' ); ?>>
						<?php
						/* translators: %s: currency code */
						echo esc_html( sprintf( __( 'خودکار از ووکامرس (%s)', 'basalamhub' ), $bsh_currency ) );
						?>
					</option>
					<option value="irt" <?php selected( $bsh_s['price_unit'], 'irt' ); ?>><?php esc_html_e( 'تومان', 'basalamhub' ); ?></option>
					<option value="irr" <?php selected( $bsh_s['price_unit'], 'irr' ); ?>><?php esc_html_e( 'ریال', 'basalamhub' ); ?></option>
				</select>
				<span class="bsh-field__hint"><?php esc_html_e( 'قیمت در باسلام به ریال ثبت می‌شود؛ قیمت تومانی خودکار ×۱۰ می‌شود.', 'basalamhub' ); ?></span>
			</label>
		</div>
	</section>

	<section class="bsh-card bsh-section">
		<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'وزن و موجودی', 'basalamhub' ); ?></h2></div>
		<div class="bsh-form-grid">
			<label class="bsh-field<?php echo esc_attr( $bsh_err_class( 'default_weight' ) ); ?>">
				<span class="bsh-field__label"><?php esc_html_e( 'وزن پیش‌فرض (گرم)', 'basalamhub' ); ?></span>
				<input class="bsh-field__input" type="number" min="1" name="default_weight" value="<?php echo esc_attr( $bsh_val( 'default_weight' ) ); ?>">
				<?php $bsh_hint( 'default_weight', __( 'برای محصولی که در ووکامرس وزن ندارد. باسلام هزینه‌ی ارسال را با وزن حساب می‌کند.', 'basalamhub' ) ); ?>
			</label>
			<label class="bsh-field<?php echo esc_attr( $bsh_err_class( 'packaging_weight' ) ); ?>">
				<span class="bsh-field__label"><?php esc_html_e( 'وزن بسته‌بندی (گرم)', 'basalamhub' ); ?></span>
				<input class="bsh-field__input" type="number" min="0" name="packaging_weight" value="<?php echo esc_attr( $bsh_val( 'packaging_weight' ) ); ?>">
				<?php $bsh_hint( 'packaging_weight', __( 'به وزن هر محصول اضافه می‌شود تا «وزن با بسته‌بندی» باسلام ساخته شود.', 'basalamhub' ) ); ?>
			</label>
			<label class="bsh-field<?php echo esc_attr( $bsh_err_class( 'unmanaged_stock' ) ); ?>">
				<span class="bsh-field__label"><?php esc_html_e( 'موجودی برای محصولات بدون مدیریت موجودی', 'basalamhub' ); ?></span>
				<input class="bsh-field__input" type="number" min="0" name="unmanaged_stock" value="<?php echo esc_attr( $bsh_val( 'unmanaged_stock' ) ); ?>">
				<?php $bsh_hint( 'unmanaged_stock', __( 'باسلام عدد موجودی می‌خواهد. عدد کم امن‌تر است: جلوی فروش کالای ناموجود را می‌گیرد.', 'basalamhub' ) ); ?>
			</label>
		</div>
	</section>

	<section class="bsh-card bsh-section" id="bsh-orders-settings">
		<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'سفارش‌های باسلام', 'basalamhub' ); ?></h2></div>
		<label class="bsh-checkbox">
			<input type="checkbox" name="orders_enabled" value="1" <?php checked( (int) $bsh_s['orders_enabled'], 1 ); ?>>
			<?php esc_html_e( 'سفارش‌های باسلام خودکار در ووکامرس ثبت شوند', 'basalamhub' ); ?>
		</label>
		<label class="bsh-checkbox">
			<input type="checkbox" name="orders_auto_confirm" value="1" <?php checked( (int) $bsh_s['orders_auto_confirm'], 1 ); ?>>
			<?php esc_html_e( 'سفارش جدید بلافاصله در باسلام «تأیید» شود (وضعیت: در حال آماده‌سازی)', 'basalamhub' ); ?>
		</label>
		<div class="bsh-form-grid">
			<label class="bsh-field<?php echo esc_attr( $bsh_err_class( 'orders_interval' ) ); ?>">
				<span class="bsh-field__label"><?php esc_html_e( 'بررسی سفارش‌ها هر چند دقیقه', 'basalamhub' ); ?></span>
				<input class="bsh-field__input" type="number" min="2" max="60" name="orders_interval" value="<?php echo esc_attr( $bsh_val( 'orders_interval' ) ); ?>">
				<?php $bsh_hint( 'orders_interval', __( 'هر بار سفارش‌های تازه و تغییر وضعیت سفارش‌های باز خوانده می‌شود. ۵ دقیقه برای بیشتر فروشگاه‌ها مناسب است.', 'basalamhub' ) ); ?>
			</label>
			<label class="bsh-field<?php echo esc_attr( $bsh_err_class( 'orders_import_days' ) ); ?>">
				<span class="bsh-field__label"><?php esc_html_e( 'سفارش‌های چند روز گذشته وارد شوند', 'basalamhub' ); ?></span>
				<input class="bsh-field__input" type="number" min="0" max="30" name="orders_import_days" value="<?php echo esc_attr( $bsh_val( 'orders_import_days' ) ); ?>">
				<?php $bsh_hint( 'orders_import_days', __( 'فقط در اولین دریافت اعمال می‌شود. صفر یعنی فقط سفارش‌هایی که از این به بعد می‌آیند.', 'basalamhub' ) ); ?>
			</label>
		</div>
	</section>

	<section class="bsh-card bsh-section" id="bsh-stock-settings">
		<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'موجودی دوطرفه', 'basalamhub' ); ?></h2></div>
		<fieldset class="bsh-fieldset">
			<legend class="bsh-field__label"><?php esc_html_e( 'مرجع موجودی', 'basalamhub' ); ?></legend>
			<label class="bsh-radio">
				<input type="radio" name="stock_reference" value="site" <?php checked( $bsh_s['stock_reference'], 'site' ); ?>>
				<span><strong><?php esc_html_e( 'سایت (پیشنهادی)', 'basalamhub' ); ?></strong> — <?php esc_html_e( 'موجودی را در ووکامرس مدیریت می‌کنی. هر فروش در سایت یا باسلام از موجودی سایت کم می‌شود و عدد جدید به باسلام می‌رود.', 'basalamhub' ); ?></span>
			</label>
			<label class="bsh-radio">
				<input type="radio" name="stock_reference" value="basalam" <?php checked( $bsh_s['stock_reference'], 'basalam' ); ?>>
				<span><strong><?php esc_html_e( 'باسلام', 'basalamhub' ); ?></strong> — <?php esc_html_e( 'موجودی را در پنل باسلام مدیریت می‌کنی. هر ساعت موجودی باسلام در سایت نوشته می‌شود و فروش سایت از موجودی باسلام کم می‌شود.', 'basalamhub' ); ?></span>
			</label>
		</fieldset>
		<label class="bsh-field<?php echo esc_attr( $bsh_err_class( 'safety_stock' ) ); ?>">
			<span class="bsh-field__label"><?php esc_html_e( 'موجودی اطمینان', 'basalamhub' ); ?></span>
			<input class="bsh-field__input" type="number" min="0" name="safety_stock" value="<?php echo esc_attr( $bsh_val( 'safety_stock' ) ); ?>">
			<?php $bsh_hint( 'safety_stock', __( 'این تعداد از هر کالا در باسلام نمایش داده نمی‌شود تا اگر هم‌زمان در سایت و باسلام فروش رفت، بیش‌فروشی نشود. مثلاً موجودی ۱۰ و اطمینان ۲ یعنی باسلام ۸ می‌بیند. برای هر محصول جدا هم در تب «انبار» محصول قابل تغییر است.', 'basalamhub' ) ); ?>
		</label>
		<label class="bsh-checkbox">
			<input type="checkbox" name="low_stock_alert" value="1" <?php checked( (int) $bsh_s['low_stock_alert'], 1 ); ?>>
			<?php esc_html_e( 'وقتی موجودی کالایی که در باسلام است کم شد یا تمام شد، هشدار بده (لاگ و بله/تلگرام)', 'basalamhub' ); ?>
		</label>
		<label class="bsh-field<?php echo esc_attr( $bsh_err_class( 'low_stock_threshold' ) ); ?>">
			<span class="bsh-field__label"><?php esc_html_e( 'حد هشدار موجودی', 'basalamhub' ); ?></span>
			<input class="bsh-field__input" type="number" min="0" name="low_stock_threshold" value="<?php echo esc_attr( $bsh_val( 'low_stock_threshold' ) ); ?>">
			<?php $bsh_hint( 'low_stock_threshold', __( 'وقتی موجودی به این عدد یا کمتر برسد هشدار می‌آید، فقط یک بار برای هر بار کم‌شدن. اگر برای محصولی در ووکامرس «آستانه‌ی کمبود موجودی» جدا گذاشته‌ای، همان استفاده می‌شود.', 'basalamhub' ) ); ?>
		</label>
	</section>

	<section class="bsh-card bsh-section">
		<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'به‌روزرسانی خودکار', 'basalamhub' ); ?></h2></div>
		<label class="bsh-checkbox">
			<input type="checkbox" name="auto_update" value="1" <?php checked( (int) $bsh_s['auto_update'], 1 ); ?>>
			<?php esc_html_e( 'بعد از ذخیره‌ی محصول متصل در ووکامرس، همان محصول در باسلام به‌روز شود', 'basalamhub' ); ?>
		</label>
		<label class="bsh-checkbox">
			<input type="checkbox" name="auto_send_new" value="1" <?php checked( (int) $bsh_s['auto_send_new'], 1 ); ?>>
			<?php esc_html_e( 'محصولات جدید هنگام انتشار خودکار به باسلام ارسال شوند', 'basalamhub' ); ?>
		</label>

		<fieldset class="bsh-fieldset">
			<legend class="bsh-field__label"><?php esc_html_e( 'کدام فیلدها در به‌روزرسانی ارسال شوند؟', 'basalamhub' ); ?></legend>
			<div class="bsh-checkbox-grid">
				<?php foreach ( BSH_Settings::field_groups() as $bsh_key => $bsh_label ) : ?>
					<label class="bsh-checkbox">
						<input type="checkbox" name="sync_fields[]" value="<?php echo esc_attr( $bsh_key ); ?>" <?php checked( in_array( $bsh_key, (array) $bsh_s['sync_fields'], true ) ); ?>>
						<?php echo esc_html( $bsh_label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
			<span class="bsh-field__hint"><?php esc_html_e( 'فیلدی که تیک ندارد در باسلام دست نمی‌خورد؛ مثلاً اگر فقط قیمت و موجودی تیک داشته باشد، توضیحاتی که در باسلام نوشته‌ای حفظ می‌شود. موقع ساخت اولیه همه‌ی فیلدها ارسال می‌شوند.', 'basalamhub' ); ?></span>
		</fieldset>

		<label class="bsh-field<?php echo esc_attr( $bsh_err_class( 'log_retention_days' ) ); ?>">
			<span class="bsh-field__label"><?php esc_html_e( 'نگهداری لاگ (روز)', 'basalamhub' ); ?></span>
			<input class="bsh-field__input" type="number" min="7" max="365" name="log_retention_days" value="<?php echo esc_attr( $bsh_val( 'log_retention_days' ) ); ?>">
			<?php $bsh_hint( 'log_retention_days', __( 'لاگ‌های قدیمی‌تر روزانه پاک می‌شوند تا دیتابیس سبک بماند.', 'basalamhub' ) ); ?>
		</label>

		<div class="bsh-card__foot">
			<button type="submit" class="bsh-btn"><?php esc_html_e( 'ذخیره‌ی تنظیمات', 'basalamhub' ); ?></button>
		</div>
	</section>
</form>
