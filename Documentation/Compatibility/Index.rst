..  include:: /Includes.rst.txt

..  _compatibility:

=============
Compatibility
=============

Feed Display is maintained for TYPO3 v13 and TYPO3 v14. The local package
metadata is the authoritative source for the supported version range.

..  list-table::
    :header-rows: 1

    *   - Requirement
        - Composer package
        - TER metadata
    *   - TYPO3
        - `^13.4 || ^14.3`
        - `13.4.0-14.3.99`
    *   - PHP
        - `>=8.2 <8.6`
        - `8.2.0-8.5.99`

TYPO3 v13 and v14 use the same extension runtime code. The frontend plugin,
TypoScript settings, Site Set, FlexForm settings, PSR-14 event and Fluid
templates are available in both supported TYPO3 major versions.

Backend module names
====================

TYPO3 v14 renamed and regrouped several backend modules. Feed Display keeps
the same feature set, but the navigation paths used in this manual differ by
TYPO3 version.

..  list-table::
    :header-rows: 1

    *   - Task
        - TYPO3 v13
        - TYPO3 v14
    *   - Assign the Site Set
        - :guilabel:`Site Management` → :guilabel:`Sites`
        - :guilabel:`Sites` → :guilabel:`Setup`
    *   - Inspect or edit TypoScript records
        - :guilabel:`Site Management` → :guilabel:`TypoScript`
        - :guilabel:`Sites` → :guilabel:`TypoScript`
    *   - Create or edit the Feed Display content element
        - :guilabel:`Web` → :guilabel:`Page`
        - :guilabel:`Content` → :guilabel:`Layout`
    *   - Compare and update database schema
        - :guilabel:`Admin Tools` → :guilabel:`Maintenance`
        - :guilabel:`System` → :guilabel:`Maintenance`
    *   - Run upgrade wizards
        - :guilabel:`Admin Tools` → :guilabel:`Upgrade`
        - :guilabel:`System` → :guilabel:`Upgrade`
    *   - View or install extensions in classic mode
        - :guilabel:`Admin Tools` → :guilabel:`Extensions`
        - :guilabel:`System` → :guilabel:`Extensions`

Site Set and static TypoScript
==============================

For TYPO3 v13 and v14, the recommended integration is the Site Set
`erhaweb/feed-display`. It ships the extension TypoScript and typed Site
Settings from `Configuration/Sets/FeedDisplay/`. See
:ref:`configuration-site-set` for setup details.

The extension still registers a static TypoScript include for installations
that use TypoScript template records. This is a compatibility fallback for
migrated projects that have not yet moved their configuration to Site Sets.

Plugin content element migration
================================

Older Feed Display versions registered the plugin as an Extbase plugin subtype:

..  code-block:: typoscript

    CType = list
    list_type = feeddisplay_pi1

TYPO3 v13.4 deprecates this subtype model and TYPO3 v14 requires plugins to be
registered as dedicated content element types. Feed Display therefore now uses:

..  code-block:: typoscript

    CType = feeddisplay_pi1

The extension provides the upgrade wizard `feedDisplayCTypeMigration`. Run it
after updating the extension in projects that already contain Feed Display
content elements. The wizard migrates existing `tt_content` records and backend
group permissions from the old `list_type` registration to the new `CType`.
The Upgrade module and CLI commands are available when TYPO3's Install system
extension is installed.

The Page TSconfig preview path changed accordingly:

..  list-table::
    :header-rows: 1

    *   - TYPO3 area
        - Old value
        - New value
    *   - Content element type
        - `CType = list`, `list_type = feeddisplay_pi1`
        - `CType = feeddisplay_pi1`
    *   - Backend preview TSconfig
        - `mod.web_layout.tt_content.preview.list.feeddisplay_pi1`
        - `mod.web_layout.tt_content.preview.feeddisplay_pi1`

Project-specific Page TSconfig or migration scripts that referenced the old
`list_type` must be adjusted to the new `CType` value.

Version-specific implementation notes
=====================================

The code intentionally contains two TYPO3 version compatibility guards:

* TYPO3 v14 can register a FlexForm directly with
  `ExtensionUtility::registerPlugin()`, while TYPO3 v13 still needs
  `ExtensionManagementUtility::addPiFlexFormValue()` for the same dedicated
  `CType`. This guard avoids the TYPO3 v14 deprecation while keeping TYPO3 v13
  fully supported.
* The list-type-to-CType upgrade helper moved from the Install namespace in
  TYPO3 v13 to the Core namespace in TYPO3 v14. The local abstract upgrade base
  chooses the available parent class at runtime.

Date formatting uses formats compatible with PHP's
`DateTimeInterface::format()`. Existing project configuration using old
`strftime()` style percent formats should be changed, for example from
`%d. %B %Y` to `d. F Y`.
