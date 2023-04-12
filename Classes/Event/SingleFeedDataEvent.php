<?php

namespace ErHaWeb\FeedDisplay\Event;

use SimplePie\Item;
use SimplePie\SimplePie;

final class SingleFeedDataEvent
{

    protected array $itemProperties;
    protected Item $item;
    protected array $settings;
    protected SimplePie $feed;

    public function __construct(array $itemProperties, Item $item, array $settings, SimplePie $feed)
    {
        $this->itemProperties = $itemProperties;
        $this->item = $item;
        $this->settings = $settings;
        $this->feed = $feed;
    }

    /**
     * @return array
     */
    public function getItemProperties(): array
    {
        return $this->itemProperties;
    }

    /**
     * @param array $itemProperties
     */
    public function setItemProperties(array $itemProperties): void
    {
        $this->itemProperties = $itemProperties;
    }

    /**
     * @return Item
     */
    public function getItem(): Item
    {
        return $this->item;
    }

    /**
     * @param Item $item
     */
    public function setItem(Item $item): void
    {
        $this->item = $item;
    }

    /**
     * @return array
     */
    public function getSettings(): array
    {
        return $this->settings;
    }

    /**
     * @param array $settings
     */
    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    /**
     * @return SimplePie
     */
    public function getFeed(): SimplePie
    {
        return $this->feed;
    }

    /**
     * @param SimplePie $feed
     */
    public function setFeed(SimplePie $feed): void
    {
        $this->feed = $feed;
    }


}
