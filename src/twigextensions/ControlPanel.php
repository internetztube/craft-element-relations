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
            ->map(fn(ElementInterface $element) => $this->chipHtml($element, $size, $showStatus, $showThumb, $showLabel, $showDraftName))
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

    private function chipHtml(
        ElementInterface $element,
        string $size,
        bool $showStatus,
        bool $showThumb,
        bool $showLabel,
        bool $showDraftName,
    ): string
    {
        // Craft 5: elementChipHtml() supports hyperlink:true which renders label-link as <a>.
        // Craft 4: fall back to elementHtml() which already generates <a> for label-link.
        if (method_exists(Cp::class, 'elementChipHtml')) {
            return Cp::elementChipHtml($element, [
                'context' => 'index',
                'size' => $size,
                'showStatus' => $showStatus,
                'showThumb' => $showThumb,
                'showLabel' => $showLabel,
                'showDraftName' => $showDraftName,
                'hyperlink' => true,
            ]);
        }

        return Cp::elementHtml($element, 'index', $size, null, $showStatus, $showThumb, $showLabel, $showDraftName);
    }

}
