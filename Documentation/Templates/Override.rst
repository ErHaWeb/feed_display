..  include:: /Includes.rst.txt
..  index:: Templates; Override
..  _templates-override:

..  _overwriteFluidTemplate:

Overriding Templates
====================

`EXT:feed_display` is using Fluid as template engine.

This documentation won't bring you all information about Fluid but only the
most important things you need for using it. You can get
more information in the section :ref:`Fluid templates of the Sitepackage tutorial
<t3sitepackage:fluid-templates>`. A complete reference of Fluid ViewHelpers
provided by TYPO3 can be found in the  :doc:`ViewHelper Reference <t3viewhelper:Index>`

..  index:: Templates; TypoScript

Change the templates using TypoScript constants
-----------------------------------------------

As any Extbase based extension, you can find the templates in the directory
:file:`Resources/Private/`.

By default, the following Fluid template files of this directory are used:

..  code-block:: none
    :caption: Page tree of directory :file:`EXT:feed_display/Resources/Private/`

    .
    ├── Layouts
    │   └── General.html
    ├── Partials
    │   ├── Feed
    │   │   ├── Authors.html
    │   │   ├── Categories.html
    │   │   ├── Contributors.html
    │   │   ├── Copyright.html
    │   │   ├── Description.html
    │   │   ├── Image.html
    │   │   ├── Links.html
    │   │   └── Title.html
    │   ├── Feed.html
    │   ├── Item
    │   │   ├── Authors.html
    │   │   ├── Categories.html
    │   │   ├── Content.html
    │   │   ├── Contributors.html
    │   │   ├── Copyright.html
    │   │   ├── Date.html
    │   │   ├── Enclosures.html
    │   │   ├── Latitude.html
    │   │   ├── Links.html
    │   │   ├── Longitude.html
    │   │   ├── Source.html
    │   │   ├── Title.html
    │   │   └── UpdatedDate.html
    │   └── Item.html
    └── Templates
        └── Feed
            └── Display.html

If you want to change a template, copy the desired files to the directory
where you store the templates.

We suggest that you use a sitepackage extension. Learn how to
:doc:`Create a sitepackage extension <t3sitepackage:Index>`.

..  tip::

    Make sure that you only copy the files for which you want to make changes.
    This has the advantage that the remaining files will be automatically
    updated with future extension updates. This way you only have to worry
    about updating the files you have copied.

In TYPO3 v13/v14 projects using the Site Set, define your own Fluid paths as
Site Settings. Projects using static TypoScript records can still use the
constant editor under the TypoScript module:

* TYPO3 v13: :guilabel:`Site Management` → :guilabel:`TypoScript`
* TYPO3 v14: :guilabel:`Sites` → :guilabel:`TypoScript`

..  figure:: /Images/ConstantEditor-FluidPaths.png
    :alt: Constant Editor: Fluid Template Paths
    :width: 634px

    Constant Editor: Fluid Template Paths

Alternatively you can define the following TypoScript constants directly:

..  code-block:: typoscript

    plugin.tx_feeddisplay_pi1 {
        view {
            templateRootPath = EXT:your_sitepackage/Resources/Private/Templates/FeedDisplay/
            partialRootPath = EXT:your_sitepackage/Resources/Private/Partials/FeedDisplay/
            layoutRootPath = EXT:your_sitepackage/Resources/Private/Layouts/FeedDisplay/
        }
    }
