<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Elements\Actions;

use CraftCms\Cms\Element\Actions\ElementAction;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\View\TemplateMode;
use Override;

use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * "Replace everywhere…" on an element index, for one element. The trigger runs resources/views/_field/replace.twig:
 * the wizard for all its usages, then a notice follows the queued job. Nothing is performed through Craft's
 * perform-action endpoint.
 */
class ReplaceRelations extends ElementAction
{
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

    /** resources/views/_field/replace.twig, for the triggers of both replace actions. */
    public static function registerReplaceJs(): void
    {
        template('element-relations/_field/replace', [], TemplateMode::Cp);
    }
}
