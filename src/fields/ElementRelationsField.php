<?php

namespace internetztube\elementRelations\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use internetztube\elementRelations\services\SpecialSeomaticGlobalService;
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
        return Craft::$app->getView()->renderTemplate('element-relations/_components/fields/relations_preview', $this->getInputData($element));
    }

    public function getInputHtml(mixed $value, ElementInterface $element = null): string
    {
        return Craft::$app->getView()->renderTemplate('element-relations/_components/fields/relations', $this->getInputData($element));
    }

    private function getInputData(ElementInterface $element)
    {
        return [
            'relations' => RelationsService::getReverseRelations($element),
            'seomaticGlobal' => SpecialSeomaticGlobalService::isInUse($element),
        ];
    }
}
