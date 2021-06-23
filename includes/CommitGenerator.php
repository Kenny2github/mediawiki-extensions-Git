<?php
namespace MediaWiki\Extension\Git;

use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\IResultWrapper;
use DatabaseUpdater;

class CommitGenerator {
	public static function generateCommits(
		IDatabase $dbw,
		bool $purge = false,
		?DatabaseUpdater $updater = null
	) {
		// Every commit depends on the previous
		// Find out which revisions already have commits associated with them
		// Generate, starting with least recent commit-less revision

		$result = self::revisionsNeedingCommits( $dbw, $purge );

		// Get most recent commit hash, to use as the parent of the
		// first new commit being generated.
		$root_parent = self::getParent( $dbw );

		$revidss = self::collateRevisionIDs( $result );

		$select_ids = self::tailRevisionIDs( $revidss );

		$result = self::collatedRevisions( $dbw, $select_ids );

		$revids = reset( $revidss );
		while ( ($row = $result->fetchObject()) !== false ) {
			if ( $updater ) {
				$msg = '...committing revision';
				if ( count( $revids ) > 1 ) {
					$msg .= 's ' . implode( ', ', $revids );
				} else {
					$msg .= ' ' . $revids[0];
				}
				$updater->output( $msg );
			}
			self::generateCommit();
			$revids = next( $revidss );
		}
		// TODO: probably might have to end up maintaining some sort of working tree
		// this may be more complicated than I thought
	}

	private static function revisionsNeedingCommits( IDatabase $dbw, bool $purge ) {
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
				return;
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
		return $result;
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

	private static function tailRevisionIDs( array $revids ) {
		$result = [];
		foreach ( $revids as $ids ) {
			// Tree is counted at end of sequence of like revisions
			$result[] = $ids[array_key_last( $ids )];
		}
		return $result;
	}

	private static function collatedRevisions( IDatabase $dbw, array $revids ) {
		$tables = [
			'r' => 'revision',
			'c' => 'comment',
			'a' => 'actor',
			'ct' => 'revision_comment_temp',
			'at' => 'revision_actor_temp'
		];
		$columns = [
			'rev_id' => 'r.rev_id',
			'comment' => 'c.comment_text',
			'actor' => 'a.actor_name'
		];
		$m = __METHOD__;
		$conds = ['r.rev_id' => $revids ];
		$opts = ['ORDER BY' => 'rev_id'];
		$join = [
			'ct' => ['LEFT JOIN', 'r.rev_id=ct.revcomment_rev'],
			'c' => ['LEFT JOIN', 'ct.revcomment_comment_id=c.comment_id'],
			'at' => ['LEFT JOIN', 'r.rev_id=at.revactor_rev'],
			'a' => ['LEFT JOIN', 'at.revactor_actor=a.actor_id']
		];
		return $dbw->select(
			$tables, $columns,
			$conds, $m, $opts, $join
		);
	}
}