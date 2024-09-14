<?php

namespace internetztube\elementRelations\twigextensions;

use Craft;
use craft\base\ElementInterface;
use internetztube\elementRelations\services\RelationsService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

/**
 * Twig extension
 */
class Main extends AbstractExtension
{
    public function getFunctions()
    {
        return [
            new TwigFunction('elementRelationsGetRelations', function(ElementInterface $element) {
                return RelationsService::getRelations($element);
            }),
            new TwigFunction('elementRelationsIsUsedInSeomaticGlobalSettings', function(ElementInterface $element) {
                return RelationsService::isUsedInSeomaticGlobalSettings($element);
            })
        ];
    }
}
