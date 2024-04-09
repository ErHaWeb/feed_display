.. include:: /Includes.rst.txt

.. _quickStart:

===========
Quick start
===========

..  rst-class:: bignums-tip

1.  :ref:`Install <installation>` the Extension

2.  Include the static TypoScript

    #.  Go to the TypoScript module under `Site Management` → `TypoScript`.

    #.  Select `Edit TypoScript Record` in the module header.

    #.  Click the button `Edit the whole template record`.

    #.  Switch to the tab `Advanced Options`.

    #.  Select `Feed Display: Static TypoScript Include (feed_display)` under `Include TypoScript sets` → `Available Items`.

    #.  Click `Save` and `Close`.

3.  Create the plugin content element

    #.  Go to the Page module under `Web` → `Page`.

    #.  In the pagetree view click on the page where you want the feed to be displayed.

    #.  Click the `+ Content` button where you want the feed plugin content element to be placed.

    #.  Switch to the tab `Plugins`.

    #.  Select the `Feed Display` item.

    #.  In the tab `General` you may want to enter some general content information like a header.

    #.  Switch to tab `Plugin`.

    #.  Under `Plugin Options` → `General` → `Feed URL` enter the full URL of your Feed. (By default `https://typo3.org/rss` is used here.)

    #.  Click `Save` and `Close`.

4.  Done

    The feed output can now be viewed in the frontend on the page where you
    created the plugin.