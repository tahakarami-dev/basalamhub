<?php
/**
 * Inline SVG icons: one line-icon family for the whole plugin (24×24 grid, 1.75 stroke,
 * round caps, currentColor), so icons follow the text colour in light and dark themes and
 * nothing is loaded from outside.
 *
 * Shapes follow the Lucide icon set (https://lucide.dev, ISC License,
 * Copyright (c) for portions of Lucide are held by Cole Bemis 2013-2022 as part of Feather
 * (MIT); all other copyright held by Lucide Contributors 2022). The logo is BasalamHub's own.
 *
 * @package BasalamHub
 */

defined( 'ABSPATH' ) || exit;

class BSH_Icons {

	/**
	 * Inner SVG markup per icon.
	 *
	 * @return array<string,string>
	 */
	private static function paths() {
		return array(
			'dashboard' => '<rect width="7" height="9" x="3" y="3" rx="1.5"/><rect width="7" height="5" x="14" y="3" rx="1.5"/><rect width="7" height="9" x="14" y="12" rx="1.5"/><rect width="7" height="5" x="3" y="16" rx="1.5"/>',
			'chart'     => '<path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9"/><path d="M13 17V5"/><path d="M8 17v-3"/>',
			'package'   => '<path d="M11 21.73a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73z"/><path d="M12 22V12"/><path d="m3.3 7 7.7 4.73a2 2 0 0 0 2 0L20.7 7"/><path d="m7.5 4.27 9 5.15"/>',
			'bag'       => '<path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>',
			'upload'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
			'download'  => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5"/><path d="M12 15V3"/>',
			'link'      => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
			'folder'    => '<path d="M20 20a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.69-.9L9.6 3.9A2 2 0 0 0 7.93 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/>',
			'tag'       => '<path d="M12.59 2.59A2 2 0 0 0 11.17 2H4a2 2 0 0 0-2 2v7.17a2 2 0 0 0 .59 1.42l8.7 8.7a2.43 2.43 0 0 0 3.42 0l6.58-6.58a2.43 2.43 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".75" fill="currentColor"/>',
			'logs'      => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M16 13H8"/><path d="M16 17H8"/><path d="M10 9H8"/>',
			'bell'      => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
			'settings'  => '<path d="M20 7h-9"/><path d="M14 17H5"/><circle cx="17" cy="17" r="3"/><circle cx="7" cy="7" r="3"/>',
			'refresh'   => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M8 16H3v5"/>',
			'check'     => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
			'alert'     => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
			'error'     => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
			'info'      => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
			'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
			'image'     => '<rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
			'search'    => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
			'plus'      => '<path d="M5 12h14"/><path d="M12 5v14"/>',
			'menu'      => '<path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/>',
			'swap'      => '<path d="M8 3 4 7l4 4"/><path d="M4 7h16"/><path d="m16 21 4-4-4-4"/><path d="M20 17H4"/>',
			'sun'       => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2"/><path d="M12 20v2"/><path d="m4.93 4.93 1.41 1.41"/><path d="m17.66 17.66 1.41 1.41"/><path d="M2 12h2"/><path d="M20 12h2"/><path d="m6.34 17.66-1.41 1.41"/><path d="m19.07 4.93-1.41 1.41"/>',
			'moon'      => '<path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/>',
			'theme'     => '<circle cx="12" cy="12" r="10"/><path d="M12 18a6 6 0 0 0 0-12v12z" fill="currentColor"/>',
			'exit'      => '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17-5-5 5-5"/><path d="M5 12h12"/>',
			'store'     => '<path d="M3 9.5 5 4h14l2 5.5"/><path d="M3 9.5a3 3 0 0 0 6 0 3 3 0 0 0 6 0 3 3 0 0 0 6 0"/><path d="M5 12.4V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-7.6"/><path d="M10 21v-4a2 2 0 0 1 4 0v4"/>',
			'cart'      => '<circle cx="8" cy="21" r="1"/><circle cx="19" cy="21" r="1"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
		);
	}

	/**
	 * An icon as inline SVG (decorative: hidden from screen readers).
	 *
	 * @param string $name  Icon name.
	 * @param int    $size  Pixel size.
	 * @param string $class Extra class.
	 * @return string
	 */
	public static function svg( $name, $size = 20, $class = '' ) {
		$paths = self::paths();
		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}
		return sprintf(
			'<svg class="bsh-icon%1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
			$class ? ' ' . esc_attr( $class ) : '',
			(int) $size,
			$paths[ $name ] // Static markup defined above.
		);
	}

	/**
	 * Prints an icon.
	 *
	 * @param string $name  Icon name.
	 * @param int    $size  Pixel size.
	 * @param string $class Extra class.
	 */
	public static function e( $name, $size = 20, $class = '' ) {
		echo self::svg( $name, $size, $class ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG from paths().
	}

	/**
	 * The BasalamHub mark: the letter «ب» drawn as a returning arrow (two-way sync), its dot
	 * below, on a Basalam-orange tile.
	 *
	 * @param int  $size Pixel size.
	 * @param bool $tile Draw the orange tile (false: glyph only, for the WordPress menu).
	 * @return string
	 */
	public static function logo( $size = 36, $tile = true ) {
		$glyph = '<path d="M7.6 13.2c0 4.6 3.4 7.6 8.4 7.6s8.4-3 8.4-7.6" fill="none" stroke="%1$s" stroke-width="2.6" stroke-linecap="round"/>'
			. '<path d="M21.6 15.2l2.8-2.4 2.4 2.8" fill="none" stroke="%1$s" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/>'
			. '<circle cx="16" cy="25.4" r="2" fill="%1$s"/>';
		if ( ! $tile ) {
			// WordPress recolours only fills in menu icons; a mid grey stroke reads on every admin colour scheme.
			return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="4.5 7 23 21" width="' . (int) $size . '" height="' . (int) $size . '">' . sprintf( $glyph, '#a7aaad' ) . '</svg>';
		}
		$id = 'bshg' . wp_rand( 1000, 9999 );
		return '<svg class="bsh-logo" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" width="' . (int) $size . '" height="' . (int) $size . '" aria-hidden="true" focusable="false">'
			. '<defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#ff7a4d"/><stop offset="1" stop-color="#f04b23"/></linearGradient></defs>'
			. '<rect width="32" height="32" rx="9" fill="url(#' . $id . ')"/>'
			. sprintf( $glyph, '#fff' )
			. '</svg>';
	}
}
