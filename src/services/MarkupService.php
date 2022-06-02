<?php

namespace internetztube\elementRelations\services;

use Craft;

class MarkupService
{
    /**
     * Converts stringified element relations into html.
     * @param string $elementRelations
     * @param int $elementId
     * @param int $siteId
     * @param string $size
     * @return string
     */
    public static function getMarkupFromElementRelations(string $elementRelations, int $elementId, int $siteId, bool $detail): string
    {
        $element = Craft::$app->elements->getElementById($elementId);
        $supportedSiteIds = $element->getSupportedSites();
        $allSiteIds = Craft::$app->sites->getAllSiteIds();
        $currentAndNotSupportedSites = collect($allSiteIds)->filter(function (int $allSiteId) use ($siteId, $allSiteIds, $supportedSiteIds) {
            return $allSiteId === $siteId || !in_array($allSiteId, $supportedSiteIds);
        })->values()->all();

        $rows = collect(explode(ElementRelationsService::IDENTIFIER_DELIMITER, $elementRelations))
            ->filter()->all();

        $currentSiteElements = collect();
        $otherSites = collect();

        $relatedProfilePictures = self::getRowsByIdentifier($rows, false, ProfilePhotoService::IDENTIFIER_PROFILE_PHOTO_START, ProfilePhotoService::IDENTIFIER_PROFILE_PHOTO_END);
        $currentSiteElements = collect($relatedProfilePictures)->map(function (int $userId) {
            return Craft::$app->getUsers()->getUserById($userId);
        })->merge($currentSiteElements);

        $relationsSeomaticLocal = self::getRowsByIdentifier($rows, true, SeomaticService::IDENTIFIER_SEOMATIC_LOCAL_START, SeomaticService::IDENTIFIER_SEOMATIC_LOCAL_END);
        $currentSiteElements = collect($relationsSeomaticLocal)->whereIn('siteId', $currentAndNotSupportedSites)
            ->map(function ($row) {
                return Craft::$app->elements->getElementById($row['elementId'], null, $row['siteId']);
            })
            ->merge($currentSiteElements);

        $otherSites = collect($relationsSeomaticLocal)->whereNotIn('siteId', $currentAndNotSupportedSites)
            ->pluck('siteId')
            ->merge($otherSites);

        $relatedSimpleElements = self::getRowsByIdentifier($rows, true, ElementRelationsService::IDENTIFIER_ELEMENTS_START, ElementRelationsService::IDENTIFIER_ELEMENTS_END);
        $currentSiteElements = collect($relatedSimpleElements)->whereIn('siteId', $currentAndNotSupportedSites)->map(function ($row) {
            return Craft::$app->elements->getElementById($row['elementId'], null, $row['siteId']);
        })->merge($currentSiteElements)->filter();
        $otherSites = collect($relatedSimpleElements)->whereNotIn('siteId', $currentAndNotSupportedSites)
            ->pluck('siteId')->merge($otherSites);
        $otherSites = collect($otherSites)->unique()->map(function (int $siteId) {
            return Craft::$app->getSites()->getSiteById($siteId);
        });

        return Craft::$app->getView()->renderTemplate(
            'element-relations/_components/fields/result',
            [
                'isUsedInSeomaticGlobal' => collect($rows)->contains(SeomaticService::IDENTIFIER_SEOMATIC_GLOBAL),
                'currentSiteElements' => $currentSiteElements->all(),
                'otherSites' => $otherSites->all(),
                'currentElementId' => $element->id,
                'detail' => $detail,
            ]
        );
    }

    /**
     * @param array $rows
     * @param bool $hasSiteIdInIdentifier
     * @param string $startIdentifier
     * @param string $endIdentifier
     * @return array
     */
    private static function getRowsByIdentifier(array $rows, bool $hasSiteIdInIdentifier, string $startIdentifier, string $endIdentifier): array
    {
        $startPattern = $startIdentifier . '%d';
        $endPattern = $endIdentifier . '%d';

        $resultRows = [];
        $siteId = null;
        $foundStartPattern = false;
        foreach ($rows as $row) {
            list($startSiteId) = sscanf($row, $startPattern);
            list($endSiteId) = sscanf($row, $endPattern);
            if ($hasSiteIdInIdentifier) {
                $isStartRow = !is_null($startSiteId);
                $isEndRow = !is_null($endSiteId);
            } else {
                $isStartRow = $row === $startIdentifier;
                $isEndRow = $row === $endIdentifier;
            }
            if (!$foundStartPattern && $isStartRow) {
                $foundStartPattern = true;
                $siteId = $startSiteId;
                continue;
            }
            if ($isEndRow) {
                $foundStartPattern = false;
                $siteId = null;
                continue;
            }
            if ($foundStartPattern) {
                $resultRow = ['elementId' => (int)$row];
                if ($hasSiteIdInIdentifier) {
                    $resultRow['siteId'] = $siteId;
                }
                $resultRows[] = $resultRow;
            }
        }
        if ($hasSiteIdInIdentifier) {
            return $resultRows;
        }
        return collect($resultRows)->flatten()->all();
    }
}