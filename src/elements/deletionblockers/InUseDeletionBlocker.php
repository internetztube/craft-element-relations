<?php

declare(strict_types=1);

namespace internetztube\elementRelations\elements\deletionblockers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\deletionblockers\BaseDeletionBlocker;
use craft\helpers\Cp;
use craft\helpers\Html;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\services\InUseGuard;

/**
 * Pro, Craft 5.10+: Craft's "Before deleting …, please address the following issue" dialog lists the live usages of the
 * elements being deleted (what visitors see), with "Replace…" (the replace wizard, for one element) and, for users who
 * may delete elements in use, "Delete anyway". Without that permission the dialog can't be passed; services/InUseGuard
 * refuses the delete on the server too, whichever way it is requested.
 */
class InUseDeletionBlocker extends BaseDeletionBlocker
{
    private int $count = 0;

    /** @var list<string> */
    private array $titles = [];

    /** @var list<ElementInterface> the elements being deleted that are in use */
    private array $inUse = [];

    public function init(): void
    {
        parent::init();
        if (!ElementRelations::isPro()) {
            return;
        }
        foreach ($this->elements as $element) {
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
        $names = InUseGuard::names($this->titles, $this->count);
        $summary = Craft::t('element-relations', 'in-use-blocker-summary', [
            'numTargets' => count($this->inUse),
            'type' => $this->inUse[0]::lowerDisplayName(),
            'types' => $this->inUse[0]::pluralLowerDisplayName(),
            'count' => $this->count,
            'names' => $names,
        ]);
        if (!ElementRelations::mayDeleteInUse()) {
            $summary .= ' ' . Craft::t('element-relations', 'in-use-blocker-not-allowed');
        }
        return $summary;
    }

    /** The live usages, as the field lists them (Craft's index in a pane, no actions). */
    public function getDetails(): ?string
    {
        Usage::$listedTypes = Usage::typesUsing(array_map(fn(ElementInterface $element): int => (int)$element->id, $this->inUse));
        $html = Cp::elementIndexHtml(Usage::class, [
            'context' => 'pane',
            'sources' => false,
            'showSiteMenu' => false,
            'showStatusMenu' => false,
            'jsSettings' => [
                'criteria' => [
                    'usesElement' => array_map(fn(ElementInterface $element): int => (int)$element->id, $this->inUse),
                    'allSites' => true,
                    'status' => 'live',
                ],
            ],
        ]);
        Usage::$listedTypes = null;
        return $html;
    }

    /** @return list<array<string, mixed>> */
    public function getActions(): array
    {
        $actions = [];
        $first = $this->inUse[0];
        if (count($this->inUse) === 1 && ElementRelations::canReplace() && in_array($first::class, ElementRelations::PRO_ELEMENT_TYPES, true)) {
            $actions[] = [
                'icon' => 'arrows-rotate',
                'label' => Craft::t('element-relations', 'replace-button'),
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
                'label' => Craft::t('element-relations', 'in-use-blocker-ignore'),
                'callback' => 'resolve();',
            ];
        }
        return $actions;
    }
}
