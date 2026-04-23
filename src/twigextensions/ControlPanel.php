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
                return strip_tags($content, [
                    'div', 'span', 'a'
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

        $html = collect($elements)
            ->map(
                fn(ElementInterface $element) =>
                    Cp::elementHtml($element, 'index', $size, null, $showStatus, $showThumb, $showLabel, $showDraftName)
            )
            ->join(' ');

        $totalCount = is_null($totalCount) ? count($elements) : $totalCount;
        $missingCount = $totalCount - count($elements);

        if ($missingCount > 0 && $buttonHref) {
            $html .= Html::tag('a', '+' . \Craft::$app->getFormatter()->asInteger($missingCount), [
                'title' => implode(', ', array_map(fn(ElementInterface $element) => $element->id, $elements)),
                'class' => 'btn small hairline',
                'href' => $buttonHref,
            ]);
        }
        return '<div class="flex gap-xs">' . $html . '</div>';
    }

}
