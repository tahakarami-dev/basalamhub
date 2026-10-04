<?php
/**
 * Bulk send page.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

$slh_batch     = SLH_Bulk::current();
$slh_running   = $slh_batch && 'running' === $slh_batch['status'];
$slh_counts    = SLH_Bulk::count_candidates();
$slh_unmapped  = SLH_Categories::unmapped_terms();
$slh_default   = (int) SLH_Settings::get( 'default_category_id', 0 );
$slh_connected = SLH_Settings::is_connected();
?>
<header class="slh-page-head">
	<h1 class="slh-page-title"><?php esc_html_e( 'ارسال گروهی', 'salamhub' ); ?></h1>
	<p class="slh-card__meta"><?php esc_html_e( 'محصولات در صف پس‌زمینه یکی‌یکی ارسال می‌شوند؛ لازم نیست مرورگر باز بماند.', 'salamhub' ); ?></p>
</header>

<?php SLH_Admin::print_notice(); ?>

<section class="slh-card slh-section" data-slh-bulk-progress <?php echo $slh_batch ? '' : 'hidden'; ?> data-running="<?php echo $slh_running ? '1' : '0'; ?>">
	<div data-slh-bulk-html>
		<?php SLH_Admin_Tools::progress_html( $slh_batch, SLH_Bulk::progress( $slh_batch ) ); ?>
	</div>
	<div class="slh-card__foot" data-slh-bulk-running-actions <?php echo $slh_running ? '' : 'hidden'; ?>>
		<button type="button" class="slh-btn slh-btn--danger" data-slh-bulk-cancel><?php esc_html_e( 'توقف ارسال گروهی', 'salamhub' ); ?></button>
		<a class="slh-btn slh-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-logs' ) ); ?>"><?php esc_html_e( 'مشاهده‌ی لاگ', 'salamhub' ); ?></a>
	</div>
	<p class="slh-field__hint" data-slh-bulk-message aria-live="polite"></p>
</section>

<?php if ( ! $slh_connected ) : ?>
	<p class="slh-alert slh-alert--error">
		<?php esc_html_e( 'هنوز به باسلام وصل نشده‌ای.', 'salamhub' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-settings' ) ); ?>"><?php esc_html_e( 'اتصال در تنظیمات', 'salamhub' ); ?></a>
	</p>
<?php endif; ?>

<?php if ( $slh_unmapped ) : ?>
	<p class="slh-alert slh-alert--warning">
		<?php
		$slh_names = implode( '، ', array_map( function ( $t ) {
			return '«' . $t->name . '»';
		}, array_slice( $slh_unmapped, 0, 5 ) ) ) . ( count( $slh_unmapped ) > 5 ? '، …' : '' );
		echo esc_html(
			$slh_default
				/* translators: 1: count, 2: names */
				? sprintf( __( '%1$s دسته‌ی ووکامرس نگاشت نشده‌اند (%2$s). محصولاتشان با دسته‌ی پیش‌فرض تنظیمات ارسال می‌شوند.', 'salamhub' ), slh_fa_number( count( $slh_unmapped ) ), $slh_names )
				/* translators: 1: count, 2: names */
				: sprintf( __( '%1$s دسته‌ی ووکامرس نگاشت نشده‌اند (%2$s). محصولات این دسته‌ها ارسال نمی‌شوند و در لاگ با دلیل ثبت می‌شوند.', 'salamhub' ), slh_fa_number( count( $slh_unmapped ) ), $slh_names )
		);
		?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-categories' ) ); ?>"><?php esc_html_e( 'نگاشت دسته‌ها', 'salamhub' ); ?></a>
	</p>
<?php endif; ?>

<section class="slh-card slh-section">
	<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'ارسال جدید', 'salamhub' ); ?></h2></div>
	<form data-slh-bulk-form>
		<fieldset class="slh-fieldset">
			<legend class="slh-field__label"><?php esc_html_e( 'کدام محصولات؟', 'salamhub' ); ?></legend>
			<label class="slh-checkbox">
				<input type="radio" name="scope" value="unsent" checked>
				<span>
					<?php esc_html_e( 'فقط محصولاتی که هنوز در باسلام نیستند', 'salamhub' ); ?>
					(<strong data-slh-count="unsent"><?php echo esc_html( slh_fa_number( $slh_counts['unsent'] ) ); ?></strong>)
				</span>
			</label>
			<label class="slh-checkbox">
				<input type="radio" name="scope" value="all">
				<span>
					<?php esc_html_e( 'همه‌ی محصولات؛ محصولات متصل به‌روز می‌شوند', 'salamhub' ); ?>
					(<strong data-slh-count="all"><?php echo esc_html( slh_fa_number( $slh_counts['all'] ) ); ?></strong>)
				</span>
			</label>
		</fieldset>

		<label class="slh-field">
			<span class="slh-field__label"><?php esc_html_e( 'دسته‌ی ووکامرس', 'salamhub' ); ?></span>
			<select class="slh-field__select" name="term_id" data-slh-bulk-term>
				<option value="0"><?php esc_html_e( 'همه‌ی دسته‌ها', 'salamhub' ); ?></option>
				<?php foreach ( SLH_Admin_Tools::wc_category_tree() as $slh_row ) : ?>
					<option value="<?php echo esc_attr( $slh_row[0]->term_id ); ?>"><?php echo esc_html( str_repeat( '— ', $slh_row[1] ) . $slh_row[0]->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="slh-field__hint"><?php esc_html_e( 'زیردسته‌ها هم شامل می‌شوند. فقط محصولات ساده‌ی منتشرشده ارسال می‌شوند؛ محصولات متغیر در نسخه‌ی بعدی اضافه می‌شوند.', 'salamhub' ); ?></span>
		</label>

		<div class="slh-card__foot">
			<button type="submit" class="slh-btn slh-btn--primary" data-slh-bulk-start <?php disabled( ! $slh_connected || $slh_running ); ?>><?php esc_html_e( 'شروع ارسال', 'salamhub' ); ?></button>
		</div>
		<p class="slh-field__hint">
			<?php esc_html_e( 'از لیست محصولات ووکامرس هم می‌توانی چند محصول را تیک بزنی و از «کارهای دسته‌جمعی» گزینه‌ی «ارسال به باسلام» را انتخاب کنی.', 'salamhub' ); ?>
		</p>
	</form>
</section>
