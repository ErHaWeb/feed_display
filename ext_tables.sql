CREATE TABLE tx_feeddisplay_feed (
	uid int(11) unsigned NOT NULL auto_increment,
	url_hash char(64) DEFAULT '' NOT NULL,
	url text,
	raw_body mediumtext,
	body_hash char(64) DEFAULT '' NOT NULL,
	fetched_at int(11) unsigned DEFAULT '0' NOT NULL,
	last_attempt_at int(11) unsigned DEFAULT '0' NOT NULL,
	last_error text,
	last_used_at int(11) unsigned DEFAULT '0' NOT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY url_hash (url_hash),
	KEY last_used_at (last_used_at)
);
