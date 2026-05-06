..  include:: /Includes.rst.txt
..  highlight:: typoscript
..  index::
    TSconfig
..  _configuration-tsconfig:

Page TSconfig
=============

After the extension has been installed, it automatically adds Page TSconfig
from file `EXT:feed_display/Configuration/page.tsconfig` to register the
"Feed Display" content element in the New Content Element Wizard and to assign
the backend preview template.

The following code will be added:

..  code-block:: typoscript

    mod.wizards.newContentElement.wizardItems.plugins {
        elements {
            feeddisplay_pi1 {
                iconIdentifier = feed-display
                title = LLL:EXT:feed_display/Resources/Private/Language/locallang_be.xlf:pi1_title
                description = LLL:EXT:feed_display/Resources/Private/Language/locallang_be.xlf:pi1_plus_wiz_description
                tt_content_defValues {
                    CType = feeddisplay_pi1
                }
            }
        }
    }
    mod.web_layout.tt_content.preview.feeddisplay_pi1 = EXT:feed_display/Resources/Private/Templates/Backend/Preview.html

..  note::

    Older Feed Display versions used `CType = list` and
    `list_type = feeddisplay_pi1`. TYPO3 v13.4 deprecates that subtype model
    and TYPO3 v14 expects the dedicated `CType = feeddisplay_pi1`. See
    :ref:`compatibility` for migration details.
