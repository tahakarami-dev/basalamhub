<?php
/**
 * «ایمپورت غرفه»: bring the Basalam booth's products into WooCommerce, with a preview.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

$slh_link_state = SLH_Linker::state();
$slh_linking    = SLH_Linker::is_running();
$slh_preview    = SLH_Importer::preview();
$slh_state      = SLH_Importer::state();
$slh_running    = SLH_Importer::is_running();
$slh_rules      = SLH_Price_Rules::is_active();
?>
<header class="slh-page-head">
	<div>
		<h1 class="slh-page-title"><?php esc_html_e( 'ایمپورت غرفه به ووکامرس', 'salamhub' ); ?></h1>
		<p class="slh-card__meta"><?php esc_html_e( 'محصولات غرفه‌ی باسلام با عکس، قیمت، موجودی، دسته و تنوع‌ها در سایت ساخته می‌شوند. اجرای دوباره هیچ محصولی را تکراری نمی‌سازد؛ فقط به‌روز می‌کند.', 'salamhub' ); ?></p>
	</div>
	<button type="button" class="slh-btn" data-slh-link-start <?php disabled( ! SLH_Settings::is_connected() || $slh_linking || $slh_running ); ?>>
		<span class="dashicons dashicons-update" aria-hidden="true"></span>
		<?php echo $slh_preview['ready'] ? esc_html__( 'دریافت دوباره‌ی فهرست غرفه', 'salamhub' ) : esc_html__( 'دریافت فهرست غرفه', 'salamhub' ); ?>
	</button>
</header>

<?php if ( ! SLH_Settings::is_connected() ) : ?>
	<p class="slh-alert slh-alert--warning"><?php esc_html_e( 'اول از تنظیمات به باسلام وصل شو.', 'salamhub' ); ?></p>
<?php endif; ?>

<section class="slh-card slh-section" data-slh-link-progress data-running="<?php echo $slh_linking ? '1' : '0'; ?>" <?php echo $slh_linking ? '' : 'hidden'; ?>>
	<div data-slh-link-html><?php SLH_Admin_Tools::link_progress_html( $slh_link_state ); ?></div>
	<p class="slh-field__hint" data-slh-link-message aria-live="polite"></p>
</section>

<?php if ( 'idle' !== $slh_state['status'] ) : ?>
	<section class="slh-card slh-section" data-slh-import-progress data-running="<?php echo $slh_running ? '1' : '0'; ?>">
		<div data-slh-import-html><?php SLH_Import_UI::import_progress_html(); ?></div>
	</section>
<?php endif; ?>

<?php if ( ! $slh_preview['ready'] && ! $slh_linking ) : ?>
	<div class="slh-card slh-empty">
		<span class="dashicons dashicons-download" aria-hidden="true"></span>
		<p><?php esc_html_e( 'اول «دریافت فهرست غرفه» را بزن. محصولات غرفه در پس‌زمینه خوانده و با محصولات سایت مقایسه می‌شوند؛ هنوز چیزی ساخته نمی‌شود.', 'salamhub' ); ?></p>
	</div>
<?php elseif ( $slh_preview['ready'] && ! $slh_running ) : ?>
	<section class="slh-card slh-section">
		<div class="slh-card__head">
			<div>
				<h2 class="slh-card__title"><?php esc_html_e( 'پیش‌نمایش', 'salamhub' ); ?></h2>
				<?php /* translators: %s: relative time */ ?>
				<p class="slh-card__meta"><?php echo esc_html( sprintf( __( 'فهرست غرفه: %s', 'salamhub' ), $slh_preview['fetched_at'] ? slh_time_ago( $slh_preview['fetched_at'] ) : '—' ) ); ?></p>
			</div>
		</div>
		<div class="slh-tiles slh-tiles--inner">
			<div class="slh-tile">
				<span class="slh-tile__label"><?php esc_html_e( 'ساخته می‌شود', 'salamhub' ); ?></span>
				<span class="slh-tile__value"><?php echo esc_html( slh_fa_number( $slh_preview['new'] ) ); ?></span>
				<span class="slh-tile__meta"><?php esc_html_e( 'در سایت جفتی ندارند', 'salamhub' ); ?></span>
			</div>
			<div class="slh-tile">
				<span class="slh-tile__label"><?php esc_html_e( 'به‌روز می‌شود', 'salamhub' ); ?></span>
				<span class="slh-tile__value"><?php echo esc_html( slh_fa_number( $slh_preview['linked'] ) ); ?></span>
				<span class="slh-tile__meta"><?php esc_html_e( 'از قبل متصل‌اند (اختیاری)', 'salamhub' ); ?></span>
			</div>
			<a class="slh-tile<?php echo $slh_preview['review'] ? ' slh-tile--alert' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-link' ) ); ?>" data-slh-nav>
				<span class="slh-tile__label"><?php esc_html_e( 'منتظر بررسی تو', 'salamhub' ); ?></span>
				<span class="slh-tile__value"><?php echo esc_html( slh_fa_number( $slh_preview['review'] ) ); ?></span>
				<span class="slh-tile__meta"><?php echo $slh_preview['review'] ? esc_html__( 'شبیه محصولی در سایت‌اند؛ اول وصلشان کن', 'salamhub' ) : esc_html__( 'موردی نیست', 'salamhub' ); ?></span>
			</a>
		</div>
		<?php if ( $slh_preview['review'] ) : ?>
			<p class="slh-alert slh-alert--warning"><?php esc_html_e( 'این محصولات وارد نمی‌شوند، چون احتمالاً در سایت هستند و ساختنشان تکراری می‌سازد. در «اتصال محصولات غرفه» تأییدشان کن؛ بعد از اتصال، ایمپورت آن‌ها را به‌روز می‌کند.', 'salamhub' ); ?></p>
		<?php endif; ?>

		<fieldset class="slh-fieldset">
			<legend class="slh-field__label"><?php esc_html_e( 'تنظیمات ایمپورت', 'salamhub' ); ?></legend>
			<label class="slh-checkbox">
				<input type="checkbox" data-slh-import-update value="1" checked>
				<?php esc_html_e( 'موجودی (و قیمت) محصولات متصل هم از باسلام به‌روز شود', 'salamhub' ); ?>
			</label>
			<label class="slh-field slh-field--inline">
				<span class="slh-field__label"><?php esc_html_e( 'وضعیت محصولات جدید در سایت', 'salamhub' ); ?></span>
				<select class="slh-field__select" data-slh-import-publish>
					<option value="publish"><?php esc_html_e( 'منتشرشده', 'salamhub' ); ?></option>
					<option value="draft"><?php esc_html_e( 'پیش‌نویس (اول خودم بررسی می‌کنم)', 'salamhub' ); ?></option>
				</select>
			</label>
		</fieldset>
		<?php if ( $slh_rules ) : ?>
			<p class="slh-alert slh-alert--warning"><?php esc_html_e( 'قانون قیمت فعال است. قیمت محصولات جدید همان قیمت باسلام وارد می‌شود و قیمت محصولات متصل دست نمی‌خورد؛ وگرنه قانون دو بار اعمال می‌شد. بعد از ایمپورت قیمت‌های سایت را بررسی کن.', 'salamhub' ); ?></p>
		<?php endif; ?>
		<ul class="slh-list">
			<li><?php esc_html_e( 'عکس‌ها در کتابخانه‌ی رسانه ذخیره می‌شوند و بعداً دوباره در باسلام آپلود نمی‌شوند.', 'salamhub' ); ?></li>
			<li><?php esc_html_e( 'دسته‌ی باسلام به دسته‌ی نگاشت‌شده می‌رود؛ اگر نگاشتی نیست، دسته‌ای با همان نام ساخته و نگاشت می‌شود.', 'salamhub' ); ?></li>
			<li><?php esc_html_e( 'موجودی سایت = موجودی باسلام + موجودی اطمینان. هر محصول بلافاصله به محصول باسلام وصل می‌شود.', 'salamhub' ); ?></li>
			<li><?php esc_html_e( 'محصولی که SKU آن در سایت هست، ساخته نمی‌شود و دلیلش در لاگ می‌آید.', 'salamhub' ); ?></li>
		</ul>
		<div class="slh-card__foot">
			<button type="button" class="slh-btn slh-btn--primary" data-slh-import-start data-new="<?php echo esc_attr( $slh_preview['new'] ); ?>" data-linked="<?php echo esc_attr( $slh_preview['linked'] ); ?>" <?php disabled( 0 === $slh_preview['new'] + $slh_preview['linked'] ); ?>>
				<?php esc_html_e( 'شروع ایمپورت', 'salamhub' ); ?>
			</button>
		</div>
		<p class="slh-field__hint" data-slh-import-message aria-live="polite"></p>
	</section>
<?php endif; ?>
