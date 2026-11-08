..  include:: /Includes.rst.txt

..  _known-problems:

==============
Known problems
==============

Check the effective settings of the affected content element, the TYPO3 log and
the refresh command's output. A feed that opens in a desktop browser can still
fail on the application server. Persistent fallback can conceal an outage.

..  _known-problems-scheduler-empty:

Scheduler mode has no output or shows the error message
=======================================================

Selecting ``fetchMode = scheduler`` does not create a job. With no stored
response, the first frontend rendering attempts a fetch. On failure the plugin
shows its configured ``errorMessage`` or the default error and limits the page
cache to 60 seconds. Further initial attempts are throttled for 60 seconds,
including across cache flushes. A valid stored response has no expiry and
survives every cache flush.

#.  Apply the normal schema update: ``tx_feeddisplay_feed`` must exist. Without
    it, scheduler rendering cannot store a first response. Check the log for
    the schema warning and run ``extension:setup`` in the deployed installation.
#.  Verify the effective ``feedUrl`` and ``fetchMode``, including FlexForm
    overrides. Check that custom templates actually display the error message.
#.  Run the command from the installation root with the same URL:

    ..  code-block:: bash

        # Composer
        vendor/bin/typo3 feeddisplay:refresh --url='https://example.org/feed.xml'

        # Classic
        php typo3/sysext/core/bin/typo3 feeddisplay:refresh --url='https://example.org/feed.xml'

#.  Expect ``updated`` or ``unchanged``. Investigate failures in the command
    output and TYPO3 log, then configure recurring execution. Saving an
    **Execute console commands** task alone does not start TYPO3's Scheduler.

See :ref:`configuration-scheduler` for first-fetch and refresh behavior. A URL
inherited only through TypoScript or Site Settings is not discovered by the
command until its first frontend attempt, unless passed explicitly with
``--url``. It does not resolve whole pages' TypoScript.

A job succeeds but the plugin remains stale
===========================================

*   **Different source spellings:** URL hashes distinguish schemes, host spelling,
    query order, trailing slashes and encoding. Redirects do not merge source
    rows. Use the same trimmed URL in the plugin and command.
*   **Different environments:** CLI and web must use the same database, deployed
    code and filesystem. Do not invoke the extension's test installation by
    mistake. In DDEV run the command inside the web container. Match proxy,
    credentials, DNS and TLS configuration between CLI and web.
*   **Wrong job or inactive cron:** Confirm that Scheduler runs and invokes the
    intended command. A scheduler-mode plugin never refreshes an existing
    usable successful response by itself, regardless of ``cacheDuration``. An
    unusable document is an exception: see the replay guidance below.
*   **Multiple nodes or external caches:** Host-local TYPO3 locks do not
    coordinate nodes. Configure shared locks and page invalidation across the
    deployment. CDNs and reverse proxies may need their own invalidation.
*   **Changing metadata:** A revision includes response bytes and selected
    headers, not only visible items. A changing build date or redirect can
    invalidate scheduler pages even when visible content looks identical. Live
    pages are not tagged and keep their usual lifetime, even with a zero or
    negative feed cache duration.

The refresh command fails, skips sources or has no sources
==========================================================

*   **Command unavailable:** Verify extension activation, complete files and
    rebuilt DI caches. Classic Git checkouts need their library build; TER
    packages already include it. See :ref:`installation`.
*   **No sources found:** An empty set succeeds. The command uses explicit
    FlexForm URLs, stored source rows and additional ``--url`` arguments. It
    excludes deleted records and non-live workspace versions, but includes
    hidden content and legacy feed plugin records. Load inherited URLs once in
    the frontend or provide them explicitly.
*   **Retired or mistyped sources keep failing:** Stored rows, including failed
    first attempts, remain selected indefinitely. Both live and scheduler
    sources are included. Correct the configuration and remove unused rows
    with ``feeddisplay:remove``; nothing is automatically pruned.
*   **Array options and commas:** Repeat quoted ``--url`` options on the command
    line. The Scheduler form splits array options at commas. Automatic source
    selection avoids this form issue; use cron when an explicit URL contains a
    literal comma. Percent-encoding only helps if the source supports it and
    the plugin uses exactly the same spelling.
*   **A source is busy:** A result of ``locked`` means skipped because another
    operation holds its lock. It is not an HTTP failure. Other sources continue.
    Repeated skips suggest overlapping or hanging processes. Check those
    processes before scheduling more frequent runs.
*   **Oversized or slow response:** Scheduled HTTP fetches, including first
    frontend fetches in that mode, cap stored response bodies at 2 MiB in total and
    each HTTP request's timeouts at ten seconds. Redirects can cause several
    requests; this is not a total time or transport download
    limit. Normal live fetches retain their previous limits. A source that
    works live can therefore fail in the job.
*   **HTTP or parse error:** Login pages, bot challenges, invalid XML, redirect
    loops and intermittent 4xx/5xx responses can fail. Live fetches keep HTTP
    feed autodiscovery. Scheduled fetches read the configured URL directly, so
    configure the feed itself for that mode.
*   **Storage, locks or invalidation fail:** Check the concrete exception and URL
    in the TYPO3 log, database permissions/capacity and filesystem permissions.
    Failed writes preserve the previous response; fresh live data can still
    appear in that request. With a positive live ``cacheDuration``, its prepared
    result is cached for that duration despite the write error; a cache flush
    removes this temporary protection. Zero and negative values keep fetching.
    Failed page invalidation remains pending and is retried, including after
    an unchanged refresh.

