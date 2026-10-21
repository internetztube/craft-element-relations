<?php

declare(strict_types=1);

namespace internetztube\elementRelations\elements\actions;

use Craft;
use craft\base\ElementAction;
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use internetztube\elementRelations\controllers\ReplaceController;
use internetztube\elementRelations\services\ReplaceService;

/**
 * Pro: "Replace everywhere…" on an element index. The trigger opens Craft's element selector modal for a replacement
 * of the same type and submits its id; the replacement itself runs in ReplaceService.
 */
class ReplaceRelations extends ElementAction
{
    public ?int $replacementId = null;

    public function getTriggerLabel(): string
    {
        return Craft::t('element-relations', 'replace-action-label');
    }

    public function getTriggerHtml(): ?string
    {
        Craft::$app->getView()->registerJsWithVars(fn(string $type, string $title): string => <<<JS
(() => {
    'use strict';
    new Craft.ElementActionTrigger({
        type: $type,
        bulk: false,
        activate: (selectedItems, elementIndex) => {
            const \$element = selectedItems.find('.element:first');
            Craft.createElementSelectorModal(\$element.data('type'), {
                multiSelect: false,
                modalTitle: $title,
                disabledElementIds: [\$element.data('id')],
                onSelect: (elements) => elementIndex.submitAction($type, {replacementId: elements[0].id}),
            });
        },
    });
})();
JS, [static::class, Craft::t('element-relations', 'replace-modal-title')]);

        return null;
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $from = $query->one();
        $to = $from instanceof ElementInterface && $this->replacementId
            ? Craft::$app->getElements()->getElementById($this->replacementId, $from::class, $from->siteId)
            : null;
        if (!$from instanceof ElementInterface || !$to || $to->id === $from->id) {
            $this->setMessage(Craft::t('element-relations', 'replace-invalid'));
            return false;
        }

        $result = ReplaceService::replace($from, $to, allowQueue: true);
        if ($result['denied']) {
            $this->setMessage(Craft::t('element-relations', 'replace-denied'));
            return false;
        }
        $this->setMessage($result['queued']
            ? Craft::t('element-relations', 'replace-queued', ['from' => (string)$from, 'to' => (string)$to])
            : ReplaceController::resultMessage($result, (string)$from, (string)$to) . ' ' . Craft::t('element-relations', 'replace-undo-hint'));
        return true;
    }
}
