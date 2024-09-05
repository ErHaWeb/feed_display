<?php

namespace ErHaWeb\FeedDisplay\Event;

use SimplePie\Item;
use SimplePie\SimplePie;

final class SingleFeedDataEvent
{
    public function __construct(
        protected array $itemProperties,
        protected Item $item,
        protected array $settings,
        protected SimplePie $feed
    ) {}

    public function getItemProperties(): array
    {
        return $this->itemProperties;
    }

    public function setItemProperties(array $itemProperties): void
    {
        $this->itemProperties = $itemProperties;
    }

    public function getItem(): Item
    {
        return $this->item;
    }

    public function setItem(Item $item): void
    {
        $this->item = $item;
    }

    public function getSettings(): array
    {
        return $this->settings;
    }

    public function setSettings(array $settings): void
    {
        $this->settings = $settings;
    }

    public function getFeed(): SimplePie
    {
        return $this->feed;
    }

    public function setFeed(SimplePie $feed): void
    {
        $this->feed = $feed;
    }
}
