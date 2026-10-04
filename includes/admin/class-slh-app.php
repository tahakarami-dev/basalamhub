<?php
/**
 * The SalamHub app shell: a full-screen, SaaS-style workspace (own sidebar, top bar,
 * light/dark theme, page switching without reload) that wraps every SalamHub page.
 *
 * Pages stay server-rendered PHP views, so they work without JavaScript and on weak
 * hosts; JS only swaps the content area for a faster feel.
 *
 * @package SalamHub
 */

defined( 'ABSPATH' ) || exit;

class SLH_App {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'wp_ajax_slh_products_send', array( __CLASS__, 'ajax_products_send' ) );
	}

	/**
	 * Whether the current admin screen is a SalamHub app page.
	 *
	 * @return bool
	 */
	public static function is_app_screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( self::pages()[ $page ] );
	}

	/**
	 * @param string $classes Body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		return self::is_app_screen() ? $classes . ' slh-app-screen' : $classes;
	}

	/**
	 * Navigation: slug => [label, dashicon, group].
	 *
	 * @return array<string,array>
	 */
	public static function pages() {
		return array(
			'salamhub'            => array( __( 'داشبورد', 'salamhub' ), 'dashicons-chart-area', 'store' ),
			'salamhub-products'   => array( __( 'محصولات', 'salamhub' ), 'dashicons-products', 'store' ),
			'salamhub-orders'     => array( __( 'سفارش‌ها', 'salamhub' ), 'dashicons-cart', 'store' ),
			'salamhub-bulk'       => array( __( 'ارسال گروهی', 'salamhub' ), 'dashicons-upload', 'sync' ),
			'salamhub-link'       => array( __( 'اتصال محصولات غرفه', 'salamhub' ), 'dashicons-admin-links', 'sync' ),
			'salamhub-import'     => array( __( 'ایمپورت غرفه', 'salamhub' ), 'dashicons-download', 'sync' ),
			'salamhub-categories' => array( __( 'نگاشت دسته‌ها', 'salamhub' ), 'dashicons-category', 'sync' ),
			'salamhub-pricing'    => array( __( 'قوانین قیمت', 'salamhub' ), 'dashicons-tag', 'sync' ),
			'salamhub-logs'       => array( __( 'لاگ', 'salamhub' ), 'dashicons-list-view', 'system' ),
			'salamhub-notify'     => array( __( 'اعلان‌ها', 'salamhub' ), 'dashicons-bell', 'system' ),
			'salamhub-settings'   => array( __( 'تنظیمات', 'salamhub' ), 'dashicons-admin-generic', 'system' ),
		);
	}

	/**
	 * Renders a view inside the app shell.
	 *
	 * @param string $view View file name.
	 * @param string $slug Page slug (for the active menu item).
	 */
	public static function render( $view, $slug ) {
		if ( ! current_user_can( SLH_Admin::CAP ) ) {
			wp_die( esc_html__( 'دسترسی کافی نداری.', 'salamhub' ) );
		}
		$pages     = self::pages();
		$title     = isset( $pages[ $slug ] ) ? $pages[ $slug ][0] : __( 'سلام‌هاب', 'salamhub' );
		$connected = SLH_Settings::is_connected();
		$conn      = SLH_Settings::connection();
		$user      = wp_get_current_user();
		$search    = isset( $_GET['s'] ) && 'salamhub-products' === $slug ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="slh-app slh-root" data-slh-app data-theme="auto" dir="rtl">
			<script>
				try { var t = localStorage.getItem( 'slh-theme' ); if ( t ) { document.currentScript.parentNode.setAttribute( 'data-theme', t ); } } catch ( e ) {}
			</script>
			<aside class="slh-app__sidebar" data-slh-sidebar aria-label="<?php esc_attr_e( 'منوی سلام‌هاب', 'salamhub' ); ?>">
				<a class="slh-app__brand" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub' ) ); ?>" data-slh-nav>
					<span class="slh-app__logo" aria-hidden="true"><span class="dashicons dashicons-update"></span></span>
					<span class="slh-app__brand-name"><?php esc_html_e( 'سلام‌هاب', 'salamhub' ); ?></span>
				</a>
				<nav class="slh-app__nav" data-slh-sidebar-nav>
					<?php self::nav( $slug ); ?>
				</nav>
				<div class="slh-app__sidebar-foot">
					<div class="slh-app__booth">
						<span class="slh-app__dot slh-app__dot--<?php echo $connected ? 'ok' : 'bad'; ?>" aria-hidden="true"></span>
						<span>
							<strong><?php echo esc_html( $connected ? $conn['vendor_title'] : __( 'وصل نیست', 'salamhub' ) ); ?></strong>
							<small><?php echo esc_html( $connected ? __( 'غرفه‌ی متصل باسلام', 'salamhub' ) : __( 'از تنظیمات وصل شو', 'salamhub' ) ); ?></small>
						</span>
					</div>
					<a class="slh-app__exit" href="<?php echo esc_url( admin_url() ); ?>">
						<span class="dashicons dashicons-wordpress" aria-hidden="true"></span>
						<?php esc_html_e( 'بازگشت به پیشخوان وردپرس', 'salamhub' ); ?>
					</a>
				</div>
			</aside>
			<div class="slh-app__scrim" data-slh-scrim hidden></div>

			<div class="slh-app__body">
				<header class="slh-app__topbar">
					<button type="button" class="slh-app__icon-btn slh-app__menu-btn" data-slh-menu aria-label="<?php esc_attr_e( 'باز کردن منو', 'salamhub' ); ?>">
						<span class="dashicons dashicons-menu-alt3" aria-hidden="true"></span>
					</button>
					<div class="slh-app__crumbs">
						<span><?php esc_html_e( 'سلام‌هاب', 'salamhub' ); ?></span>
						<span aria-hidden="true">/</span>
						<strong data-slh-crumb><?php echo esc_html( $title ); ?></strong>
					</div>
					<form class="slh-app__search" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" role="search" data-slh-nav-form>
						<input type="hidden" name="page" value="salamhub-products">
						<span class="dashicons dashicons-search" aria-hidden="true"></span>
						<input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'جستجوی محصول با نام یا SKU', 'salamhub' ); ?>" aria-label="<?php esc_attr_e( 'جستجوی محصول', 'salamhub' ); ?>">
					</form>
					<div class="slh-app__actions">
						<a class="slh-app__pill slh-app__pill--<?php echo $connected ? 'ok' : 'bad'; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=salamhub-settings' ) ); ?>" data-slh-nav>
							<span class="slh-app__dot slh-app__dot--<?php echo $connected ? 'ok' : 'bad'; ?>" aria-hidden="true"></span>
							<?php echo $connected ? esc_html__( 'متصل به باسلام', 'salamhub' ) : esc_html__( 'وصل نیست', 'salamhub' ); ?>
						</a>
						<button type="button" class="slh-app__icon-btn" data-slh-theme aria-label="<?php esc_attr_e( 'تغییر پوسته: خودکار، روشن، تیره', 'salamhub' ); ?>" title="<?php esc_attr_e( 'پوسته', 'salamhub' ); ?>">
							<span class="dashicons dashicons-admin-appearance" aria-hidden="true"></span>
						</button>
						<a class="slh-app__user" href="<?php echo esc_url( get_edit_profile_url() ); ?>" title="<?php esc_attr_e( 'نمایه', 'salamhub' ); ?>">
							<span class="slh-app__avatar" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( trim( $user->display_name ) ? trim( $user->display_name ) : $user->user_login, 0, 1 ) ) ); ?></span>
							<span><?php echo esc_html( $user->display_name ); ?></span>
						</a>
					</div>
				</header>
				<div class="slh-app__progress" data-slh-loading hidden></div>
				<main class="slh-app__main" data-slh-main tabindex="-1">
					<div class="slh-app__content" data-slh-content data-page="<?php echo esc_attr( $slug ); ?>" data-title="<?php echo esc_attr( $title ); ?>">
						<?php include SLH_DIR . 'includes/admin/views/' . $view . '.php'; ?>
					</div>
				</main>
			</div>
		</div>
		<?php
	}

	/**
	 * Sidebar navigation (also re-rendered after each page swap, so badges stay fresh).
	 *
	 * @param string $active Active slug.
	 */
	public static function nav( $active ) {
		$groups = array(
			'store'  => __( 'فروشگاه', 'salamhub' ),
			'sync'   => __( 'همگام‌سازی', 'salamhub' ),
			'system' => __( 'سیستم', 'salamhub' ),
		);
		$errors = SLH_Logger::count_open_errors( 24 * 7 );
		$batch  = SLH_Bulk::current();
		foreach ( $groups as $group => $label ) {
			echo '<div class="slh-app__nav-group"><span class="slh-app__nav-label">' . esc_html( $label ) . '</span>';
			foreach ( self::pages() as $slug => $page ) {
				if ( $page[2] !== $group ) {
					continue;
				}
				$badge = '';
				if ( 'salamhub-orders' === $slug && SLH_Order_Sync::missing_count() ) {
					$badge = '<span class="slh-app__badge slh-app__badge--alert">' . esc_html( slh_fa_digits( SLH_Order_Sync::missing_count() ) ) . '</span>';
				} elseif ( 'salamhub-logs' === $slug && $errors ) {
					$badge = '<span class="slh-app__badge slh-app__badge--alert">' . esc_html( slh_fa_digits( $errors ) ) . '</span>';
				} elseif ( 'salamhub-import' === $slug && SLH_Importer::is_running() ) {
					$badge = '<span class="slh-app__badge">' . esc_html( slh_fa_digits( SLH_Importer::progress()['percent'] ) ) . '٪</span>';
				} elseif ( 'salamhub-link' === $slug && SLH_Linker::is_running() ) {
					$badge = '<span class="slh-app__badge">…</span>';
				} elseif ( 'salamhub-bulk' === $slug && $batch && 'running' === $batch['status'] ) {
					$badge = '<span class="slh-app__badge">' . esc_html( slh_fa_digits( SLH_Bulk::progress( $batch )['percent'] ) ) . '٪</span>';
				}
				printf(
					'<a class="slh-app__nav-item%1$s" href="%2$s" data-slh-nav %3$s><span class="dashicons %4$s" aria-hidden="true"></span><span>%5$s</span>%6$s</a>',
					$slug === $active ? ' is-active' : '',
					esc_url( admin_url( 'admin.php?page=' . $slug ) ),
					$slug === $active ? 'aria-current="page"' : '',
					esc_attr( $page[1] ),
					esc_html( $page[0] ),
					$badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
				);
			}
			echo '</div>';
		}
	}

	/* ---------------------------------------------------------------------
	 * Data for the dashboard
	 * ------------------------------------------------------------------ */

	/**
	 * Daily product sync outcomes for the last N days, in the site's timezone.
	 *
	 * @param int $days Days.
	 * @return array[] [ ['date' => Y-m-d, 'label' => Jalali, 'ok' => int, 'error' => int], … ] oldest first.
	 */
	public static function activity( $days = 14 ) {
		global $wpdb;
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'now', $tz );
		$out   = array();
		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$d = $today->modify( "-{$i} days" );
			list( $jy, $jm, $jd ) = slh_gregorian_to_jalali( (int) $d->format( 'Y' ), (int) $d->format( 'n' ), (int) $d->format( 'j' ) );
			$out[ $d->format( 'Y-m-d' ) ] = array(
				'date'  => $d->format( 'Y-m-d' ),
				'day'   => slh_fa_digits( $jd ),
				'label' => slh_fa_digits( sprintf( '%04d/%02d/%02d', $jy, $jm, $jd ) ),
				'ok'    => 0,
				'error' => 0,
			);
		}
		// Hourly buckets in UTC, then shifted into local days in PHP (portable SQL).
		$since = gmdate( 'Y-m-d H:i:s', time() - ( $days + 1 ) * DAY_IN_SECONDS );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT SUBSTR(created_at, 1, 13) AS h, event, COUNT(*) AS n FROM ' . SLH_Logger::table() . " WHERE object_type = 'product' AND event IN ('product_created','product_updated','product_failed') AND created_at >= %s GROUP BY SUBSTR(created_at, 1, 13), event", // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$since
			)
		);
		foreach ( (array) $rows as $r ) {
			$local = ( new DateTimeImmutable( $r->h . ':00:00', new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->format( 'Y-m-d' );
			if ( isset( $out[ $local ] ) ) {
				$out[ $local ][ 'product_failed' === $r->event ? 'error' : 'ok' ] += (int) $r->n;
			}
		}
		return array_values( $out );
	}

	/**
	 * Total products (published, simple + variable) for "linked of total".
	 *
	 * @return int
	 */
	public static function product_total() {
		$counts = wp_count_posts( 'product' );
		return (int) ( isset( $counts->publish ) ? $counts->publish : 0 );
	}

	/**
	 * Health checks shared by the dashboard: [status ok|warn|bad, title, detail, url].
	 *
	 * @return array[]
	 */
	public static function health_checks() {
		$conn      = SLH_Settings::connection();
		$queue     = SLH_Queue::stats();
		$token_bad = SLH_Settings::has_token() && null === SLH_Settings::get_token();
		$settings  = admin_url( 'admin.php?page=salamhub-settings' );
		$checks    = array();

		if ( ! SLH_Settings::has_token() ) {
			$checks[] = array( 'bad', __( 'توکن باسلام', 'salamhub' ), __( 'وارد نشده.', 'salamhub' ), $settings );
		} elseif ( $token_bad ) {
			$checks[] = array( 'bad', __( 'توکن باسلام', 'salamhub' ), __( 'قابل خواندن نیست؛ دوباره واردش کن.', 'salamhub' ), $settings );
		} elseif ( 'ok' !== $conn['status'] ) {
			$checks[] = array( 'bad', __( 'توکن باسلام', 'salamhub' ), $conn['message'] ? $conn['message'] : __( 'هنوز تست نشده.', 'salamhub' ), $settings );
		} else {
			/* translators: %s: relative time */
			$checks[] = array( 'ok', __( 'توکن باسلام', 'salamhub' ), sprintf( __( 'معتبر · بررسی %s', 'salamhub' ), slh_time_ago( $conn['checked_at'] ) ), '' );
		}

		if ( ! SLH_Queue::available() ) {
			$checks[] = array( 'bad', __( 'صف پس‌زمینه', 'salamhub' ), __( 'Action Scheduler در دسترس نیست.', 'salamhub' ), '' );
		} elseif ( $queue['past_due'] > 0 ) {
			/* translators: %s: count */
			$checks[] = array( 'warn', __( 'صف پس‌زمینه', 'salamhub' ), sprintf( __( '%s کار بیش از ۱۰ دقیقه منتظر مانده؛ احتمالاً WP-Cron خاموش است. از هاستینگ بخواه Cron Job برای wp-cron.php هر ۵ دقیقه بسازد.', 'salamhub' ), slh_fa_digits( $queue['past_due'] ) ), '' );
		} else {
			/* translators: 1: pending, 2: running */
			$checks[] = array( 'ok', __( 'صف پس‌زمینه', 'salamhub' ), sprintf( __( 'فعال · %1$s در انتظار، %2$s در حال اجرا', 'salamhub' ), slh_fa_digits( $queue['pending'] ), slh_fa_digits( $queue['running'] ) ), '' );
		}

		if ( (int) get_option( 'slh_pause_until', 0 ) > time() ) {
			/* translators: %s: seconds */
			$checks[] = array( 'warn', __( 'محدودیت درخواست', 'salamhub' ), sprintf( __( 'صف به درخواست باسلام %s ثانیه مکث کرده و خودکار ادامه می‌دهد.', 'salamhub' ), slh_fa_digits( (int) get_option( 'slh_pause_until' ) - time() ) ), '' );
		}

		if ( SLH_Order_Sync::enabled() ) {
			$orders_url = admin_url( 'admin.php?page=salamhub-orders' );
			$last       = SLH_Reconcile::last();
			if ( ! $last ) {
				$checks[] = array( 'warn', __( 'تطبیق شبانه‌ی سفارش‌ها', 'salamhub' ), __( 'هنوز اجرا نشده؛ هر شب حدود ساعت ۳ خودکار اجرا می‌شود.', 'salamhub' ), $orders_url );
			} elseif ( $last['error'] ) {
				$checks[] = array( 'bad', __( 'تطبیق شبانه‌ی سفارش‌ها', 'salamhub' ), $last['error'], $orders_url );
			} elseif ( strtotime( $last['at'] . ' UTC' ) < time() - 2 * DAY_IN_SECONDS ) {
				$checks[] = array( 'warn', __( 'تطبیق شبانه‌ی سفارش‌ها', 'salamhub' ), __( 'بیش از دو روز است اجرا نشده؛ احتمالاً WP-Cron خاموش است.', 'salamhub' ), $orders_url );
			} else {
				/* translators: 1: relative time, 2: checked, 3: missing */
				$checks[] = array( $last['missing'] ? 'warn' : 'ok', __( 'تطبیق شبانه‌ی سفارش‌ها', 'salamhub' ), sprintf( __( '%1$s · %2$s سفارش بررسی شد، %3$s جاافتاده', 'salamhub' ), slh_time_ago( $last['at'] ), slh_fa_number( $last['checked'] ), slh_fa_number( $last['missing'] ) ), $last['missing'] ? $orders_url : '' );
			}
		}

		$channels = SLH_Notifier::active_channels();
		$labels   = SLH_Notifier::channels();
		$checks[] = $channels
			/* translators: %s: messengers */
			? array( 'ok', __( 'اعلان‌ها', 'salamhub' ), sprintf( __( 'فعال در %s', 'salamhub' ), implode( '، ', array_map( function ( $c ) use ( $labels ) { return $labels[ $c ]['label']; }, $channels ) ) ), '' )
			: array( 'warn', __( 'اعلان‌ها', 'salamhub' ), __( 'خاموش؛ سفارش جدید و خطاها را در بله یا تلگرام بگیر.', 'salamhub' ), admin_url( 'admin.php?page=salamhub-notify' ) );

		$unmapped = count( SLH_Categories::unmapped_terms() );
		$cats_url = admin_url( 'admin.php?page=salamhub-categories' );
		if ( $unmapped ) {
			$checks[] = (int) SLH_Settings::get( 'default_category_id', 0 )
				/* translators: %s: count */
				? array( 'warn', __( 'نگاشت دسته‌ها', 'salamhub' ), sprintf( __( '%s دسته نگاشت نشده؛ با دسته‌ی پیش‌فرض ارسال می‌شوند.', 'salamhub' ), slh_fa_number( $unmapped ) ), $cats_url )
				/* translators: %s: count */
				: array( 'bad', __( 'نگاشت دسته‌ها', 'salamhub' ), sprintf( __( '%s دسته‌ی دارای محصول نگاشت نشده؛ محصولاتشان ارسال نمی‌شوند.', 'salamhub' ), slh_fa_number( $unmapped ) ), $cats_url );
		} else {
			$checks[] = array( 'ok', __( 'نگاشت دسته‌ها', 'salamhub' ), __( 'همه‌ی دسته‌های دارای محصول نگاشت شده‌اند.', 'salamhub' ), '' );
		}

		$multiplier = SLH_Product_Mapper::rial_multiplier();
		$checks[]   = null === $multiplier
			/* translators: %s: currency */
			? array( 'bad', __( 'واحد پول', 'salamhub' ), sprintf( __( 'واحد %s قابل تبدیل به ریال نیست.', 'salamhub' ), get_woocommerce_currency() ), $settings )
			: array( 'ok', __( 'واحد پول', 'salamhub' ), 1 === $multiplier ? __( 'ریال (بدون تبدیل)', 'salamhub' ) : sprintf( /* translators: %s: multiplier */ __( 'قیمت‌ها ×%s به ریال تبدیل می‌شوند', 'salamhub' ), slh_fa_number( $multiplier ) ), '' );

		$checks[] = SLH_Crypto::available()
			? array( 'ok', __( 'رمزنگاری توکن', 'salamhub' ), function_exists( 'sodium_crypto_secretbox' ) ? 'libsodium' : 'OpenSSL AES-256-GCM', '' )
			: array( 'bad', __( 'رمزنگاری توکن', 'salamhub' ), __( 'نه sodium هست نه openssl.', 'salamhub' ), '' );
		$checks[] = array( version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'bad', __( 'PHP و ووکامرس', 'salamhub' ), 'PHP ' . PHP_VERSION . ' · WooCommerce ' . ( defined( 'WC_VERSION' ) ? WC_VERSION : '?' ), '' );
		return $checks;
	}

	/* ---------------------------------------------------------------------
	 * Products page
	 * ------------------------------------------------------------------ */

	/**
	 * Status tabs for the products page.
	 *
	 * @return array<string,string>
	 */
	public static function product_tabs() {
		return array(
			''       => __( 'همه', 'salamhub' ),
			'synced' => __( 'همگام', 'salamhub' ),
			'queued' => __( 'در صف', 'salamhub' ),
			'stale'  => __( 'همگام نیست', 'salamhub' ),
			'error'  => __( 'خطا', 'salamhub' ),
			'unsent' => __( 'ارسال‌نشده', 'salamhub' ),
		);
	}

	/**
	 * Products with their Basalam link, filtered and paginated, plus tab counts.
	 *
	 * @param array $args status, search, page, per_page.
	 * @return array{items: object[], total: int, counts: array<string,int>}
	 */
	public static function products( array $args ) {
		global $wpdb;
		$links = SLH_Links::table();
		$base  = "FROM {$wpdb->posts} p LEFT JOIN {$links} l ON l.object_type = 'product' AND l.wc_id = p.ID WHERE p.post_type = 'product' AND p.post_status IN ('publish','draft','pending','private')";

		$where  = '';
		$params = array();
		if ( ! empty( $args['search'] ) ) {
			$like   = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where .= " AND (p.post_title LIKE %s OR p.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s))";
			$params = array( $like, $like );
		}

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$count_sql = "SELECT COALESCE(l.sync_status, 'none') AS s, (l.basalam_id IS NULL) AS unsent, COUNT(*) AS n {$base} {$where} GROUP BY COALESCE(l.sync_status, 'none'), (l.basalam_id IS NULL)";
		$rows      = $params ? $wpdb->get_results( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_results( $count_sql );
		$counts    = array_fill_keys( array_keys( self::product_tabs() ), 0 );
		foreach ( (array) $rows as $r ) {
			$counts[''] += (int) $r->n;
			if ( isset( $counts[ $r->s ] ) ) {
				$counts[ $r->s ] += (int) $r->n;
			}
			if ( (int) $r->unsent ) {
				$counts['unsent'] += (int) $r->n;
			}
		}

		$status = isset( $args['status'] ) ? $args['status'] : '';
		if ( 'unsent' === $status ) {
			$where .= ' AND l.basalam_id IS NULL';
		} elseif ( in_array( $status, array( 'synced', 'queued', 'stale', 'error' ), true ) ) {
			$where   .= ' AND l.sync_status = %s';
			$params[] = $status;
		}
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		$page     = max( 1, isset( $args['page'] ) ? (int) $args['page'] : 1 );
		$total    = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) {$base} {$where}", $params ) ) : $wpdb->get_var( "SELECT COUNT(*) {$base} {$where}" ) );
		$items    = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_status, l.sync_status, l.basalam_id, l.last_synced_at, l.last_error {$base} {$where} ORDER BY p.post_modified_gmt DESC, p.ID DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			)
		);
		// phpcs:enable
		return array( 'items' => $items ? $items : array(), 'total' => $total, 'counts' => $counts );
	}

	/**
	 * "Send selected" from the products page: one batch, so it shows up in bulk progress.
	 */
	public static function ajax_products_send() {
		if ( ! current_user_can( SLH_Admin::CAP ) || ! check_ajax_referer( 'slh_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'نشست کاری منقضی شده. صفحه را تازه کن و دوباره امتحان کن.', 'salamhub' ) ), 403 );
		}
		$ids    = isset( $_POST['ids'] ) ? array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['ids'] ) ) ) ) : array();
		$result = SLH_Bulk::start( array( 'scope' => 'ids', 'ids' => $ids ) );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				/* translators: %s: count */
				'message' => sprintf( __( '%s محصول در صف ارسال قرار گرفت. پیشرفت را در «ارسال گروهی» ببین.', 'salamhub' ), slh_fa_number( $result['total'] ) ),
			)
		);
	}
}
