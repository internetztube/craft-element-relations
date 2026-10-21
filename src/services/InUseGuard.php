<?php

declare(strict_types=1);

namespace internetztube\elementRelations\services;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\actions\Delete;
use craft\elements\actions\SetStatus;
use craft\events\ElementActionEvent;
use craft\events\ModelEvent;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\models\RelationsModel;

/**
 * Pro: elements that are in use can't be deleted, disabled (everywhere or for a site), expired or scheduled from the
 * control panel, unless the user has the "Delete or disable elements in use" permission (admins do). "In use" means
 * live usages: what visitors see (live entries, enabled elements, no drafts) plus plugin settings. Console requests,
 * migrations and queue jobs are never blocked.
 *
 * Guarded where Craft decides (Element::EVENT_BEFORE_DELETE / EVENT_BEFORE_SAVE), so the edit page, slideouts and
 * index actions are all covered; index actions are also checked up front, because Craft's Delete action reports
 * success even when an element refuses to be deleted.
 */
class InUseGuard
{
    /** Titles named in a message: all of them up to this many, else one less and “and N more”. */
    public const NAMED = 3;

    public static function register(): void
    {
        \yii\base\Event::on(Element::class, Element::EVENT_BEFORE_DELETE, [self::class, 'beforeDelete']);
        \yii\base\Event::on(Element::class, Element::EVENT_BEFORE_SAVE, [self::class, 'beforeSave']);
        \yii\base\Event::on(\craft\services\Elements::class, \craft\services\Elements::EVENT_BEFORE_PERFORM_ACTION, [self::class, 'beforePerformAction']);
        \yii\base\Event::on(\craft\services\Elements::class, \craft\services\Elements::EVENT_BEFORE_DELETE_FOR_SITE, [self::class, 'beforeDeleteForSite']);
        // Craft 5.10+: the delete dialog lists the usages before anything is deleted
        if (defined(Element::class . '::EVENT_DEFINE_DELETION_BLOCKERS')) {
            \yii\base\Event::on(Element::class, Element::EVENT_DEFINE_DELETION_BLOCKERS, function (\craft\events\DefineElementDeletionBlockersEvent $event): void {
                $event->blockers[] = new \internetztube\elementRelations\elements\deletionblockers\InUseDeletionBlocker($event->elements, $event->hardDelete);
            });
        }
    }

    /** "Delete for site" can't be canceled by its event, so a user error stops it (and is shown as such). */
    public static function beforeDeleteForSite(\craft\events\ElementEvent $event): void
    {
        $element = $event->element;
        if (!self::guards($element)) {
            return;
        }
        $message = self::check($element, (int)$element->siteId, 'in-use-delete-site');
        if ($message !== null) {
            throw new \yii\web\BadRequestHttpException($message); // a 400 with the reason, not a server error
        }
    }

    public static function beforeDelete(ModelEvent $event): void
    {
        $element = $event->sender;
        if (!$element instanceof ElementInterface || !self::guards($element)) {
            return;
        }
        $message = self::check($element, null, 'in-use-delete');
        if ($message !== null) {
            $element->addError('id', $message); // shown by the edit page and returned by elements/delete
            $event->isValid = false;
        }
    }

    public static function beforeSave(ModelEvent $event): void
    {
        $element = $event->sender;
        if (!$element instanceof ElementInterface || $event->isNew || !self::guards($element)) {
            return;
        }
        $change = self::goesOffline($element);
        if ($change === null) {
            return;
        }
        $message = self::check($element, $change['siteId'], 'in-use-' . $change['why']);
        if ($message !== null) {
            $element->addError($change['attribute'], $message);
            $event->isValid = false;
        }
    }

    /** Index actions: refuse the whole action with a clear message instead of a silent or generic failure. */
    public static function beforePerformAction(ElementActionEvent $event): void
    {
        $action = $event->action;
        $disable = $action instanceof SetStatus && $action->status === SetStatus::DISABLED;
        $forSite = $action instanceof \craft\elements\actions\DeleteForSite;
        if (!$action instanceof Delete && !$disable && !$forSite) {
            return;
        }
        foreach ((clone $event->criteria)->status(null)->all() as $element) {
            if (!$element instanceof ElementInterface || !self::guards($element)) {
                continue;
            }
            // Craft's "Set status → Disabled" disables only the current site of a multi-site element
            $oneSite = $forSite || ($disable && count(\craft\helpers\ElementHelper::supportedSitesForElement($element)) > 1);
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
     * as a whole. The sites are left out of the titles: the message names the site when it is about one, and the
     * "Used in" field lists every usage with its sites.
     * @return array{count: int, titles: list<string>} titles quoted, e.g. “Travel slowly”
     */
    public static function liveUsages(ElementInterface $element, ?int $siteId = null): array
    {
        $model = (new RelationsModel((int)($element->getCanonicalId() ?? $element->id), (int)$element->siteId))
            ->withQueryFactory(fn(string $type): mixed => ElementRelations::find($type)); // Craft's defaults: live entries, enabled elements, no drafts
        $siteIds = $siteId === null ? [] : [$siteId];
        $titles = [];
        foreach ($model->getElementsIterator($siteIds, self::NAMED) as $usage) {
            $titles[] = '“' . $usage . '”';
        }
        $global = $siteId === null ? $model->getGlobalUsages() : [];
        foreach (array_slice($global, 0, max(0, self::NAMED - count($titles))) as $usage) {
            $titles[] = '“' . $usage->label . '”';
        }
        return ['count' => $model->getCount($siteIds) + count($global), 'titles' => $titles];
    }

    /** Whether this element in this request is guarded: Pro, a control panel request, a user without the permission. */
    private static function guards(ElementInterface $element): bool
    {
        return ElementRelations::isPro() && ElementRelations::guardsInUse() && self::counts($element);
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
            $names .= ' ' . Craft::t('element-relations', 'in-use-more', ['count' => $count - count($shown)]);
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
        if (($element instanceof \craft\base\NestedElementInterface && $element->getPrimaryOwnerId() !== null) || $element->trashed) {
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
        $live = [Element::STATUS_ENABLED, \craft\elements\Entry::STATUS_LIVE];
        $before = $element::find()->id($element->id)->siteId($element->siteId)->status(null)->one();
        $wasLive = $before instanceof ElementInterface && in_array($before->getStatus(), $live, true);
        if ($wasLive && !$element->enabled) {
            return ['why' => 'disable', 'attribute' => 'enabled', 'siteId' => null];
        }
        // the edit page's sidebar can switch any site off, not just the one being edited (which may stay live)
        foreach (\craft\helpers\ElementHelper::supportedSitesForElement($element) as $site) {
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
            'why' => $status === \craft\elements\Entry::STATUS_PENDING ? 'schedule' : 'expire',
            'attribute' => $status === \craft\elements\Entry::STATUS_PENDING ? 'postDate' : 'expiryDate',
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
        return Craft::t('element-relations', $key, [
            'element' => (string)$element,
            'count' => $usages['count'],
            'names' => self::names($usages['titles'], $usages['count']),
            'site' => $siteId !== null ? (string)Craft::$app->getSites()->getSiteById($siteId)?->getName() : '',
        ]) . ' ' . Craft::t('element-relations', 'in-use-hint', ['count' => $usages['count']]);
    }
}
