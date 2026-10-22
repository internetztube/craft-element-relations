<?php

declare(strict_types=1);

namespace internetztube\elementRelations\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\base\FieldLayoutElement;
use craft\base\PreviewableFieldInterface;
use craft\base\SortableFieldInterface;
use craft\enums\Color;
use craft\errors\FieldNotFoundException;
use craft\fieldlayoutelements\CustomField;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\StringHelper;
use craft\models\FieldLayoutTab;
use craft\models\GqlSchema;
use GraphQL\Type\Definition\Type;
use internetztube\elementRelations\ElementRelations;
use internetztube\elementRelations\elements\Usage;
use internetztube\elementRelations\elements\db\UsageQuery;
use internetztube\elementRelations\gql\types\Relations as RelationsType;
use internetztube\elementRelations\helpers\ElementSummary;
use internetztube\elementRelations\models\RelationsModel;
use internetztube\elementRelations\services\ReplaceService;
use yii\base\InvalidConfigException;

/**
 * Read-only field that shows where the element is used. It stores nothing; the value is computed from the cache table.
 * The control panel UI is Craft's own: element indexes for the field, element chips for index columns.
 */
class ElementRelationsField extends Field implements PreviewableFieldInterface, SortableFieldInterface
{
    /**
     * Dropdowns (element types, sites; with counts) for the toolbar of the index being rendered right now; picked up by
     * the `cp.elements.toolbar` hook (see ElementRelations::init()), templates/_field/filter-menu.twig. Craft offers no
     * other way to add toolbar items.
     * @var list<array{param: string, label: string, allLabel: string, options: array<string|int, array{label: string, count: int}>}>
     */
    public static array $toolbarMenus = [];

    public const VIEW_MODE_TABLE = 'table';
    public const VIEW_MODE_CARDS = 'cards';

    /** Initial view mode of the index; authors can switch it and Craft remembers their choice. */
    public string $viewMode = self::VIEW_MODE_TABLE;
    public bool $showStatusMenu = true;
    /** Elements per page */
    public ?int $pageSize = 20;
    /** Chips shown in element index columns before the link to the total; each one is an element to load per row */
    public ?int $previewLimit = 5;

    public static function displayName(): string
    {
        return Craft::t("element-relations", "Element Relations");
    }

    public static function icon(): string
    {
        return '@internetztube/elementRelations/icon-mask.svg';
    }

    public static function isMultiInstance(): bool
    {
        return false;
    }

    public static function supportedTranslationMethods(): array
    {
        return [self::TRANSLATION_METHOD_NONE];
    }

    public static function dbType(): array|string|null
    {
        return null;
    }

