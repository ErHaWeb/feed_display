..  include:: /Includes.rst.txt

..  _configuration-scheduler:

Persistent feeds and scheduled refresh
=====================================

Last successful response
------------------------

Feed Display stores the last successfully parsed document for each source URL
in ``tx_feeddisplay_feed``. It has no expiry and survives every TYPO3 cache
flush. Only a successful fetch replaces it; a valid RSS or Atom feed without
items also counts as success. Download, parse and storage errors preserve the
previous response. Include this table in normal database backups.

The source is shared by plugins using the same URL. Display options and prepared
arrays remain separate per plugin configuration. A refresh by one plugin or the
command updates that shared source for all plugins. The spelling of the URL
matters: redirects do not merge two different configured URLs.

The TYPO3 ``feeddisplay`` cache contains only disposable display data. Flushing
it rebuilds these arrays from the database; it does not force a download or
delete the persistent response. Use ``feeddisplay:refresh`` to force a refresh.
Only scheduler-mode plugins tag their pages for source invalidation. A new
revision saved by a live fetch or the command invalidates those scheduler pages.
Live pages retain their normal TYPO3 cache lifetime and are not invalidated by
feed changes. ``cacheDuration`` does not disable the TYPO3 page cache.

Scheduler pages also carry the source revision actually used during rendering.
After TYPO3 writes the page cache entry, Feed Display compares those revisions
with the database. If a source changed or was removed while the page rendered,
the newly written entry is discarded. The running response can still show the
older content; the next request rebuilds it. Matching revisions keep the page's
normal lifetime. No additional source query runs on a page-cache hit.

Avoid combining an uncached live plugin and a scheduler plugin for the same URL
on one page when the source changes on every fetch. If the scheduler plugin
renders first and the live plugin then stores a new revision, that page contains
two different source versions and is discarded after every rendering. Mixed
mode alone does not disable caching: a live plugin rendered first can update
the source before the scheduler plugin reads the same revision.

A revision read failure is logged and leaves that source unverified; another
source with a confirmed mismatch can still cause the page to be discarded.
Failures removing an obsolete entry are also logged. This check concerns TYPO3's
page cache, not reverse-proxy, CDN or third-party static caches.

Live mode (default)
-------------------

Existing plugins remain in live mode without configuration changes. When the
plugin runs, a positive ``cacheDuration`` limits refresh attempts, including
retries after failure. Zero and negative durations attempt a new fetch on every
uncached rendering, still persist successes and fall back to the last success.
Failures do not change the last successful payload or its success timestamp.
There is no seven-day fallback deadline. If storing a fresh response fails,
positive cache durations retain its prepared display result for that duration.
This is temporary cache protection, not durable storage; zero and negative
durations continue attempting a fetch on each rendering.

Protection starts with the first successful fetch after the update. Existing
3.0.2 display-cache entries remain usable for their original lifetime until a
persistent response exists; incomplete display arrays are never converted to
source documents.

Scheduler mode
--------------

Select ``scheduler`` through the content element's Fetch mode field, Site
Settings, or TypoScript:

..  code-block:: typoscript

    plugin.tx_feeddisplay_pi1.settings.fetchMode = scheduler

In a site's ``settings.yaml`` use YAML syntax:

..  code-block:: yaml

    plugin.tx_feeddisplay_pi1.settings.fetchMode: scheduler

A nonempty FlexForm value overrides inherited configuration. Keep ``fetchMode``
in ``ignoreFlexFormSettingsIfEmpty`` when an empty field should inherit.

If there is no usable stored document, the first frontend request tries to fetch
one. A failed first attempt is retried no more than once every 60 seconds.
Without data, the configured error message is shown and the page's maximum cache
lifetime is 60 seconds. Once a usable response exists, frontend rendering uses
it regardless of age and does not fetch the feed document again. A recurring
job is required to update it.

New documents retain the parser input selected by SimplePie, including response
bytes, headers, MIME type and original source/base URIs. Rebuilding display data
does not repeat autodiscovery and keeps the originally selected feed when a page
offers several.

If a stored document cannot be reconstructed, the frontend attempts repair at
most once every 60 seconds under the same source lock and scheduler HTTP limits.
Failed repair preserves the stored payload. In scheduler mode, ``cacheDuration``
controls only prepared arrays; zero and negative values do not trigger ordinary
frontend downloads.

