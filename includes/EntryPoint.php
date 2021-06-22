<?php
namespace MediaWiki\Extension\Git;

use RequestContext;
use Title;
use MediaWiki;

class EntryPoint {
	private $mInput;

	function __construct() {
		$this->mInput = fopen( 'php://input', 'r' );
	}

	function __destruct() {
		fclose( $this->mInput );
	}

	public static function main() {
		global $wgTitle;

		if (
			!isset( $_SERVER['HTTP_GIT_PROTOCOL'] )
			|| trim( $_SERVER['HTTP_GIT_PROTOCOL'] ) !== 'version=2'
		) {
			http_response_code( 505 );
			echo 'MediaWiki over Git only supports Git protocol version 2. '
				. 'Please upgrade your Git client.';
			die( 1 );
		}

		// Things don't like it when $wgTitle isn't set
		$wgTitle = Title::makeTitle( NS_SPECIAL, 'Badtitle/extensions/Git/git.php' );
		RequestContext::getMain()->setTitle( $wgTitle );

		(new self)->chooseRoute();

		(new MediaWiki)->doPostOutputShutdown();

	}

	public function chooseRoute() {
		$path = explode( 'git.php', $_SERVER['REQUEST_URI'] )[1];
		if ( $_SERVER['REQUEST_METHOD'] === 'GET' ) {
			if ( $path === '/info/refs?service=git-upload-pack' ) {
				CapabilityAdvertisement::main();
			}
		} else if ( $_SERVER['REQUEST_METHOD'] === 'POST' ) {
			if ( $path === '/git-upload-pack' ) {
				$this->chooseCommand();
			}
		}
	}

	public function chooseCommand() {
		$cmdLine = PktLine::read( $this->mInput );
		if ( strpos( $cmdLine, 'command=' ) === false ) {
			http_response_code( 400 );
			echo 'Invalid command line.';
			return;
		}
		$cmd = substr( $cmdLine, strlen( 'command=' ) );
		$capabilities = [];
		while ( 1 ) {
			$capability = PktLine::read( $this->mInput );
			if (
				$capability === PktLine::FLUSH
				|| $capability === PktLine::DELIMITER
			) break;
			$capabilities[] = $capability;
		}
		$args = [];
		while ( $arg = PktLine::read( $this->mInput ) ) {
			$args[] = $arg;
		}
		switch ( $cmd ) {
			case 'ls-refs':
				ListRefs::main( $capabilities, $args );
				break;
			default:
				http_response_code( 404 );
				echo 'Invalid command.';
		}
	}
}