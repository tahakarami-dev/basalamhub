<?php
/**
 * Log table partial. Expects $slh_rows (log rows).
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

$slh_level_badge = array(
	'success' => 'synced',
	'warning' => 'queued',
	'error'   => 'error',
);
?>
<div class="slh-table-wrap">
<table class="slh-table">
	<thead>
		<tr>
			<th scope="col"><?php esc_html_e( 'زمان', 'salamhub' ); ?></th>
			<th scope="col"><?php esc_html_e( 'مورد', 'salamhub' ); ?></th>
			<th scope="col"><?php esc_html_e( 'وضعیت', 'salamhub' ); ?></th>
			<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'اقدام', 'salamhub' ); ?></span></th>
		</tr>
	</thead>
	<tbody>
	<?php foreach ( $slh_rows as $slh_row ) : ?>
		<?php
		$slh_title = $slh_row->title;
		$slh_edit  = 'product' === $slh_row->object_type && $slh_row->object_id ? get_edit_post_link( (int) $slh_row->object_id ) : '';
		?>
		<tr id="slh-log-<?php echo esc_attr( $slh_row->id ); ?>" class="<?php echo $slh_row->resolved ? 'slh-row--resolved' : ''; ?>">
			<td class="slh-table__time"><?php echo esc_html( slh_format_time( $slh_row->created_at ) ); ?></td>
			<td>
				<?php if ( $slh_edit ) : ?>
					<a href="<?php echo esc_url( $slh_edit ); ?>"><?php echo esc_html( $slh_title ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $slh_title ); ?>
				<?php endif; ?>
				<span class="slh-table__why">
					<?php echo esc_html( $slh_row->message ); ?>
					<?php if ( $slh_row->reason ) : ?>
						<?php echo esc_html( $slh_row->reason ); ?>
					<?php endif; ?>
				</span>
				<?php if ( $slh_row->suggestion && 'success' !== $slh_row->level ) : ?>
					<span class="slh-table__todo"><?php echo esc_html( $slh_row->suggestion ); ?></span>
				<?php endif; ?>
				<?php if ( $slh_row->context ) : ?>
					<details class="slh-details">
						<summary><?php esc_html_e( 'جزئیات فنی', 'salamhub' ); ?></summary>
						<pre dir="ltr"><?php echo esc_html( $slh_row->context ); ?></pre>
					</details>
				<?php endif; ?>
			</td>
			<td>
				<?php
				if ( isset( $slh_level_badge[ $slh_row->level ] ) ) {
					echo slh_badge( $slh_level_badge[ $slh_row->level ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				} else {
					echo '<span class="slh-muted">' . esc_html__( 'اطلاع', 'salamhub' ) . '</span>';
				}
				if ( $slh_row->resolved && 'error' === $slh_row->level ) {
					echo '<span class="slh-table__resolved">' . esc_html__( 'برطرف شد', 'salamhub' ) . '</span>';
				}
				?>
			</td>
			<td>
				<?php if ( 'error' === $slh_row->level && ! $slh_row->resolved && $slh_row->retry_hook ) : ?>
					<button type="button" class="slh-btn slh-btn--ghost" data-slh-retry="<?php echo esc_attr( $slh_row->id ); ?>"><?php esc_html_e( 'تلاش مجدد', 'salamhub' ); ?></button>
				<?php endif; ?>
			</td>
		</tr>
	<?php endforeach; ?>
	</tbody>
</table>
</div>