Run the refresh command
-----------------------

From the deployed installation root:

..  code-block:: bash

    # Composer
    vendor/bin/typo3 feeddisplay:refresh

    # Classic
    php typo3/sysext/core/bin/typo3 feeddisplay:refresh

The command combines non-deleted live-workspace feed plugins' explicit FlexForm
URLs, all stored source rows, and additional repeatable ``--url`` arguments.
Legacy ``list_type = feeddisplay_pi1`` records are included. Hidden records are
included, deleted records and workspace versions are excluded from this scan.
Stored sources are intentionally retained even when no content element uses
them anymore, including failed first attempts. Both live and scheduler sources
are refreshed. There is no usage tracking or automatic pruning.

It does not resolve full page TypoScript. A URL inherited through TypoScript or
Site Settings becomes discoverable after its first frontend attempt, or can be
provided explicitly:

..  code-block:: bash

    vendor/bin/typo3 feeddisplay:refresh --url='https://example.org/rss' --url='https://example.org/atom'

An empty source list succeeds with an explanatory message. ``updated`` means a
new document revision was saved; ``unchanged`` means a successful fetch renewed
freshness without changing the revision. ``locked`` means the source was skipped
because another operation holds its lock. A failed source is diagnosed while
other sources continue; the command exits unsuccessfully if any source fails.

In EXT:scheduler create an **Execute console commands** task for
``feeddisplay:refresh``. No additional extension-specific Scheduler task is
necessary, and EXT:scheduler is optional. Ensure cron actually invokes TYPO3's
Scheduler. With automatic selection, no array option is needed. TYPO3's Scheduler
form splits array options on commas; for URLs containing literal commas use
cron with quoted, repeated ``--url`` arguments instead.

Source types and operational limits
-----------------------------------

HTTP(S) feeds retain TYPO3's HTTP configuration. Live fetches keep SimplePie's
feed autodiscovery. Scheduled fetches, including first frontend fetches in
scheduler mode, read the configured URL directly, so configure the feed itself
for this mode. The selected parser input is stored as binary-safe scalar
data and replayed with its original bytes, selected headers and URL context.
Local RSS/Atom files are also supported;
use absolute paths for jobs and ensure CLI and web PHP see the same filesystem
and run with equivalent read permissions. Relative paths use the process's
working directory; do not share relative sources between different directories.
Run jobs as the application user, never with broader filesystem privileges.

Scheduled HTTP fetches, including first frontend fetches in scheduler mode, limit
recorded response bodies to 2 MiB in total and cap each HTTP request's configured
timeouts at ten seconds. Reading into PHP strings is bounded; the transport may
buffer data first. This is not a guaranteed network-download limit. Redirects
can cause several requests, so ten seconds is not a total source or command
deadline. Guzzle 7's PHP stream timeout can be an idle timeout.
These new limits are not applied to normal live fetches. A large live source can
therefore render successfully but fail in the refresh job. Local sources of
scheduled fetches must be regular files of at most 2 MiB.

Database writes are atomic per response, and failed page invalidation remains
pending for a later retry, including an unchanged refresh. The default TYPO3
locks coordinate processes on one host. Multi-node deployments need a shared
locking strategy and shared page-cache invalidation; the extension does not
introduce a distributed lock service. Replay of the feed document does not
prevent separate HTTP requests by custom getters or event listeners. Feed images
are reused while their file exists in ``typo3temp``. An absent image is downloaded
during rendering, including in scheduler mode. Clearing temporary files can
trigger this download again.

Remove unused sources
---------------------

Only explicitly selected URLs are removed:

..  code-block:: bash

    vendor/bin/typo3 feeddisplay:remove --url='https://example.org/unused.xml'

For Classic use the Classic executable shown above. Repeat ``--url`` for several
sources. The command is intentionally unavailable in Scheduler selections.
It acquires the same source lock, deletes the stored row and invalidates tagged
scheduler pages even when the row is already missing. Repeating it is safe,
including after a failed page flush. A busy lock or failure is reported with a failing exit code.

Remove remaining plugin configuration as well when retiring a source. Otherwise
a later frontend request or refresh scan can recreate it. Manual deletion is
not a permanent exclusion rule. There is no automatic deletion or retention
policy; administrators control when durable data may be removed.
