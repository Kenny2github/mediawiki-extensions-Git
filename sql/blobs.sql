CREATE TABLE IF NOT EXISTS /*_*/git_blobs (
	-- page title
	page_id integer unsigned NOT NULL,
	-- slot type
	role_id integer NOT NULL,
	-- hash
	sha1 binary(20) NOT NULL,
	-- keys
	PRIMARY KEY(page_id, role_id)
);