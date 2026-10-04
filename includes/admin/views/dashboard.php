<?php
/**
 * Dashboard and health page.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

$conn      = SLH_Settings::connection();
$connected = SLH_Settings::is_connected();
$counts    = SLH_Links::counts( 'product' );
$queue     = SLH_Queue::stats();
$open_err  = SLH_Logger::count_open_errors( 24 );
$last_sync = get_option( 'slh_last_sync_at', '' );
$errors    = SLH_Logger::recent_errors( 10 );
$token_bad = SLH_Settings::has_token() && null === SLH_Settings::get_token();
$wc_ver    = defined( 'WC_VERSION' ) ? WC_VERSION : '';

// Health checks: [ status(ok|warn|bad), title, detail ].
$checks = array();

if ( ! SLH_Settings::has_token() ) {
	$checks[] = array( 'bad', __( 'توکن باسلام', 'salamhub' ), __( 'وارد نشده. از تنظیمات وارد کن.', 'salamhub' ) );
} elseif ( $token_bad ) {
	$checks[] = array( 'bad', __( 'توکن باسلام', 'salamhub' ), __( 'قابل خواندن نیست (کلیدهای امنیتی وردپرس عوض شده). دوباره واردش کن.', 'salamhub' ) );
} elseif ( 'ok' !== $conn['status'] ) {
	$checks[] = array( 'bad', __( 'توکن باسلام', 'salamhub' ), $conn['message'] ? $conn['message'] : __( 'هنوز تست نشده. در تنظیمات «تست اتصال» را بزن.', 'salamhub' ) );
} else {
	/* translators: %s: relative time */
	$checks[] = array( 'ok', __( 'توکن باسلام', 'salamhub' ), sprintf( __( 'معتبر · آخرین بررسی %s', 'salamhub' ), slh_time_ago( $conn['checked_at'] ) ) );
}

$checks[] = array( $last_sync ? 'ok' : 'warn', __( 'آخرین همگام‌سازی موفق', 'salamhub' ), $last_sync ? slh_time_ago( $last_sync ) : __( 'هنوز محصولی ارسال نشده.', 'salamhub' ) );

if ( ! SLH_Queue::available() ) {
	$checks[] = array( 'bad', __( 'صف پس‌زمینه', 'salamhub' ), __( 'Action Scheduler در دسترس نیست. ووکامرس را به‌روز کن.', 'salamhub' ) );
} elseif ( $queue['past_due'] > 0 ) {
	/* translators: %s: count */
	$checks[] = array( 'warn', __( 'صف پس‌زمینه', 'salamhub' ), sprintf( __( '%s کار بیش از ۱۰ دقیقه منتظر مانده. احتمالاً WP-Cron روی هاست غیرفعال است؛ از هاستینگ بخواه یک Cron Job برای wp-cron.php هر ۵ دقیقه بسازد.', 'salamhub' ), slh_fa_digits( $queue['past_due'] ) ) );
} else {
	/* translators: 1: pending, 2: running */
	$checks[] = array( 'ok', __( 'صف پس‌زمینه', 'salamhub' ), sprintf( __( 'فعال · %1$s در انتظار، %2$s در حال اجرا', 'salamhub' ), slh_fa_digits( $queue['pending'] ), slh_fa_digits( $queue['running'] ) ) );
}

$checks[] = SLH_Crypto::available()
	? array( 'ok', __( 'رمزنگاری توکن', 'salamhub' ), function_exists( 'sodium_crypto_secretbox' ) ? 'libsodium' : 'OpenSSL AES-256-GCM' )
	: array( 'bad', __( 'رمزنگاری توکن', 'salamhub' ), __( 'نه sodium هست نه openssl؛ توکن ذخیره نمی‌شود. از هاستینگ بخواه یکی را فعال کند.', 'salamhub' ) );

$checks[] = array( version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'bad', __( 'نسخه‌ی PHP', 'salamhub' ), PHP_VERSION );
$checks[] = array( version_compare( $wc_ver, '7.0', '>=' ) ? 'ok' : 'warn', __( 'نسخه‌ی ووکامرس', 'salamhub' ), $wc_ver );

$multiplier = SLH_Product_Mapper::rial_multiplier();
$checks[]   = null === $multiplier
	/* translators: %s: currency */
	? array( 'bad', __( 'واحد پول', 'salamhub' ), sprintf( __( 'واحد %s قابل تبدیل به ریال نیست؛ در تنظیمات واحد را دستی انتخاب کن.', 'salamhub' ), get_woocommerce_currency() ) )
	: array( 'ok', __( 'واحد پول', 'salamhub' ), 1 === $multiplier ? __( 'ریال (بدون تبدیل)', 'salamhub' ) : sprintf( /* translators: %s: multiplier */ __( 'قیمت‌ها ×%s به ریال تبدیل می‌شوند', 'salamhub' ), slh_fa_number( $multiplier ) ) );

$check_icons = array( 'ok' => 'dashicons-yes-alt', 'warn' => 'dashicons-warning', 'bad' => 'dashicons-dismiss' );
?>
<header class="slh-page-head">
	<h1 class="slh-page-title"><?php esc_html_e( 'سلام‌هاب', 'salamhub' ); ?></h1>
	<p class="slh-card__meta"><?php esc_html_e( 'یک بار ثبت کن، دو جا بفروش.', 'salamhub' ); ?></p>
</header>

