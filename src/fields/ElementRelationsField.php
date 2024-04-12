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
    /**
     * This field's data IS NOT stored in the content table, but is stored separately.
     * Therefore, this field must not be translatable.
     * @return array
     */
    public static function supportedTranslationMethods(): array
    {
        return [self::TRANSLATION_METHOD_NONE];
    }

    public static function displayName(): string
    {
        return Craft::t('element-relations', 'Element Relations');
    }

    public function getTableAttributeHtml($value, ElementInterface $element): string
    {
        return $this->_getLazyHtml($element, false);
    }

    private function _getLazyHtml(ElementInterface $element, bool $isElementDetail): string
    {
        $id = sprintf('%s-%s-%s', $element->id, $element->siteId, StringHelper::randomString(6));
        $endpoint = UrlHelper::actionUrl('element-relations/element-relations/get-by-element-id', [
            'elementId' => $element->id,
            'siteId' => $element->siteId,
            'size' => 'small',
            'isElementDetail' => $isElementDetail ? 'true' : 'false',
        ], null, false);

        $refreshEndpoint = UrlHelper::actionUrl('element-relations/element-relations/get-by-element-id', [
            'elementId' => $element->id,
            'siteId' => $element->siteId,
            'size' => 'small',
            'refresh' => 'true',
            'isElementDetail' => $isElementDetail ? 'true' : 'false',
        ], null, false);

        return Craft::$app->getView()->renderTemplate(
            'element-relations/_components/fields/relations-lazy',
            [
                'id' => $id,
                'isElementDetail' => $isElementDetail,
                'endpoint' => $endpoint,
                'refreshEndpoint' => $refreshEndpoint,
            ]
        );
    }

    public function getInputHtml($value, ElementInterface $element = null): string
    {
        return $this->_getLazyHtml($element, true);
    }
}
