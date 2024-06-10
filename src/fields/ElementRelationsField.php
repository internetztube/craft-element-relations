<?php

namespace internetztube\elementRelations\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\elements\Asset;
use internetztube\elementRelations\services\relations\GenericRelationsService;
use internetztube\elementRelations\services\relations\SeoMaticGlobalRelationsService;
use internetztube\elementRelations\services\relations\SeoMaticLocalRelationsService;
use internetztube\elementRelations\services\relations\UserPhotoRelationService;

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
        $allRelations = collect();
        $data = [
            'genericRelations' => GenericRelationsService::getReverseRelations($element),
            'userPhoto' => $element instanceof Asset ? UserPhotoRelationService::getReverseRelations($element) : [],
        ];
        $allRelations = $allRelations->merge($data['userPhoto']);
        $allRelations = $allRelations->merge($data['genericRelations']);

        $data['seoMaticLocal'] = $element instanceof Asset ? SeoMaticLocalRelationsService::getReverseRelations($element) : [];
        $data['seoMaticGlobal'] = $element instanceof Asset && SeoMaticGlobalRelationsService::getReverseRelations($element);
        $allRelations = $allRelations->merge($data['seoMaticLocal']);

        $data['allRelations'] = $allRelations->all();
        return $data;
    }
}