A mixed run continues after a failed source but returns a failure status overall.
Do not assume that no source updated just because the task is marked failed.

..  _known-problems-no-feed-items-displayed:

No feed items are displayed
===========================

*   **No successful response:** The configured error message is shown when no
    usable source exists. Live mode can continue using its display cache while
    the new schema is missing, but permanent protection requires the table.
*   **The feed is empty:** A valid RSS/Atom feed without items is a successful
    replacement, not an error. The no-items message can appear instead of the
    fetch-error message.
*   **Server access differs from browser access:** Check application-server DNS,
    firewall, proxy, authentication and certificate trust through TYPO3's HTTP
    configuration. Disabling TLS verification is not a correction for missing
    certificates.
*   **Local file inaccessible:** Use a path visible inside the PHP container,
    including readable symlink targets. For jobs use absolute paths and the
    application user with equivalent read permissions. Relative paths depend
    on the process's working directory and can point to different files in CLI.
*   **Customization suppresses output:** Check getter fields, ``maxFeedCount``,
    templates, conditions and ``SingleFeedDataEvent`` listeners. Listener errors
    propagate normally; they are not disguised as feed download errors.
*   **Broken stored document:** A valid prepared display-cache hit remains usable
    without reading the BLOB. On a miss, checksum, structure and header errors
    are diagnosed. New documents restore the selected parser input without
    repeating discovery. If reconstruction fails, the
    frontend attempts repair no more than once every 60 seconds; failed repair
    keeps the payload and success time. A manual refresh can replace the document
    immediately. No SimplePie PHP objects are persisted.

Images trigger requests
=======================

Feed images are downloaded only when their temporary file is missing. Rendering
can therefore still make an image HTTP request in scheduler mode, after a
temporary-file cleanup or when an image is used for the first time. Custom
getters and event listeners may also make their own requests.

The plugin is missing or displays the example feed
==================================================

*   Include the ``erhaweb/feed-display`` Site Set in the active site's
    dependencies, or static TypoScript for a template-record setup. Check the
    actual site's rootline. See :ref:`configuration-site-set`.
*   Replace the default ``https://typo3.org/rss`` URL in the relevant settings.
    A saved FlexForm can override the value you changed site-wide.
*   Check content/page visibility, language, access restrictions and the rendered
    column. These can prevent plugin execution entirely.
*   For legacy ``CType = list`` / ``list_type = feeddisplay_pi1`` records, run
    ``feedDisplayCTypeMigration`` when required. The current content type is
    ``feeddisplay_pi1``. Also check backend-group permissions for editors.

Settings seem ignored
======================

*   Inspect the content element's FlexForm as well as Site Settings and later
    TypoScript assignments. Use YAML ``fetchMode: scheduler`` in site settings
    and TypoScript ``fetchMode = scheduler`` in setup, with the full plugin path.
*   ``ignoreFlexFormSettingsIfEmpty`` belongs to the plugin, not ``settings``.
    Keep ``fetchMode`` in that list for inheritance. An unignored empty or
    unknown mode uses live behavior.
*   The default ignore list can treat FlexForm ``0`` as inherited. To explicitly
    select zero, adjust that setting's inheritance rule or the inherited value.
*   ``getFields.feed`` and ``getFields.items`` select SimplePie getters. Check
    exact names, supported arguments and the data structure expected by custom
    templates. Cropping/HTML settings affect display, not the stored document.

Content is stale or changes at unexpected times
================================================

*   A positive live ``cacheDuration`` limits attempts per shared source,
    including after failures. Different plugin options do not keep independent
    old copies of the feed. A plugin with a shorter interval can update all
    plugins of that URL.
*   Cache flushes rebuild display arrays from the persistent source without
    forcing a download. Use ``feeddisplay:refresh`` to force an attempt. The
    last successful response has no expiry; monitor failures in logs/jobs.
*   ``cacheDuration = 0`` or a negative value does not disable TYPO3 page caching.
    A cached page may avoid plugin execution altogether. Live page-cache
    lifetimes remain unchanged; live pages are not invalidated by source changes.
    Only scheduler pages carry source tags. A successful changed revision from
    either a live plugin or the job invalidates those scheduler pages.
*   A scheduler page whose source changes or is removed during rendering is
    discarded immediately after TYPO3 writes its cache entry. The running
    response can still contain the older revision; the next request rebuilds it.
    If checking a source revision fails, the warning identifies its hash and the
    page-cache identifier. That source remains unverified. A failed removal is
    diagnosed as well. Check database/cache availability; reverse-proxy, CDN
    and third-party static caches require their own handling.
*   Mixed live/scheduler plugins of the same URL can prevent effective page
    caching. In particular, if a scheduler plugin renders before a live plugin
    with ``cacheDuration <= 0`` and every fetch changes the source revision,
    every rendering creates an inconsistent page that is discarded. Use one
    mode for that source on the page. Mixed mode alone is not sufficient to
    cause this: live first, followed by scheduler using the same revision, can
    remain cacheable.
*   Existing 3.0.2 display entries remain valid for their original lifetime until
    the first durable response exists. They cannot protect against a full
    cache flush before that first successful fetch.
*   Removing a source does not disable configured plugins. Another frontend
    attempt or refresh scan can recreate it. Remove its configuration as well
    when retiring it. ``feeddisplay:remove`` requires explicit URLs, is safe to
    repeat and reports lock conflicts or failed invalidation for manual retry.

If a problem remains, report its effective configuration, TYPO3/PHP versions,
command output and relevant log entry in the
`issue tracker <https://github.com/ErHaWeb/feed_display/issues>`__.
