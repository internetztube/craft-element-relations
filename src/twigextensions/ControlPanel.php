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
                $html = strip_tags($content, [
                    'div', 'span', 'a'
                ]);
                // In Craft 5, label-link is a <span>; upgrade to <a> using the chip's data-cp-url
                return $this->injectLabelLinkAnchors($html);
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

    // Walk the stripped HTML and replace each <span class="label-link"> with <a>, using the
    // data-cp-url from the enclosing chip div. Craft 5 renders label-link as a span;
    // Craft 4 already renders it as an <a>, so nothing to replace there.
    private function injectLabelLinkAnchors(string $html): string
    {
        $result = '';
        $offset = 0;
        $cpUrlAttr  = 'data-cp-url="';
        $labelSpan  = '<span class="label-link">';
        $spanClose  = '</span>';

        while (($cpUrlPos = strpos($html, $cpUrlAttr, $offset)) !== false) {
            $urlStart = $cpUrlPos + strlen($cpUrlAttr);
            $urlEnd   = strpos($html, '"', $urlStart);
            if ($urlEnd === false) break;

            $cpUrl = htmlspecialchars_decode(substr($html, $urlStart, $urlEnd - $urlStart));

            $spanStart = strpos($html, $labelSpan, $cpUrlPos);
            if ($spanStart === false) break;

            $contentStart = $spanStart + strlen($labelSpan);
            $contentEnd   = strpos($html, $spanClose, $contentStart);
            if ($contentEnd === false) break;

            $result .= substr($html, $offset, $spanStart - $offset);
            $result .= '<a class="label-link" href="' . htmlspecialchars($cpUrl, ENT_QUOTES) . '">';
            $result .= substr($html, $contentStart, $contentEnd - $contentStart);
            $result .= '</a>';

            $offset = $contentEnd + strlen($spanClose);
        }

        $result .= substr($html, $offset);
        return $result;
    }

}
