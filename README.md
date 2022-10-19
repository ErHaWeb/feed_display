# Feed Display
This Extbase/Fluid based TYPO3 CMS Extension fetches and parses RSS/Atom web feeds and prepares them for frontend display.

The Extension is based on the feed parser library [SimplePie](https://simplepie.org/) ([GitHub](https://github.com/simplepie/simplepie)), which is currently being actively maintained.

The standard parameters can be configured both via TypoScript and in the plugin content element.
A special feature of the extension is that TypoScript can be used to flexibly define which information should be read from the feed. These are then available as variables in the fluid template.

With the constants
```
plugin.tx_feeddisplay_pi1.settings.getFields.feed
plugin.tx_feeddisplay_pi1.settings.getFields.items
```
a comma-separated list of fields to be fetched is specified. Internally, each field is checked to see if a getter method is available.

Example:
```
plugin.tx_feeddisplay_pi1.settings.getFields.feed := addToList(author)
```
executes the `get_author() method in the feed object.

Some of the SimplePie methods expect parameters. For this reason, up to three parameters can be passed using a special syntax.

Example:
```
plugin.tx_feeddisplay_pi1.settings.getFields.feed := addToList(channel_tags | http://www.itunes.com/dtds/podcast-1.0.dtd | image)
```
runs the `get_channel_tags("http://www.itunes.com/dtds/podcast-1.0.dtd", "image")` method on the feed object.

By default I have stored all available fields in the constants (see also the getter methods in the [SimplePie Reference](https://simplepie.org/wiki/reference/)).
However, you can adapt the fields used to the feed and/or template.

The entire result is stored in its own cache (using the caching framework) so that the feed does not have to be parsed with each call.
If something has changed in the plugin configuration (TypoScript or FlexForm), the cache is renewed immediately, otherwise only after a configurable time has elapsed.