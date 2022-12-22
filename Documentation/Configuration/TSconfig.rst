..  include:: /Includes.rst.txt
..  highlight:: typoscript
..  index::
    TSconfig
..  _configuration-tsconfig:

Page TSconfig
=============

After the extension has been installed, it automatically adds Page TSconfig
from file `EXT:feed_display/Configuration/page.tsconfig` to register the
"Feed Display" plug-in content element in the New Content Element Wizard.

The following code will be added:

..  code-block:: typoscript

    mod.wizards.newContentElement.wizardItems.plugins {
        elements {
            feeddisplay_pi1 {
                iconIdentifier = feed-display
                title = LLL:EXT:feed_display/Resources/Private/Language/locallang_be.xlf:pi1_title
                description = LLL:EXT:feed_display/Resources/Private/Language/locallang_be.xlf:pi1_plus_wiz_description
                tt_content_defValues {
                    CType = list
                    list_type = feeddisplay_pi1
                }
            }
        }
    }