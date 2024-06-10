<?php

namespace internetztube\elementRelations\twigextensions;

use craft\helpers\Cp;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ControlPanel extends AbstractExtension
{
    public function getFunctions()
    {
        return [
            new TwigFunction('elementRelationsElementPreviewHtml', function(...$args) {
                return Cp::elementPreviewHtml(...$args);
            }),
        ];
    }
}
