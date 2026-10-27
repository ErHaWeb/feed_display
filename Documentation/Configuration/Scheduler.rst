..  include:: /Includes.rst.txt

..  _configuration-scheduler:

==============================
Fetching feeds with a schedule
==============================

Every successfully fetched feed is stored in the table ``tx_feeddisplay_feed``,
one row per feed URL. The plugin renders the stored feed, and a failed fetch
never replaces it. The error message is only displayed for a feed that has never
been fetched successfully.

By default, page rendering fetches a feed once the stored feed is older than
:ref:`cacheDuration <configuration-typoscript-constants-settings-cacheduration>`.
To fetch feeds independent of page rendering instead, set
:ref:`fetchMode <configuration-typoscript-constants-settings-fetchmode>` to
``scheduler``, either for the whole site or for single plugins in the FlexForm,
and run the console command ``feeddisplay:refresh`` regularly.

..  _configuration-scheduler-refresh:

Refreshing feeds
================

..  code-block:: bash

    vendor/bin/typo3 feeddisplay:refresh

The command fetches

*   the feed URLs set in the plugin options of all content elements,
*   all feeds rendered within the last 30 days (option ``--max-age-days``),
*   additional feed URLs passed with ``--url`` (can be repeated).

A feed URL that only comes from TypoScript is known once a page showing it has
been rendered.

Whenever the content of a feed changes, the pages showing it are removed from
the page cache. The command exits with a failure code if a feed could not be
fetched; its last successfully fetched feed is kept.

..  _configuration-scheduler-prune:

Removing unused feeds
=====================

..  code-block:: bash

    vendor/bin/typo3 feeddisplay:prune

The command removes stored feeds that have not been rendered within the last
30 days (option ``--max-age-days``). Feeds set in the plugin options of content
elements are always kept.

..  _configuration-scheduler-task:

Scheduler tasks
===============

With the system extension `scheduler` installed, both commands are available in
the scheduler module as task "Execute console commands". A typical setup:

*   ``feeddisplay:refresh`` every 15 minutes
*   ``feeddisplay:prune`` once a day

Without the scheduler extension, run the commands with a cron job.
