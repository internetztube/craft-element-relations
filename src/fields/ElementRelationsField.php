<?php

namespace internetztube\elementRelations\fields;

use craft\base\Element;
use craft\base\PreviewableFieldInterface;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Db;
use internetztube\elementRelations\services\ElementRelationsService;
use yii\db\Schema;
use craft\helpers\Json;

class ElementRelationsField extends Field implements PreviewableFieldInterface
{
    public static function supportedTranslationMethods(): array
    {
        return [self::TRANSLATION_METHOD_SITE];
    }

    public static function displayName(): string
    {
        return Craft::t('element-relations', 'Relations');
    }

    public function getTableAttributeHtml($value, ElementInterface $element): string
    {
        return $this->_getLazyHtml($element, 'small');
    }

    public function getInputHtml($value, ElementInterface $element = null): string
    {
        return $this->_getLazyHtml($element);
    }

    private function _getLazyHtml(Element $element, string $size = 'default')
    {
        $id = sprintf('%s-%s-%s', $element->id, $element->siteId, StringHelper::randomString(6));
        $endpoint = UrlHelper::actionUrl('element-relations/element-relations/get-by-element-id', [
            'elementId' => $element->id,
            'siteId' => $element->siteId,
            'size' => $size,
        ], null, false);
        return Craft::$app->getView()->renderTemplate(
            'element-relations/_components/fields/Relations_lazy',
            ['endpoint' => $endpoint, 'id' => $id]
        );
    }
}
