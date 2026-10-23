<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Support;

use CraftCms\Cms\Asset\Elements\Asset;
use CraftCms\Cms\Cp\Html\ElementHtml;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Site\Sites;
use CraftCms\Cms\Support\Html;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Models\CacheRecord;
use Internetztube\ElementRelations\Models\GlobalUsage;

use function CraftCms\Cms\t;

/** Small presentation helpers for the relations index, built from Craft's own components. */
class ElementSummary
{
    /** "Section › Entry type", "Volume", "Product type" — where the element lives. */
    public static function where(ElementInterface $element): string
    {
        $parts = match (true) {
            $element instanceof Entry => [$element->getSection()?->name, $element->getType()->name],
            $element instanceof Asset => [$element->getVolume()->name],
            $element instanceof \craft\commerce\elements\Product => [$element->getType()->name],
            $element instanceof \craft\commerce\elements\Variant => [$element->getOwner()?->getType()->name],
            default => [],
        };
        return implode(' › ', array_filter(array_map(fn(?string $part): ?string => $part ? t($part, category: 'site') : null, $parts)));
    }

    /**
     * The sites the element uses the listed target in, like the Field column lists the fields (a list spanning every
     * site shows the element once): "English, German". Its own site when nothing is known.
     */
    public static function sitesHtml(ElementInterface $element): string
    {
        $siteIds = array_keys(Usage::$sites[(int)$element->id] ?? []);
        if (!$siteIds) {
            return Html::encode(t((string)$element->getSite()->name, category: 'site'));
        }
        $names = [];
        foreach (app(Sites::class)->getAllSites() as $site) { // in the sites' own order
            if (in_array((int)$site->id, $siteIds, true)) {
                $names[] = Html::encode(t((string)$site->name, category: 'site'));
            }
        }
        return implode(', ', $names);
    }

    /**
     * "Field" column of the index: the field(s) through which `$element` uses the target (see Usage::$via).
     * Relations that don't come from a field (a user photo) get a label instead.
     */
    public static function viaHtml(ElementInterface $element): string
    {
        $vias = Usage::$via[(int)$element->id] ?? []; // the fields of every site it uses the target in
        $parts = [];
        // per field: the disabled blocks (nested elements) it is used in, and whether it is used anywhere live
        $disabled = [];
        $live = [];
        foreach ($vias as $via) {
            if ($via['fieldId']) {
                $via['disabledIn'] !== null ? $disabled[$via['fieldId']][$via['disabledIn']] = true : $live[$via['fieldId']] = true;
            }
        }
        foreach ($vias as $via) {
            $field = $via['fieldId'] ? Fields::getFieldById($via['fieldId']) : null;
            if ($field) {
                $fieldId = (int)$field->id;
                $html = Html::encode(t((string)$field->name, category: 'site'));
                if (isset($disabled[$fieldId])) {
                    $html .= ' ' . Html::tag('span', Html::encode(t(isset($live[$fieldId]) ? 'field-via-also-disabled' : 'field-via-disabled', [
                        'count' => count($disabled[$fieldId]),
                    ], 'element-relations')), ['class' => 'light', 'data-element-relations-disabled' => true]);
                }
                $parts[$fieldId] = $html;
            } elseif ($via['type'] === CacheRecord::TYPE_ELEMENT_USER_PHOTO) {
                $label = t('field-via-user-photo', category: 'element-relations');
                $parts[$label] = Html::tag('span', Html::encode($label), ['class' => 'light']);
            }
        }
        return implode(', ', $parts);
    }

    /**
     * Craft chips for non-element usages (plugin settings), linked to their settings page.
     * @param GlobalUsage[] $usages
     */
    public static function globalUsagesHtml(array $usages): string
    {
        return Html::tag('div', implode('', array_map(
            fn(GlobalUsage $usage): string => app(ElementHtml::class)->chipHtml($usage, ['hyperlink' => true, 'showThumb' => true, 'autoReload' => false]),
            $usages,
        )), ['class' => 'flex flex-wrap gap-xs']);
    }
}
