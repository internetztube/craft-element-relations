<?php

namespace internetztube\elementRelations\twigextensions;

use Craft;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\models\Site;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;
class ControlPanel extends AbstractExtension
{
    public function getFilters()
    {
        // Define custom Twig filters
        // (see https://twig.symfony.com/doc/3.x/advanced.html#filters)
        return [
            new TwigFilter('passwordify', function($string) {
                return strtr($string, [
                    'a' => '@',
                    'e' => '3',
                    'i' => '1',
                    'o' => '0',
                    's' => '5',
                ]);
            }),
            // ...
        ];
    }

    public function getFunctions()
    {
        return [
            new TwigFunction('elementRelationsElementPreviewHtml', function(...$args) {
                return Cp::elementPreviewHtml(...$args);
            }),
            new TwigFunction('elementRelationsSitePreviewHtml', function(...$args) {
                return self::sitePreviewHtml(...$args);
            })
        ];
    }

    private static function sitePreviewHtml(array $sites, string $size, int $elementId): string
    {
        if (empty($sites)) {
            return '';
        }
        $otherElements = collect($sites)->map(function (Site $site) use ($size, $elementId) {
            return self::siteHtml($site, $size, $elementId);
        })->all();
        return Html::tag('span', '+' . count($otherElements), [
            'class' => 'btn small',
            'role' => 'button',
            'onclick' => 'jQuery(this).replaceWith(' . Json::encode('<br />' . implode('', $otherElements)) . ')',
        ]);
    }

    /**
     * @param Site $site
     * @param string $size
     * @param int $elementId
     * @return string
     */
    private static function siteHtml(Site $site, string $size, int $elementId): string
    {
        $element = Craft::$app->elements->getElementById($elementId, null, $site->id);
        return "
            <div class=\"element hasstatus $size\" title=\"$site->name - $site->handle\">
              <span class=\"status " . ($site->enabled ? "enabled" : "disabled" ) . "\"></span>
              <div class=\"label\">
                <a href=\"{$element->getCpEditUrl()}\" class=\"title\" style=\"white-space: nowrap;\">$site->name</a>
              </div>
            </div>
        ";
    }

}
