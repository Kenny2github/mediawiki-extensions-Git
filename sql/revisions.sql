CREATE TABLE IF NOT EXISTS /*_*/git_revisions (
	-- revision ID
	rev_id integer unsigned,
	-- git commit hash
	git_commit char(40) binary,
	-- keys
	PRIMARY KEY(rev_id),
	FOREIGN KEY(rev_id) REFERENCES /*_*/revision(rev_id) ON DELETE CASCADE,
	FOREIGN KEY(git_commit) REFERENCES /*_*/git_commits(sha1) ON DELETE CASCADE
);
