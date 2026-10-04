<?php
/**
 * Price rules page.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

$slh_user   = get_current_user_id();
$slh_failed = get_transient( 'slh_price_errors_' . $slh_user );
delete_transient( 'slh_price_errors_' . $slh_user );
$slh_errors = $slh_failed ? $slh_failed['errors'] : array();
$slh_rules  = SLH_Price_Rules::get();
$slh_tree   = SLH_Admin_Tools::wc_category_tree();

/**
 * Renders the three controls of one rule.
 */
$slh_rule_fields = function ( $name, array $rule, $error ) {
	?>
	<div class="slh-rule<?php echo $error ? ' slh-field--error' : ''; ?>">
		<select class="slh-field__select" name="<?php echo esc_attr( $name ); ?>[type]" aria-label="<?php esc_attr_e( 'نوع تغییر', 'salamhub' ); ?>">
			<option value="none" <?php selected( $rule['type'], 'none' ); ?>><?php esc_html_e( 'بدون تغییر', 'salamhub' ); ?></option>
			<option value="percent" <?php selected( $rule['type'], 'percent' ); ?>><?php esc_html_e( 'درصدی', 'salamhub' ); ?></option>
			<option value="fixed" <?php selected( $rule['type'], 'fixed' ); ?>><?php esc_html_e( 'مبلغ ثابت (تومان)', 'salamhub' ); ?></option>
		</select>
		<select class="slh-field__select" name="<?php echo esc_attr( $name ); ?>[direction]" aria-label="<?php esc_attr_e( 'جهت', 'salamhub' ); ?>">
			<option value="up" <?php selected( $rule['direction'], 'up' ); ?>><?php esc_html_e( 'افزایش', 'salamhub' ); ?></option>
			<option value="down" <?php selected( $rule['direction'], 'down' ); ?>><?php esc_html_e( 'کاهش', 'salamhub' ); ?></option>
		</select>
		<input class="slh-field__input" type="text" inputmode="decimal" name="<?php echo esc_attr( $name ); ?>[value]" value="<?php echo esc_attr( $rule['value'] ? $rule['value'] : '' ); ?>" aria-label="<?php esc_attr_e( 'مقدار', 'salamhub' ); ?>" placeholder="<?php esc_attr_e( 'مقدار', 'salamhub' ); ?>">
		<?php if ( $error ) : ?>
			<span class="slh-field__hint"><?php echo esc_html( $error ); ?></span>
		<?php endif; ?>
	</div>
	<?php
};

// Preview: the 8 most recent published simple products.
$slh_preview = wc_get_products( array( 'status' => 'publish', 'type' => 'simple', 'limit' => 8, 'orderby' => 'date', 'order' => 'DESC' ) );
$slh_mult    = SLH_Product_Mapper::rial_multiplier();
?>
<header class="slh-page-head">
	<h1 class="slh-page-title"><?php esc_html_e( 'قوانین قیمت', 'salamhub' ); ?></h1>
	<p class="slh-card__meta"><?php esc_html_e( 'قیمت محصول در باسلام می‌تواند با سایت فرق داشته باشد؛ مثلاً برای پوشش کارمزد باسلام. قیمت سایت دست نمی‌خورد.', 'salamhub' ); ?></p>
</header>

