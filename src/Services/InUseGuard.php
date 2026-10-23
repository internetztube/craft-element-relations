<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Services;

use CraftCms\Cms\Element\Actions\Delete;
use CraftCms\Cms\Element\Actions\DeleteForSite;
use CraftCms\Cms\Element\Actions\SetStatus;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\Contracts\NestedElementInterface;
use CraftCms\Cms\Element\Element;
use CraftCms\Cms\Element\ElementHelper;
use CraftCms\Cms\Element\Events\DefineDeletionBlockers;
use CraftCms\Cms\Element\Events\ElementActionPerforming;
use CraftCms\Cms\Element\Events\ElementDeleting;
use CraftCms\Cms\Element\Events\ElementDeletingForSite;
use CraftCms\Cms\Element\Events\ElementSaving;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Support\Facades\Sites;
use Illuminate\Support\Facades\Event;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Elements\DeletionBlockers\InUseDeletionBlocker;
use Internetztube\ElementRelations\Models\RelationsModel;

use function CraftCms\Cms\t;

/**
 * Elements that are in use can't be deleted, disabled (everywhere or for a site), expired or scheduled from the control
 * panel, unless the user has the "Delete or disable elements in use" permission (admins do). "In use" means live
 * usages: what visitors see (live entries, enabled elements, no drafts) plus plugin settings. Console requests,
 * migrations and queue jobs are never blocked.
 *
 * Guarded where Craft decides (ElementDeleting / ElementSaving), so the edit page, slideouts and index actions are all
 * covered; index actions are also checked up front, so the whole action is refused with a clear message.
 */
class InUseGuard
{
    /** Titles named in a message: all of them up to this many, else one less and “and N more”. */
    public const NAMED = 3;

    public static function register(): void
    {
        Event::listen(ElementDeleting::class, [self::class, 'beforeDelete']);
        Event::listen(ElementSaving::class, [self::class, 'beforeSave']);
        Event::listen(ElementActionPerforming::class, [self::class, 'beforePerformAction']);
        Event::listen(ElementDeletingForSite::class, [self::class, 'beforeDeleteForSite']);
        // the delete dialog lists the usages before anything is deleted
        Event::listen(function (DefineDeletionBlockers $event): void {
            $event->blockers[] = new InUseDeletionBlocker($event->elements, $event->hardDelete);
        });
    }

    /** "Delete for site" can't be canceled by its event, so an error stops it (and is shown as such). */
    public static function beforeDeleteForSite(ElementDeletingForSite $event): void
    {
        $element = $event->element;
        if (!self::guards($element)) {
            return;
        }
        $message = self::check($element, (int)$element->siteId, 'in-use-delete-site');
        if ($message !== null) {
            abort(400, $message); // a 400 with the reason, not a server error
        }
    }

    public static function beforeDelete(ElementDeleting $event): void
    {
        $element = $event->element;
        if (!self::guards($element)) {
            return;
        }
        $message = self::check($element, null, 'in-use-delete');
        if ($message !== null) {
            $element->errors()->add('id', $message); // shown by the edit page and returned by the delete action
            $event->isValid = false;
        }
    }

    public static function beforeSave(ElementSaving $event): void
    {
        $element = $event->element;
        if ($event->isNew || !self::guards($element)) {
            return;
        }
        $change = self::goesOffline($element);
        if ($change === null) {
            return;
        }
        $message = self::check($element, $change['siteId'], 'in-use-' . $change['why']);
        if ($message !== null) {
            $element->errors()->add($change['attribute'], $message);
            $event->isValid = false;
        }
    }

    /** Index actions: refuse the whole action with a clear message instead of a silent or generic failure. */
    public static function beforePerformAction(ElementActionPerforming $event): void
    {
        $action = $event->action;
        $disable = $action instanceof SetStatus && $action->status === SetStatus::DISABLED;
        $forSite = $action instanceof DeleteForSite;
        if (!$action instanceof Delete && !$disable && !$forSite) {
            return;
        }
        foreach ((clone $event->query)->status(null)->all() as $element) {
            if (!$element instanceof ElementInterface || !self::guards($element)) {
                continue;
            }
            // Craft's "Set status → Disabled" disables only the current site of a multi-site element
            $oneSite = $forSite || ($disable && count(ElementHelper::supportedSitesForElement($element)) > 1);
            $message = $oneSite
                ? self::check($element, (int)$element->siteId, $forSite ? 'in-use-delete-site' : 'in-use-disable-site')
                : self::check($element, null, $disable ? 'in-use-disable' : 'in-use-delete');
            if ($message !== null) {
                $event->isValid = false;
                $event->message = $message;
                return;
            }
        }
    }

