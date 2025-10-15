<?php

namespace internetztube\elementRelations\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\base\PreviewableFieldInterface;
use craft\errors\FieldNotFoundException;
use craft\errors\InvalidConfigException;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\models\FieldLayoutTab;
use internetztube\elementRelations\assetbundles\ElementRelationsAsset;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\gql\types\Relations as RelationsType;
use craft\models\GqlSchema;
use GraphQL\Type\Definition\Type;

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

  public static function icon(): string
  {
      return '@internetztube/elementRelations/icon-mask.svg';
  }

    public static function isMultiInstance(): bool
    {
        return false;
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
        return new RelationsModel($element->id, $element->siteId);
    }

    public function includeInGqlSchema(GqlSchema $schema): bool
    {
        return true;
    }

    public function getContentGqlType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => RelationsType::getType(),
            'resolve' => fn($source) => $source->{$this->handle},
        ];
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

    public function getTabHash(ElementInterface $element): ?string
    {
        return collect($element->getFieldLayout()->getTabs())
            ->filter(function (FieldLayoutTab $tab) {
                return collect($tab->getElements())
                    ->filter(fn($layoutElement) => $layoutElement instanceof CustomField)
                    ->map(function (CustomField $layoutElement) {
                        try {
                            return $layoutElement->getField();
                        } catch (FieldNotFoundException | InvalidConfigException $e) {
                            // Field was deleted but still referenced in layout, or has invalid configuration
                            return null;
                        }
                    })
                    ->filter(fn(?FieldInterface $field) => $field?->id === $this->id)
                    ->isNotEmpty();
            })
            ->first()
            ?->getHtmlId();
    }
}
