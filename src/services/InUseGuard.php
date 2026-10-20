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
    /** Titles named in the message. */
    private const NAMED = 3;

    public static function register(): void
    {
        \yii\base\Event::on(Element::class, Element::EVENT_BEFORE_DELETE, [self::class, 'beforeDelete']);
        \yii\base\Event::on(Element::class, Element::EVENT_BEFORE_SAVE, [self::class, 'beforeSave']);
        \yii\base\Event::on(\craft\services\Elements::class, \craft\services\Elements::EVENT_BEFORE_PERFORM_ACTION, [self::class, 'beforePerformAction']);
        \yii\base\Event::on(\craft\services\Elements::class, \craft\services\Elements::EVENT_BEFORE_DELETE_FOR_SITE, [self::class, 'beforeDeleteForSite']);
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
            throw new \yii\base\UserException($message);
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
            $message = $forSite
                ? self::check($element, (int)$element->siteId, 'in-use-delete-site')
                : self::check($element, null, $disable ? 'in-use-disable' : 'in-use-delete');
            if ($message !== null) {
                $event->isValid = false;
                $event->message = $message;
                return;
            }
        }
    }

    /**
     * Live usages of the element: the number of elements using it (each once, in any of its sites) and the first few
     * titles. With a site, only the usages in that site (a disable for one site); plugin settings count for the
     * element as a whole.
     * @return array{count: int, titles: list<string>}
     */
    public static function liveUsages(ElementInterface $element, ?int $siteId = null): array
    {
        $model = (new RelationsModel((int)($element->getCanonicalId() ?? $element->id), (int)$element->siteId))
            ->withQueryFactory(fn(string $type): mixed => $type::find()); // Craft's defaults: live entries, enabled elements, no drafts
        $siteIds = $siteId === null ? [] : [$siteId];
        // each relating element once, whatever the number of its sites; ids only, so thousands stay cheap
        $ids = [];
        foreach ($model->getElementQueries($siteIds) as $query) {
            foreach ($query->ids() as $id) {
                $ids[(int)$id] = true;
            }
        }
        $titles = [];
        foreach ($model->getElementsIterator($siteIds, batchSize: 10) as $usage) {
            $titles[(int)$usage->id] ??= (string)$usage;
            if (count($titles) >= self::NAMED) {
                break;
            }
        }
        $titles = array_values($titles);
        $global = $siteId === null ? $model->getGlobalUsages() : [];
        foreach (array_slice($global, 0, max(0, self::NAMED - count($titles))) as $usage) {
            $titles[] = $usage->label;
        }
        return ['count' => count($ids) + count($global), 'titles' => $titles];
    }

    /** Whether this element in this request is guarded: Pro, a control panel request, a user without the permission. */
    private static function guards(ElementInterface $element): bool
    {
        if (!ElementRelations::isPro() || !ElementRelations::guardsInUse()) {
            return false;
        }
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
     * What a save changes about the element being live: disabled everywhere, disabled for this site, expired or
     * scheduled (entries). Null when it stays live or was not live before.
     * @return array{why: string, attribute: string, siteId: int|null}|null
     */
    private static function goesOffline(ElementInterface $element): ?array
    {
        $before = $element::find()->id($element->id)->siteId($element->siteId)->status(null)->one();
        if (!$before instanceof ElementInterface || !in_array($before->getStatus(), [Element::STATUS_ENABLED, \craft\elements\Entry::STATUS_LIVE], true)) {
            return null;
        }
        if (in_array($element->getStatus(), [Element::STATUS_ENABLED, \craft\elements\Entry::STATUS_LIVE], true)) {
            return null;
        }
        if (!$element->enabled) {
            return ['why' => 'disable', 'attribute' => 'enabled', 'siteId' => null];
        }
        if (!$element->getEnabledForSite()) {
            return ['why' => 'disable-site', 'attribute' => 'enabled', 'siteId' => (int)$element->siteId];
        }
        $status = $element->getStatus();
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
        $names = implode(', ', array_map(fn(string $title): string => "“{$title}”", $usages['titles']));
        if ($usages['count'] > count($usages['titles'])) {
            $names .= ' ' . Craft::t('element-relations', 'in-use-more', ['count' => $usages['count'] - count($usages['titles'])]);
        }
        return Craft::t('element-relations', $key, [
            'element' => (string)$element,
            'count' => $usages['count'],
            'names' => $names,
            'site' => $siteId !== null ? (string)Craft::$app->getSites()->getSiteById($siteId)?->getName() : '',
        ]) . ' ' . Craft::t('element-relations', 'in-use-hint');
    }
}
