<?php

use MediaWiki\Extension\Git\EntryPoint;

define( 'MW_API', true );
define( 'MW_ENTRY_POINT', 'git' );

//		   $IP/extensions/Git
require dirname( dirname( __DIR__ ) ) . '/includes/WebStart.php';

EntryPoint::main();