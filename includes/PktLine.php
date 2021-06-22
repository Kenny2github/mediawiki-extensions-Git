<?php
namespace MediaWiki\Extension\Git;

class PktLine {
	public const FLUSH = 0;
	public const DELIMITER = 1;
	public const MESSAGE = 2;

	public static function read( $file, bool $trim = true ) {
		$length = intval( fread( $file, 4 ), 16 );
		if ( $length <= 4 ) return $length;
		$line = fread( $file, $length - 4 );
		if ( $trim ) $line = rtrim( $line, "\n" );
		return $line;
	}

	public static function write( $line = null, string $end = "\n" ) {
		if ( !is_string( $line ) ) {
			if ( !$line ) echo '0000'; // null or 0 => flush
			else echo sprintf( '%04x', $line ); // integer => delim or msg
			return;
		}
		$line .= $end;
		echo sprintf( '%04x', strlen( $line ) + 4 ) . $line;
	}
}