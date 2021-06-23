CREATE TABLE IF NOT EXISTS /*_*/git_commits (
	-- git commit hash - 40 hexits for sha1
	sha1 char(40) binary PRIMARY KEY,
	-- tree hash
	tree char(40) binary,
	-- parents (normal edits get one) - max 25(.6) parents
	parents varchar(1024),
	-- commit author
	author varchar(255),
	-- committer (us for normal revs, author for git revs)
	committer varchar(255),
	-- extended commit message, if any
	comment blob
);
