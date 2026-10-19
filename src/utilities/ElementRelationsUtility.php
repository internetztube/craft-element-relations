<?php

declare(strict_types=1);

namespace internetztube\elementRelations\utilities;

use Craft;
use craft\base\Utility;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Cp;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\records\ReplacementRecord;
use internetztube\elementRelations\jobs\GenerateResaveAllElementRelationsJobsJob;

/**
 * Utilities → Element Relations: rebuild the relations cache; Pro: history of replacements with rollback buttons.
 */
class ElementRelationsUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t("element-relations", "Element Relations");
    }

    public static function id(): string
    {
        return "element-relations";
    }

    public static function icon(): ?string
    {
        return Craft::getAlias("@internetztube/elementRelations/icon-mask.svg");
    }

    public static function contentHtml(): string
    {
        $pushed = false;
        $request = Craft::$app->getRequest();
        if ($request instanceof \craft\web\Request && $request->getIsPost()) {
            Craft::$app->getQueue()->push(new GenerateResaveAllElementRelationsJobsJob());
            $pushed = true;
        }
        return Craft::$app->getView()->renderTemplate("element-relations/_utility", [
            "pushed" => $pushed,
            "history" => ElementRelations::isPro() ? self::history() : null,
        ]);
    }

    /**
     * Pro: the latest replace operations as table rows (element chips, Craft status labels).
     * @return list<array{id: int, date: \DateTime|false, user: string, from: string, to: string, elements: int, status: string|null, canRollBack: bool, canRevert: bool, withoutRevision: int}>
     */
    private static function history(): array
    {
        $elements = Craft::$app->getElements();
        $viewer = Craft::$app->getUser()->getIdentity();
        $chip = function (int $id, int $siteId) use ($elements, $viewer): string {
            $element = $elements->getElementById($id, null, $siteId);
            if (!$element) {
                return Html::tag('span', Html::encode(Craft::t('element-relations', 'history-deleted', ['id' => $id])), ['class' => 'light']);
            }
            if ($viewer && !$viewer->admin && !$elements->canView($element, $viewer)) {
                return Html::tag('span', Html::encode(Craft::t('element-relations', 'restricted-title')), ['class' => 'light']);
            }
            return Cp::elementChipHtml($element);
        };
        $colors = [
            ReplacementRecord::STATUS_QUEUED => Color::Blue,
            ReplacementRecord::STATUS_APPLIED => Color::Teal,
            ReplacementRecord::STATUS_ROLLED_BACK => Color::Gray,
            ReplacementRecord::STATUS_FAILED => Color::Red,
        ];
        $rows = [];
        $operations = ReplacementRecord::find()->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])->limit(50);
        if ($viewer && !$viewer->admin) {
            $operations->where(['userId' => $viewer->id]); // admins see everyone's replacements, others their own
        }
        foreach ($operations->all() as $operation) {
            /** @var ReplacementRecord $operation */
            $user = $operation->userId ? User::find()->id($operation->userId)->status(null)->one() : null;
            $rows[] = [
                'id' => (int)$operation->id,
                'date' => DateTimeHelper::toDateTime($operation->dateCreated),
                'user' => $user instanceof User ? Cp::elementChipHtml($user) : '',
                'from' => $chip((int)$operation->fromId, (int)$operation->siteId),
                'to' => $chip((int)$operation->toId, (int)$operation->siteId),
                'elements' => count(array_unique(array_map(fn(array $change): string => $change['elementId'] . ':' . $change['siteId'], $operation->getChanges()))),
                'status' => Cp::statusLabelHtml([
                    'color' => $colors[$operation->status] ?? Color::Gray,
                    'label' => Craft::t('element-relations', 'history-status-' . $operation->status),
                ]),
                'canRollBack' => $operation->status === ReplacementRecord::STATUS_APPLIED && $operation->getChanges() && ElementRelations::canRollBackOperation($operation),
                'canRevert' => $operation->status === ReplacementRecord::STATUS_APPLIED && $operation->canRevertToRevisions() && ElementRelations::canRollBackOperation($operation),
                'withoutRevision' => (int)($operation->getResult()['withoutRevision'] ?? 0),
            ];
        }
        return $rows;
    }
}
