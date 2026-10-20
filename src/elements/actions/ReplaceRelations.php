<?php

declare(strict_types=1);

namespace internetztube\elementRelations\elements\actions;

use Craft;
use craft\base\ElementAction;

/**
 * Pro: "Replace everywhere…" on an element index, for one element. The trigger runs templates/_field/replace.twig:
 * the wizard for all its usages, then a notice follows the queued job. Nothing is performed through Craft's
 * perform-action endpoint.
 */
class ReplaceRelations extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('element-relations', 'replace-action-label');
    }

    public function getTriggerHtml(): ?string
    {
        self::registerReplaceJs();
        Craft::$app->getView()->registerJsWithVars(fn(string $type): string => <<<JS
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

    /** templates/_field/replace.twig and its translations, for the triggers of both replace actions. */
    public static function registerReplaceJs(): void
    {
        $view = Craft::$app->getView();
        $view->registerTranslations('element-relations', ['replace-running', 'rollback-running', 'replace-undo']);
        $view->renderTemplate('element-relations/_field/replace', [], \craft\web\View::TEMPLATE_MODE_CP);
    }
}
