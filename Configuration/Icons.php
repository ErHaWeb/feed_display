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

/**
 * Registers Feed Display Icon in the IconRegistry
 */

use TYPO3\CMS\Core\Imaging\IconProvider\SvgIconProvider;

return [
    'feed-display' => [
        'provider' => SvgIconProvider::class,
        'source' => 'EXT:feed_display/Resources/Public/Icons/Extension.svg',
    ],
];