    /**
     * Live usages of the element: how many elements use it (each once, whatever sites it uses it in) and the first few
     * titles. With a site, only the usages in that site (a disable for one site); plugin settings count for the element
     * as a whole.
     * @return array{count: int, titles: list<string>} titles quoted, e.g. “Travel slowly”
     */
    public static function liveUsages(ElementInterface $element, ?int $siteId = null): array
    {
        $model = (new RelationsModel((int)($element->getCanonicalId() ?? $element->id), (int)$element->siteId))
            ->withQueryFactory(fn(string $type): mixed => ElementRelations::find($type)); // Craft's defaults: live entries, enabled elements, no drafts
        $siteIds = $siteId === null ? [] : [$siteId];
        $titles = [];
        // named only when the user may view it (the count stays complete: “and N more”); ponytail: the first 50
        // usages are looked at, an editor who may view none of them gets the count alone
        $viewer = ElementRelations::restrictedViewer();
        foreach ($model->getElementsIterator($siteIds, $viewer ? 50 : self::NAMED) as $usage) {
            if ($viewer && !$usage->canView($viewer)) {
                continue;
            }
            $titles[] = '“' . $usage . '”';
            if (count($titles) === self::NAMED) {
                break;
            }
        }
        $global = $siteId === null ? $model->getGlobalUsages() : [];
        foreach (array_slice($global, 0, max(0, self::NAMED - count($titles))) as $usage) {
            $titles[] = '“' . $usage->label . '”';
        }
        return ['count' => $model->getCount($siteIds) + count($global), 'titles' => $titles];
    }

    /** Whether this element in this request is guarded: a control panel request, a user without the permission. */
    private static function guards(ElementInterface $element): bool
    {
        return ElementRelations::guardsInUse() && self::counts($element);
    }

    /**
     * The usages for a message: all titles when there are at most NAMED, else the first NAMED - 1 and “and N more”, so
     * it never says “and 1 more” where the title would fit.
     * @param list<string> $titles
     */
    public static function names(array $titles, int $count): string
    {
        $shown = $count <= self::NAMED ? array_slice($titles, 0, self::NAMED) : array_slice($titles, 0, self::NAMED - 1);
        $names = implode(', ', $shown);
        if ($count > count($shown)) {
            $names .= ' ' . t('in-use-more', ['count' => $count - count($shown)], 'element-relations');
        }
        return $names;
    }

    /** Whether the element's live usages matter at all: the live element itself, and it has any usage. */
    public static function counts(ElementInterface $element): bool
    {
        // the live element only: drafts and revisions don't change what visitors see; propagation and resaving
        // follow a save that was already checked
        if ($element->getIsDraft() || $element->getIsRevision() || ($element instanceof Element && ($element->propagating || $element->resaving))) {
            return false;
        }
        // nested elements (a Matrix block) go with their owner; trashed ones were checked when they were deleted
        if (($element instanceof NestedElementInterface && $element->getPrimaryOwnerId() !== null) || $element->trashed) {
            return false;
        }
        // cheap first: no cache row means no usage
        return (new RelationsModel((int)$element->id, (int)$element->siteId))->getIsInUseFast([]);
    }

    /**
     * What a save changes about the element being live: disabled everywhere, disabled for a site, expired or
     * scheduled (entries). Null when it stays live everywhere it was live.
     * @return array{why: string, attribute: string, siteId: int|null}|null
     */
    private static function goesOffline(ElementInterface $element): ?array
    {
        $live = [Element::STATUS_ENABLED, Entry::STATUS_LIVE];
        $before = $element::find()->id($element->id)->siteId($element->siteId)->status(null)->one();
        $wasLive = $before instanceof ElementInterface && in_array($before->getStatus(), $live, true);
        if ($wasLive && !$element->enabled) {
            return ['why' => 'disable', 'attribute' => 'enabled', 'siteId' => null];
        }
        // the edit page's sidebar can switch any site off, not just the one being edited (which may stay live)
        foreach (ElementHelper::supportedSitesForElement($element) as $site) {
            $siteId = (int)$site['siteId'];
            if ($element->getEnabledForSite($siteId) !== false) {
                continue;
            }
            $stored = $siteId === (int)$element->siteId ? $before : $element::find()->id($element->id)->siteId($siteId)->status(null)->one();
            if ($stored instanceof ElementInterface && in_array($stored->getStatus(), $live, true)) {
                return ['why' => 'disable-site', 'attribute' => 'enabled', 'siteId' => $siteId];
            }
        }
        $status = $element->getStatus();
        if (!$wasLive || in_array($status, $live, true)) {
            return null;
        }
        return [
            'why' => $status === Entry::STATUS_PENDING ? 'schedule' : 'expire',
            'attribute' => $status === Entry::STATUS_PENDING ? 'postDate' : 'expiryDate',
            'siteId' => null,
        ];
    }

    /** The message when the element has live usages (in the site), null when it may go. */
    private static function check(ElementInterface $element, ?int $siteId, string $key): ?string
    {
        $usages = self::liveUsages($element, $siteId);
        if ($usages['count'] === 0) {
            return null;
        }
        return t($key, [
            'element' => (string)$element,
            'count' => $usages['count'],
            'names' => self::names($usages['titles'], $usages['count']),
            'site' => $siteId !== null ? (string)Sites::getSiteById($siteId)?->getName() : '',
        ], 'element-relations') . ' ' . t('in-use-hint', ['count' => $usages['count']], 'element-relations');
    }
}
