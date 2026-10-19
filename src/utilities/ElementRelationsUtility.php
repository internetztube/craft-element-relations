<?php

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
 * Element Relations utility
 */
class ElementRelationsUtility extends Utility
{
    public static function displayName(): string
    {
        return Craft::t("element-relations", "Element Relations");
    }

    static function id(): string
    {
        return "element-relations";
    }

    public static function icon(): ?string
    {
        return Craft::getAlias("@internetztube/elementRelations/icon-mask.svg");
    }

    static function contentHtml(): string
    {
        $pushed = false;
        if (Craft::$app->request->getIsPost()) {
            Craft::$app->getQueue()->push(new GenerateResaveAllElementRelationsJobsJob());
            $pushed = true;
        }
        return Craft::$app->getView()->renderTemplate("element-relations/_utility", [
            "pushed" => $pushed,
            "history" => ElementRelations::isPro() ? self::history() : null,
        ]);
    }

    /** Pro: the latest replace operations as table rows (element chips, Craft status labels). */
    private static function history(): array
    {
        $elements = Craft::$app->getElements();
        $chip = function (int $id, int $siteId) use ($elements): string {
            $element = $elements->getElementById($id, null, $siteId);
            return $element
                ? Cp::elementChipHtml($element)
                : Html::tag('span', Html::encode(Craft::t('element-relations', 'history-deleted', ['id' => $id])), ['class' => 'light']);
        };
        $colors = [
            ReplacementRecord::STATUS_QUEUED => Color::Blue,
            ReplacementRecord::STATUS_APPLIED => Color::Teal,
            ReplacementRecord::STATUS_ROLLED_BACK => Color::Gray,
        ];
        $rows = [];
        foreach (ReplacementRecord::find()->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])->limit(50)->all() as $operation) {
            /** @var ReplacementRecord $operation */
            $user = $operation->userId ? User::find()->id($operation->userId)->status(null)->one() : null;
            $rows[] = [
                'id' => $operation->id,
                'date' => DateTimeHelper::toDateTime($operation->dateCreated),
                'user' => $user ? Cp::elementChipHtml($user) : '',
                'from' => $chip($operation->fromId, $operation->siteId),
                'to' => $chip($operation->toId, $operation->siteId),
                'elements' => count(array_unique(array_map(fn($c) => $c['elementId'] . ':' . $c['siteId'], $operation->getChanges()))),
                'status' => Cp::statusLabelHtml([
                    'color' => $colors[$operation->status] ?? Color::Gray,
                    'label' => Craft::t('element-relations', 'history-status-' . $operation->status),
                ]),
                'canRollBack' => ElementRelations::canRollBack() && $operation->status === ReplacementRecord::STATUS_APPLIED && $operation->getChanges(),
            ];
        }
        return $rows;
    }
}
