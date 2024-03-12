<?php

namespace ErHaWeb\FeedDisplay\Utility;

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

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * TypoScript Utility class
 */
class TypoScript
{
    public function override(array $base, array $overload): array
    {
        $overload = $this->removeDotsFromArrayKeys($overload);
        $configuration = $overload['overrideFlexformSettingsIfEmpty'] ?? '';
        $validFields = GeneralUtility::trimExplode(',', $configuration, true);
        foreach ($validFields as $fieldName) {

            // Multilevel field
            if (str_contains($fieldName, '.')) {
                $keyAsArray = explode('.', $fieldName);

                $foundInOriginal = $this->getValue($base, $keyAsArray);
                if ($foundInOriginal) {
                    $base = $this->setValue($base, $keyAsArray, $foundInOriginal);
                } else {
                    $foundInCurrentTs = $this->getValue($overload, $keyAsArray);
                    if ($foundInCurrentTs) {
                        $base = $this->setValue($base, $keyAsArray, $foundInCurrentTs);
                    }
                }
            } else if (((!isset($base[$fieldName]) || $base[$fieldName] === '0') || ($base[$fieldName] === ''))
                && isset($overload[$fieldName])
            ) {
                $base[$fieldName] = $overload[$fieldName];
            }
        }
        return $base;
    }

    public function convertPlainArrayToTypoScriptArray(array $plainArray): array
    {
        $typoScriptArray = [];
        foreach ($plainArray as $key => $value) {
            if (is_array($value)) {
                if (isset($value['_typoScriptNodeValue'])) {
                    $typoScriptArray[$key] = $value['_typoScriptNodeValue'];
                    unset($value['_typoScriptNodeValue']);
                }
                $typoScriptArray[$key . '.'] = $this->convertPlainArrayToTypoScriptArray($value);
            } else {
                $typoScriptArray[$key] = $value ?? '';
            }
        }
        return $typoScriptArray;
    }

    protected function getValue(mixed $data, mixed $path): mixed
    {
        $found = true;

        for ($x = 0; ($x < count($path) && $found); $x++) {
            $key = $path[$x];

            if (isset($data[$key])) {
                $data = $data[$key];
            } else {
                $found = false;
            }
        }

        if ($found) {
            return $data;
        }
        return null;
    }

    protected function setValue(array $array, array $path, mixed $value): array
    {
        $this->setValueByReference($array, $path, $value);

        return array_merge_recursive([], $array);
    }

    private function setValueByReference(array &$array, array $path, $value): void
    {
        while (count($path) > 1) {
            $key = array_shift($path);
            if (!isset($array[$key])) {
                $array[$key] = [];
            }
            $array = &$array[$key];
        }

        $key = reset($path);
        $array[$key] = $value;
    }

    protected function removeDotsFromArrayKeys(array $array): array
    {
        $newArray = [];
        foreach ($array as $key => $value) {
            $newArray[rtrim($key, '.')] = $value;
        }
        return $newArray;
    }
}
