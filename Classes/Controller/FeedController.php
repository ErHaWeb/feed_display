<?php

/**
 * This file is part of the "feed_display" Extension for TYPO3 CMS.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

declare(strict_types=1);

namespace ErHaWeb\FeedDisplay\Controller;

use ErHaWeb\FeedDisplay\Service\FeedProvider;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class FeedController extends ActionController
{
    public function __construct(private readonly FeedProvider $feedProvider) {}

    public function displayAction(): ResponseInterface
    {
        if ($this->settings === []) {
            return $this->htmlResponse();
        }

        $this->view->assign('data', $this->feedProvider->getData($this->settings));
        return $this->htmlResponse();
    }
}
