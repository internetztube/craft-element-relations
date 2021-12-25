<?php

namespace internetztube\elementRelations\fields;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\PreviewableFieldInterface;
use craft\helpers\Db;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use internetztube\elementRelations\services\ElementRelationsService;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;
use yii\db\Schema;

class ElementRelationsField extends Field implements PreviewableFieldInterface
{
    public static function supportedTranslationMethods(): array
    {
        return [self::TRANSLATION_METHOD_NONE];
    }

    public static function displayName(): string
    {
        return Craft::t('element-relations', 'Relations');
    }

    /**
     * @param mixed $value
     * @param Element|ElementInterface $element
     * @return string
     * @throws Exception
     */
    public function getTableAttributeHtml($value, ElementInterface $element): string
    {
        try {
            return $this->_getLazyHtml($element, 'small');
        } catch (LoaderError | SyntaxError | RuntimeError | Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param Element $element
     * @param string $size
     * @return string
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     * @throws Exception
     */
    private function _getLazyHtml(Element $element, string $size = 'default'): string
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

    /**
     * @param string $value
     * @param ElementInterface|Element|null $element
     * @return string
     *
     * @throws Exception
     * @throws LoaderError
     * @throws RuntimeError
     * @throws SyntaxError
     */
    public function getInputHtml($value, ElementInterface $element = null): string
    {
        return $this->_getLazyHtml($element);
    }
}
