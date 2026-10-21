<?php

declare(strict_types=1);

namespace internetztube\elementRelations\elements\db;

use Craft;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\ElementQuery;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\User;
use craft\helpers\Db;
use craft\models\CategoryGroup;
use craft\models\Section;
use craft\models\Site;
use craft\models\Volume;
use DateTime;
use internetztube\elementRelations\behaviors\ElementQueryBehavior;
use internetztube\elementRelations\elements\RestrictedUsage;
use internetztube\elementRelations\elements\Usage;

/**
 * Element query over all element types at once. Combine with the `usesElement` param:
 *
 * ```php
 * Usage::find()->usesElement($asset)->search('contact')->all(); // Entry, Product, Menu, ... instances
 * ```
 *
 * Filtering, searching, sorting, counting and paging run on the `elements` table across all types. The matched rows
 * are then loaded through their own element type's query (one query per type and site on the page), so every result
 * is a fully populated element of its real class.
 *
 * @extends ElementQuery<int, ElementInterface>
 */
class UsageQuery extends ElementQuery
{
    /**
     * Restrict to these element type classes (the index's type dropdown sends this as criteria).
     * @var class-string<ElementInterface>|class-string<ElementInterface>[]|null
     */
    public mixed $elementTypes = null;

    /**
     * List every site at once, overriding the `siteId` Craft's element index always sends (its site menu/current site).
     * The field's index sets this so a "where is it used?" list covers all sites, with the Site column telling which.
     */
    public bool $allSites = false;

    /**
     * Only what this user may view. Applied automatically for non-admin users in control panel requests (the field's
     * list, the wizard's modal, any element index request for this type); `false` switches it off (server code only).
     *
     * Private on purpose: element index requests apply request criteria to the query with Craft::configure(), so a
     * public property could be switched off from the browser. Only viewableBy() sets it.
     *
     * Restricted queries need `usesElement` targets the user may view, and then only return relating elements that
     * passed Craft's Elements::canView() for that user (see viewablePairs()), so search, sorting and counts never touch
     * anything else. hiddenCount() tells how many usages were left out.
     */
    private User|false|null $_viewableBy = null;

    /** @var array<string, array{pairs: array<int, int[]>}> per request: "<viewer>|<targets>" => viewable pairs */
    private static array $_pairs = [];

    /** @param array<string, mixed> $config */
    public function __construct(array $config = [])
    {
        parent::__construct(Usage::class, $config);
    }

    /**
     * @param class-string<ElementInterface>|class-string<ElementInterface>[]|null $value
     */
    public function elementTypes(mixed $value): static
    {
        $this->elementTypes = $value;
        return $this;
    }

    public function viewableBy(User|false|null $user): static
    {
        $this->_viewableBy = $user;
        return $this;
    }

    /**
     * How many usages of the targets (relating element × site, drafts included) the user may not view.
     * @param int[] $targetIds
     */
    public static function hiddenCount(array $targetIds, User $viewer): int
    {
        if ($viewer->admin || !$targetIds) {
            return 0;
        }
        // the very query the list runs, once without and once with the restriction, so the numbers always add up
        $count = function (User|false $as) use ($targetIds): int {
            $query = Usage::find()->allSites()->status(null)->drafts(null)->viewableBy($as);
            Craft::configure($query, ['usesElement' => $targetIds]);
            return (int)$query->count();
        };
        return max(0, $count(false) - $count($viewer));
    }

    public function allSites(bool $value = true): static
    {
        $this->allSites = $value;
        return $this;
    }

