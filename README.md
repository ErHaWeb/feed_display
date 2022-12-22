# Feed Display

The aim of this extension is to provide a way to display RSS and Atom web
feed data retrieved from any URL in the frontend. It is possible to configure
which data is to be read from the feed for the purpose of display.

Parsing of feed data is done by the [SimplePie](https://simplepie.org/)
library, which is a very fast and easy-to-use feed parser, written in PHP.

The entire result is stored in its own cache (using the caching framework)
so that the feed does not have to be parsed with each call. If something has
changed in the plugin configuration (TypoScript or FlexForm), the cache is
renewed immediately, otherwise only after a configurable time has elapsed.

For more information, see the documentation at [docs.typo3.org](https://docs.typo3.org/p/erhaweb/feed-display/1.2/en-us/).