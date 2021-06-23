<?php
namespace MediaWiki\Extension\Git;

use DatabaseUpdater;

class GitHooks {
	public static function schemaUpdate( DatabaseUpdater $updater ) {
		$sql_dir = dirname( __DIR__ ) . '/sql';
		$updater->addExtensionTable(
			'git_commits',
			$sql_dir . '/commits.sql'
		);
		$updater->addExtensionTable(
			'git_revisions',
			$sql_dir . '/revisions.sql'
		);
		$updater->output( 'Generating Git commits from revisions...' );
		CommitGenerator::generateCommits( $update->getDB(), true, $updater );
	}
}