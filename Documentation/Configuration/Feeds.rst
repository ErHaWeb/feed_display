..  include:: /Includes.rst.txt
..  highlight:: typoscript

..  _configuration-feeds:

=====
Feeds
=====

..  versionadded:: 3.1
    Feed Display uses `settings.feeds` as the intended feed
    configuration for one or more RSS/Atom feeds.

FlexForm
========

Editors configure one or more feed records in the plugin FlexForm under
:guilabel:`Plugin Options` → :guilabel:`General` → :guilabel:`Feeds`.
Each record represents one RSS or Atom feed and stores its own URL. The
FlexForm references these records through an inline 1:n relation.

..  versionadded:: 3.1
    FlexForm feed URLs are stored as inline feed records, so a plugin instance
    can reference any number of feeds.

TypoScript
==========

TypoScript can define any number of feed entries:

..  versionadded:: 3.1
    TypoScript can configure multiple feeds below `settings.feeds`.

..  code-block:: typoscript

    plugin.tx_feeddisplay_pi1.settings {
        maxFeedCount = 10
        cacheDuration = 3600

        feeds {
            10 {
                url = https://example.org/news/rss.xml
            }

            20 {
                url = https://blog.example.org/feed.atom
            }
        }
    }

Site Settings
=============

Site Settings use a string list for the same runtime setting:

..  versionadded:: 3.1
    Site Settings expose `settings.feeds` as a string list of feed URLs.

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/settings.yaml

    plugin.tx_feeddisplay_pi1.settings.feeds:
      - 'https://example.org/news/rss.xml'
      - 'https://blog.example.org/feed.atom'

Migration
=========

The old top-level `settings.feedUrl` is deprecated. Existing FlexForm values
are migrated by the upgrade wizard `feedDisplayFeedUrlFlexFormMigration`, which
creates feed records and stores their references in `settings.feeds`.

..  versionadded:: 3.1
    The upgrade wizard `feedDisplayFeedUrlFlexFormMigration` migrates legacy
    FlexForm `settings.feedUrl` values to feed records referenced by
    `settings.feeds`.

For TypoScript, migrate old configuration manually:

..  code-block:: typoscript

    plugin.tx_feeddisplay_pi1.settings.feedUrl = https://example.org/news/rss.xml

to:

..  code-block:: typoscript

    plugin.tx_feeddisplay_pi1.settings.feeds {
        10 {
            url = https://example.org/news/rss.xml
        }
    }
