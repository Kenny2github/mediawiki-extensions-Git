<?php
namespace MediaWiki\Extension\Git;

use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;
use DatabaseUpdater;
use MediaWiki\MediaWikiServices;
use Title;
use NamespaceInfo;

class CommitGenerator {
	public static function generateCommits(
		IDatabase $dbw,
		bool $purge = false,
		?DatabaseUpdater $updater = null
	) {
		// Collate an array of arrays of revision IDs.
		// All revisions in each sub-array probably came from the same commit.
		// This also handles starting from scratch if $purge is true.
		$revidss = self::revisionIDsNeedingCommits( $dbw, $purge );
		// No revisions to work on, our job here is done
		if ( $revidss === false ) return;

		// Get most recent commit hash, to use as the parent of the
		// first new commit being generated.
		$commitHash = self::getParent( $dbw );

		$services = MediaWikiServices::getInstance();
		$store = $services->getRevisionStore();
		$nsInfo = $services->getNamespaceInfo();

		$roles = self::getSlotRoleIDs( $dbw ); // [Slot Name => Slot ID]
		$tree = self::getBlobs( $dbw ); // [Page ID => [Slot ID => Blob Hash]]
		foreach ( $revidss as $revids ) {
			// From this set of revision IDs (which, remember, we are treating
			// as having come from one commit), get a mapping of
			// [Page ID => Most recent revision to this page in this set]
			$pages = self::latestRevisions( $revids, $store );
			// Update $tree with blob-hashes of the contents of the above revisions
			self::updateTree( $tree, $pages, $roles );
			// Now that the blobs are hashed, work our way up the hierarchy.
			// Hash the slots of a page as one tree, then pages in a namespace.
			// Finally, hash the root tree of namespaces and return that hash.
			$treeHash = self::hashTree( $tree, $roles, $nsInfo );
			// Author and comment information comes from the last revision in the set.
			$rev = $store->getRevisionById( $revids[array_key_last( $revids )] );
			// Generate the commit hash and insert commit data into the DB.
			// This hash is the parent of the next commit.
			$commitHash = self::hashCommit( $treeHash, $commitHash, $rev );
		}
		// Bring the blob tree up to date in the DB for future generations.
		self::updateBlobs( $dbw, $tree );
	}

	private static function revisionIDsNeedingCommits( IDatabase $dbw, bool $purge ) {
		$tables = [
			'r' => 'revision',
			'c' => 'comment',
			'ct' => 'revision_comment_temp',
			'at' => 'revision_actor_temp'
		];
		$columns = [
			'rev_id' => 'r.rev_id',
			'comment' => 'c.comment_text',
			'actor' => 'at.revactor_actor'
		];
		$m = __METHOD__;
		$opts = ['ORDER BY' => 'rev_id'];
		$join = [
			'ct' => ['LEFT JOIN', 'r.rev_id=ct.revcomment_rev'],
			'c' => ['LEFT JOIN', 'ct.revcomment_comment_id=c.comment_id'],
			'at' => ['LEFT JOIN', 'r.rev_id=at.revactor_rev']
		];
		if ( $purge ) {
			// Don't care about commits we've already generated,
			// because purge means we re-generate them all.
			$dbw->delete( 'git_commits', IDatabase::ALL_ROWS, __METHOD__ );
			$dbw->delete( 'git_revisions', IDatabase::ALL_ROWS, __METHOD__ );
			$dbw->delete( 'git_blobs', IDatabase::ALL_ROWS, __METHOD__ );
			// All revisions now need commits
			$result = $dbw->select(
				$tables, $columns,
				[], $m, $opts, $join
			);
		} else {
			// Find the first revision that doesn't already have a commit
			$result = $dbw->selectRow(
				['want' => 'revision', 'have' => 'git_revisions'],
				['rev_id' => 'want.rev_id'],
				'have.rev_id IS NULL',
				__METHOD__,
				['ORDER BY' => 'want.rev_id'],
				['have' => ['LEFT JOIN', 'want.rev_id=have.rev_id']]
			);
			if ( $result === false ) {
				// All revisions have commits attached, so we're done here
				return false;
			}
			// Even if some subsequent revisions have commits associated,
			// each commit depends on the previous, so those commits have
			// to be discarded as they will be replaced.
			// Delete commits associated with revisions later than the first
			// revision without an associated commit.
			// Due to the ON DELETE CASCADE, this deletes from git_revisions too.
			$dbw->deleteJoin(
				'git_commits', 'git_revisions',
				'sha1', 'git_commit',
				'git_revisions.rev_id>=' . $result->rev_id,
				__METHOD__
			);
			// Only revisions later than (and including) the first revision
			// without an associated commit need commits.
			$result = $dbw->select(
				$tables, $columns,
				'rev_id>=' . $result->rev_id,
				$m, $opts, $join
			);
		}
		return self::collateRevisionIDs( $result );
	}

	private static function getParent( IDatabase $dbw ) {
		// Get most recent commit hash, to use as the parent of the
		// first new commit being generated.
		$root_parent = $dbw->selectRow(
			'git_revisions', 'git_commit',
			[], __METHOD__,
			['ORDER BY' => 'rev_id DESC']
		);
		return $root_parent ? $root_parent->git_commit : null;
	}

	private static function collateRevisionIDs( IResultWrapper $result ) {
		$revids = [];
		$last_comment = null;
		$last_actor = null;
		while ( ($row = $result->fetchObject()) !== false ) {
			// Combine multiple sequential revisions with the same comment
			// into one commit, since one commit that modifies multiple pages
			// is translated into multiple sequential revisions.
			if ( $row->comment !== $last_comment || $row->actor !== $last_actor ) {
				$revids[] = [];
				$last_comment = $row->comment;
				$last_actor = $row->actor;
			}
			array_push( $revids[array_key_last( $revids )], $row->rev_id );
		}
		return $revids;
	}

