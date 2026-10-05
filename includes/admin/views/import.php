<?php
/**
 * «ایمپورت غرفه»: bring the Basalam booth's products into WooCommerce, with a preview.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

$bsh_link_state = BSH_Linker::state();
$bsh_linking    = BSH_Linker::is_running();
$bsh_preview    = BSH_Importer::preview();
$bsh_state      = BSH_Importer::state();
$bsh_running    = BSH_Importer::is_running();
$bsh_rules      = BSH_Price_Rules::is_active();
?>
<header class="bsh-page-head">
	<div>
		<h1 class="bsh-page-title"><?php esc_html_e( 'ایمپورت غرفه به ووکامرس', 'basalamhub' ); ?></h1>
		<p class="bsh-card__meta"><?php esc_html_e( 'محصولات غرفه‌ی باسلام با عکس، قیمت، موجودی، دسته و تنوع‌ها در سایت ساخته می‌شوند. اجرای دوباره هیچ محصولی را تکراری نمی‌سازد؛ فقط به‌روز می‌کند.', 'basalamhub' ); ?></p>
	</div>
	<button type="button" class="bsh-btn" data-bsh-link-start <?php disabled( ! BSH_Settings::is_connected() || $bsh_linking || $bsh_running ); ?>>
		<?php BSH_Icons::e( 'refresh' ); ?>
		<?php echo $bsh_preview['ready'] ? esc_html__( 'دریافت دوباره‌ی فهرست غرفه', 'basalamhub' ) : esc_html__( 'دریافت فهرست غرفه', 'basalamhub' ); ?>
	</button>
</header>

<?php if ( ! BSH_Settings::is_connected() ) : ?>
	<p class="bsh-alert bsh-alert--warning"><?php esc_html_e( 'اول از تنظیمات به باسلام وصل شو.', 'basalamhub' ); ?></p>
<?php endif; ?>

<section class="bsh-card bsh-section" data-bsh-link-progress data-running="<?php echo $bsh_linking ? '1' : '0'; ?>" <?php echo $bsh_linking ? '' : 'hidden'; ?>>
	<div data-bsh-link-html><?php BSH_Admin_Tools::link_progress_html( $bsh_link_state ); ?></div>
	<p class="bsh-field__hint" data-bsh-link-message aria-live="polite"></p>
</section>

<?php if ( 'idle' !== $bsh_state['status'] ) : ?>
	<section class="bsh-card bsh-section" data-bsh-import-progress data-running="<?php echo $bsh_running ? '1' : '0'; ?>">
		<div data-bsh-import-html><?php BSH_Import_UI::import_progress_html(); ?></div>
	</section>
<?php endif; ?>

<?php if ( ! $bsh_preview['ready'] && ! $bsh_linking ) : ?>
	<div class="bsh-card bsh-empty">
		<?php BSH_Icons::e( 'download' ); ?>
		<p><?php esc_html_e( 'اول «دریافت فهرست غرفه» را بزن. محصولات غرفه در پس‌زمینه خوانده و با محصولات سایت مقایسه می‌شوند؛ هنوز چیزی ساخته نمی‌شود.', 'basalamhub' ); ?></p>
	</div>
<?php elseif ( $bsh_preview['ready'] && ! $bsh_running ) : ?>
	<section class="bsh-card bsh-section">
		<div class="bsh-card__head">
			<div>
				<h2 class="bsh-card__title"><?php esc_html_e( 'پیش‌نمایش', 'basalamhub' ); ?></h2>
				<?php /* translators: %s: relative time */ ?>
				<p class="bsh-card__meta"><?php echo esc_html( sprintf( __( 'فهرست غرفه: %s', 'basalamhub' ), $bsh_preview['fetched_at'] ? bsh_time_ago( $bsh_preview['fetched_at'] ) : '—' ) ); ?></p>
			</div>
		</div>
		<div class="bsh-tiles bsh-tiles--inner">
			<div class="bsh-tile">
				<span class="bsh-tile__label"><?php esc_html_e( 'ساخته می‌شود', 'basalamhub' ); ?></span>
				<span class="bsh-tile__value"><?php echo esc_html( bsh_fa_number( $bsh_preview['new'] ) ); ?></span>
				<span class="bsh-tile__meta"><?php esc_html_e( 'در سایت جفتی ندارند', 'basalamhub' ); ?></span>
			</div>
			<div class="bsh-tile">
				<span class="bsh-tile__label"><?php esc_html_e( 'به‌روز می‌شود', 'basalamhub' ); ?></span>
				<span class="bsh-tile__value"><?php echo esc_html( bsh_fa_number( $bsh_preview['linked'] ) ); ?></span>
				<span class="bsh-tile__meta"><?php esc_html_e( 'از قبل متصل‌اند (اختیاری)', 'basalamhub' ); ?></span>
			</div>
			<a class="bsh-tile<?php echo $bsh_preview['review'] ? ' bsh-tile--alert' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-link' ) ); ?>" data-bsh-nav>
				<span class="bsh-tile__label"><?php esc_html_e( 'منتظر بررسی تو', 'basalamhub' ); ?></span>
				<span class="bsh-tile__value"><?php echo esc_html( bsh_fa_number( $bsh_preview['review'] ) ); ?></span>
				<span class="bsh-tile__meta"><?php echo $bsh_preview['review'] ? esc_html__( 'شبیه محصولی در سایت‌اند؛ اول وصلشان کن', 'basalamhub' ) : esc_html__( 'موردی نیست', 'basalamhub' ); ?></span>
			</a>
		</div>
		<?php if ( $bsh_preview['review'] ) : ?>
			<p class="bsh-alert bsh-alert--warning"><?php esc_html_e( 'این محصولات وارد نمی‌شوند، چون احتمالاً در سایت هستند و ساختنشان تکراری می‌سازد. در «اتصال محصولات غرفه» تأییدشان کن؛ بعد از اتصال، ایمپورت آن‌ها را به‌روز می‌کند.', 'basalamhub' ); ?></p>
		<?php endif; ?>

		<fieldset class="bsh-fieldset">
			<legend class="bsh-field__label"><?php esc_html_e( 'تنظیمات ایمپورت', 'basalamhub' ); ?></legend>
			<label class="bsh-checkbox">
				<input type="checkbox" data-bsh-import-update value="1" checked>
				<?php esc_html_e( 'موجودی (و قیمت) محصولات متصل هم از باسلام به‌روز شود', 'basalamhub' ); ?>
			</label>
			<label class="bsh-field bsh-field--inline">
				<span class="bsh-field__label"><?php esc_html_e( 'وضعیت محصولات جدید در سایت', 'basalamhub' ); ?></span>
				<select class="bsh-field__select" data-bsh-import-publish>
					<option value="publish"><?php esc_html_e( 'منتشرشده', 'basalamhub' ); ?></option>
					<option value="draft"><?php esc_html_e( 'پیش‌نویس (اول خودم بررسی می‌کنم)', 'basalamhub' ); ?></option>
				</select>
			</label>
		</fieldset>
		<?php if ( $bsh_rules ) : ?>
			<p class="bsh-alert bsh-alert--warning"><?php esc_html_e( 'قانون قیمت فعال است. قیمت محصولات جدید همان قیمت باسلام وارد می‌شود و قیمت محصولات متصل دست نمی‌خورد؛ وگرنه قانون دو بار اعمال می‌شد. بعد از ایمپورت قیمت‌های سایت را بررسی کن.', 'basalamhub' ); ?></p>
		<?php endif; ?>
		<ul class="bsh-list">
			<li><?php esc_html_e( 'عکس‌ها در کتابخانه‌ی رسانه ذخیره می‌شوند و بعداً دوباره در باسلام آپلود نمی‌شوند.', 'basalamhub' ); ?></li>
			<li><?php esc_html_e( 'دسته‌ی باسلام به دسته‌ی نگاشت‌شده می‌رود؛ اگر نگاشتی نیست، دسته‌ای با همان نام ساخته و نگاشت می‌شود.', 'basalamhub' ); ?></li>
			<li><?php esc_html_e( 'موجودی سایت = موجودی باسلام + موجودی اطمینان. هر محصول بلافاصله به محصول باسلام وصل می‌شود.', 'basalamhub' ); ?></li>
			<li><?php esc_html_e( 'محصولی که SKU آن در سایت هست، ساخته نمی‌شود و دلیلش در لاگ می‌آید.', 'basalamhub' ); ?></li>
		</ul>
		<div class="bsh-card__foot">
			<button type="button" class="bsh-btn bsh-btn--primary" data-bsh-import-start data-new="<?php echo esc_attr( $bsh_preview['new'] ); ?>" data-linked="<?php echo esc_attr( $bsh_preview['linked'] ); ?>" <?php disabled( 0 === $bsh_preview['new'] + $bsh_preview['linked'] ); ?>>
				<?php esc_html_e( 'شروع ایمپورت', 'basalamhub' ); ?>
			</button>
		</div>
		<p class="bsh-field__hint" data-bsh-import-message aria-live="polite"></p>
	</section>
<?php endif; ?>
