..  include:: /Includes.rst.txt

..  _introduction:

============
Introduction
============

..  _what-it-does:

What does it do?
================

The aim of this extension is to provide a way to display RSS and Atom web
feed data retrieved from any URL in the frontend. It is possible to configure
which data is to be read from the feed for the purpose of display.

Parsing of feed data is done by the `SimplePie <https://simplepie.org/>`__
library, which is a very fast and easy-to-use feed parser, written in PHP.

The entire result is stored in its own cache (using the caching framework)
so that the feed does not have to be parsed with each call. If something has
changed in the plugin configuration (TypoScript or FlexForm), the cache is
renewed immediately, otherwise only after a configurable time has elapsed.

..  _screenshots:

Screenshots
===========

Here you can find screenshots of all application areas of this extension.

..  _screenshots-frontendview:

Frontend View
-------------

Below you can find an example of the frontend output of the official TYPO3
news feed. Styling and structure can be customized as you like.

..  figure:: /Images/FrontendView.png
    :class: with-shadow
    :alt: Frontend View
    :width: 727px

    Frontend View

..  _screenshots-newcontentelementwizard:

New Content Element Wizard
--------------------------

..  figure:: /Images/NewContentElementWizard.png
    :alt: New Content Element Wizard
    :width: 799px

    New Content Element Wizard

..  _screenshots-pluginsettings:

Plugin Settings
---------------

Below you can find screenshots of all available plugin options. Use these
options if you want to make settings on content element level.
Alternatively, these can also be configured by
:ref:`TypoScript Constants <configuration-typoscript-constants>` in the
constant editor.

..  tabs::

    ..  group-tab:: General

        **Plugin Options: "General" Tab**

        ..  figure:: /Images/PluginOptions-General.png
            :alt: Plugin Options: General Tab
            :width: 720px

            Plugin Options: "General" Tab

    ..  group-tab:: Advanced

        **Plugin Options: "Advanced" Tab**

        ..  figure:: /Images/PluginOptions-Advanced.png
            :alt: Plugin Options: Advanced Tab
            :width: 720px

            Plugin Options: "Advanced" Tab

    ..  group-tab:: Get Fields

        **Plugin Options: "Get Fields" Tab**

        ..  figure:: /Images/PluginOptions-GetFields.png
            :alt: Plugin Options: Get Fields Tab
            :width: 720px

            Plugin Options: "Get Fields" Tab


..  _screenshots-constanteditor:

Constant Editor
---------------

Below you can find screenshots of all available constants in the constant
editor. Use these options if you want to make settings on a global level for
all content elements.

..  figure:: /Images/ConstantEditor-Options.png
    :alt: Constant Editor Feed Display Options
    :width: 534px

    Constant Editor Feed Display Options

..  tabs::

    ..  group-tab:: Files

        **Constant Editor: "Files"**

        ..  figure:: /Images/ConstantEditor-Files.png
            :alt: Constant Editor: "Files"
            :width: 534px

            Constant Editor: "Files"

    ..  group-tab:: General

        **Constant Editor: "General"**

        ..  figure:: /Images/ConstantEditor-General.png
            :alt: Constant Editor: "General"
            :width: 534px

            Constant Editor: "General"

    ..  group-tab:: Advanced

        **Constant Editor: "Advanced"**

        ..  figure:: /Images/ConstantEditor-Advanced.png
            :alt: Constant Editor: "Advanced"
            :width: 534px

            Constant Editor: "Advanced"

    ..  group-tab:: Get Fields

        **Constant Editor: "Fields to get from Feed and Items"**

        ..  figure:: /Images/ConstantEditor-GetFields.png
            :alt: Constant Editor: "Fields to get from Feed and Items"
            :width: 534px

            Constant Editor: "Fields to get from Feed and Items"