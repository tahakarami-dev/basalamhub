<?php
/**
 * «اعلان‌ها»: Telegram and Bale bots for new orders, important errors and nightly
 * reconciliation results.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

$slh_errors = get_transient( 'slh_notify_errors_' . get_current_user_id() );
delete_transient( 'slh_notify_errors_' . get_current_user_id() );
$slh_errors = is_array( $slh_errors ) ? $slh_errors : array();
$slh_s      = SLH_Notifier::settings();
$slh_err    = function ( $key, $hint ) use ( $slh_errors ) {
	$is = isset( $slh_errors[ $key ] );
	echo '<span class="slh-field__hint' . ( $is ? ' is-error' : '' ) . '">' . esc_html( $is ? $slh_errors[ $key ] : $hint ) . '</span>';
};
$slh_help = array(
	'bale'     => __( 'در بله به @botfather پیام بده، «/newbot» را بزن و توکن ربات را کپی کن. بعد ربات خودت را باز کن و «/start» بفرست.', 'salamhub' ),
	'telegram' => __( 'در تلگرام به @BotFather پیام بده، «/newbot» را بزن و توکن را کپی کن. توجه: بیشتر هاست‌های ایران به تلگرام دسترسی ندارند؛ اگر پیام آزمایشی نرسید، از بله استفاده کن.', 'salamhub' ),
);
?>
<header class="slh-page-head">
	<div>
		<h1 class="slh-page-title"><?php esc_html_e( 'اعلان‌ها', 'salamhub' ); ?></h1>
		<p class="slh-card__meta"><?php esc_html_e( 'سفارش جدید باسلام، خطاهای مهم و نتیجه‌ی تطبیق شبانه را در بله یا تلگرام بگیر. پیام‌ها از صف پس‌زمینه فرستاده می‌شوند و سایت را کند نمی‌کنند.', 'salamhub' ); ?></p>
	</div>
</header>

<?php SLH_Admin::print_notice(); ?>
<p class="slh-field__hint" data-slh-message aria-live="polite"></p>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'slh_save_notify' ); ?>
	<input type="hidden" name="action" value="slh_save_notify">

	<div class="slh-channels slh-section">
		<?php foreach ( SLH_Notifier::channels() as $slh_ch => $slh_meta ) : ?>
			<?php $slh_c = $slh_s[ $slh_ch ]; ?>
			<section class="slh-card" data-slh-channel="<?php echo esc_attr( $slh_ch ); ?>">
				<div class="slh-card__head">
					<h2 class="slh-card__title slh-channel__title"><?php echo esc_html( $slh_meta['label'] ); ?></h2>
					<?php
					if ( in_array( $slh_ch, SLH_Notifier::active_channels(), true ) ) {
						echo '<span class="slh-badge slh-badge--synced">' . esc_html__( 'فعال', 'salamhub' ) . '</span>';
					} else {
						echo '<span class="slh-badge slh-badge--stale">' . esc_html__( 'خاموش', 'salamhub' ) . '</span>';
					}
					?>
				</div>
				<p class="slh-card__meta"><?php echo esc_html( $slh_help[ $slh_ch ] ); ?></p>
				<label class="slh-checkbox">
					<input type="checkbox" name="<?php echo esc_attr( $slh_ch ); ?>[enabled]" value="1" <?php checked( (int) $slh_c['enabled'], 1 ); ?>>
					<?php
					/* translators: %s: messenger */
					echo esc_html( sprintf( __( 'اعلان در %s روشن باشد', 'salamhub' ), $slh_meta['label'] ) );
					?>
				</label>
				<label class="slh-field<?php echo isset( $slh_errors[ $slh_ch . '_token' ] ) ? ' slh-field--error' : ''; ?>">
					<span class="slh-field__label"><?php esc_html_e( 'توکن ربات', 'salamhub' ); ?></span>
					<input class="slh-field__input slh-field__ltr" type="password" name="<?php echo esc_attr( $slh_ch ); ?>[token]" autocomplete="off" spellcheck="false"
						placeholder="<?php echo esc_attr( $slh_c['token'] ? SLH_Crypto::mask( SLH_Notifier::token( $slh_ch ) ) : '123456789:AAH…' ); ?>">
					<?php $slh_err( $slh_ch . '_token', $slh_c['token'] ? __( 'ذخیره شده و رمزنگاری‌شده. برای عوض کردن، توکن جدید را وارد کن.', 'salamhub' ) : __( 'رمزنگاری‌شده ذخیره می‌شود.', 'salamhub' ) ); ?>
				</label>
				<label class="slh-field<?php echo isset( $slh_errors[ $slh_ch . '_chat_id' ] ) ? ' slh-field--error' : ''; ?>">
					<span class="slh-field__label"><?php esc_html_e( 'شناسه‌ی گفتگو', 'salamhub' ); ?></span>
					<input class="slh-field__input slh-field__ltr" type="text" name="<?php echo esc_attr( $slh_ch ); ?>[chat_id]" value="<?php echo esc_attr( $slh_c['chat_id'] ); ?>" data-slh-chat-id>
					<?php
					$slh_err(
						$slh_ch . '_chat_id',
						$slh_c['chat_title']
							/* translators: %s: chat name */
							? sprintf( __( 'گفتگو: %s', 'salamhub' ), $slh_c['chat_title'] )
							: __( 'بعد از ذخیره‌ی توکن، به ربات پیام بده و «پیدا کردن شناسه» را بزن.', 'salamhub' )
					);
					?>
				</label>
				<?php if ( 'telegram' === $slh_ch ) : ?>
					<label class="slh-field<?php echo isset( $slh_errors['telegram_api_base'] ) ? ' slh-field--error' : ''; ?>">
						<span class="slh-field__label"><?php esc_html_e( 'آدرس واسط API (اختیاری)', 'salamhub' ); ?></span>
						<input class="slh-field__input slh-field__ltr" type="url" name="telegram[api_base]" value="<?php echo esc_attr( $slh_c['api_base'] ); ?>" placeholder="https://api.telegram.org">
						<?php $slh_err( 'telegram_api_base', __( 'فقط اگر هاستت در ایران است و یک واسط (relay) امن برای Bot API تلگرام داری. خالی = آدرس اصلی تلگرام.', 'salamhub' ) ); ?>
					</label>
				<?php endif; ?>
				<div class="slh-inline-actions">
					<button type="button" class="slh-btn" data-slh-notify-find <?php disabled( ! $slh_c['token'] ); ?>><?php esc_html_e( 'پیدا کردن شناسه', 'salamhub' ); ?></button>
					<button type="button" class="slh-btn" data-slh-notify-test <?php disabled( ! $slh_c['token'] || '' === (string) $slh_c['chat_id'] ); ?>><?php esc_html_e( 'پیام آزمایشی', 'salamhub' ); ?></button>
				</div>
				<p class="slh-field__hint" data-slh-channel-message aria-live="polite"></p>
			</section>
		<?php endforeach; ?>
	</div>

	<section class="slh-card slh-section">
		<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'برای چه چیزهایی پیام بیاید؟', 'salamhub' ); ?></h2></div>
		<label class="slh-checkbox">
			<input type="checkbox" name="events[new_order]" value="1" <?php checked( (int) $slh_s['events']['new_order'], 1 ); ?>>
			<?php esc_html_e( 'سفارش جدید باسلام (اقلام، مبلغ، گیرنده و لینک سفارش)', 'salamhub' ); ?>
		</label>
		<label class="slh-checkbox">
			<input type="checkbox" name="events[errors]" value="1" <?php checked( (int) $slh_s['events']['errors'], 1 ); ?>>
			<?php esc_html_e( 'خطاهای مهم (هر خطا حداکثر هر ۶ ساعت یک بار؛ حداکثر ۱۰ پیام خطا در ساعت)', 'salamhub' ); ?>
		</label>
		<label class="slh-checkbox">
			<input type="checkbox" name="events[reconcile]" value="1" <?php checked( (int) $slh_s['events']['reconcile'], 1 ); ?>>
			<?php esc_html_e( 'تطبیق شبانه سفارش جاافتاده‌ای پیدا کرد', 'salamhub' ); ?>
		</label>
		<div class="slh-card__foot">
			<button type="submit" class="slh-btn slh-btn--primary"><?php esc_html_e( 'ذخیره', 'salamhub' ); ?></button>
		</div>
	</section>
</form>
