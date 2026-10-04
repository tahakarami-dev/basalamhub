<?php
/**
 * Bulk send page.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

$bsh_batch     = BSH_Bulk::current();
$bsh_running   = $bsh_batch && 'running' === $bsh_batch['status'];
$bsh_counts    = BSH_Bulk::count_candidates();
$bsh_unmapped  = BSH_Categories::unmapped_terms();
$bsh_default   = (int) BSH_Settings::get( 'default_category_id', 0 );
$bsh_connected = BSH_Settings::is_connected();
?>
<header class="bsh-page-head">
	<h1 class="bsh-page-title"><?php esc_html_e( 'ارسال گروهی', 'basalamhub' ); ?></h1>
	<p class="bsh-card__meta"><?php esc_html_e( 'محصولات در صف پس‌زمینه یکی‌یکی ارسال می‌شوند؛ لازم نیست مرورگر باز بماند.', 'basalamhub' ); ?></p>
</header>

<?php BSH_Admin::print_notice(); ?>

<section class="bsh-card bsh-section" data-bsh-bulk-progress <?php echo $bsh_batch ? '' : 'hidden'; ?> data-running="<?php echo $bsh_running ? '1' : '0'; ?>">
	<div data-bsh-bulk-html>
		<?php BSH_Admin_Tools::progress_html( $bsh_batch, BSH_Bulk::progress( $bsh_batch ) ); ?>
	</div>
	<div class="bsh-card__foot" data-bsh-bulk-running-actions <?php echo $bsh_running ? '' : 'hidden'; ?>>
		<button type="button" class="bsh-btn bsh-btn--danger" data-bsh-bulk-cancel><?php esc_html_e( 'توقف ارسال گروهی', 'basalamhub' ); ?></button>
		<a class="bsh-btn bsh-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-logs' ) ); ?>"><?php esc_html_e( 'مشاهده‌ی لاگ', 'basalamhub' ); ?></a>
	</div>
	<p class="bsh-field__hint" data-bsh-bulk-message aria-live="polite"></p>
</section>

<?php if ( ! $bsh_connected ) : ?>
	<p class="bsh-alert bsh-alert--error">
		<?php esc_html_e( 'هنوز به باسلام وصل نشده‌ای.', 'basalamhub' ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-settings' ) ); ?>"><?php esc_html_e( 'اتصال در تنظیمات', 'basalamhub' ); ?></a>
	</p>
<?php endif; ?>

<?php if ( $bsh_unmapped ) : ?>
	<p class="bsh-alert bsh-alert--warning">
		<?php
		$bsh_names = implode(
			'، ',
			array_map(
				function ( $t ) {
					return '«' . $t->name . '»';
				},
				array_slice( $bsh_unmapped, 0, 5 )
			)
		) . ( count( $bsh_unmapped ) > 5 ? '، …' : '' );
		echo esc_html(
			$bsh_default
				/* translators: 1: count, 2: names */
				? sprintf( __( '%1$s دسته‌ی ووکامرس نگاشت نشده‌اند (%2$s). محصولاتشان با دسته‌ی پیش‌فرض تنظیمات ارسال می‌شوند.', 'basalamhub' ), bsh_fa_number( count( $bsh_unmapped ) ), $bsh_names )
				/* translators: 1: count, 2: names */
				: sprintf( __( '%1$s دسته‌ی ووکامرس نگاشت نشده‌اند (%2$s). محصولات این دسته‌ها ارسال نمی‌شوند و در لاگ با دلیل ثبت می‌شوند.', 'basalamhub' ), bsh_fa_number( count( $bsh_unmapped ) ), $bsh_names )
		);
		?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=basalamhub-categories' ) ); ?>"><?php esc_html_e( 'نگاشت دسته‌ها', 'basalamhub' ); ?></a>
	</p>
<?php endif; ?>

<section class="bsh-card bsh-section">
	<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'ارسال جدید', 'basalamhub' ); ?></h2></div>
	<form data-bsh-bulk-form>
		<fieldset class="bsh-fieldset">
			<legend class="bsh-field__label"><?php esc_html_e( 'کدام محصولات؟', 'basalamhub' ); ?></legend>
			<label class="bsh-checkbox">
				<input type="radio" name="scope" value="unsent" checked>
				<span>
					<?php esc_html_e( 'فقط محصولاتی که هنوز در باسلام نیستند', 'basalamhub' ); ?>
					(<strong data-bsh-count="unsent"><?php echo esc_html( bsh_fa_number( $bsh_counts['unsent'] ) ); ?></strong>)
				</span>
			</label>
			<label class="bsh-checkbox">
				<input type="radio" name="scope" value="all">
				<span>
					<?php esc_html_e( 'همه‌ی محصولات؛ محصولات متصل به‌روز می‌شوند', 'basalamhub' ); ?>
					(<strong data-bsh-count="all"><?php echo esc_html( bsh_fa_number( $bsh_counts['all'] ) ); ?></strong>)
				</span>
			</label>
		</fieldset>

		<label class="bsh-field">
			<span class="bsh-field__label"><?php esc_html_e( 'دسته‌ی ووکامرس', 'basalamhub' ); ?></span>
			<select class="bsh-field__select" name="term_id" data-bsh-bulk-term>
				<option value="0"><?php esc_html_e( 'همه‌ی دسته‌ها', 'basalamhub' ); ?></option>
				<?php foreach ( BSH_Admin_Tools::wc_category_tree() as $bsh_row ) : ?>
					<option value="<?php echo esc_attr( $bsh_row[0]->term_id ); ?>"><?php echo esc_html( str_repeat( '— ', $bsh_row[1] ) . $bsh_row[0]->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="bsh-field__hint"><?php esc_html_e( 'زیردسته‌ها هم شامل می‌شوند. محصولات ساده و متغیرِ منتشرشده ارسال می‌شوند.', 'basalamhub' ); ?></span>
		</label>

		<div class="bsh-card__foot">
			<button type="submit" class="bsh-btn bsh-btn--primary" data-bsh-bulk-start <?php disabled( ! $bsh_connected || $bsh_running ); ?>><?php esc_html_e( 'شروع ارسال', 'basalamhub' ); ?></button>
		</div>
		<p class="bsh-field__hint">
			<?php esc_html_e( 'از لیست محصولات ووکامرس هم می‌توانی چند محصول را تیک بزنی و از «کارهای دسته‌جمعی» گزینه‌ی «ارسال به باسلام» را انتخاب کنی.', 'basalamhub' ); ?>
		</p>
	</form>
</section>
