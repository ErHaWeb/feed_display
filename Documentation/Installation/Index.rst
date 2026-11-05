..  include:: /Includes.rst.txt

..  _installation:

============
Installation
============

Feed Display supports TYPO3 v13 and v14. Composer installation is recommended.
See :ref:`compatibility` for the exact TYPO3/PHP version range and for renamed
TYPO3 v14 backend module paths.

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

            These tables can be created in the Maintenance module under
            :guilabel:`Analyze Database Structure` → :guilabel:`Apply selected changes`.

            * TYPO3 v13: :guilabel:`Admin Tools` → :guilabel:`Maintenance`
            * TYPO3 v14: :guilabel:`System` → :guilabel:`Maintenance`

            ..  figure:: /Images/Maintenance-AnalyzeDatabaseStructure.png
                :class: with-shadow
                :alt: Maintenance: Analyze Database Structure
                :width: 993px

                Maintenance: Analyze Database Structure

    ..  group-tab:: Composer/DDEV

        **Install the extension with Composer in a DDEV environment**

        #.  In your command line interface, change to the root directory of your project and enter the following command:

            ..  code-block:: bash

                ddev composer req erhaweb/feed-display

        #.  Apply database changes

            This extension uses the caching framework to cache feed data and
            plugin configuration. For this, the tables `cache_feeddisplay`
            and `cache_feeddisplay_tags` must be created.

            These tables can be created in the Maintenance module under
            :guilabel:`Analyze Database Structure` → :guilabel:`Apply selected changes`.

            * TYPO3 v13: :guilabel:`Admin Tools` → :guilabel:`Maintenance`
            * TYPO3 v14: :guilabel:`System` → :guilabel:`Maintenance`

            ..  figure:: /Images/Maintenance-AnalyzeDatabaseStructure.png
                :class: with-shadow
                :alt: Maintenance: Analyze Database Structure
                :width: 993px

                Maintenance: Analyze Database Structure

    ..  group-tab:: Classic

        **Install the extension in the classic way**

        #.  Open the TYPO3 backend.

        #.  Go to the Extension Manager.

            * TYPO3 v13: :guilabel:`Admin Tools` → :guilabel:`Extensions`
            * TYPO3 v14: :guilabel:`System` → :guilabel:`Extensions`

        #.  Select `Get Extensions` in the module header.

        #.  Enter the extension key `feed_display` in the search field.

        In the result list click the `Import & Install` button under `Actions`

        ..  figure:: /Images/AdminTools-Extensions-GetExtensions.png
            :alt: The "Get Extensions" dialog
            :width: 782px

            The "Get Extensions" dialog

        The TER package includes the complete SimplePie library bundle. No
        Composer command is required on the target server. TYPO3 v14 loads its
        autoloader through ``providesPackages``; TYPO3 v13 registers the bundled
        classes through the ``autoload`` section of ``ext_emconf.php``.

Classic Git checkouts and source archives
=======================================

Git checkouts and GitHub source archives contain the bundle configuration and
lockfile, but not the generated library files. Before activating such a checkout
in a Classic installation, run this command in the extension directory:

..  code-block:: bash

    composer build-extension

For local DDEV development, execute the command inside the web container. Build
the bundle before clearing the TYPO3 caches or activating the extension.

If the Classic Git checkout was already active when its package metadata
changed, run ``php typo3/sysext/core/bin/typo3 dumpautoload`` from the TYPO3
installation root and clear the TYPO3 caches. The Extension Manager performs
this autoload refresh automatically during normal extension activation.

Composer installations obtain SimplePie through their normal project dependency
and do not require this additional build. Classic installations receive library
updates with a new extension release; the extension never downloads libraries
during a request.
