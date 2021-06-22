<?php
namespace MediaWiki\Extension\Git;

class CapabilityAdvertisement {
	public const CAPABILITIES = [
		'version 2',
		'agent=git/mediawiki',
		'ls-refs',
		'object-format=sha1',
	];

	public static function main() {
		header( 'Content-Type: application/x-git-upload-pack-advertisement' );
		header( 'Pragma: no-cache' );
		header( 'Cache-Control: no-cache, max-age=0, must-revalidate' );
		PktLine::write( '# service=git-upload-pack' );
		PktLine::write();
		foreach ( self::CAPABILITIES as $capability ) {
			PktLine::write( $capability );
		}
		PktLine::write();
	}
}