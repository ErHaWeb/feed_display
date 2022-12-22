..  include:: /Includes.rst.txt
..  highlight:: typoscript

..  index::
    TypoScript; Constants
..  _configuration-typoscript-constants:

Constants
=========

..  _configuration-typoscript-constants-view:

View
----

The following options are located under the following path:
:typoscript:`plugin.tx_feeddisplay_pi1.view`

..  _configuration-typoscript-constants-view-templaterootpath:

Template root path
~~~~~~~~~~~~~~~~~~

..  confval:: templateRootPath

    :type: string
    :Default: empty
    :Path: plugin.tx_feeddisplay_pi1.view

    In addition to the default path `EXT:feed_display/Resources/Private/Templates/`,
    this constant can be used to define a custom template root path to overwrite
    individual fluid files as needed.

..  _configuration-typoscript-constants-view-partialrootpath:

Partial root path
~~~~~~~~~~~~~~~~~

..  confval:: partialRootPath

    :type: string
    :Default: empty
    :Path: plugin.tx_feeddisplay_pi1.view

    In addition to the default path `EXT:feed_display/Resources/Private/Partials/`,
    this constant can be used to define a custom partial root path to overwrite
    individual fluid files as needed.

..  _configuration-typoscript-constants-view-layoutrootpath:

Layout root path
~~~~~~~~~~~~~~~~~

..  confval:: layoutRootPath

    :type: string
    :Default: empty
    :Path: plugin.tx_feeddisplay_pi1.view

    In addition to the default path `EXT:feed_display/Resources/Private/Layouts/`,
    this constant can be used to define a custom layout root path to overwrite
    individual fluid files as needed.

..  _configuration-typoscript-constants-settings:

Settings
--------

The following options are located under the following path:
:typoscript:`plugin.tx_feeddisplay_pi1.settings`

Use these settings if you want to define values globally for all Feed
Display Plugin content elements.

Benutze

..  _configuration-typoscript-constants-settings-feedurl:

Feed URL
~~~~~~~~

..  confval:: feedUrl

    :type: string
    :Default: https://typo3.org/rss
    :Path: plugin.tx_feeddisplay_pi1.settings

    URL that is fetched by the service

..  _configuration-typoscript-constants-settings-maxfeedcount:

Maximum items
~~~~~~~~~~~~~

..  confval:: maxFeedCount

    :type: int
    :Default: 10
    :Path: plugin.tx_feeddisplay_pi1.settings

    Maximum feed items to show

..  _configuration-typoscript-constants-settings-maxcontentlength:

Maximum content length
~~~~~~~~~~~~~~~~~~~~~~

..  confval:: maxContentLength

    :type: int
    :Default: 500
    :Path: plugin.tx_feeddisplay_pi1.settings

    Crop the characters of the feed content to the configured length

..  _configuration-typoscript-constants-settings-maxheaderlength:

Maximum header length
~~~~~~~~~~~~~~~~~~~~~~

..  confval:: maxHeaderLength

    :type: int
    :Default: 80
    :Path: plugin.tx_feeddisplay_pi1.settings

    Crop the characters of the feed header to the configured length

..  _configuration-typoscript-constants-settings-logomaxwidth:

Maximum logo width
~~~~~~~~~~~~~~~~~~

..  confval:: logoMaxWidth

    :type: int
    :Default: empty
    :Path: plugin.tx_feeddisplay_pi1.settings

    Maximum width of the feeds logo image

..  _configuration-typoscript-constants-settings-logomaxheight:

Maximum logo height
~~~~~~~~~~~~~~~~~~~

..  confval:: logoMaxHeight

    :type: int
    :Default: empty
    :Path: plugin.tx_feeddisplay_pi1.settings

    Maximum height of the feeds logo image

..  _configuration-typoscript-constants-settings-feediconmaxwidth:

Maximum feed icon width
~~~~~~~~~~~~~~~~~~~~~~~

..  confval:: feedIconMaxWidth

    :type: int
    :Default: 26
    :Path: plugin.tx_feeddisplay_pi1.settings

    Maximum width of the feeds icon image

..  _configuration-typoscript-constants-settings-feediconmaxheight:

Maximum feed icon height
~~~~~~~~~~~~~~~~~~~~~~~~

..  confval:: feedIconMaxHeight

    :type: int
    :Default: 26
    :Path: plugin.tx_feeddisplay_pi1.settings

    Maximum height of the feeds icon image

..  _configuration-typoscript-constants-settings-dateformat:

Date format
~~~~~~~~~~~

..  confval:: dateFormat

    :type: DateTime
    :Default: %d. %B %Y
    :Path: plugin.tx_feeddisplay_pi1.settings

    Use PHP's `strftime() <https://www.php.net/manual/en/function.strftime.php#refsect1-function.strftime-parameters>`__
    function to define the format for dates.

