..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

..  tabs::

    ..  group-tab:: Composer

        **Install the extension with Composer**

        #.  In your command line interface, change to the root directory of your project and enter the following command:

            ..  code-block:: bash

                composer req erhaweb/feed-display

        #.  Apply database changes

            This extension uses the caching framework to cache feed data and
            plugin configuration. For this, the tables `cache_feeddisplay`
            and `cache_feeddisplay_tags` must be created.

            These tables can be created under `Admin Tools` → `Maintenance` → `Analyze Database Structure` → `Apply selected changes`.

            ..  figure:: /Images/Maintenance-AnalyzeDatabaseStructure.png
                :class: with-shadow
                :alt: Maintenance: Analyze Database Structure
                :width: 993px

                Maintenance: Analyze Database Structure

            ..  tip::

                If you have installed the `TYPO3 Console Extension by Helmut Hummel <https://extensions.typo3.org/extension/typo3_console>`__, you can also create the missing tables with the following command:

                ..  code-block:: bash

                    typo3 database:updateschema "*.add,*.change"

    ..  group-tab:: Composer/DDEV

        **Install the extension with Composer in a DDEV environment**

        #.  In your command line interface, change to the root directory of your project and enter the following command:

            ..  code-block:: bash

                ddev composer req erhaweb/feed-display

        #.  Apply database changes

            This extension uses the caching framework to cache feed data and
            plugin configuration. For this, the tables `cache_feeddisplay`
            and `cache_feeddisplay_tags` must be created.

            These tables can be created under `Admin Tools` → `Maintenance` → `Analyze Database Structure` → `Apply selected changes`.

            ..  figure:: /Images/Maintenance-AnalyzeDatabaseStructure.png
                :class: with-shadow
                :alt: Maintenance: Analyze Database Structure
                :width: 993px

                Maintenance: Analyze Database Structure

            ..  tip::

                If you have installed the `TYPO3 Console Extension by Helmut Hummel <https://extensions.typo3.org/extension/typo3_console>`__, you can also create the missing tables with the following command:

                ..  code-block:: bash

                    ddev typo3 database:updateschema "*.add,*.change"

    ..  group-tab:: Classic

        **Install the extension in the classic way**

        #.  Open the TYPO3 backend.

        #.  Go to the Extension Manager under `Admin Tools` → `Extensions`.

        #.  Select `Get Extensions` in the module header.

        #.  Enter the extension key `feed_display` in the search field.

        In the result list click the `Import & Install` button under `Actions`

        ..  figure:: /Images/AdminTools-Extensions-GetExtensions.png
            :alt: The "Get Extensions" dialog
            :width: 782px

            The "Get Extensions" dialog