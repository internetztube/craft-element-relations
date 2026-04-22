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
                return $this->elementPreviewHtml(...$args);
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
            ->map(fn(ElementInterface $element) => $this->elementChipHtml($element, $size, $showStatus, $showThumb, $showLabel, $showDraftName))
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

    private function elementChipHtml(
        ElementInterface $element,
        string $size,
        bool $showStatus,
        bool $showThumb,
        bool $showLabel,
        bool $showDraftName,
    ): string
    {
        $chip = Cp::elementHtml($element, 'index', $size, null, $showStatus, $showThumb, $showLabel, $showDraftName);
        // Strip form inputs to avoid triggering a provisional draft
        $chip = strip_tags($chip, ['div', 'span', 'a']);
        // Craft 5 renders label-link as a <span> inside <craft-element-label>; convert to <a>
        $cpUrl = $element->getCpEditUrl();
        if ($cpUrl) {
            $chip = preg_replace(
                '/<span class="label-link">(.*?)<\/span>/s',
                '<a class="label-link" href="' . htmlspecialchars($cpUrl, ENT_QUOTES) . '">$1</a>',
                $chip,
                1
            );
        }
        return $chip;
    }

}
