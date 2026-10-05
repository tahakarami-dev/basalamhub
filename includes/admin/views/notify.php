<?php
/**
 * «اعلان‌ها»: Telegram and Bale bots for new orders, important errors and nightly
 * reconciliation results.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

$bsh_errors = get_transient( 'bsh_notify_errors_' . get_current_user_id() );
delete_transient( 'bsh_notify_errors_' . get_current_user_id() );
$bsh_errors = is_array( $bsh_errors ) ? $bsh_errors : array();
$bsh_s      = BSH_Notifier::settings();
$bsh_err    = function ( $key, $hint ) use ( $bsh_errors ) {
	$is = isset( $bsh_errors[ $key ] );
	echo '<span class="bsh-field__hint' . ( $is ? ' is-error' : '' ) . '">' . esc_html( $is ? $bsh_errors[ $key ] : $hint ) . '</span>';
};
$bsh_help   = array(
	'bale'     => __( 'در بله به @botfather پیام بده، «/newbot» را بزن و توکن ربات را کپی کن. بعد ربات خودت را باز کن و «/start» بفرست.', 'basalamhub' ),
	'telegram' => __( 'در تلگرام به @BotFather پیام بده، «/newbot» را بزن و توکن را کپی کن. توجه: بیشتر هاست‌های ایران به تلگرام دسترسی ندارند؛ اگر پیام آزمایشی نرسید، از بله استفاده کن.', 'basalamhub' ),
);
?>
<header class="bsh-page-head">
	<div>
		<h1 class="bsh-page-title"><?php esc_html_e( 'اعلان‌ها', 'basalamhub' ); ?></h1>
		<p class="bsh-card__meta"><?php esc_html_e( 'سفارش جدید باسلام، خطاهای مهم و نتیجه‌ی تطبیق شبانه را در بله یا تلگرام بگیر. پیام‌ها از صف پس‌زمینه فرستاده می‌شوند و سایت را کند نمی‌کنند.', 'basalamhub' ); ?></p>
	</div>
</header>

<?php BSH_Admin::print_notice(); ?>
<p class="bsh-field__hint" data-bsh-message aria-live="polite"></p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'bsh_save_notify' ); ?>
	<input type="hidden" name="action" value="bsh_save_notify">

	<div class="bsh-channels bsh-section">
		<?php foreach ( BSH_Notifier::channels() as $bsh_ch => $bsh_meta ) : ?>
			<?php $bsh_c = $bsh_s[ $bsh_ch ]; ?>
			<section class="bsh-card" data-bsh-channel="<?php echo esc_attr( $bsh_ch ); ?>">
				<div class="bsh-card__head">
					<h2 class="bsh-card__title bsh-channel__title"><?php echo esc_html( $bsh_meta['label'] ); ?></h2>
					<?php
					if ( in_array( $bsh_ch, BSH_Notifier::active_channels(), true ) ) {
						echo '<span class="bsh-badge bsh-badge--synced">' . esc_html__( 'فعال', 'basalamhub' ) . '</span>';
					} else {
						echo '<span class="bsh-badge bsh-badge--stale">' . esc_html__( 'خاموش', 'basalamhub' ) . '</span>';
					}
					?>
				</div>
				<p class="bsh-card__meta"><?php echo esc_html( $bsh_help[ $bsh_ch ] ); ?></p>
				<label class="bsh-checkbox">
					<input type="checkbox" name="<?php echo esc_attr( $bsh_ch ); ?>[enabled]" value="1" <?php checked( (int) $bsh_c['enabled'], 1 ); ?>>
					<?php
					/* translators: %s: messenger */
					echo esc_html( sprintf( __( 'اعلان در %s روشن باشد', 'basalamhub' ), $bsh_meta['label'] ) );
					?>
				</label>
				<label class="bsh-field<?php echo isset( $bsh_errors[ $bsh_ch . '_token' ] ) ? ' bsh-field--error' : ''; ?>">
					<span class="bsh-field__label"><?php esc_html_e( 'توکن ربات', 'basalamhub' ); ?></span>
					<input class="bsh-field__input bsh-field__ltr" type="password" name="<?php echo esc_attr( $bsh_ch ); ?>[token]" autocomplete="off" spellcheck="false"
						placeholder="<?php echo esc_attr( $bsh_c['token'] ? BSH_Crypto::mask( BSH_Notifier::token( $bsh_ch ) ) : '123456789:AAH…' ); ?>">
					<?php $bsh_err( $bsh_ch . '_token', $bsh_c['token'] ? __( 'ذخیره شده و رمزنگاری‌شده. برای عوض کردن، توکن جدید را وارد کن.', 'basalamhub' ) : __( 'رمزنگاری‌شده ذخیره می‌شود.', 'basalamhub' ) ); ?>
				</label>
				<label class="bsh-field<?php echo isset( $bsh_errors[ $bsh_ch . '_chat_id' ] ) ? ' bsh-field--error' : ''; ?>">
					<span class="bsh-field__label"><?php esc_html_e( 'شناسه‌ی گفتگو', 'basalamhub' ); ?></span>
					<input class="bsh-field__input bsh-field__ltr" type="text" name="<?php echo esc_attr( $bsh_ch ); ?>[chat_id]" value="<?php echo esc_attr( $bsh_c['chat_id'] ); ?>" data-bsh-chat-id>
					<?php
					$bsh_err(
						$bsh_ch . '_chat_id',
						$bsh_c['chat_title']
							/* translators: %s: chat name */
							? sprintf( __( 'گفتگو: %s', 'basalamhub' ), $bsh_c['chat_title'] )
							: __( 'بعد از ذخیره‌ی توکن، به ربات پیام بده و «پیدا کردن شناسه» را بزن.', 'basalamhub' )
					);
					?>
				</label>
				<?php if ( 'telegram' === $bsh_ch ) : ?>
					<label class="bsh-field<?php echo isset( $bsh_errors['telegram_api_base'] ) ? ' bsh-field--error' : ''; ?>">
						<span class="bsh-field__label"><?php esc_html_e( 'آدرس واسط API (اختیاری)', 'basalamhub' ); ?></span>
						<input class="bsh-field__input bsh-field__ltr" type="url" name="telegram[api_base]" value="<?php echo esc_attr( $bsh_c['api_base'] ); ?>" placeholder="https://api.telegram.org">
						<?php $bsh_err( 'telegram_api_base', __( 'فقط اگر هاستت در ایران است و یک واسط (relay) امن برای Bot API تلگرام داری. خالی = آدرس اصلی تلگرام.', 'basalamhub' ) ); ?>
					</label>
				<?php endif; ?>
				<div class="bsh-inline-actions">
					<button type="button" class="bsh-btn" data-bsh-notify-find <?php disabled( ! $bsh_c['token'] ); ?>><?php esc_html_e( 'پیدا کردن شناسه', 'basalamhub' ); ?></button>
					<button type="button" class="bsh-btn" data-bsh-notify-test <?php disabled( ! $bsh_c['token'] || '' === (string) $bsh_c['chat_id'] ); ?>><?php esc_html_e( 'پیام آزمایشی', 'basalamhub' ); ?></button>
				</div>
				<p class="bsh-field__hint" data-bsh-channel-message aria-live="polite"></p>
			</section>
		<?php endforeach; ?>
	</div>

	<section class="bsh-card bsh-section">
		<div class="bsh-card__head"><h2 class="bsh-card__title"><?php esc_html_e( 'برای چه چیزهایی پیام بیاید؟', 'basalamhub' ); ?></h2></div>
		<label class="bsh-checkbox">
			<input type="checkbox" name="events[new_order]" value="1" <?php checked( (int) $bsh_s['events']['new_order'], 1 ); ?>>
			<?php esc_html_e( 'سفارش جدید باسلام (اقلام، مبلغ، گیرنده و لینک سفارش)', 'basalamhub' ); ?>
		</label>
		<label class="bsh-checkbox">
			<input type="checkbox" name="events[errors]" value="1" <?php checked( (int) $bsh_s['events']['errors'], 1 ); ?>>
			<?php esc_html_e( 'خطاهای مهم (هر خطا حداکثر هر ۶ ساعت یک بار؛ حداکثر ۱۰ پیام خطا در ساعت)', 'basalamhub' ); ?>
		</label>
		<label class="bsh-checkbox">
			<input type="checkbox" name="events[reconcile]" value="1" <?php checked( (int) $bsh_s['events']['reconcile'], 1 ); ?>>
			<?php esc_html_e( 'تطبیق شبانه سفارش جاافتاده‌ای پیدا کرد', 'basalamhub' ); ?>
		</label>
		<label class="bsh-checkbox">
			<input type="checkbox" name="events[low_stock]" value="1" <?php checked( (int) $bsh_s['events']['low_stock'], 1 ); ?>>
			<?php esc_html_e( 'موجودی کالایی که در باسلام است کم شد یا تمام شد', 'basalamhub' ); ?>
		</label>
		<label class="bsh-checkbox">
			<input type="checkbox" name="events[weekly]" value="1" <?php checked( (int) $bsh_s['events']['weekly'], 1 ); ?>>
			<?php esc_html_e( 'گزارش هفتگی (شنبه‌ها ساعت ۹ صبح): فروش دو کانال، سفارش‌های جاافتاده، خطاها و کالاهای رو به اتمام', 'basalamhub' ); ?>
		</label>
		<div class="bsh-card__foot">
			<button type="submit" class="bsh-btn bsh-btn--primary"><?php esc_html_e( 'ذخیره', 'basalamhub' ); ?></button>
		</div>
	</section>
