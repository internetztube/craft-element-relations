<?php

namespace internetztube\elementRelations\helpers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craft\helpers\Cp;
use craft\helpers\Html;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\models\GlobalUsage;

/**
 * Small presentation helpers for the relations index, built from Craft's own components.
 */
class ElementSummary
{
    /** "Section › Entry type", "Volume", "Category group", ... — where the element lives. */
    public static function where(ElementInterface $element): string
    {
        $parts = match (true) {
            $element instanceof Entry => [$element->getSection()?->name, $element->getType()->name],
            $element instanceof Asset => [$element->getVolume()->name],
            $element instanceof Category, $element instanceof Tag => [$element->getGroup()->name],
            $element instanceof \craft\commerce\elements\Product => [$element->getType()->name],
            $element instanceof \craft\commerce\elements\Variant => [$element->getOwner()?->getType()->name],
            default => [],
        };
        return implode(' › ', array_filter(array_map(fn($p) => $p ? Craft::t('site', $p) : null, $parts)));
    }

    /**
     * "Field" column of the index: the field(s) through which `$element` uses the target, each linking to the
     * element's edit page at that field (tab + scroll, see the script in ElementRelations::init()).
     * Relations that don't come from a field (user photo, navigation node, ...) get a label instead.
     */
    public static function viaHtml(ElementInterface $element): string
    {
        $vias = Usage::$via["$element->id:$element->siteId"] ?? [];
        $editUrl = $element->getCpEditUrl();
        $layoutFields = $element->getFieldLayout()?->getCustomFields() ?? [];
        $parts = [];
        foreach ($vias as $via) {
            if ($via['fieldId'] && ($field = Craft::$app->getFields()->getFieldById($via['fieldId']))) {
                $onLayout = (bool)array_filter($layoutFields, fn($f) => $f->id === $field->id);
                $label = Html::encode(Craft::t('site', $field->name));
                $parts[$field->id] = $editUrl && $onLayout
                    ? Html::a($label, $editUrl . "#fields-$field->handle-field", ['class' => 'go', 'title' => Craft::t('element-relations', 'field-via-open')])
                    : $label;
            } else {
                $label = match (true) {
                    $via['type'] === 'element-user-photo' => Craft::t('element-relations', 'field-via-user-photo'),
                    $element instanceof \verbb\navigation\elements\Menu => Craft::t('element-relations', 'field-via-navigation'),
                    $element instanceof \verbb\wishlist\elements\ListElement => Craft::t('element-relations', 'field-via-wishlist'),
                    $element instanceof \verbb\formie\elements\Form => Craft::t('element-relations', 'field-via-form'),
                    $element instanceof \verbb\formie\elements\Submission => Craft::t('element-relations', 'field-via-submission'),
                    default => '',
                };
                if ($label !== '') {
                    $parts[$label] = Html::tag('span', Html::encode($label), ['class' => 'light']);
                }
            }
        }
        return implode(', ', $parts);
    }

    /** Craft chips for non-element usages (plugin settings), linked to their settings page. */
    public static function globalUsagesHtml(array $usages): string
    {
        return Html::tag('div', implode('', array_map(
            fn(GlobalUsage $usage) => Cp::chipHtml($usage, ['hyperlink' => true, 'showThumb' => true, 'autoReload' => false]),
            $usages,
        )), ['class' => 'flex flex-wrap gap-xs']);
    }
}
