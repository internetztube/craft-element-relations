<?php

declare(strict_types=1);

namespace internetztube\elementRelations\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\helpers\Html;

/**
 * Pro: "Replace…" in the field's usage list (Craft's index over Usage): replaces the list's element in the ticked
 * usages. The trigger runs templates/_field/replace.twig (the wizard for the ticked usages, then a notice following
 * the queued job); the element comes from the field around the index (ElementRelationsField::inputHtml()).
 */
class ReplaceUsages extends ElementAction
{
    public function getTriggerLabel(): string
    {
        return Craft::t('element-relations', 'replace-button');
    }

    public function getTriggerHtml(): ?string
    {
        ReplaceRelations::registerReplaceJs();
        Craft::$app->getView()->registerCss('.element-index .btn.element-relations-replace-button { gap: 8px; padding-inline: 14px 18px; }');
        Craft::$app->getView()->registerJsWithVars(fn(string $type): string => <<<JS
new Craft.ElementActionTrigger({
    type: $type,
    bulk: true,
    activate: (selectedItems, elementIndex) => {
        const field = elementIndex.\$container.closest('[data-element-relations-from]')[0].dataset;
        ElementRelationsReplace.start({
            fromId: Number(field.elementRelationsFrom),
            siteId: Number(field.elementRelationsSite),
            // Craft gives one id per selected row (an element in two sites twice): deduped here on purpose
            sourceIds: [...new Set(elementIndex.getSelectedElementIds())],
            // the whole page ticked: the wizard preselects "all in the list", the way "select all" is meant
            allTicked: elementIndex.getSelectedElementIds().length >= elementIndex.view.getAllElements().length,
            // the list's filters, for "All … in the list" (ReplaceController::LIST_CRITERIA picks what it needs)
            criteria: {
                ...elementIndex.settings.criteria, // the type and site dropdowns
                status: elementIndex.status,
                drafts: elementIndex.drafts,
                search: elementIndex.searchText,
                filterConfig: elementIndex.getViewParams().filterConfig, // the filter HUD's rules (Craft sends them as this)
            },
        }, elementIndex);
    },
});
JS, [static::class]);

        // a button in the action bar (without trigger HTML, Craft lists the action in its gear menu), with the plugin's
        // icon and a tooltip saying whose feature it is
        $icon = Html::tag('span', Html::svg(Craft::getAlias('@internetztube/elementRelations/icon-mask.svg')), [
            'class' => 'cp-icon small',
            'aria-hidden' => 'true',
        ]);
        return Html::button($icon . Html::tag('span', Html::encode($this->getTriggerLabel())), [
            'class' => ['btn', 'element-relations-replace-button'],
            'title' => Craft::t('element-relations', 'replace-button-title'),
        ]);
    }
}
