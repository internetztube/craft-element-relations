<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Elements\DeletionBlockers;

use CraftCms\Cms\Cp\Html\ElementIndexHtml;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\DeletionBlockers\BaseDeletionBlocker;
use CraftCms\Cms\Element\ElementCollection;
use CraftCms\Cms\Support\Html;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Elements\Queries\UsageQuery;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Services\InUseGuard;

use function CraftCms\Cms\t;

/**
 * Craft's "Before deleting …, please address the following issue" dialog lists the live usages of the elements being
 * deleted (what visitors see), with "Replace…" (the replace wizard, for one element) and, for users who may delete
 * elements in use, "Delete anyway". Without that permission the dialog can't be passed; Services\InUseGuard refuses
 * the delete on the server too, whichever way it is requested.
 */
class InUseDeletionBlocker extends BaseDeletionBlocker
{
    private int $count = 0;

    /** @var list<string> */
    private array $titles = [];

    /** @var list<ElementInterface> the elements being deleted that are in use */
    private array $inUse = [];

    /** @param array<string, mixed> $config */
    public function __construct(ElementCollection $elements, bool $hardDelete, array $config = [])
    {
        parent::__construct($elements, $hardDelete, $config);
        foreach ($elements as $element) {
            if (!$element instanceof ElementInterface || !InUseGuard::counts($element)) {
                continue;
            }
            $usages = InUseGuard::liveUsages($element);
            if ($usages['count'] === 0) {
                continue;
            }
            $this->inUse[] = $element;
            $this->count += $usages['count'];
            $this->titles = array_slice(array_values(array_unique([...$this->titles, ...$usages['titles']])), 0, InUseGuard::NAMED);
        }
    }

    public function isActive(): bool
    {
        return $this->inUse !== [];
    }

    public function getSummary(): string
    {
        $summary = t('in-use-blocker-summary', [
            'numTargets' => count($this->inUse),
            'type' => $this->inUse[0]::lowerDisplayName(),
            'types' => $this->inUse[0]::pluralLowerDisplayName(),
            'count' => $this->count,
            'names' => InUseGuard::names($this->titles, $this->count),
        ], 'element-relations');
        if (!ElementRelations::mayDeleteInUse()) {
            $summary .= ' ' . t('in-use-blocker-not-allowed', category: 'element-relations');
        }
        return $summary;
    }

    /** The live usages, as the field lists them (Craft's index in a pane, no actions). */
    public function getDetails(): ?string
    {
        $ids = array_map(fn(ElementInterface $element): int => (int)$element->id, $this->inUse);
        Usage::$listedTypes = Usage::typesUsing($ids);
        $html = app(ElementIndexHtml::class)->html(Usage::class, [
            'context' => 'pane',
            'sources' => false,
            'showSiteMenu' => false,
            'showStatusMenu' => false,
            'jsSettings' => [
                'criteria' => ['usesElement' => $ids, 'allSites' => true, 'status' => 'live'],
            ],
        ]);
        Usage::$listedTypes = null;
        // whose list this is: the plugin's icon and name above it
        $source = Html::tag('p', Html::tag('span', Html::svg(ElementRelations::iconPath()), ['class' => 'cp-icon small', 'aria-hidden' => 'true'])
            . Html::tag('span', Html::encode(t('in-use-blocker-details', category: 'element-relations'))), ['class' => 'flex gap-xs light', 'style' => 'margin: 0 0 8px; flex-wrap: nowrap;']);
        // what the list leaves out for this user, so its rows and the count above add up
        $viewer = ElementRelations::restrictedViewer();
        $hidden = $viewer ? UsageQuery::hiddenCount($ids, $viewer, 'live') : 0;
        if ($hidden) {
            $html .= Html::tag('p', Html::encode(t('restricted-count-input', ['count' => $hidden], 'element-relations')), ['class' => 'light', 'style' => 'margin-top: 8px;', 'data-element-relations-hidden' => $hidden]);
        }
        return $source . $html;
    }

    /** @return list<array<string, mixed>> */
    public function getActions(): array
    {
        $actions = [];
        $first = $this->inUse[0];
        if (count($this->inUse) === 1 && ElementRelations::canReplace() && in_array($first::class, ElementRelations::PRO_ELEMENT_TYPES, true)) {
            $actions[] = [
                'icon' => 'arrows-rotate',
                'label' => t('replace-button', category: 'element-relations'),
                // the wizard for all its usages; resolved once the queued replacement is done
                'callback' => Html::jsWithVars(fn(int $fromId, int $siteId) => <<<JS
let submitted = false;
const slideout = new Craft.CpScreenSlideout('element-relations/replace/wizard', {
    params: { fromId: $fromId, siteId: $siteId, scope: 'everywhere' },
});
slideout.on('submit', (event) => {
    submitted = true;
    const operationId = event.response && event.response.data && event.response.data.operationId;
    if (!operationId) {
        reject(); // nothing was replaced, the usages are still there
        return;
    }
    Craft.cp.runQueue?.();
    const poll = () => Craft.sendActionRequest('POST', 'element-relations/replace/status', { data: { operationId } })
        .then(({ data }) => data.done ? (data.status === 'failed' ? reject() : resolve(data.message)) : setTimeout(poll, 1000))
        .catch(() => reject());
    poll();
});
slideout.on('close', () => {
    if (!submitted) {
        reject();
    }
});
JS, [(int)$first->id, (int)$first->siteId]),
            ];
        }
        if (ElementRelations::mayDeleteInUse()) {
            $actions[] = [
                'icon' => 'xmark',
                'label' => t('in-use-blocker-ignore', category: 'element-relations'),
                'callback' => 'resolve();',
            ];
        }
        return $actions;
    }
}
