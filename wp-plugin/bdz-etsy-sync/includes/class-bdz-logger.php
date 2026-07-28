<?php
/**
 * A small ring-buffer log, kept in an option so the admin screen can show what
 * the last run actually did. Capped so it can never grow without bound.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class BDZ_Etsy_Logger {

	const OPTION = 'bdz_etsy_log';
	const LIMIT  = 400;

	public static function add( $message, $level = 'info' ) {
		$lines = get_option( self::OPTION, array() );
		if ( ! is_array( $lines ) ) {
			$lines = array();
		}

		$lines[] = array(
			'time'    => time(),
			'level'   => in_array( $level, array( 'info', 'warn', 'error', 'ok' ), true ) ? $level : 'info',
			'message' => (string) $message,
		);

		if ( count( $lines ) > self::LIMIT ) {
			$lines = array_slice( $lines, -self::LIMIT );
		}

		update_option( self::OPTION, $lines, false );
	}

	public static function warn( $message ) {
		self::add( $message, 'warn' );
	}

	public static function error( $message ) {
		self::add( $message, 'error' );
	}

	public static function ok( $message ) {
		self::add( $message, 'ok' );
	}

	public static function all() {
		$lines = get_option( self::OPTION, array() );
		return is_array( $lines ) ? $lines : array();
	}

	/** Lines added after a given index — lets the admin page tail the log. */
	public static function since( $index ) {
		$lines = self::all();
		$index = max( 0, (int) $index );
		return array(
			'lines' => array_slice( $lines, $index ),
			'next'  => count( $lines ),
		);
	}

	public static function clear() {
		update_option( self::OPTION, array(), false );
	}
}