..  _configuration-typoscript-constants-settings-striptags:

Strip tags
~~~~~~~~~~

..  confval:: stripTags

    :type: boolean
    :Default: 1
    :Path: plugin.tx_feeddisplay_pi1.settings

    Remove HTML tags from the feed content

..  _configuration-typoscript-constants-settings-linktarget:

Link target
~~~~~~~~~~~

..  confval:: linkTarget

    :type: string
    :Default: _blank
    :Path: plugin.tx_feeddisplay_pi1.settings

    Target attribute to define the feed link behavior

    **Possible values:**

    _blank
        Opens in a new window or tab
    _self
        Opens in the same frame as it was clicked
    _parent
        Opens in the parent frame
    _top
        Opens in the full body of the window

..  _configuration-typoscript-constants-settings-errormessage:

Error message
~~~~~~~~~~~~~

..  confval:: errorMessage

    :type: string
    :Default: empty
    :Path: plugin.tx_feeddisplay_pi1.settings

    This message is displayed if no feed items could be fetched

..  _configuration-typoscript-constants-settings-cacheduration:

Cache duration
~~~~~~~~~~~~~~

..  confval:: cacheDuration

    :type: int
    :Default: 3600
    :Path: plugin.tx_feeddisplay_pi1.settings

    Time in seconds in which the data is to be read from the
    cache until the next renewal of the cache. (0 = no cache)

..  _configuration-typoscript-constants-settings-overrideflexformsettingsifempty:

Override Flexform Settings if empty
~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~~

..  confval:: overrideFlexformSettingsIfEmpty

    :type: list of strings, separated by comma
    :Default: feedUrl, maxFeedCount, maxContentLength, maxHeaderLength, logoMaxWidth, logoMaxHeight, feedIconMaxWidth, feedIconMaxHeight, dateFormat, stripTags, linkTarget, errorMessage, cacheDuration, getFields.feed, getFields.items
    :Path: plugin.tx_feeddisplay_pi1.settings

    Comma separated list of settings to be overridden by TypoScript if the
    plugin settings value is either empty or 0.

..  _configuration-typoscript-constants-settings-getfields:

Get Fields
~~~~~~~~~~

..  confval:: getFields

    :type: Array
    :Default: empty
    :Path: plugin.tx_feeddisplay_pi1.settings

    Fields to get from feed and feed items.

    Please see the options below.

..  _configuration-typoscript-constants-settings-getfields-feed:

Get Fields: Feed
""""""""""""""""

..  confval:: getFields.feed

    :type: list of strings, separated by comma
    :Default: author, authors, contributor, contributors, copyright, description, encoding, favicon, items, item_quantity, language, link, links, permalink, title, type, subscribe_url, latitude, longitude, image_height, image_link, image_title, image_url, image_width, all_discovered_feeds, base
    :Path: plugin.tx_feeddisplay_pi1.settings

    Comma-separated list of fields to be read from the feed by SimplePie
    and made available as variables in Fluid.

    Please see the `SimplePie feed reference <http://simplepie.org/api/class-SimplePie.html>`__
    to find out which values can be used here. The Feed Display Extension
    transforms each entry of the list into a getter function name.

    For example `item_quantity` internally becomes `get_item_quantity()`.

    Some SimplePie methods expect parameters. For this reason, up to three
    parameters can be passed via a special syntax. Use the "|" symbol to
    append the parameters to the element name.

    **Example:**

    ..  code-block:: typoscript

        plugin.tx_feeddisplay_pi1.settings.getFields.feed := addToList(channel_tags | http://www.itunes.com/dtds/podcast-1.0.dtd | image)

    This leads to the following SimplePid function call:

    ..  code-block:: php

        get_channel_tags("http://www.itunes.com/dtds/podcast-1.0.dtd", "image");

..  _configuration-typoscript-constants-settings-getfields-items:

Get Fields: Items
"""""""""""""""""

..  confval:: getFields.items

    :type: list of strings, separated by comma
    :Default: author, authors, categories, category, content, contributor, contributors, copyright, date|U, description, enclosure, enclosures, feed, id, link, links, local_date|, permalink, source, title, latitude, longitude, base
    :Path: plugin.tx_feeddisplay_pi1.settings

    Comma-separated list of fields to be read from the feed items by

    Please see the `SimplePie feed item reference <http://simplepie.org/api/class-SimplePie_Item.html>`__
    to find out which values can be used here. The Feed Display Extension
    transforms each entry of the list into a getter function name.

    For example `author` internally becomes `get_author()`.

    As in :ref:`getFields.feed <configuration-typoscript-constants-settings-getfields-feed>`
    up to three parameters are supported.