    protected function beforePrepare(): bool
    {
        if ($this->allSites) {
            $this->siteId = Craft::$app->getSites()->getAllSiteIds(); // siteId is normalized before beforePrepare()
        }
        /** @var Query<array-key, mixed> $subQuery prepare() creates it before calling beforePrepare() */
        $subQuery = $this->subQuery;
        $viewer = $this->viewer();
        if ($this->elementTypes) {
            $subQuery->andWhere(['elements.type' => (array)$this->elementTypes]);
        }
        // Date-based statuses (live / pending / expired) live in the entries and products tables. Left joins, so other
        // element types stay in the result (a plain joinElementTable() would inner-join and also drop Craft's type filter).
        $subQuery->leftJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[elements.id]]');
        if (self::hasProducts()) {
            $subQuery->leftJoin(['commerce_products' => '{{%commerce_products}}'], '[[commerce_products.id]] = [[elements.id]]');
        }
        if ($viewer) {
            // a restricted user only ever sees usages of elements they may view, and only usages they may view
            $targetIds = $this->targetIds();
            if (!$targetIds) {
                return false;
            }
            $elementsService = Craft::$app->getElements();
            foreach ($targetIds as $targetId) {
                $target = $elementsService->getElementById($targetId, null, '*');
                if (!$target || !$elementsService->canView($target, $viewer)) {
                    return false;
                }
            }
            $pairs = self::viewablePairs($targetIds, $viewer)['pairs'];
            if (!$pairs) {
                return false;
            }
            $condition = ['or'];
            foreach ($pairs as $siteId => $ids) {
                $condition[] = ['and', ['elements_sites.siteId' => $siteId], ['elements.id' => $ids]];
            }
            $subQuery->andWhere($condition);
        }
        return parent::beforePrepare();
    }

    /**
     * Entry statuses, applied to every element type: an entry or product is live/pending/expired by its dates, every
     * other enabled element counts as live.
     * @return string|array<int|string, mixed>|\yii\db\ExpressionInterface|false|null
     */
    protected function statusCondition(string $status): mixed
    {
        if (!in_array($status, [Entry::STATUS_LIVE, Entry::STATUS_PENDING, Entry::STATUS_EXPIRED], true)) {
            return parent::statusCondition($status);
        }

        $now = new DateTime();
        $now->setTime((int)$now->format('H'), (int)$now->format('i'), 59); // like EntryQuery: cacheable per minute
        $nowDb = Db::prepareDateForDb($now);
        $enabled = ['elements.enabled' => true, 'elements_sites.enabled' => true];
        $dated = self::hasProducts() ? ['entries', 'commerce_products'] : ['entries'];

        $perTable = fn(callable $condition): array => array_map($condition, $dated);
        return match ($status) {
            Entry::STATUS_LIVE => [
                'and',
                $enabled,
                ...$perTable(fn(string $t): array => [
                    'or',
                    ["$t.id" => null], // not an element of this dated type
                    ['and', ['<=', "$t.postDate", $nowDb], ['or', ["$t.expiryDate" => null], ['>', "$t.expiryDate", $nowDb]]],
                ]),
            ],
            Entry::STATUS_PENDING => ['and', $enabled, ['or', ...$perTable(fn(string $t): array => ['>', "$t.postDate", $nowDb])]],
            Entry::STATUS_EXPIRED => ['and', $enabled, ['or', ...$perTable(fn(string $t): array => ['<=', "$t.expiryDate", $nowDb])]],
        };
    }

    /** @return list<int> the `usesElement` targets (ElementQueryBehavior) */
    private function targetIds(): array
    {
        $behavior = $this->getBehavior('elementRelations');
        $targets = $behavior instanceof ElementQueryBehavior ? $behavior->usesElement : null;
        if ($targets === null) {
            return [];
        }
        return array_values(array_filter(array_map(
            fn($target): int => $target instanceof ElementInterface ? (int)$target->id : (int)$target,
            is_array($targets) ? $targets : [$targets],
        )));
    }

    /**
     * The usages (relating element id per site) of the targets that the user may view: candidates from the cache,
     * narrowed by the coarse SQL permissions, then each checked with Elements::canView(). Memoized per request.
     * @param int[] $targetIds
     * @return array{pairs: array<int, int[]>} siteId => viewable element ids
     */
    private static function viewablePairs(array $targetIds, User $viewer): array
    {
        sort($targetIds);
        $key = $viewer->id . '|' . implode(',', $targetIds);
        if (isset(self::$_pairs[$key])) {
            return self::$_pairs[$key];
        }
        $base = (new Query())
            ->select(['id' => 'elements.id', 'siteId' => 'elements_sites.siteId', 'type' => 'elements.type'])
            ->distinct()
            ->from(['cache' => '{{%elementrelations_cache}}'])
            ->innerJoin(['elements' => Table::ELEMENTS], '[[elements.id]] = [[cache.sourcePrimaryOwnerId]]')
            ->innerJoin(['elements_sites' => Table::ELEMENTS_SITES], '[[elements_sites.elementId]] = [[elements.id]] AND [[elements_sites.siteId]] = [[cache.sourceSiteId]]')
            ->leftJoin(['entries' => Table::ENTRIES], '[[entries.id]] = [[elements.id]]')
            ->leftJoin(['assets' => Table::ASSETS], '[[assets.id]] = [[elements.id]]')
            ->leftJoin(['categories' => Table::CATEGORIES], '[[categories.id]] = [[elements.id]]')
            ->where(['cache.targetElementId' => $targetIds, 'elements.dateDeleted' => null, 'elements.revisionId' => null])
            ->andWhere(['not', ['cache.sourceElementId' => null]]);
        /** @var list<array{id: int|string, siteId: int|string, type: string}> $candidates */
        $candidates = $base->andWhere(self::viewCondition($viewer))->all();

        $byTypeAndSite = [];
        foreach ($candidates as $row) {
            $byTypeAndSite[$row['type']][(int)$row['siteId']][] = (int)$row['id'];
        }
        $elementsService = Craft::$app->getElements();
        $pairs = [];
        foreach ($byTypeAndSite as $type => $sites) {
            if (!is_subclass_of($type, ElementInterface::class)) {
                continue;
            }
            foreach ($sites as $siteId => $ids) {
                /** @var ElementInterface[] $loaded */
                $loaded = $type::find()->id($ids)->siteId($siteId)->status(null)->drafts(null)->provisionalDrafts(null)->all();
                foreach ($loaded as $element) {
                    if ($elementsService->canView($element, $viewer)) {
                        $pairs[$siteId][] = (int)$element->id;
                    }
                }
            }
        }
        return self::$_pairs[$key] = ['pairs' => $pairs];
    }

    /** Forgets the memoized permission results (tests, long-running processes after permission changes). */
    public static function resetPermissionCache(): void
    {
        self::$_pairs = [];
    }

    /** The user to restrict to: explicit, or the logged-in non-admin user of a control panel request. */
    private function viewer(): ?User
    {
        if ($this->_viewableBy instanceof User) {
            return $this->_viewableBy->admin ? null : $this->_viewableBy;
        }
        if ($this->_viewableBy === false) {
            return null;
        }
        $request = Craft::$app->getRequest();
        if (!$request instanceof \craft\web\Request || !$request->getIsCpRequest()) {
            return null; // console, queue and site requests are not restricted
        }
        $user = Craft::$app->getUser()->getIdentity();
        return $user && !$user->admin ? $user : null;
    }

    /**
     * The coarse part of Craft's view permissions as SQL, for the core element types: only what the user could see
     * at all (section, volume, category group, global set, other users, sites). Finer rules (other authors' entries,
     * drafts, temporary uploads, plugin authorization events) are checked per element in viewablePairs().
     * @return array<int|string, mixed>
     */
    private static function viewCondition(User $user): array
    {
        // ids of the sections/volumes/groups/sets whose permission (`viewEntries:<uid>`, ...) the user has
        $uidsAllowed = fn(array $items, string $permission): array => array_values(array_filter(array_map(
            fn(Section|Volume|CategoryGroup|GlobalSet $item): ?int => $user->can(sprintf($permission, $item->uid)) ? $item->id : null,
            $items,
        )));
        $sectionIds = $uidsAllowed(Craft::$app->getEntries()->getAllSections(), 'viewEntries:%s');
        $volumeIds = array_values(array_unique([
            ...$uidsAllowed(Craft::$app->getVolumes()->getAllVolumes(), 'viewAssets:%s'),
            ...$uidsAllowed(Craft::$app->getVolumes()->getAllVolumes(), 'viewPeerAssets:%s'),
        ]));
        $groupIds = $uidsAllowed(Craft::$app->getCategories()->getAllGroups(), 'viewCategories:%s');
        $globalSetIds = $uidsAllowed(Craft::$app->getGlobals()->getAllSets(), 'editGlobalSet:%s');
        $core = [Entry::class, Asset::class, Category::class, User::class, GlobalSet::class];

        $condition = [
            'or',
            ['not in', 'elements.type', $core], // plugin types: checked per element in populate()
            ['and', ['elements.type' => Entry::class], ['entries.sectionId' => $sectionIds ?: [0]]],
            ['and', ['elements.type' => Asset::class], ['assets.volumeId' => $volumeIds ?: [0]]],
            ['and', ['elements.type' => Category::class], ['categories.groupId' => $groupIds ?: [0]]],
            ['and', ['elements.type' => GlobalSet::class], ['elements.id' => $globalSetIds ?: [0]]],
            $user->can('viewUsers')
                ? ['elements.type' => User::class]
                : ['and', ['elements.type' => User::class], ['elements.id' => $user->id]],
        ];

        if (Craft::$app->getIsMultiSite()) {
            $siteIds = array_values(array_filter(array_map(
                fn(Site $site): ?int => $user->can("editSite:$site->uid") ? $site->id : null,
                Craft::$app->getSites()->getAllSites(),
            )));
            $condition = ['and', $condition, ['elements_sites.siteId' => $siteIds ?: [0]]];
        }
        return $condition;
    }

    private static function hasProducts(): bool
    {
        return Craft::$app->getPlugins()->isPluginEnabled('commerce');
    }

    /**
     * @param list<array{id: int|string, siteId: int|string}> $rows
     * @return list<ElementInterface>|array<array<string, mixed>>
     */
    public function populate($rows): array
    {
        if ($this->asArray) {
            return parent::populate($rows);
        }

        // the element type of each row (not selected by the base query, and adding it to its select would break
        // Craft's search param, which reuses the query as an `id IN (...)` subquery)
        /** @var array<int, string> $types */
        $types = (new Query())
            ->select(['id', 'type'])
            ->from(Table::ELEMENTS)
            ->where(['id' => array_column($rows, 'id')])
            ->pairs();

        $idsByTypeAndSite = [];
        foreach ($rows as $row) {
            $idsByTypeAndSite[$types[$row['id']] ?? ''][$row['siteId']][] = (int)$row['id'];
        }

        $elements = [];
        foreach ($idsByTypeAndSite as $type => $sites) {
            if (!is_subclass_of($type, ElementInterface::class)) {
                continue;
            }
            foreach ($sites as $siteId => $ids) {
                /** @var ElementInterface[] $loaded */
                $loaded = $type::find()
                    ->id($ids)
                    ->siteId($siteId)
                    ->status(null)
                    ->drafts(null)
                    ->provisionalDrafts(null)
                    ->revisions(null)
                    ->trashed(null)
                    ->all();
                foreach ($loaded as $element) {
                    $elements["$type:$siteId:$element->id"] = $element;
                    Usage::$via["$element->id:$siteId"] = []; // reset: an earlier list in this request may have used another target
                }
            }
        }

        // through which field(s) each loaded element uses the target (for the index's "Field" column)
        $targetIds = $this->targetIds();
        if ($targetIds && $elements) {
            /** @var list<array{sourcePrimaryOwnerId: int|string, sourceSiteId: int|string, fieldId: int|string|null, type: string}> $rowsVia */
            $rowsVia = (new Query())
                ->select(['sourcePrimaryOwnerId', 'sourceSiteId', 'fieldId', 'type'])
                ->distinct()
                ->from('{{%elementrelations_cache}}')
                ->where(['targetElementId' => $targetIds, 'sourcePrimaryOwnerId' => array_column($rows, 'id')])
                ->all();
            foreach ($rowsVia as $via) {
                Usage::$via["{$via['sourcePrimaryOwnerId']}:{$via['sourceSiteId']}"][] = ['fieldId' => $via['fieldId'] ? (int)$via['fieldId'] : null, 'type' => $via['type']];
            }
        }

        // keep the order of this query (sorting, pagination); the stand-in is a last line of defence only, restricted
        // queries already contain nothing but viewable elements (see beforePrepare())
        $viewer = $this->viewer();
        $elementsService = Craft::$app->getElements();
        $ordered = [];
        foreach ($rows as $row) {
            $key = ($types[$row['id']] ?? '') . ":{$row['siteId']}:{$row['id']}";
            if (isset($elements[$key])) {
                $ordered[] = $viewer && !$elementsService->canView($elements[$key], $viewer)
                    ? RestrictedUsage::fromElement($elements[$key])
                    : $elements[$key];
            }
        }
        return $ordered;
    }
}