</form>

<?php $bsh_report = get_option( 'bsh_report_last' ); ?>
<section class="bsh-card bsh-section">
	<div class="bsh-card__head">
		<div>
			<h2 class="bsh-card__title"><?php esc_html_e( 'گزارش هفتگی', 'basalamhub' ); ?></h2>
			<p class="bsh-card__meta">
				<?php
				echo esc_html(
					is_array( $bsh_report )
						/* translators: %s: relative time */
						? sprintf( __( 'آخرین گزارش: %s', 'basalamhub' ), bsh_time_ago( $bsh_report['at'] ) )
						: __( 'هر شنبه ساعت ۹ صبح خودکار ساخته و فرستاده می‌شود.', 'basalamhub' )
				);
				?>
			</p>
		</div>
		<button type="button" class="bsh-btn" data-bsh-report-now><?php esc_html_e( 'ساخت و ارسال الان', 'basalamhub' ); ?></button>
	</div>
	<pre class="bsh-report" data-bsh-report-text<?php echo is_array( $bsh_report ) ? '' : ' hidden'; ?>><?php echo is_array( $bsh_report ) ? esc_html( $bsh_report['text'] ) : ''; ?></pre>
	<p class="bsh-field__hint" data-bsh-report-message aria-live="polite"></p>
</section>
