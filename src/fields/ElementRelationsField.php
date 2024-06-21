<?php

namespace internetztube\elementRelations\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;

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

    // Craft 4
    public function getTableAttributeHtml(mixed $value, ElementInterface $element): string
    {
        return $this->render($element, true);
    }

    // Craft 5
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        return $this->render($element, true);

    }

    public function getInputHtml(mixed $value, ElementInterface $element = null): string
    {
        return $this->render($element, false);
    }

    private function render(ElementInterface $element, bool $isPreview): string
    {
        /**
         * Since you cannot really use a Draft or a Revision inside a Relation Field, we're always defaulting
         * to the canonical.
         */
        $element = $element->getCanonical();
        $endpoint = UrlHelper::actionUrl("element-relations/element-relations/get-by-element-id", [
            "elementId" => $element->id,
            "siteId" => $element->siteId,
            "is-preview" => $isPreview ? "true" : "false",
        ], null, false);

        return Craft::$app->getView()->renderTemplate(
            "element-relations/_components/fields/lazy",
            [
                "id" => sprintf("%s-%s-%s", $element->id, $element->siteId, StringHelper::randomString(6)),
                "endpoint" => $endpoint,
            ]
        );
    }
}
