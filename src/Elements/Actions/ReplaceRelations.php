<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Elements\Actions;

use CraftCms\Cms\Element\Actions\ElementAction;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\View\TemplateMode;
use Internetztube\ElementRelations\ElementRelations;
use Override;

use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * "Replace everywhere…" on an element index, for one element. The trigger runs resources/views/_field/replace.twig:
 * the wizard for all its usages, then a notice follows the queued job. Nothing is performed through Craft's
 * perform-action endpoint: Craft's classic index runs the trigger's JavaScript, its Vue indexes (Entries, Assets,
 * Users) dispatch CLIENT_EVENT (ClientEventElementActions).
 */
class ReplaceRelations extends ElementAction
{
    /** The window event the Vue indexes dispatch for this action, with the ticked element and the index's site. */
    public const CLIENT_EVENT = 'element-relations:replace-everywhere';

    #[Override]
    public static function supportsBulk(): bool
    {
        return false;
    }

    #[Override]
    public function getTriggerLabel(): string
    {
        return t('replace-action-label', category: 'element-relations');
    }

    #[Override]
    public function getTriggerHtml(): ?string
    {
        self::registerReplaceJs();
        HtmlStack::jsWithVars(fn(string $type): string => <<<JS
new Craft.ElementActionTrigger({
    type: $type,
    bulk: false,
    activate: (selectedItems, elementIndex) => {
        const element = selectedItems.find('.element:first')[0].dataset;
        ElementRelationsReplace.start({ fromId: Number(element.id), siteId: elementIndex.siteId, sourceIds: null }, elementIndex);
    },
});
JS, [static::class]);
        return null;
    }

    /**
     * The handler of CLIENT_EVENT, on every control panel page of a user who may replace (ElementRelations::boot()):
     * Craft's Vue pages load their JavaScript once and then switch pages without a reload. The wizard's slideout is
     * classic control panel UI: its CSS comes with the first one opened. The index is reloaded when the replacement is
     * done.
     */
    public static function registerClientEventJs(): void
    {
        self::registerReplaceJs();
        HtmlStack::jsWithVars(fn(string $event, string $css): string => <<<JS
window.addEventListener($event, (event) => {
    const { elementIds = [], siteId } = event.detail || {};
    if (elementIds.length !== 1) {
        return;
    }
    if (!document.getElementById('element-relations-classic-ui')) {
        const style = Object.assign(document.createElement('style'), { id: 'element-relations-classic-ui', textContent: $css });
        document.head.append(style);
        document.body.classList.contains('ltr') || document.body.classList.contains('rtl') || document.body.classList.add(document.documentElement.dir === 'rtl' ? 'rtl' : 'ltr');
    }
    ElementRelationsReplace.start({ fromId: Number(elementIds[0]), siteId, sourceIds: null }, { updateElements: () => window.Cp?.\$inertia?.reload() });
});
JS, [self::CLIENT_EVENT, ElementRelations::classicUiCss()], key: 'element-relations-replace-everywhere');
    }

    /** resources/views/_field/replace.twig, for the triggers of both replace actions. */
    public static function registerReplaceJs(): void
    {
        template('element-relations/_field/replace', [], TemplateMode::Cp);
    }
}