    /** @return array<int, array<int|string, mixed>> */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['viewMode'], 'in', 'range' => [self::VIEW_MODE_TABLE, self::VIEW_MODE_CARDS]];
        $rules[] = [['pageSize'], 'default', 'value' => 20];
        $rules[] = [['pageSize'], 'integer', 'min' => 1, 'max' => 500];
        $rules[] = [['previewLimit'], 'default', 'value' => 5];
        $rules[] = [['previewLimit'], 'integer', 'min' => 1, 'max' => 50];
        return $rules;
    }

    /**
     * The same settings Craft's relational fields offer for their element index, built with Craft's form helpers.
     */
    public function getSettingsHtml(): ?string
    {
        $html = Cp::selectFieldHtml([
            'label' => Craft::t('app', 'View Mode'),
            'instructions' => Craft::t('element-relations', 'settings-view-mode-instructions'),
            'id' => 'viewMode',
            'name' => 'viewMode',
            'options' => [
                ['label' => Craft::t('app', 'Table'), 'value' => self::VIEW_MODE_TABLE],
                ['label' => Craft::t('app', 'Cards'), 'value' => self::VIEW_MODE_CARDS],
            ],
            'value' => $this->viewMode,
            'errors' => $this->getErrors('viewMode'),
        ]);
        $html .= Cp::lightswitchFieldHtml([
            'label' => Craft::t('element-relations', 'settings-show-status-menu'),
            'id' => 'showStatusMenu',
            'name' => 'showStatusMenu',
            'on' => $this->showStatusMenu,
        ]);
        $html .= Cp::textFieldHtml([
            'label' => Craft::t('element-relations', 'settings-page-size'),
            'id' => 'pageSize',
            'name' => 'pageSize',
            'type' => 'number',
            'min' => 1,
            'size' => 4,
            'value' => $this->pageSize,
            'errors' => $this->getErrors('pageSize'),
        ]);
        $html .= Cp::textFieldHtml([
            'label' => Craft::t('element-relations', 'settings-preview-limit'),
            'id' => 'previewLimit',
            'name' => 'previewLimit',
            'type' => 'number',
            'min' => 1,
            'max' => 50,
            'size' => 4,
            'value' => $this->previewLimit,
            'errors' => $this->getErrors('previewLimit'),
        ]);
        return $html;
    }

    public function normalizeValue(mixed $value, ?ElementInterface $element = null): ?RelationsModel
    {
        if (!$element || !$element->id) {
            return null;
        }
        // Drafts and revisions cannot be relation targets, so always look at the canonical element.
        return new RelationsModel($element->getCanonicalId() ?? $element->id, (int)$element->siteId);
    }

    /**
     * Used by element exports (CSV/JSON/XML) and `getSerializedFieldValues()`.
     * @param RelationsModel|null $value
     */
    public function serializeValue(mixed $value, ?ElementInterface $element = null): mixed
    {
        if (!$value instanceof RelationsModel) {
            return null;
        }
        $viewer = self::restrictedViewer();
        $ids = $viewer
            ? array_values(array_unique(array_map('intval', self::usageQuery($value, $viewer)->ids()))) // exports in the CP
            : $value->getSourceElementIds([]);
        return ['count' => count($ids), 'elementIds' => $ids];
    }

    public function serializeValueForDb(mixed $value, ElementInterface $element): mixed
    {
        return null;
    }

    public function includeInGqlSchema(GqlSchema $schema): bool
    {
        return true;
    }

    /** @return array{name: string|null, type: RelationsType, resolve: \Closure(ElementInterface): mixed} */
    public function getContentGqlType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => RelationsType::getType(),
            'resolve' => fn(ElementInterface $source): mixed => $source->{$this->handle},
        ];
    }

    /**
     * Sort option for element indexes: number of relating elements in the index's site, plus a half when the element
     * is used in another site, so "used in other sites" (0.5) ranks above unused (0) and below used here (1 and more).
     * Craft only takes a string here (it appends the direction), so the subqueries are built with the query builder
     * and passed as their SQL.
     * @return array{label: string, orderBy: string, attribute: string, defaultDir: string}
     */
    public function getSortOption(): array
    {
        // elements as the list counts them: the usages of the element in the index's site, a relating element once
        // (whatever its sites and however many fields point at the target), no trashed elements, revisions or
        // provisional drafts; plugin settings count as one each.
        // The source check is a correlated EXISTS (one primary key lookup per cache row), not a join: this runs once
        // per element of the index, and with a join PostgreSQL may hash all of `elements` each time when it expects
        // many usages per target (a large, skewed cache table), which never finishes.
        $liveSource = (new \craft\db\Query())
            ->select(new \yii\db\Expression('1'))
            ->from(['sources' => \craft\db\Table::ELEMENTS])
            ->leftJoin(['drafts' => \craft\db\Table::DRAFTS], '[[drafts.id]] = [[sources.draftId]]')
            ->where(['sources.id' => new \yii\db\Expression('[[elementrelations_cache.sourcePrimaryOwnerId]]')])
            ->andWhere(['sources.dateDeleted' => null, 'sources.revisionId' => null])
            ->andWhere(['or', ['drafts.id' => null], ['<>', 'drafts.provisional', true]]);
        $usages = (new \craft\db\Query())
            ->select(['elementrelations_cache.sourcePrimaryOwnerId', 'elementrelations_cache.globalKey'])
            ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])
            ->where(['elementrelations_cache.targetElementId' => new \yii\db\Expression('[[elements.id]]')])
            ->andWhere(['elementrelations_cache.targetSiteId' => new \yii\db\Expression('[[elements_sites.siteId]]')])
            ->andWhere(['or', ['elementrelations_cache.sourceElementId' => null], ['exists', $liveSource]])
            ->groupBy(['elementrelations_cache.sourcePrimaryOwnerId', 'elementrelations_cache.globalKey']);
        $count = (new \craft\db\Query())->select(['COUNT(*)'])->from(['usages' => $usages]);
        $elsewhere = (new \craft\db\Query())
            ->select(new \yii\db\Expression('1'))
            ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])
            ->where(['elementrelations_cache.targetElementId' => new \yii\db\Expression('[[elements.id]]')])
            ->andWhere(['<>', 'elementrelations_cache.targetSiteId', new \yii\db\Expression('[[elements_sites.siteId]]')])
            ->andWhere(['or', ['elementrelations_cache.sourceElementId' => null], ['exists', $liveSource]]);
        return [
            'label' => Craft::t('site', (string)$this->name),
            'orderBy' => '((' . $count->createCommand()->getRawSql() . ') + CASE WHEN EXISTS (' . $elsewhere->createCommand()->getRawSql() . ') THEN 0.5 ELSE 0 END)',
            'attribute' => "field:$this->uid",
            'defaultDir' => 'desc',
        ];
    }

    /**
     * The field is a column of this element index: once Craft has loaded the page's elements, load the column's data
     * for all of them (RelationsModel::prefetch()), not several queries per row in getPreviewHtml(). Users with limited
     * permissions get their per-row lists (see getPreviewHtml()).
     */
    public function modifyElementIndexQuery(\craft\elements\db\ElementQueryInterface $query): void
    {
        parent::modifyElementIndexQuery($query);
        if (!$query instanceof \craft\elements\db\ElementQuery || self::restrictedViewer()) {
            return;
        }
        $query->on(\craft\elements\db\ElementQuery::EVENT_AFTER_POPULATE_ELEMENTS, function (\craft\events\PopulateElementsEvent $event): void {
            $siteId = null;
            $ids = [];
            foreach ($event->elements as $element) {
                if ($element instanceof ElementInterface && $element->id) {
                    $ids[] = (int)($element->getCanonicalId() ?? $element->id);
                    $siteId ??= (int)$element->siteId;
                }
            }
            RelationsModel::prefetch($ids, $this->previewLimit ?? 5, $siteId ?? (int)Craft::$app->getSites()->getPrimarySite()->id);
        });
    }

    /**
     * Element index column: what uses the element in this site (its version here), as Craft chips (with Craft's "+N"
     * popover), like the field's list; a note when only its versions in other sites are used.
     * @param RelationsModel|null $value
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof RelationsModel) {
            return '';
        }
        $value = $value->forTargetSite();
        if (!$value->getIsInUseFast([])) {
            return match (true) {
                $element->getIsUnpublishedDraft() => '',
                // used, only not in this site: never "Unused"; a status label like "Unused", and the sites
                (bool)$value->getOtherSiteCounts() => Html::tag('div',
                    (string)Cp::statusLabelHtml(['color' => Color::Sky, 'icon' => 'globe', 'label' => Craft::t('element-relations', 'preview-other-sites')])
                    . Html::tag('div', Html::encode(implode(', ', array_map(
                        fn(int $siteId): string => Craft::t('site', (string)Craft::$app->getSites()->getSiteById($siteId, true)?->getName()),
                        array_keys($value->getOtherSiteCounts()),
                    ))), ['class' => 'light smalltext']),
                    ['data-element-relations-other-sites' => true],
                ),
                default => $this->unusedLabelHtml(),
            };
        }

        // a usage is an element in one site; the first few as chips, then the total
        $viewer = self::restrictedViewer();
        if ($viewer) {
            // control panel users with limited permissions: only what they may view, plus how much they may not
            $visibleQuery = self::usageQuery($value, $viewer);
            $elements = (clone $visibleQuery)->limit($this->previewLimit ?? 5)->all();
            $total = (int)$visibleQuery->count();
            $hidden = UsageQuery::hiddenCount([$value->getElementId()], $viewer, targetSiteId: $value->getSiteId());
        } else {
            $elements = $value->getPreviewElements($this->previewLimit ?? 5);
            $total = $value->getCountFast([]);
            $hidden = 0;
        }
        $html = Cp::elementPreviewHtml($elements, showThumb: false);
        if ($total > count($elements)) {
            $html .= Html::a(
                Craft::t('element-relations', 'preview-total', ['count' => Craft::$app->getFormatter()->asInteger($total)]),
                $element->getCpEditUrl() . (($tab = $this->getTabHash($element)) ? "#$tab" : ''),
                ['class' => 'light smalltext'],
            );
        }
        if ($hidden) {
            $html .= Html::tag('div', Html::encode(Craft::t('element-relations', 'restricted-count', ['count' => $hidden])), ['class' => 'light smalltext']);
        }
        if ($value->getIsUsedInSeomaticGlobalSettings()) {
            $html .= Html::tag('div', Craft::t('element-relations', 'field-value-seomatic-global'), ['class' => 'light']);
        }
        return $html;
    }

    /**
     * The field: Craft's own element index over the virtual Usage type, i.e. one list of everything that uses the
     * element in this site (its version here; the relating elements may be in any site), across all element types,
     * with Craft's search, sorting, status/drafts menu and pagination. Below it: non-element usages (plugin settings)
     * as Craft chips, and the other sites whose versions of the element are used.
     * @param RelationsModel|null $value
     */
    public function getInputHtml(mixed $value, ?ElementInterface $element = null): string
    {
        if (!$element || !$value instanceof RelationsModel) {
            return $this->hintHtml(Craft::t('element-relations', 'field-hint-not-saved'));
        }
        if ($element->getIsUnpublishedDraft()) {
            return $this->hintHtml(Craft::t('element-relations', 'field-hint-draft'));
        }

        $value = $value->forTargetSite();
        $globalUsages = $value->getGlobalUsages();
        $otherSites = $this->otherSitesHtml($value, $element, $value->getIsInUseFast([]) || $globalUsages);
        if (!$value->getIsInUseFast([]) && !$globalUsages) {
            return $otherSites ?: $this->unusedLabelHtml();
        }

        $html = '';
        if ($value->getIsInUseFast([])) {
            self::$toolbarMenus = self::toolbarMenus($value);
            Usage::$listedTypes = $value->getElementTypes(); // the View menu offers these types' columns
            $html .= Cp::elementIndexHtml(Usage::class, [
                'context' => 'embedded-index',
                'id' => 'element-relations-' . StringHelper::randomString(8),
                'sources' => false,
                'showStatusMenu' => $this->showStatusMenu,
                // Craft's index is per site (its JS always sends a siteId); the relating elements can be in any site
                // (a cross-site relation), so no site menu and `allSites` on the query (see UsageQuery), narrowed to
                // the element in this site by `usesElementSite`.
                'showSiteMenu' => false,
                'defaultViewMode' => $this->viewMode,
                'jsSettings' => [
                    'criteria' => ['usesElement' => $value->getElementId(), 'usesElementSite' => $value->getSiteId(), 'allSites' => true],
                    // the same target as the index's base condition: Craft sends it with every request of the index,
                    // the filter HUD's too, so the filter offers the rules of the types in this list
                    'condition' => self::listCondition($value->getElementId(), $value->getSiteId())->getConfig(),
                    // not `static`: that also drops the actions (Pro: "Replace…") and their checkboxes
                    'inlineEditable' => false,
                    'canHaveDrafts' => true, // the status menu's "Drafts" option only takes effect with this
                    'batchSize' => $this->pageSize ?? 20,
                    'storageKey' => 'elementRelations',
                ],
            ]);
            self::$toolbarMenus = [];
            Usage::$listedTypes = null;
            if ($viewer = self::restrictedViewer()) {
                // so nobody takes the element for unused when the usages are only hidden from them
                $hidden = UsageQuery::hiddenCount([$value->getElementId()], $viewer, targetSiteId: $value->getSiteId());
                if ($hidden) {
                    $html .= Html::tag('p', Html::encode(Craft::t('element-relations', 'restricted-count-input', ['count' => $hidden])), ['class' => 'light', 'data-element-relations-hidden' => $hidden]);
                }
            }
            if (ElementRelations::upsells()) {
                $html .= self::upsellHtml('upsell-replace');
            }
        }
        if ($globalUsages) {
            // Craft's `flex` utility would center (and shrink) the index, so plain block layout with a spaced heading
            $html .= Html::tag('h3', Html::encode(Craft::t('element-relations', 'field-headline-global')), ['class' => 'element-relations-heading', 'style' => 'margin-top: var(--m);'])
                . ElementSummary::globalUsagesHtml($globalUsages);
        }
        $html .= $otherSites;
        // the element the list belongs to, for the "Replace…" action on its ticked usages (elements/actions/ReplaceUsages)
        return Html::tag('div', $html, ['class' => 'element-relations', 'data' => [
            'element-relations-from' => $value->getElementId(),
            'element-relations-site' => $element->siteId,
        ]]);
    }

    /**
     * HTML id of the field layout tab containing this field, so links can deep-link to it.
     */
    public function getTabHash(ElementInterface $element): ?string
    {
        return collect($element->getFieldLayout()?->getTabs() ?? [])
            ->first(function (FieldLayoutTab $tab): bool {
                return collect($tab->getElements())
                    ->map(function (FieldLayoutElement $layoutElement): ?FieldInterface {
                        try {
                            return $layoutElement instanceof CustomField ? $layoutElement->getField() : null;
                        } catch (FieldNotFoundException|InvalidConfigException) {
                            return null; // field deleted but still referenced in the layout
                        }
                    })
                    ->contains(fn(?FieldInterface $field): bool => $field?->id === $this->id);
            })
            ?->getHtmlId();
    }

    /**
     * The wizard's review: how many of the usages (all, or the ticked ones) keep no revision when replaced (element
     * types without revisions, sections with versioning off); empty when all get one.
     * @param list<int>|null $onlyOwnerIds
     */
    public static function revisionHintFor(ElementInterface $element, ?array $onlyOwnerIds = null): string
    {
        $value = new RelationsModel((int)$element->id, (int)$element->siteId);
        if ($onlyOwnerIds === null && ($viewer = self::restrictedViewer())) {
            $onlyOwnerIds = self::usageQuery($value, $viewer)->ids(); // what this user may replace in
        }
        $coverage = ReplaceService::revisionCoverage($value->getElementId(), $onlyOwnerIds);
        $without = $coverage['unsupported'] + $coverage['versioningOff'];
        return $without ? Craft::t('element-relations', 'replace-revision-hint', ['count' => $without]) : '';
    }

    /**
     * The type dropdown of the field's index, only when the list has more than one type; the counts are what this
     * user's list shows (UsageQuery restricts by view permissions). No site dropdown: the list is the element's usages
     * in this site.
     * @return list<array{param: string, label: string, allLabel: string, options: array<string|int, array{label: string, count: int}>}>
     */
    private static function toolbarMenus(RelationsModel $value): array
    {
        $viewer = self::restrictedViewer();
        $types = $value->getCountsByType();
        if ($viewer) {
            $types = array_filter(array_map(fn(string $type): int => (int)self::usageQuery($value, $viewer)->elementTypes($type)->count(), array_combine(array_keys($types), array_keys($types))));
        }
        // "All …": the list's elements, each once
        $total = $viewer ? (int)self::usageQuery($value, $viewer)->count() : $value->getCountFast([]);
        $menus = [];
        if (count($types) > 1) {
            $options = [];
            foreach ($types as $type => $count) {
                // some plugin element types don't define a plural name and inherit "Elements"
                $label = $type::pluralDisplayName();
                $options[$type] = ['label' => $label === Craft::t('app', 'Elements') ? $type::displayName() : $label, 'count' => $count];
            }
            $menus[] = ['param' => 'elementTypes', 'label' => Craft::t('element-relations', 'field-column-type'), 'allLabel' => Craft::t('element-relations', 'field-all-types'), 'options' => $options, 'total' => $total];
        }
        return $menus;
    }

    /** The list's base condition: "uses element X in site S" (see UsesElementConditionRule). */
    private static function listCondition(int $elementId, int $siteId): \craft\elements\conditions\ElementConditionInterface
    {
        $condition = Usage::createCondition();
        $condition->addConditionRule(Craft::createObject(['class' => \internetztube\elementRelations\elements\conditions\UsesElementConditionRule::class, 'elementId' => $elementId, 'siteId' => $siteId]));
        return $condition;
    }

    /**
     * All usages of the element (forTargetSite(): of its version in that site; relating elements of every site, every
     * status, drafts included) as the given user may see them.
     */
    private static function usageQuery(RelationsModel $value, \craft\elements\User $viewer): UsageQuery
    {
        $query = Usage::find()->allSites()->status(null)->drafts(null)->viewableBy($viewer);
        Craft::configure($query, ['usesElement' => $value->getElementId(), 'usesElementSite' => $value->getTargetSiteId()]); // behavior params, see ElementQueryBehavior
        return $query;
    }

    /**
     * The sites whose versions of the element are used, with how many relating elements, each linking to the element's
     * edit page there (the field's tab); empty when there are none. "Also used in these other sites" under a list,
     * "Used in other sites" when nothing uses it here: an element used in another site is never shown as unused. A site
     * the user may not edit is named without a link.
     */
    private function otherSitesHtml(RelationsModel $value, ElementInterface $element, bool $usedHere): string
    {
        $items = [];
        $tab = $this->getTabHash($element);
        $viewer = self::restrictedViewer();
        foreach ($value->getOtherSiteCounts() as $siteId => $count) {
            $site = Craft::$app->getSites()->getSiteById($siteId, true);
            if (!$site) {
                continue;
            }
            // Craft's site chip: the site's name (linking to the element in that site) over the number of usages
            $name = Html::encode(Craft::t('site', $site->getName()));
            if (!$viewer || $viewer->can("editSite:$site->uid")) {
                $name = Html::a($name, \craft\helpers\UrlHelper::urlWithParams((string)$element->getCpEditUrl(), ['site' => $site->handle]) . ($tab ? "#$tab" : ''));
            }
            $items[] = Cp::chipHtml($site, ['autoReload' => false, 'labelHtml' => Html::tag('div', Html::tag('div', $name)
                . Html::tag('div', Html::encode(Craft::t('element-relations', 'field-other-site-count', ['count' => $count])), ['class' => ['smalltext', 'light']]), ['class' => 'chip-label'])]);
        }
        if (!$items) {
            return '';
        }
        $chips = Html::tag('div', implode('', $items), ['class' => 'flex flex-wrap gap-xs', 'data-element-relations-other-sites' => true]);
        if (!$usedHere) {
            // in place of the red "Unused" label: the same kind of label, then the sites
            return (string)Cp::statusLabelHtml(['color' => Color::Sky, 'icon' => 'globe', 'label' => Craft::t('element-relations', 'preview-other-sites')])
                . Html::tag('div', $chips, ['style' => 'margin-top: var(--s);']);
        }
        return Html::tag('h3', Html::encode(Craft::t('element-relations', 'field-headline-other-sites')), ['class' => 'element-relations-heading', 'style' => 'margin-top: var(--m);']) . $chips;
    }

    /** The logged-in non-admin user of a control panel request, whose view permissions apply; null otherwise. */
    /** The control panel user whose view permissions apply: a non-admin in a control panel request, else null. */
    public static function restrictedViewer(): ?\craft\elements\User
    {
        $request = Craft::$app->getRequest();
        if (!$request instanceof \craft\web\Request || !$request->getIsCpRequest()) {
            return null;
        }
        $user = Craft::$app->getUser()->getIdentity();
        return $user && !$user->admin ? $user : null;
    }

    private function unusedLabelHtml(): string
    {
        $html = (string)Cp::statusLabelHtml(['color' => Color::Red, 'label' => Craft::t('element-relations', 'field-value-unused')]);
        return ElementRelations::upsells() ? $html . self::upsellHtml('upsell-unused') : $html;
    }

    /** One quiet line under the list pointing out what Pro would do here, with the link to buy it (admins on Lite). */
    private static function upsellHtml(string $key): string
    {
        $icon = Html::tag('span', Html::svg(Craft::getAlias('@internetztube/elementRelations/icon-mask.svg')), ['class' => 'cp-icon small', 'aria-hidden' => 'true']);
        $link = Html::a(Html::encode(Craft::t('element-relations', 'upsell-link')), ElementRelations::upgradeUrl());
        return Html::tag('p', $icon . Html::tag('span', Html::encode(Craft::t('element-relations', $key)) . ' ' . $link), [
            'class' => 'light smalltext flex gap-xs',
            'style' => 'margin-top: var(--s); flex-wrap: nowrap;',
            'data-element-relations-upsell' => true,
        ]);
    }

    private function hintHtml(string $text): string
    {
        return Html::tag('p', Html::encode($text), ['class' => 'light']);
    }
}