	private static function getSlotRoleIDs( IDatabase $dbw ) {
		$map = [];
		$result = $dbw->select( 'slot_roles', '*' );
		while ( ($row = $result->fetchObject()) !== false ) {
			$map[$row->role_name] = $row->role_id;
		}
		return $map;
	}

	private static function latestRevisions( array $revids, $store ) {
		$revs = array_map( $store->getRevisionById, $revids );
		$pages = [];
		foreach ( $revs as $rev ) {
			$pageid = $rev->getPageId();
			$pages[$pageid] = $rev;
		}
		return $pages;
	}

	private static function hashObject( string $type, string $text, bool $raw = true ) {
		return sha1( $type . ' ' . strlen( $text ) . "\0" . $text, $raw );
	}

	private static function updateTree( array $tree, array $pages, array $roles ) {
		foreach ( $pages as $pageid => $rev ) {
			$slots = $rev->getSlots()->getSlots();
			$tree[$pageid] = [];
			foreach ( $slots as $name => $content ) {
				$text = $content->serialize();
				$hash = self::hashObject( 'blob', $text );
				$tree[$pageid][$roles[$name]] = $hash;
			}
		}
	}

	private static function hashTree( array $tree, array $roles, NamespaceInfo $nsInfo ) {
		// Hash each collection of slot blobs as a tree
		$slotTree = []; // [Page ID => Slot Tree Hash]
		foreach ( $tree as $pageid => $slots ) {
			// slot names present in this tree
			$pageroles = array_keys( array_intersect( $roles, array_keys( $slots ) ) );
			sort( $pageroles );
			$text = '';
			foreach ( $pageroles as $role ) {
				$text .= '100644 ' . $role . "\0";
				$text .= $slots[$roles[$role]]; // blob hash
			}
			$hash = self::hashObject( 'tree', $text );
			$slotTree[$pageid] = $hash;
		}
		// Group pages by namespace
		$namespace = []; // [Namespace Number => [Page ID => Page Title]]
		foreach ( array_keys( $slotTree ) as $pageid ) {
			$title = Title::newFromID( $pageid );
			$ns = $title->getNamespace();
			if ( !isset( $namespace[$ns] ) ) $namespace[$ns] = [];
			$namespace[$ns][$pageid] = $title->getDBkey();
		}
		// Hash each namespace of page trees as a tree
		$nsTree = []; // [Namespace Number => Page Tree Hash]
		foreach ( $namespace as $ns => $pages ) {
			$ids = array_flip( $pages );
			$titles = array_values( $pages );
			sort( $titles );
			$text = '';
			foreach ( $titles as $title ) {
				$text .= '040000 ' . $title . "\0";
				$text .= $slotTree[$ids[$title]]; // tree hash
			}
			$hash = self::hashObject( 'tree', $text );
			$nsTree[$ns] = $hash;
		}
		// Hash the root collection of namespace trees as the final tree
		$namespace = array_flip( $nsInfo->getCanonicalNamespaces() );
		$names = array_keys( $namespace );
		sort( $names );
		$text = '';
		foreach ( $names as $name ) {
			$text .= '040000 ' . $name . "\0";
			$text .= $nsTree[$namespace[$name]];
		}
		return self::hashObject( 'tree', $text, false );
	}

	private static function hashCommit(
		IDatabase $dbw, string $treeHash,
		?string $parentHash, $rev
	) {
		global $wgNoReplyAddress, $wgSitename, $wgLocalTZoffset;
		$actor = $rev->getUser( $rev::RAW );
		$timestamp = wfTimestamp( TS_UNIX, $rev->getTimestamp() );
		$timestamp .= sprintf( ' %+03d%02d', $wgLocalTZoffset / 60, $wgLocalTZoffset % 60 );
		$author = $actor->getName() . '<' . $wgNoReplyAddress . '> ' . $timestamp;
		$committer = $wgSitename . '<' . $wgNoReplyAddress . '> ' . $timestamp;
		$comment = $rev->getComment( $rev::RAW )->$text;

		$text = '';
		$text .= 'tree ' . $treeHash . "\n";
		if ( $parentHash ) $text .= 'parent ' . $parentHash . "\n";
		$text .= 'author ' . $author . "\n";
		$text .= 'committer ' . $committer . "\n";
		$text .= "\n" . $comment . "\n";
		$hash = self::hashObject( 'commit', $text, false );
		$dbw->insert(
			'git_commits',
			[
				'sha1' => $hash,
				'tree' => $treeHash,
				'parents' => $parentHash ?? '',
				'author' => $author,
				'committer' => $committer,
				'comment' => $comment
			],
			__METHOD__
		);
		return $hash;
	}

	private static function getBlobs( IDatabase $dbw ) {
		$tree = [];
		$result = $dbw->select(
			'git_blobs',
			['page_id', 'role_id', 'sha1'],
			[], __METHOD__
		);
		while ( ($row = $result->fetchObject()) !== false ) {
			if ( !isset( $tree[$row->page_id] ) ) $tree[$row->page_id] = [];
			$tree[$row->page_id][$row->role_id] = $row->sha1;
		}
		return $tree;
	}

	private static function updateBlobs( IDatabase $dbw, array $tree ) {
		$rows = [];
		foreach ( $tree as $pageid => $slots ) {
			foreach ( $slots as $slot => $hash ) {
				$rows[] = ['page_id' => $page, 'role_id' => $slot, 'sha1' => $hash];
			}
		}
		$dbw->replace(
			'git_blobs',
			['page_id', 'role_id'],
			$rows,
			__METHOD__
		);
	}
}