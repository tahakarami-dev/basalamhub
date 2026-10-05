<?php
/**
 * «تست با باسلام واقعی»: step-by-step checks against the real API and a copyable report.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

$bsh_connected = BSH_Settings::is_connected();
$bsh_products  = wc_get_products(
	array(
		'status'  => 'publish',
		'limit'   => 200,
		'orderby' => 'date',
		'order'   => 'DESC',
		'type'    => array( 'simple', 'variable' ),
	)
);
$bsh_steps     = array(
	array( 'read', __( '۱. اتصال و خواندن', 'basalamhub' ), __( 'توکن، غرفه، دسته‌ها، ویژگی‌ها، محصولات و سفارش‌های غرفه را می‌خواند و بررسی می‌کند همه‌ی فیلدهایی که افزونه لازم دارد در پاسخ باسلام هست. چیزی تغییر نمی‌کند.', 'basalamhub' ), '' ),
	array( 'upload', __( '۲. آپلود تصویر', 'basalamhub' ), __( 'یک تصویر نارنجی ساده آپلود می‌کند. به هیچ محصولی وصل نمی‌شود و در غرفه دیده نمی‌شود.', 'basalamhub' ), __( 'یک تصویر آزمایشی در باسلام آپلود شود؟', 'basalamhub' ) ),
	array( 'product', __( '۳. ارسال یک محصول', 'basalamhub' ), __( 'محصول انتخاب‌شده را با مسیر عادی افزونه به باسلام می‌فرستد و دوباره می‌خواند تا نام، قیمت، موجودی، تصویر و تنوع‌ها مقایسه شوند. دوباره زدن همین دکمه به‌روزرسانی را تست می‌کند.', 'basalamhub' ), __( 'این محصول در غرفه‌ی باسلام ساخته یا به‌روز می‌شود و برای خریداران قابل دیدن است. ادامه می‌دهی؟', 'basalamhub' ) ),
	array( 'hide', __( '۴. ناموجود کردن محصول تست', 'basalamhub' ), __( 'موجودی همان محصول را در باسلام صفر می‌کند تا کسی سفارشش ندهد.', 'basalamhub' ), __( 'موجودی این محصول در باسلام صفر شود؟', 'basalamhub' ) ),
	array( 'orders', __( '۵. دریافت سفارش‌ها', 'basalamhub' ), __( 'سفارش‌های اخیر غرفه را همین حالا در ووکامرس ثبت می‌کند (بدون تکرار) و خلاصه‌ی هرکدام را گزارش می‌دهد. برای این مرحله یک خرید آزمایشی از غرفه لازم است.', 'basalamhub' ), __( 'سفارش‌های اخیر باسلام در ووکامرس ثبت شوند؟', 'basalamhub' ) ),
);
?>
<header class="bsh-page-head">
	<div>
		<h1 class="bsh-page-title"><?php esc_html_e( 'تست با باسلام واقعی', 'basalamhub' ); ?></h1>
		<p class="bsh-card__meta"><?php esc_html_e( 'مرحله‌ها را به ترتیب اجرا کن. آخر کار، گزارش پایین صفحه را کپی کن و برای پشتیبانی بفرست؛ توکن در گزارش نیست و نام و تلفن مشتری‌ها پوشانده می‌شود.', 'basalamhub' ); ?></p>
	</div>
</header>

<?php if ( ! BSH_Settings::has_token() ) : ?>
	<p class="bsh-alert bsh-alert--warning">
		<?php esc_html_e( 'اول در تنظیمات توکن باسلام را وارد کن.', 'basalamhub' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-settings' ) ); ?>" data-bsh-nav><?php esc_html_e( 'رفتن به تنظیمات', 'basalamhub' ); ?></a>
	</p>
<?php endif; ?>

<section class="bsh-card bsh-section bsh-livetest" data-bsh-livetest>
	<label class="bsh-field">
		<span class="bsh-field__label"><?php esc_html_e( 'محصول تست (برای مرحله‌ی ۳ و ۴)', 'basalamhub' ); ?></span>
		<select class="bsh-field__select" data-bsh-lt-product>
			<option value=""><?php esc_html_e( '— یک محصول ارزان و عکس‌دار انتخاب کن —', 'basalamhub' ); ?></option>
			<?php foreach ( $bsh_products as $bsh_p ) : ?>
				<?php $bsh_link = BSH_Links::get( 'product', $bsh_p->get_id() ); ?>
				<option value="<?php echo esc_attr( $bsh_p->get_id() ); ?>">
					<?php echo esc_html( $bsh_p->get_name() . ( $bsh_p->is_type( 'variable' ) ? ' · ' . __( 'متغیر', 'basalamhub' ) : '' ) . ( $bsh_link && $bsh_link->basalam_id ? ' · ' . __( 'در باسلام هست', 'basalamhub' ) : '' ) ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<span class="bsh-field__hint"><?php esc_html_e( 'محصول باید دسته‌ی باسلام (نگاشت یا دسته‌ی پیش‌فرض) و تصویر داشته باشد.', 'basalamhub' ); ?></span>
	</label>

	<ol class="bsh-livetest__steps">
		<?php foreach ( $bsh_steps as $bsh_step ) : ?>
			<li class="bsh-livetest__step" data-bsh-lt-step="<?php echo esc_attr( $bsh_step[0] ); ?>">
				<div class="bsh-livetest__head">
					<div>
						<strong><?php echo esc_html( $bsh_step[1] ); ?></strong>
						<p class="bsh-card__meta"><?php echo esc_html( $bsh_step[2] ); ?></p>
					</div>
					<button type="button" class="bsh-btn<?php echo 'read' === $bsh_step[0] ? ' bsh-btn--primary' : ''; ?>" data-bsh-lt-run="<?php echo esc_attr( $bsh_step[0] ); ?>" data-confirm="<?php echo esc_attr( $bsh_step[3] ); ?>" <?php disabled( ! BSH_Settings::has_token() ); ?>><?php esc_html_e( 'اجرا', 'basalamhub' ); ?></button>
				</div>
				<ul class="bsh-livetest__result" data-bsh-lt-result hidden></ul>
			</li>
		<?php endforeach; ?>
	</ol>
</section>

<section class="bsh-card bsh-section">
	<div class="bsh-card__head">
		<h2 class="bsh-card__title"><?php esc_html_e( 'گزارش', 'basalamhub' ); ?></h2>
		<button type="button" class="bsh-btn bsh-btn--ghost" data-bsh-lt-copy><?php esc_html_e( 'کپی گزارش', 'basalamhub' ); ?></button>
	</div>
	<textarea class="bsh-livetest__report" data-bsh-lt-report readonly rows="12" dir="rtl"><?php echo esc_textarea( BSH_Live_Test::environment() ); ?></textarea>
</section>
