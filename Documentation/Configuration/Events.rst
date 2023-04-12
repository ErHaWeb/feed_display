..  include:: /Includes.rst.txt
..  highlight:: typoscript
..  index::
    Events
..  _configuration-events:

Events
======

This extension currently provides exactly one PSR-14 event, which can be
used to easily change the data retrieved from a feed. This is especially
useful when the feed is a bit more complex.

Add an event listener
---------------------

To use the event, you must first create an event listener class where you
use your own logic to modify the properties of the SimplePie items.
Finally, these custom properties can be used in Fluid:

..  code-block:: php
    :caption: EXT:sitepackage/Classes/EventListener/SingleFeedDisplayListener.php

    <?php
    declare(strict_types=1);

    namespace VendorName\Sitepackage\EventListener;

    use ErHaWeb\FeedDisplay\Event\SingleFeedDataEvent;

    class SingleFeedDisplayListener
    {
        public function __invoke(SingleFeedDataEvent $event): void
        {
            // Get the array of properties of the current SimplePie item
            $itemProperties = $event->getItemProperties();

            // Get the current SimplePie item
            $feedItem = $event->getItem();

            // Get the extension Settings
            $settings = $event->getSettings();

            // Get the current SimplePie feed
            $feed = $event->getFeed();

            // Make any changes to the item properties here, e.g.:
            foreach (['jobLocation', 'companyLogo', 'companyName'] as $extraField) {
                $tag = $feedItem->get_item_tags('https://schemas.jobteaser.com/xml/joboffer', $extraField);
                if (is_array($tag)) {
                    $itemProperties[$extraField] = trim((string)($tag[0]['data'] ?? ''));
                }
            }

            // Set the item properties
            $event->setItemProperties($itemProperties);
        }
    }

Register the event listener
---------------------------

To register your event listener, simply add the following lines to your
:file:`Configuration/Services.yaml` file:

..  code-block:: yaml
    :caption: EXT:sitepackage/Configuration/Services.yaml

    services:
      VendorName\Sitepackage\EventListener\SingleFeedDisplayListener:
        tags:
          - name: event.listener

Since the above listener implementation has the event type in the method
signature, the :yaml:`event` tag can be omitted. If you don't want to use
the FQCN as identifier for the event listener, you can additionally assign
any identifier via the :yaml:`identifier` tag. For more information on
registering event listeners, see :ref:`t3coreapi:EventDispatcherRegistration`.

..  note::
    Thanks to Georg Ringer for contributing this feature.