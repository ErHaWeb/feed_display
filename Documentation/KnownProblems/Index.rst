..  include:: /Includes.rst.txt

..  _known-problems:

==============
Known problems
==============

The following issues are known problems. However those are not fixable inside EXT:feed_display.

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

In this context, please note that the Feed Display extension itself caches the return. This means that in case of doubt
a faulty feed may persist for a longer period of time until the TYPO3 cache is cleared again, although a direct call to
the feed URL will work again.