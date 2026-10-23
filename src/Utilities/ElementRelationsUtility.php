<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Utilities;

use CraftCms\Cms\Cp\Html\ElementHtml;
use CraftCms\Cms\Cp\Html\StatusHtml;
use CraftCms\Cms\Shared\Enums\Color;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\User\Elements\User;
use CraftCms\Cms\Utility\Utility;
use CraftCms\Cms\View\TemplateMode;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Models\Replacement;
use Internetztube\ElementRelations\Services\ReplaceService;
use Override;

use function CraftCms\Cms\currentUserElement;
use function CraftCms\Cms\t;
use function CraftCms\Cms\template;

/**
 * Utilities → Element Relations: rebuild the relations cache; the history of replacements with rollback buttons.
 */
class ElementRelationsUtility extends Utility
{
    #[Override]
    public static function displayName(): string
    {
        return t('Element Relations', category: 'element-relations');
    }

    #[Override]
    public static function id(): string
    {
        return 'element-relations';
    }

    #[Override]
    public static function icon(): string
    {
        return ElementRelations::iconPath();
    }

    #[Override]
    public static function contentHtml(): string
    {
        return template('element-relations/_utility', [
            'history' => ElementRelations::isPro() ? self::history() : null,
        ], TemplateMode::Cp);
    }

    /**
     * The latest replace operations as table rows (element chips, Craft status labels).
     * @return list<array<string, mixed>>
     */
    private static function history(): array
    {
        $viewer = currentUserElement();
        $chip = function (int $id, int $siteId) use ($viewer): string {
            $element = Elements::getElementById($id, null, $siteId);
            if (!$element) {
                return Html::tag('span', Html::encode(t('history-deleted', ['id' => $id], 'element-relations')), ['class' => 'light']);
            }
            if ($viewer && !$viewer->admin && !$element->canView($viewer)) {
                return Html::tag('span', Html::encode(t('restricted-title', category: 'element-relations')), ['class' => 'light']);
            }
            return app(ElementHtml::class)->elementChipHtml($element);
        };
        $colors = [
            Replacement::STATUS_QUEUED => Color::Blue,
            Replacement::STATUS_APPLIED => Color::Teal,
            Replacement::STATUS_ROLLED_BACK => Color::Gray,
            Replacement::STATUS_ROLLBACK_QUEUED => Color::Blue,
            Replacement::STATUS_FAILED => Color::Red,
        ];
        $rows = [];
        $operations = Replacement::query()->orderByDesc('dateCreated')->orderByDesc('id')->limit(50)
            // admins see everyone's replacements, others their own
            ->when($viewer && !$viewer->admin, fn($query) => $query->where('userId', $viewer?->id));
        foreach ($operations->get() as $operation) {
            /** @var Replacement $operation */
            $user = $operation->userId ? User::find()->id($operation->userId)->status(null)->one() : null;
            $changed = $operation->getChangedElementCount();
            $rows[] = [
                'id' => (int)$operation->id,
                'date' => $operation->dateCreated,
                'user' => $user instanceof User ? app(ElementHtml::class)->elementChipHtml($user) : '',
                'from' => $chip($operation->fromId, $operation->siteId),
                'to' => $chip($operation->toId, $operation->siteId),
                'elements' => $changed,
                'status' => (string)app(StatusHtml::class)->statusLabelHtml([
                    'color' => $colors[$operation->status] ?? Color::Gray,
                    'label' => t('history-status-' . $operation->status, category: 'element-relations'),
                ]),
                'canRollBack' => $operation->status === Replacement::STATUS_APPLIED && $changed > 0 && ElementRelations::canRollBackOperation($operation),
                'canRevert' => $operation->status === Replacement::STATUS_APPLIED && $operation->canRevertToRevisions() && ElementRelations::canRollBackOperation($operation),
                'withoutRevision' => (int)($operation->getResult()['withoutRevision'] ?? 0),
                // its job is gone (a worker died …): offer to continue it
                'canResume' => ReplaceService::isStuck($operation) && ($viewer?->admin || (int)$operation->userId === (int)$viewer?->id),
            ];
        }
        return $rows;
    }
}
