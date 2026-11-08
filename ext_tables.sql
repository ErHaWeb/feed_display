CREATE TABLE tx_feeddisplay_feed (
    uid int(11) unsigned NOT NULL auto_increment,
    url_hash char(64) DEFAULT '' NOT NULL,
    url text,
    response_data longblob,
    revision char(64) DEFAULT '' NOT NULL,
    fetched_at int(11) unsigned DEFAULT '0' NOT NULL,
    last_attempt_at int(11) unsigned DEFAULT '0' NOT NULL,
    last_error text,
    invalidation_pending smallint(5) unsigned DEFAULT '0' NOT NULL,
    PRIMARY KEY (uid),
    UNIQUE KEY url_hash (url_hash)
);
