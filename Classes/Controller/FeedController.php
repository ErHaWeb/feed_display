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
 * Controller for feed display plugin
 */

namespace ErHaWeb\FeedDisplay\Controller;

use ErHaWeb\FeedDisplay\Utility\TypoScript;
use Psr\Http\Message\ResponseInterface;
use SimplePie\SimplePie;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\TypoScript\TypoScriptService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

class FeedController extends ActionController
{
    /**
     * @var FrontendInterface
     */
    private FrontendInterface $cache;

    /**
     * @var SimplePie
     */
    private SimplePie $feed;

    public function __construct(FrontendInterface $cache, SimplePie $feed)
    {
        $this->cache = $cache;
        $this->feed = $feed;
    }

    /**
     * Initializes the current action
     *
     * @return void
     */
    public function initializeAction(): void
    {
        $this->buildSettings();
    }

    /**
     * @return ResponseInterface
     */
    public function displayAction(): ResponseInterface
    {
        if ($this->settings) {
            $cacheIdentifier = "feeddisplay";
            $data = $this->cache->get($cacheIdentifier);
            $cacheDuration = (int)$this->settings['cacheDuration'];

            if ($cacheDuration === 0) {
                $data = $this->getFeedData();
                $this->cache->remove($cacheIdentifier);
            } else if ($data === false || $data['settings'] !== $this->settings) {
                $data = $this->getFeedData();
                $this->cache->set($cacheIdentifier, $data, [], $cacheDuration);
            }

            $this->view->assign('data', $data);
        }
        return $this->htmlResponse();
    }

    /**
     * @return array
     */
    private function getFeedData(): array
    {
        $data = [];
        $data['settings'] = $this->settings;

        if ($this->initFeed()) {
            $getFeedFields = GeneralUtility::trimExplode(',', $this->settings['getFields']['feed']);
            $data['feed']['subscribeUrl'] = $this->feed->subscribe_url();

            foreach ($getFeedFields as $getFeedField) {
                $fieldParts = GeneralUtility::trimExplode('|', $getFeedField);
                $field = GeneralUtility::underscoredToLowerCamelCase($fieldParts[0]);
                $value = $this->getValue($this->feed, $fieldParts);

                if ($getFeedField === 'image_url') {
                    $data['feed']['image'] = $this->getImage($value);
                }

                $data['feed'][$field] = $value;
            }


            $maxFeedCount = (int)($this->settings['maxFeedCount'] ?? 0);
            foreach ($this->feed->get_items(0, $maxFeedCount) as $item) {
                $getItemsFields = GeneralUtility::trimExplode(',', $this->settings['getFields']['items']);
                $itemProperties = [];

                foreach ($getItemsFields as $getItemsField) {
                    if ($getItemsField) {
                        $fieldParts = GeneralUtility::trimExplode('|', $getItemsField);
                        $field = GeneralUtility::underscoredToLowerCamelCase($fieldParts[0]);
                        $value = $this->getValue($item, $fieldParts);
                        $itemProperties[$field] = $value;
                    }
                }

                if ($itemProperties) {
                    $data['items'][] = $itemProperties;
                }
            }
        }
        return $data;
    }

    /**
     * @param object $object
     * @param array $fieldParts
     * @return mixed
     */
    private function getValue(object $object, array $fieldParts)
    {
        $getMethod = 'get_' . $fieldParts[0];
        $value = '';

        if (method_exists($object, $getMethod)) {
            switch (count($fieldParts)) {
                case 1:
                    $value = $object->$getMethod();
                    break;
                case 2:
                    $value = $object->$getMethod($fieldParts[1]);
                    break;
                case 3:
                    $value = $object->$getMethod($fieldParts[1], $fieldParts[2]);
                    break;
                case 4:
                    $value = $object->$getMethod($fieldParts[1], $fieldParts[2], $fieldParts[3]);
                    break;
            }
        }
        return $value;
    }


    /**
     * @param $fileUrl
     * @return string|null
     */
    private function getImage($fileUrl): ?string
    {
        if ($fileUrl) {
            $urlParts = parse_url($fileUrl);
            $pathParts = pathinfo($urlParts['path']);

            $fileName = $pathParts['filename'];
            $fileExtension = $pathParts['extension'];
            $temporaryFileName = md5($fileName) . '.' . $fileExtension;
            $content = GeneralUtility::getUrl($fileUrl);

            if ($content !== false) {
                $tempFilePath = 'typo3temp/assets/images/' . $temporaryFileName;
                if (!@is_file(Environment::getPublicPath() . '/' . $tempFilePath)) {
                    GeneralUtility::writeFileToTypo3tempDir(Environment::getPublicPath() . '/' . $tempFilePath, $content);
                }
                return $tempFilePath;
            }
        }

        return null;
    }

    /**
     * @return bool
     */
    private function initFeed(): bool
    {
        $feedUrl = $this->settings['feedUrl'] ?? '';
        if (isset($feedUrl) && $feedUrl !== '') {
            $feedUrl = stripslashes($feedUrl);
            $this->feed->set_feed_url($feedUrl);
            $this->feed->enable_cache(false);
            $this->feed->init();
            return true;
        }
        return false;
    }

    /**
     * @return void
     */
    private function buildSettings(): void
    {
        $fullTypoScriptSettings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT
        );
        if (!($fullTypoScriptSettings['plugin.']['tx_feeddisplay_pi1.'] ?? null)) {
            $this->settings = [];
            return;
        }
        $tsSettings = ($fullTypoScriptSettings['plugin.']['tx_feeddisplay_pi1.'])['settings.'];

        $originalSettings = $this->configurationManager->getConfiguration(
            ConfigurationManagerInterface::CONFIGURATION_TYPE_SETTINGS
        );

        // Use stdWrap for given defined settings
        if (isset($originalSettings['useStdWrap']) && !empty($originalSettings['useStdWrap'])) {
            $typoScriptService = GeneralUtility::makeInstance(TypoScriptService::class);
            $typoScriptArray = $typoScriptService->convertPlainArrayToTypoScriptArray($originalSettings);
            $stdWrapProperties = GeneralUtility::trimExplode(',', $originalSettings['useStdWrap'], true);
            foreach ($stdWrapProperties as $key) {
                if (is_array($typoScriptArray[$key . '.']) && $this->configurationManager->getContentObject()) {
                    $originalSettings[$key] = $this->configurationManager->getContentObject()->stdWrap(
                        $typoScriptArray[$key] ?? '',
                        $typoScriptArray[$key . '.']
                    );
                }
            }
        }

        // start override
        if (isset($tsSettings['overrideFlexformSettingsIfEmpty'])) {
            $typoScriptUtility = GeneralUtility::makeInstance(TypoScript::class);
            $originalSettings = $typoScriptUtility->override($originalSettings, $tsSettings);
        }

        foreach (($GLOBALS['TYPO3_CONF_VARS']['EXT']['feed_display']['Controller/FeedController.php']['overrideSettings'] ?? []) as $_funcRef) {
            $_params = [
                'originalSettings' => $originalSettings,
                'tsSettings' => $tsSettings,
            ];
            $originalSettings = GeneralUtility::callUserFunction($_funcRef, $_params, $this);
        }

        $this->settings = $originalSettings;
    }
}
