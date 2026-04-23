<?php

namespace internetztube\elementRelations\twigextensions;

use craft\base\ElementInterface;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class ControlPanel extends AbstractExtension
{
    public function getFunctions()
    {
        return [
            new TwigFunction('elementRelationsElementPreviewHtml', function (...$args) {
                $content = $this->elementPreviewHtml(...$args);
                // strip out all inputs in order to not trigger a new provisional draft
                // craft-element-label is kept so Craft's JS can initialise chips
                return strip_tags($content, [
                    'div', 'span', 'a', 'craft-element-label',
                ]);
            }),
        ];
    }

    public function elementPreviewHtml(
        array $elements,
        int $totalCount = null,
        string $buttonHref = null,
        string $size = Cp::ELEMENT_SIZE_SMALL,
    ): string
    {
        $showStatus = true;
        $showThumb = false;
        $showLabel = true;
        $showDraftName = true;

        if (empty($elements)) {
            return '';
        }

        // Use Craft's native elementPreviewHtml which generates the inline-chips
        // no-truncate wrapper that Craft's JS targets for chip initialisation.
        $html = Cp::elementPreviewHtml($elements, $size, $showStatus, $showThumb, $showLabel, $showDraftName);

        $totalCount = is_null($totalCount) ? count($elements) : $totalCount;
        $missingCount = $totalCount - count($elements);

        if ($missingCount > 0 && $buttonHref) {
            $html .= Html::tag('a', '+' . \Craft::$app->getFormatter()->asInteger($missingCount), [
                'title' => implode(', ', array_map(fn(ElementInterface $element) => $element->id, $elements)),
                'class' => 'btn small hairline',
                'href' => $buttonHref,
            ]);
        }
        return $html;
    }

}
