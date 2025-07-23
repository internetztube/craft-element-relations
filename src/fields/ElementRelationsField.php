<?php

namespace internetztube\elementRelations\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\base\PreviewableFieldInterface;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayoutTab;
use internetztube\elementRelations\assetbundles\ElementRelationsAsset;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\models\RelationsModel;

class ElementRelationsField extends Field implements PreviewableFieldInterface
{
    public static function supportedTranslationMethods(): array
    {
        return [self::TRANSLATION_METHOD_NONE];
    }

    public static function displayName(): string
    {
        return Craft::t("element-relations", "Element Relations");
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): ?RelationsModel
    {
        if (!$element || !$element->id) {
            return null;
        }
        /**
         * Since you cannot really use a Draft or a Revision inside a Relation Field, we're always defaulting
         * to the canonical.
         */
        return new RelationsModel($element->id);
    }

    /**
     * Craft 4
     * @param RelationsModel $value
     * @param ElementInterface $element
     * @return string
     */
    public function getTableAttributeHtml(mixed $value, ElementInterface $element): string
    {
        return Craft::$app->getView()
            ->renderTemplate("element-relations/_components/fields/preview", [
                "element" => $element,
                "value" => $value,
                "currentSite" => $element->site,
                "tabHash" => $this->getTabHash($element),
            ]);
    }

    /**
     * Craft 5
     * @param RelationsModel $value
     * @param ElementInterface $element
     * @return string
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        return Craft::$app->getView()
            ->renderTemplate("element-relations/_components/fields/preview", [
                "element" => $element,
                "value" => $value,
                "currentSite" => $element->site,
                "tabHash" => $this->getTabHash($element),
            ]);
    }

    public function getInputHtml(mixed $value, ElementInterface $element = null): string
    {
        Craft::$app->getView()->registerAssetBundle(ElementRelationsAsset::class);

        $paginationEndpoint = UrlHelper::actionUrl("element-relations/element-relations/paginate", [
            "elementId" => $element->id,
            "siteId" => $element->siteId,
            "limit" => 10
        ], null, false);

        return Craft::$app->getView()
            ->renderTemplate("element-relations/_components/fields/input", [
                "element" => $element,
                "value" => $value,
                "currentSite" => $element->site,
                "tabHash" => $this->getTabHash($element),
                "containerId" => "elementRelations-pagination-" . StringHelper::randomString(6),
                "paginationEndpoint" => $paginationEndpoint,
            ]);
    }

    public function getTabHash(ElementInterface $element): string
    {
        /** @var FieldLayoutTab $tab */
        return collect($element->getFieldLayout()->getTabs())
            ->filter(function (FieldLayoutTab $tab) {
                return collect($tab->getElements())
                    ->filter(fn($layoutElement) => $layoutElement instanceof CustomField)
                    ->map(fn (CustomField $layoutElement) => $layoutElement->getField())
                    ->filter(fn (FieldInterface $field) => $field->id === $this->id)
                    ->isNotEmpty();
            })
            ->first()
            ->getHtmlId();
    }
}
