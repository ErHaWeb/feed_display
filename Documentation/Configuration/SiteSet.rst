..  include:: /Includes.rst.txt
..  index::
    Site Sets
    Site Settings
..  _configuration-site-set:

======================
Site Set and settings
======================

The recommended TYPO3 v13/v14 integration is the Site Set
`erhaweb/feed-display`. Assign it to your site configuration to load the
extension TypoScript and make the typed Site Settings available.

Backend path:

..  list-table::
    :header-rows: 1

    *   - TYPO3 v13
        - TYPO3 v14
    *   - :guilabel:`Site Management` → :guilabel:`Sites`
        - :guilabel:`Sites` → :guilabel:`Setup`

The Site Set files live in `EXT:feed_display/Configuration/Sets/FeedDisplay/`.
Project-specific settings are stored by TYPO3 in
`config/sites/<site-identifier>/settings.yaml`.

Example
=======

..  code-block:: yaml
    :caption: config/sites/<site-identifier>/settings.yaml

    plugin.tx_feeddisplay_pi1.settings.feedUrl: 'https://typo3.org/rss'
    plugin.tx_feeddisplay_pi1.settings.maxFeedCount: 10
    plugin.tx_feeddisplay_pi1.settings.dateFormat: 'd. F Y'

Static TypoScript fallback
==========================

The extension still ships `EXT:feed_display/Configuration/TypoScript/` for
projects that use TypoScript template records. Prefer the Site Set in new TYPO3
v13/v14 projects and avoid mixing both approaches unless the TypoScript record
is deliberately used for project-specific overrides.
