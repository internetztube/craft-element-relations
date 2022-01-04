<?php

namespace internetztube\elementRelations\services;

use Craft;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\models\Site;

class MarkupService
{
    public static function getMarkupFromElementRelations(
        string $elementRelations,
        int $elementId,
        int $siteId,
        string $size = 'small'
    ): string
    {
        $rows = collect(explode(ElementRelationsService::IDENTIFIER_DELIMITER, $elementRelations))
            ->filter()->all();

        $currentSiteElements = collect();
        $otherSites = collect();

        $relatedProfilePictures = self::getRowsByIdentifier($rows, false, ElementRelationsService::IDENTIFIER_PROFILE_PICTURE_START, ElementRelationsService::IDENTIFIER_PROFILE_PICTURE_END);
        $currentSiteElements = collect($relatedProfilePictures)->map(function (int $userId) {
            return Craft::$app->getUsers()->getUserById($userId);
        })->merge($currentSiteElements);

        $relationsSeomaticLocal = self::getRowsByIdentifier($rows, true, ElementRelationsService::IDENTIFIER_SEOMATIC_LOCAL_START, ElementRelationsService::IDENTIFIER_SEOMATIC_LOCAL_END);
        $currentSiteElements = collect($relationsSeomaticLocal)->where('siteId', $siteId)
            ->map(function ($row) {
                return Craft::$app->elements->getElementById($row['elementId'], null, $row['siteId']);
            })
            ->merge($currentSiteElements);

        $otherSites = collect($relationsSeomaticLocal)->where('siteId', '!=', $siteId)
            ->pluck('siteId')
            ->merge($otherSites);

        $relatedSimpleElements = self::getRowsByIdentifier($rows, true, ElementRelationsService::IDENTIFIER_ELEMENTS_START, ElementRelationsService::IDENTIFIER_ELEMENTS_END);
        $currentSiteElements = collect($relatedSimpleElements)->where('siteId', $siteId)->map(function ($row) {
            return Craft::$app->elements->getElementById($row['elementId'], null, $row['siteId']);
        })->merge($currentSiteElements);
        $otherSites = collect($relatedSimpleElements)->where('siteId', '!=', $siteId)
            ->pluck('siteId')->merge($otherSites);

        $result = collect();
        $seomaticGlobal = collect($rows)->contains(ElementRelationsService::IDENTIFIER_SEOMATIC_GLOBAL);
        if ($seomaticGlobal) {
            $result->push('Used in SEOmatic Global Settings');
        }

        $otherSites = collect($otherSites)->unique()->map(function (int $siteId) {
            return Craft::$app->getSites()->getSiteById($siteId);
        });

        $result->push(Cp::elementPreviewHtml($currentSiteElements->all(), $size, true, false));
        $result->push(self::sitePreviewHtml($otherSites->all(), $size, $elementId));

        $result = $result->filter();

        if (!$result->count()) {
            $result->push('<span style="color: red">Unused</span>');
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
    private static function getRowsByIdentifier(array $rows, bool $hasSiteIdInIdentifier, string $startIdentifier, string $endIdentifier)
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
            $result .= '<br />Used in these sites: ';
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