<?php if ( ! $connected ) : ?>
	<section class="slh-card slh-section">
		<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'راه‌اندازی در چند دقیقه', 'salamhub' ); ?></h2></div>
		<ol class="slh-steps">
			<li><?php esc_html_e( 'در پنل توسعه‌دهندگان باسلام یک توکن دسترسی شخصی بساز (با دسترسی محصولات غرفه).', 'salamhub' ); ?></li>
			<li><?php esc_html_e( 'توکن را در تنظیمات سلام‌هاب بچسبان و «ذخیره و تست اتصال» را بزن.', 'salamhub' ); ?></li>
			<li><?php esc_html_e( 'شناسه‌ی دسته‌ی پیش‌فرض باسلام را وارد کن.', 'salamhub' ); ?></li>
			<li><?php esc_html_e( 'در صفحه‌ی ویرایش یک محصول، «ارسال به باسلام» را بزن.', 'salamhub' ); ?></li>
		</ol>
		<div class="slh-card__foot">
			<a class="slh-btn slh-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-settings' ) ); ?>"><?php esc_html_e( 'اتصال به باسلام', 'salamhub' ); ?></a>
		</div>
	</section>
<?php endif; ?>

<section class="slh-card slh-section">
	<div class="slh-card__head">
		<h2 class="slh-card__title"><?php esc_html_e( 'اتصال به باسلام', 'salamhub' ); ?></h2>
		<?php echo $connected ? slh_badge( 'synced' ) : slh_badge( 'error' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<p class="slh-card__meta">
		<?php
		if ( $connected ) {
			/* translators: 1: booth title, 2: relative time */
			echo esc_html( sprintf( __( 'غرفه‌ی «%1$s» · آخرین همگام‌سازی %2$s', 'salamhub' ), $conn['vendor_title'], slh_time_ago( $last_sync ) ) );
		} else {
			esc_html_e( 'وصل نیست. تا اتصال برقرار نشود چیزی ارسال نمی‌شود.', 'salamhub' );
		}
		?>
	</p>
	<div class="slh-stats">
		<div><div class="slh-stat__value"><?php echo esc_html( slh_fa_number( $counts['linked'] ) ); ?></div><div class="slh-stat__label"><?php esc_html_e( 'محصول متصل', 'salamhub' ); ?></div></div>
		<div><div class="slh-stat__value"><?php echo esc_html( slh_fa_number( $queue['pending'] + $queue['running'] ) ); ?></div><div class="slh-stat__label"><?php esc_html_e( 'در صف', 'salamhub' ); ?></div></div>
		<div><div class="slh-stat__value"><?php echo esc_html( slh_fa_number( $open_err ) ); ?></div><div class="slh-stat__label"><?php esc_html_e( 'خطای باز در ۲۴ ساعت', 'salamhub' ); ?></div></div>
		<div><div class="slh-stat__value">—</div><div class="slh-stat__label"><?php esc_html_e( 'سفارش جاافتاده (با فعال‌شدن تطبیق شبانه)', 'salamhub' ); ?></div></div>
	</div>
	<div class="slh-card__foot">
		<a class="slh-btn<?php echo $connected ? '' : ' slh-btn--ghost'; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-logs' ) ); ?>"><?php esc_html_e( 'مشاهده‌ی لاگ', 'salamhub' ); ?></a>
		<a class="slh-btn slh-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-settings' ) ); ?>"><?php esc_html_e( 'تنظیمات', 'salamhub' ); ?></a>
	</div>
</section>

<section class="slh-card slh-section">
	<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'سلامت سیستم', 'salamhub' ); ?></h2></div>
	<ul class="slh-checks">
		<?php foreach ( $checks as $check ) : ?>
			<li class="slh-check slh-check--<?php echo esc_attr( $check[0] ); ?>">
				<span class="dashicons <?php echo esc_attr( $check_icons[ $check[0] ] ); ?>" aria-hidden="true"></span>
				<span class="slh-check__title"><?php echo esc_html( $check[1] ); ?></span>
				<span class="slh-check__detail"><?php echo esc_html( $check[2] ); ?></span>
			</li>
		<?php endforeach; ?>
		<?php if ( $queue['failed'] > 0 ) : ?>
			<li class="slh-check slh-check--warn">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<span class="slh-check__title"><?php esc_html_e( 'کارهای متوقف‌شده‌ی صف', 'salamhub' ); ?></span>
				<span class="slh-check__detail">
					<?php
					/* translators: %s: count */
					echo esc_html( sprintf( __( '%s کار در ۲۴ ساعت گذشته با خطای سیستمی متوقف شد. جزئیات در ووکامرس › وضعیت › کارهای زمان‌بندی‌شده (گروه salamhub).', 'salamhub' ), slh_fa_digits( $queue['failed'] ) ) );
					?>
				</span>
			</li>
		<?php endif; ?>
	</ul>
</section>

<section class="slh-section">
	<div class="slh-section__head">
		<h2 class="slh-card__title"><?php esc_html_e( '۱۰ خطای اخیر', 'salamhub' ); ?></h2>
		<?php if ( $errors ) : ?>
			<a class="slh-btn slh-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-logs&level=error' ) ); ?>"><?php esc_html_e( 'همه‌ی خطاها', 'salamhub' ); ?></a>
		<?php endif; ?>
	</div>
	<?php if ( ! $errors ) : ?>
		<div class="slh-card"><p class="slh-card__body"><?php esc_html_e( 'هیچ خطایی ثبت نشده. همه‌چیز تحت کنترل است.', 'salamhub' ); ?></p></div>
	<?php else : ?>
		<?php
		$slh_rows = $errors;
		include __DIR__ . '/log-table.php';
		?>
	<?php endif; ?>
</section>
