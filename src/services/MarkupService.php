<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\models\Site;

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
    public static function getMarkupFromElementRelations(string $elementRelations, int $elementId, int $siteId, string $size = 'small'): string
    {
        $element = Craft::$app->elements->getElementById($elementId, null, $siteId);
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

        $result = collect();


        $otherSites = collect($otherSites)->unique()->map(function (int $siteId) {
            return Craft::$app->getSites()->getSiteById($siteId);
        });

        $result->push(Cp::elementPreviewHtml($currentSiteElements->all(), $size, true, false));
        $result = $result->filter();

        $seomaticGlobal = collect($rows)->contains(SeomaticService::IDENTIFIER_SEOMATIC_GLOBAL);
        if ($seomaticGlobal) {
            if ($result->isNotEmpty()) {
                $result->push('<br />');
            }
            $result->push(Craft::t('element-relations', 'field-value-seomatic-global'));
        }

        $result->push(self::sitePreviewHtml($otherSites->all(), $size, $elementId));

        $result = $result->filter();

        if (!$result->count()) {
            $message = sprintf('<span style="color: red;margin-bottom: 6px;display: inline-block;">%s</span>', Craft::t('element-relations', 'field-value-unused'));
            $result->push($message);
        }

        return $result->implode('');
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

    /**
     * @param Site[] $sites
     * @param string $size
     * @param int $elementId
     * @return string
     */
    private static function sitePreviewHtml(array $sites, string $size, int $elementId): string
    {
        $result = '';
        if (!empty($sites)) {
            $result .= '<br />' . Craft::t('element-relations', 'field-value-used-in-these-sites') . ' ';
            $otherElements = collect($sites)->map(function (Site $site) use ($size, $elementId) {
                return self::siteHtml($site, $size, $elementId);
            })->all();
            $result .= Html::tag('span', '+' . count($otherElements), [
                'class' => 'btn small',
                'role' => 'button',
                'onclick' => 'jQuery(this).replaceWith(' . Json::encode('<br />' . implode('', $otherElements)) . ')',
            ]);
        }
        return $result;
    }

    /**
     * @param Site $site
     * @param string $size
     * @param int $elementId
     * @return string
     */
    private static function siteHtml(Site $site, string $size, int $elementId): string
    {
        $element = Craft::$app->elements->getElementById($elementId, null, $site->id);
        return sprintf('
            <div class="element hasstatus %s" title="%s - %s">
              <span class="status %s"></span>
              <div class="label">
                <a href="%s" class="title" style="white-space: nowrap;">%s</a>
              </div>
            </div>
        ', $size, $site->name, $site->handle, $site->enabled ? 'enabled' : 'disabled', $element->getCpEditUrl(), $site->name);
    }
}
