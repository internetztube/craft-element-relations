<?php

namespace internetztube\elementRelations\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use internetztube\elementRelations\services\extractors\SpecialExtractorSeomaticGlobalService;
use internetztube\elementRelations\services\RelationsService;

class ElementRelationsField extends Field implements PreviewableFieldInterface
{
    public static function supportedTranslationMethods(): array
    {
        return [self::TRANSLATION_METHOD_NONE];
    }

    public static function displayName(): string
    {
        return Craft::t('element-relations', 'Element Relations');
    }

    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        /**
         * Since you cannot really use a Draft or a Revision inside a Relation Field, we're always defaulting
         * to the canonical.
         */
        $element = $element->getCanonical();
        return Craft::$app->getView()->renderTemplate('element-relations/_components/fields/relations_preview', [
            'relations' => RelationsService::getRelations($element),
            'seomaticGlobal' => SpecialExtractorSeomaticGlobalService::isInUse($element),
        ]);
    }

    public function getInputHtml(mixed $value, ElementInterface $element = null): string
    {
        /**
         * Since you cannot really use a Draft or a Revision inside a Relation Field, we're always defaulting
         * to the canonical.
         */
        $element = $element->getCanonical();
        return Craft::$app->getView()->renderTemplate('element-relations/_components/fields/relations', [
            'relations' => RelationsService::getRelations($element),
            'seomaticGlobal' => SpecialExtractorSeomaticGlobalService::isInUse($element),
        ]);
    }
}
