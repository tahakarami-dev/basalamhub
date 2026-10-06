<?php
/**
 * RTL Theme (راست‌چین) license gate.
 *
 * This is the file to ENCODE on rtl-theme.com before release. BasalamHub only starts when
 * the license is active: everything the plugin does is wired up by BSH_Plugin::boot(),
 * and that hook is added only inside the «Product is Active» block below.
 *
 * Loaded from basalamhub.php only when the ionCube Loader is present (an encoded file
 * without the loader stops the whole site), see BSH_License_Guard there.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

// --------------------------------------------------------------------------------------------------- Start RTL License
$rtlLicenseClassName  = 'RTL_License_420efec66a1fed09';
$rtlLicenseFilePath   = __DIR__ . DIRECTORY_SEPARATOR . $rtlLicenseClassName . '.php';
$rtlLicenseFileHash   = @sha1_file($rtlLicenseFilePath);

if ( $rtlLicenseFileHash === '00bb7bc3407cc988636cdb5ed0828bf9ffce0d37' && file_exists($rtlLicenseFilePath) ) {
	require_once $rtlLicenseFilePath;

	if ( class_exists($rtlLicenseClassName) && method_exists($rtlLicenseClassName, 'isActive') ) {
		$rtlLicenseClass = new $rtlLicenseClassName();

		if ( $rtlLicenseClass->{'isActive'}() === true ) {
			// Product is Active Now, Enable Pro Features
			if ( ! defined( 'BSH_LICENSED' ) ) {
				define( 'BSH_LICENSED', true );
			}
			add_action( 'plugins_loaded', array( 'BSH_Plugin', 'boot' ), 20 );
		}
	}
}
// ----------------------------------------------------------------------------------------------------- End RTL License
