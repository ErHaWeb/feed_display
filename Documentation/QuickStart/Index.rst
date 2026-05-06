.. include:: /Includes.rst.txt

.. _quickStart:

===========
Quick start
===========

..  rst-class:: bignums-tip

1.  :ref:`Install <installation>` the Extension

2.  Include the Site Set

    #.  Open the site configuration.

        * TYPO3 v13: :guilabel:`Site Management` → :guilabel:`Sites`
        * TYPO3 v14: :guilabel:`Sites` → :guilabel:`Setup`

    #.  Edit the site where the feed should be displayed.

    #.  Add the Site Set `Feed Display` (`erhaweb/feed-display`).

    #.  Save the site configuration.

    ..  tip::

        Existing projects that still use TypoScript template records can use
        the static include `Feed Display: Static TypoScript Include
        (feed_display)` as a fallback. Prefer the Site Set for TYPO3 v13/v14.

3.  Create the plugin content element

    #.  Go to the page content module.

        * TYPO3 v13: :guilabel:`Web` → :guilabel:`Page`
        * TYPO3 v14: :guilabel:`Content` → :guilabel:`Layout`

    #.  In the pagetree view click on the page where you want the feed to be displayed.

    #.  Click the `+ Content` button where you want the feed plugin content element to be placed.

    #.  Switch to the tab `Plugins`.

    #.  Select the `Feed Display` item.

    #.  In the tab `General` you may want to enter some general content information like a header.

    #.  Switch to tab `Plugin`.

    #.  Under `Plugin Options` → `General` → `Feed URL` enter the full URL of your Feed. (By default `https://typo3.org/rss` is used here.)

    #.  Click `Save` and `Close`.

4.  Done

    The feed output can be viewed in the frontend on the page where you
    created the plugin.

..  note::

    After upgrading from an older Feed Display version, run the upgrade wizard
    `feedDisplayCTypeMigration` once. It migrates existing content elements
    from `CType = list` / `list_type = feeddisplay_pi1` to
    `CType = feeddisplay_pi1`.