<?php SLH_Admin::print_notice(); ?>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<?php wp_nonce_field( 'slh_save_price_rules' ); ?>
	<input type="hidden" name="action" value="slh_save_price_rules">

	<section class="slh-card slh-section">
		<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'قانون سراسری', 'salamhub' ); ?></h2></div>
		<p class="slh-card__meta"><?php esc_html_e( 'روی همه‌ی محصولات اعمال می‌شود، مگر دسته‌ای که قانون جدا دارد.', 'salamhub' ); ?></p>
		<?php $slh_rule_fields( 'global', $slh_rules['global'], isset( $slh_errors['global'] ) ? $slh_errors['global'] : '' ); ?>
	</section>

	<section class="slh-card slh-section">
		<div class="slh-card__head"><h2 class="slh-card__title"><?php esc_html_e( 'گردکردن', 'salamhub' ); ?></h2></div>
		<div class="slh-rule">
			<select class="slh-field__select" name="rounding[unit]" aria-label="<?php esc_attr_e( 'واحد گردکردن', 'salamhub' ); ?>">
				<option value="0" <?php selected( (int) $slh_rules['rounding']['unit'], 0 ); ?>><?php esc_html_e( 'گرد نشود', 'salamhub' ); ?></option>
				<option value="100" <?php selected( (int) $slh_rules['rounding']['unit'], 100 ); ?>><?php esc_html_e( 'به ۱۰۰ تومان', 'salamhub' ); ?></option>
				<option value="1000" <?php selected( (int) $slh_rules['rounding']['unit'], 1000 ); ?>><?php esc_html_e( 'به ۱٬۰۰۰ تومان', 'salamhub' ); ?></option>
				<option value="10000" <?php selected( (int) $slh_rules['rounding']['unit'], 10000 ); ?>><?php esc_html_e( 'به ۱۰٬۰۰۰ تومان', 'salamhub' ); ?></option>
			</select>
			<select class="slh-field__select" name="rounding[mode]" aria-label="<?php esc_attr_e( 'جهت گردکردن', 'salamhub' ); ?>">
				<option value="up" <?php selected( $slh_rules['rounding']['mode'], 'up' ); ?>><?php esc_html_e( 'رو به بالا', 'salamhub' ); ?></option>
				<option value="nearest" <?php selected( $slh_rules['rounding']['mode'], 'nearest' ); ?>><?php esc_html_e( 'نزدیک‌ترین', 'salamhub' ); ?></option>
				<option value="down" <?php selected( $slh_rules['rounding']['mode'], 'down' ); ?>><?php esc_html_e( 'رو به پایین', 'salamhub' ); ?></option>
			</select>
		</div>
		<p class="slh-field__hint"><?php esc_html_e( 'بعد از اعمال قانون انجام می‌شود. قیمت صفر یا منفی در هر حالت ارسال نمی‌شود.', 'salamhub' ); ?></p>
	</section>

	<?php if ( $slh_tree ) : ?>
	<?php
	$slh_names = array();
	foreach ( $slh_tree as $slh_row ) {
		$slh_names[ (int) $slh_row[0]->term_id ] = $slh_row[0];
	}
	// Rows: categories that have a rule (or a rejected one to fix).
	$slh_rows = array_keys( $slh_rules['categories'] );
	foreach ( array_keys( $slh_errors ) as $slh_key ) {
		if ( 0 === strpos( $slh_key, 'cat_' ) ) {
			$slh_rows[] = (int) substr( $slh_key, 4 );
		}
	}
	$slh_rows = array_values( array_unique( array_filter( $slh_rows, function ( $id ) use ( $slh_names ) {
		return isset( $slh_names[ $id ] );
	} ) ) );
	?>
	<section class="slh-section">
		<div class="slh-section__head">
			<h2 class="slh-card__title"><?php esc_html_e( 'قانون جدا برای دسته‌ها', 'salamhub' ); ?></h2>
		</div>
		<p class="slh-card__meta"><?php esc_html_e( 'قانون دسته بر قانون سراسری اولویت دارد و زیردسته‌ها هم از آن پیروی می‌کنند.', 'salamhub' ); ?></p>
		<div class="slh-table-wrap" data-slh-rules-wrap <?php echo $slh_rows ? '' : 'hidden'; ?>>
		<table class="slh-table">
			<thead><tr><th scope="col"><?php esc_html_e( 'دسته', 'salamhub' ); ?></th><th scope="col"><?php esc_html_e( 'قانون', 'salamhub' ); ?></th><th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'حذف', 'salamhub' ); ?></span></th></tr></thead>
			<tbody data-slh-rules>
			<?php foreach ( $slh_rows as $slh_tid ) : ?>
				<tr data-term="<?php echo esc_attr( $slh_tid ); ?>">
					<td><?php echo esc_html( $slh_names[ $slh_tid ]->name ); ?></td>
					<td><?php $slh_rule_fields( 'categories[' . $slh_tid . ']', isset( $slh_rules['categories'][ $slh_tid ] ) ? $slh_rules['categories'][ $slh_tid ] : SLH_Price_Rules::empty_rule(), isset( $slh_errors[ 'cat_' . $slh_tid ] ) ? $slh_errors[ 'cat_' . $slh_tid ] : '' ); ?></td>
					<td><button type="button" class="slh-btn slh-btn--ghost" data-slh-rule-remove><?php esc_html_e( 'حذف', 'salamhub' ); ?></button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<div class="slh-rule slh-rule-add">
			<select class="slh-field__select" data-slh-rule-term aria-label="<?php esc_attr_e( 'دسته', 'salamhub' ); ?>">
				<option value=""><?php esc_html_e( 'یک دسته انتخاب کن…', 'salamhub' ); ?></option>
				<?php foreach ( $slh_tree as $slh_row ) : ?>
					<option value="<?php echo esc_attr( $slh_row[0]->term_id ); ?>" data-name="<?php echo esc_attr( $slh_row[0]->name ); ?>" <?php disabled( in_array( (int) $slh_row[0]->term_id, $slh_rows, true ) ); ?>><?php echo esc_html( str_repeat( '— ', $slh_row[1] ) . $slh_row[0]->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="slh-btn" data-slh-rule-add><?php esc_html_e( 'افزودن قانون برای این دسته', 'salamhub' ); ?></button>
		</div>
		<template data-slh-rule-template>
			<tr data-term="__TERM__">
				<td>__NAME__</td>
				<td><?php $slh_rule_fields( 'categories[__TERM__]', array( 'type' => 'percent', 'direction' => 'up', 'value' => 0 ), '' ); ?></td>
				<td><button type="button" class="slh-btn slh-btn--ghost" data-slh-rule-remove><?php esc_html_e( 'حذف', 'salamhub' ); ?></button></td>
			</tr>
		</template>
	</section>
	<?php endif; ?>

	<div class="slh-form-actions">
		<button type="submit" class="slh-btn slh-btn--primary"><?php esc_html_e( 'ذخیره‌ی قوانین', 'salamhub' ); ?></button>
	</div>
</form>

<section class="slh-section">
	<div class="slh-section__head"><h2 class="slh-card__title"><?php esc_html_e( 'پیش‌نمایش با قوانین ذخیره‌شده', 'salamhub' ); ?></h2></div>
	<?php if ( null === $slh_mult ) : ?>
		<p class="slh-alert slh-alert--error"><?php esc_html_e( 'واحد پول فروشگاه قابل تبدیل نیست؛ در تنظیمات واحد قیمت را انتخاب کن.', 'salamhub' ); ?></p>
	<?php elseif ( ! $slh_preview ) : ?>
		<div class="slh-card"><p class="slh-card__body"><?php esc_html_e( 'محصول ساده‌ی منتشرشده‌ای برای پیش‌نمایش نیست.', 'salamhub' ); ?></p></div>
	<?php else : ?>
		<div class="slh-table-wrap">
		<table class="slh-table">
			<thead><tr>
				<th scope="col"><?php esc_html_e( 'محصول', 'salamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'قیمت سایت', 'salamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'قیمت در باسلام', 'salamhub' ); ?></th>
				<th scope="col"><?php esc_html_e( 'قانون', 'salamhub' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $slh_preview as $slh_p ) : ?>
				<?php
				$slh_base = (int) round( (float) wc_get_price_excluding_tax( $slh_p, array( 'price' => $slh_p->get_price() ) ) * $slh_mult );
				$slh_for  = SLH_Price_Rules::rule_for( $slh_p );
				$slh_out  = $slh_base > 0 ? SLH_Price_Rules::apply( $slh_base, $slh_for['rule'], $slh_rules['rounding'] ) : 0;
				$slh_src  = 'category' === $slh_for['source'] ? get_term( $slh_for['term_id'], 'product_cat' ) : null;
				?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $slh_p->get_id() ) ); ?>"><?php echo esc_html( $slh_p->get_name() ); ?></a></td>
					<td><?php echo $slh_base > 0 ? esc_html( slh_fa_number( $slh_base / 10 ) . ' ' . __( 'تومان', 'salamhub' ) ) : '—'; ?></td>
					<td>
						<?php if ( $slh_out > 0 ) : ?>
							<strong><?php echo esc_html( slh_fa_number( $slh_out / 10 ) . ' ' . __( 'تومان', 'salamhub' ) ); ?></strong>
						<?php else : ?>
							<span class="slh-badge slh-badge--error"><?php esc_html_e( 'ارسال نمی‌شود', 'salamhub' ); ?></span>
						<?php endif; ?>
					</td>
					<td class="slh-table__why">
						<?php
						echo esc_html( SLH_Price_Rules::describe( $slh_for['rule'] ) );
						if ( $slh_src && ! is_wp_error( $slh_src ) ) {
							/* translators: %s: category */
							echo ' · ' . esc_html( sprintf( __( 'دسته‌ی «%s»', 'salamhub' ), $slh_src->name ) );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
	<?php endif; ?>
</section>
