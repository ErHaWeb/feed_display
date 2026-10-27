..  include:: /Includes.rst.txt

..  _known-problems:

==============
Known problems
==============

The following issues are known problems. However those are not fixable inside `EXT:feed_display`.

If you notice anything else feel free to open an issue on `Github <https://github.com/ErHaWeb/feed_display/issues>`__.

..  _known-problems-no-feed-items-displayed:

No feed items are displayed
===========================

**Question:**

I get the message "Sorry, no items could be fetched." in the frontend although the feed is available when I call the
feed URL directly. What could be the cause of this problem?

**Answer:**

Please verify that the feed is indeed being delivered reliably. I know of cases where the feed seemed to work initially,
but an error 500 occurred on some calls. If this error occurs sporadically, it could be that the memory size on the
system delivering the feed has been exceeded.

In this context, please note that the Feed Display extension stores the last successfully fetched feed and keeps
rendering it while fetching fails. A feed that delivers a valid but empty response, however, replaces the stored feed.
The table ``tx_feeddisplay_feed`` shows the last error of each feed in the field ``last_error``.

If the feed is loaded from a remote ``http`` or ``https`` URL, also verify the
TYPO3 HTTP client configuration of the instance. Proxy requirements and
outbound network restrictions apply through TYPO3's PSR-17/PSR-18 interfaces,
so a blocked or incomplete TYPO3 HTTP setup can prevent feed retrieval.
