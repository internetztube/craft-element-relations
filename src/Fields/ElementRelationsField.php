<?php

declare(strict_types=1);

namespace Internetztube\ElementRelations\Fields;

use CraftCms\Cms\Cp\Html\ElementHtml;
use CraftCms\Cms\Cp\Html\ElementIndexHtml;
use CraftCms\Cms\Cp\Html\PreviewHtml;
use CraftCms\Cms\Cp\Html\StatusHtml;
use CraftCms\Cms\Database\Table;
use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Element\ElementCollection;
use CraftCms\Cms\Element\Queries\Contracts\ElementQueryInterface;
use CraftCms\Cms\Element\Queries\ElementQuery;
use CraftCms\Cms\Field\Contracts\PreviewableFieldInterface;
use CraftCms\Cms\Field\Contracts\SortableFieldInterface;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Field\FieldContext;
use CraftCms\Cms\FieldLayout\FieldLayoutTab;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\Form\Contracts\Control;
use CraftCms\Cms\Form\Controls\Choice;
use CraftCms\Cms\Form\Controls\Lightswitch;
use CraftCms\Cms\Form\Controls\Number;
use CraftCms\Cms\Form\Form;
use CraftCms\Cms\Form\FormContext;
use CraftCms\Cms\Form\Nodes\Field as FormField;
use CraftCms\Cms\Gql\Data\GqlSchema;
use CraftCms\Cms\Shared\Enums\Color;
use CraftCms\Cms\Support\Facades\HtmlStack;
use CraftCms\Cms\View\HtmlFragment;
use CraftCms\Cms\Support\Facades\I18N;
use CraftCms\Cms\Site\Sites;
use CraftCms\Cms\Support\Html;
use CraftCms\Cms\Support\Str;
use CraftCms\Cms\Support\Url;
use CraftCms\Cms\User\Elements\User;
use GraphQL\Type\Definition\Type;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Internetztube\ElementRelations\ElementRelations;
use Internetztube\ElementRelations\Elements\Conditions\UsesElementConditionRule;
use Internetztube\ElementRelations\Elements\Queries\UsageQuery;
use Internetztube\ElementRelations\Elements\Usage;
use Internetztube\ElementRelations\Form\HtmlControl;
use Internetztube\ElementRelations\Gql\Types\Relations as RelationsType;
use Internetztube\ElementRelations\Models\CacheRecord;
use Internetztube\ElementRelations\Models\RelationsModel;
use Internetztube\ElementRelations\Services\ReplaceService;
use Internetztube\ElementRelations\Support\ElementSummary;
use Internetztube\ElementRelations\Support\Sql;
use Override;

use function CraftCms\Cms\t;

/**
 * Read-only field that shows where the element is used. It stores nothing; the value is computed from the cache table.
 * The control panel UI is Craft's own: element indexes for the field, element chips for index columns.
 */
class ElementRelationsField extends Field implements PreviewableFieldInterface, SortableFieldInterface
{
    /**
     * Dropdowns (element types; with counts) for the toolbar of the index being rendered right now; picked up by the
     * `cp.elements.toolbar` hook (see ElementRelations::boot()), resources/views/_field/filter-menu.twig. Craft offers
     * no other way to add toolbar items.
     * @var list<array{param: string, label: string, allLabel: string, options: array<string|int, array{label: string, count: int}>, total: int}>
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

    #[Override]
    public static function displayName(): string
    {
        return t('Element Relations', category: 'element-relations');
    }

    #[Override]
    public static function icon(): string
    {
        return ElementRelations::iconPath();
    }

    #[Override]
    public static function isMultiInstance(): bool
    {
        return false;
    }

    #[Override]
    public static function dbType(): array|string|null
    {
        return null;
    }

    /** @return array<string, mixed> */
    #[Override]
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'viewMode' => [Rule::in([self::VIEW_MODE_TABLE, self::VIEW_MODE_CARDS])],
            'pageSize' => ['nullable', 'integer', 'min:1', 'max:500'],
            'previewLimit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
    }

    /** The same settings Craft's relational fields offer for their element index. */
    #[Override]
    public function settingsForm(FormContext $context = new FormContext()): ?Form
    {
        return Form::make([
            FormField::make(t('View Mode'), Choice::make('viewMode')->options([
                ['label' => t('Table'), 'value' => self::VIEW_MODE_TABLE],
                ['label' => t('Cards'), 'value' => self::VIEW_MODE_CARDS],
            ])->value($this->viewMode))->instructions(t('settings-view-mode-instructions', category: 'element-relations')),
            FormField::make(t('settings-show-status-menu', category: 'element-relations'), Lightswitch::make('showStatusMenu')->value($this->showStatusMenu)),
            FormField::make(t('settings-page-size', category: 'element-relations'), Number::make('pageSize')->min(1)->size(4)->value($this->pageSize)),
            FormField::make(t('settings-preview-limit', category: 'element-relations'), Number::make('previewLimit')->min(1)->max(50)->size(4)->value($this->previewLimit)),
        ]);
    }

    #[Override]
    public function normalizeValue(mixed $value, ?ElementInterface $element): ?RelationsModel
    {
        if (!$element || !$element->id) {
            return null;
        }
        // Drafts and revisions cannot be relation targets, so always look at the canonical element.
        return new RelationsModel((int)($element->getCanonicalId() ?? $element->id), (int)$element->siteId);
    }

    /**
     * Used by element exports and `getSerializedFieldValues()`.
     * @param RelationsModel|null $value
     * @return array{count: int, elementIds: list<int>}|null
     */
    #[Override]
    public function serializeValue(mixed $value, ?ElementInterface $element): mixed
    {
        if (!$value instanceof RelationsModel) {
            return null;
        }
        $viewer = ElementRelations::restrictedViewer();
        $ids = $viewer
            ? array_values(array_unique(array_map(intval(...), self::usageQuery($value, $viewer)->ids()))) // exports in the CP
            : $value->getSourceElementIds([]);
        return ['count' => count($ids), 'elementIds' => $ids];
    }

    #[Override]
    public function serializeValueForDb(mixed $value, ElementInterface $element): mixed
    {
        return null;
    }

    #[Override]
    public function includeInGqlSchema(GqlSchema $schema): bool
    {
        return true;
    }

    /** @return array{name: string|null, type: Type, resolve: \Closure(ElementInterface): mixed} */
    #[Override]
    public function getContentGqlType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => RelationsType::getType(),
            'resolve' => fn(ElementInterface $source): mixed => $source->getFieldValue((string)$this->handle),
        ];
    }

    /**
     * Sort option for element indexes: number of relating elements in the index's site, plus a half when the element
     * is used in another site, so "used in other sites" (0.5) ranks above unused (0) and below used here (1 and more).
     * @return array{label: string, orderBy: string, attribute: string, defaultDir: string}
     */
    #[Override]
    public function getSortOption(): array
    {
        // elements as the list counts them: the usages of the element in the index's site, a relating element once
        // (whatever its sites and however many fields point at the target), no trashed elements, revisions or
        // provisional drafts; plugin settings count as one each.
        // The source check is a correlated EXISTS (one primary key lookup per cache row), not a join: this runs once
        // per element of the index, and with a join PostgreSQL may hash all of `elements` each time when it expects
        // many usages per target (a large, skewed cache table), which never finishes.
        $cache = CacheRecord::TABLE;
        $liveSource = fn(Builder $sources) => $sources
            ->selectRaw('1')
            ->from(Table::ELEMENTS . ' as sources')
            ->leftJoin(Table::DRAFTS . ' as er_drafts', 'er_drafts.id', '=', 'sources.draftId')
            ->whereColumn('sources.id', "$cache.sourcePrimaryOwnerId")
            ->whereNull('sources.dateDeleted')
            ->whereNull('sources.revisionId')
            // no bound value: the SQL is written out (toRawSql), where `false` would become a 0 PostgreSQL rejects
            ->where(fn(Builder $draft) => $draft->whereNull('er_drafts.id')->orWhere('er_drafts.provisional', Sql::raw('false')));
        $usages = DB::table($cache)
            ->select(["$cache.sourcePrimaryOwnerId", "$cache.globalKey"])
            ->whereColumn("$cache.targetElementId", 'elements.id')
            ->whereColumn("$cache.targetSiteId", 'elements_sites.siteId')
            ->where(fn(Builder $live) => $live->whereNull("$cache.sourceElementId")->orWhereExists($liveSource))
            ->groupBy(["$cache.sourcePrimaryOwnerId", "$cache.globalKey"]);
        $count = DB::query()->selectRaw('COUNT(*)')->fromSub($usages, 'usages');
        $elsewhere = DB::table($cache)
            ->selectRaw('1')
            ->whereColumn("$cache.targetElementId", 'elements.id')
            ->whereColumn("$cache.targetSiteId", '<>', 'elements_sites.siteId')
            ->where(fn(Builder $live) => $live->whereNull("$cache.sourceElementId")->orWhereExists($liveSource));
        return [
            'label' => t((string)$this->name, category: 'site'),
            // SQL as a string: Craft adds the direction to a field's string orderBy, not to an expression
            'orderBy' => '((' . $count->toRawSql() . ') + CASE WHEN EXISTS (' . $elsewhere->toRawSql() . ') THEN 0.5 ELSE 0 END)',
            'attribute' => isset($this->layoutElement->handle) ? "fieldInstance:{$this->layoutElement->uid}" : "field:$this->uid",
            'defaultDir' => 'desc',
        ];
    }

    /**
     * The field is a column of this element index: once Craft has loaded the page's elements, load the column's data
     * for all of them (RelationsModel::prefetch()), not several queries per row in getPreviewHtml(). Users with limited
     * permissions get their per-row lists (see getPreviewHtml()).
     */
    #[Override]
    public function modifyElementIndexQuery(ElementQueryInterface $query): void
    {
        parent::modifyElementIndexQuery($query);
        if (!$query instanceof ElementQuery || ElementRelations::restrictedViewer()) {
            return;
        }
        $query->afterQuery(function (mixed $result): mixed {
            if (!$result instanceof ElementCollection) {
                return $result; // a count
            }
            $siteId = null;
            $ids = [];
            foreach ($result as $element) {
                if ($element->id) {
                    $ids[] = (int)($element->getCanonicalId() ?? $element->id);
                    $siteId ??= (int)$element->siteId;
                }
            }
            RelationsModel::prefetch($ids, $this->previewLimit ?? 5, $siteId ?? (int)app(Sites::class)->getPrimarySite()->id);
            return $result;
        });
    }

    /** The field has no value of its own to show while its preview loads. */
    #[Override]
    public function previewPlaceholderHtml(mixed $value, ?ElementInterface $element): string
    {
        return '';
    }

    /**
     * Element index column: what uses the element in this site (its version here), as Craft chips (with Craft's "+N"
     * popover), like the field's list; a note when only its versions in other sites are used.
     * @param RelationsModel|null $value
     */
    #[Override]
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
                    self::otherSitesLabel()
                    . Html::tag('div', Html::encode(implode(', ', array_map(
                        fn(int $siteId): string => t((string)app(Sites::class)->getSiteById($siteId, true)?->getName(), category: 'site'),
                        array_keys($value->getOtherSiteCounts()),
                    ))), ['class' => 'light smalltext']),
                    ['data-element-relations-other-sites' => true],
                ),
                default => $this->unusedLabelHtml(),
            };
        }

        // a usage is an element in one site; the first few as chips, then the total
        $viewer = ElementRelations::restrictedViewer();
        if ($viewer) {
            // control panel users with limited permissions: only what they may view, plus how much they may not
            $visibleQuery = self::usageQuery($value, $viewer);
            $elements = (clone $visibleQuery)->limit($this->previewLimit ?? 5)->all();
            $total = $visibleQuery->count();
            $hidden = UsageQuery::hiddenCount([$value->getElementId()], $viewer, targetSiteId: $value->getSiteId());
        } else {
            $elements = $value->getPreviewElements($this->previewLimit ?? 5);
            $total = $value->getCountFast([]);
            $hidden = 0;
        }
        /** @var list<ElementInterface> $elements */
        $html = app(PreviewHtml::class)->elementPreviewHtml($elements, showThumb: false);
        if ($total > count($elements)) {
            $html .= Html::a(
                Html::encode(t('preview-total', ['count' => I18N::getFormatter()->asInteger($total)], 'element-relations')),
                (string)$element->getCpEditUrl() . (($tab = $this->getTabHash($element)) ? "#$tab" : ''),
                ['class' => 'light smalltext'],
            );
        }
        if ($hidden) {
            $html .= Html::tag('div', Html::encode(t('restricted-count', ['count' => $hidden], 'element-relations')), ['class' => 'light smalltext']);
        }
        return $html;
    }

    /**
     * The field: Craft's own element index over the virtual Usage type, i.e. one list of everything that uses the
     * element in this site (its version here; the relating elements may be in any site), across all element types,
     * with Craft's search, sorting, status/drafts menu and pagination. Below it: non-element usages (plugin settings)
     * as Craft chips, and the other sites whose versions of the element are used.
     */
    #[Override]
    public function formControl(FieldContext $context): Control
    {
        $html = HtmlStack::capture(function () use ($context): string {
            ElementRelations::registerClassicUi();
            return $this->inputHtmlFor($context->value, $context->element);
        });
        // the field has no value: ticking a usage or typing in the list's search must not reach Craft's form, which
        // takes every input/change event in a field for an edit and saves a provisional draft
        $wrapped = '<div data-element-relations-input oninput="event.stopPropagation()" onchange="event.stopPropagation()">' . $html->html . '</div>';
        return HtmlControl::make($context->path)->fragment(new HtmlFragment($wrapped, $html->headHtml, $html->bodyHtml))->mode($context->mode);
    }

    /** The field's HTML for an element (see formControl()). */
    public function inputHtmlFor(mixed $value, ?ElementInterface $element): string
    {
        if (!$element || !$value instanceof RelationsModel) {
            return $this->hintHtml(t('field-hint-not-saved', category: 'element-relations'));
        }
        if ($element->getIsUnpublishedDraft()) {
            return $this->hintHtml(t('field-hint-draft', category: 'element-relations'));
        }

        $value = $value->forTargetSite();
        $globalUsages = $value->getGlobalUsages();
        // elements use it here (a list); plugin settings alone get no empty list, only their chips
        $hasElements = $value->getCountFast([]) > 0;
        $otherSites = $this->otherSitesHtml($value, $element, $hasElements || $globalUsages);
        if (!$hasElements && !$globalUsages) {
            return $otherSites ?: $this->unusedLabelHtml();
        }

        $html = '';
        if ($hasElements) {
            self::$toolbarMenus = self::toolbarMenus($value);
            Usage::$listedTypes = $value->getElementTypes(); // the View menu offers these types' columns
            $indexId = 'element-relations-' . Str::random(8);
            $html .= app(ElementIndexHtml::class)->html(Usage::class, [
                'context' => 'embedded-index',
                'id' => $indexId,
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
                    // not `static`: that also drops the actions ("Replace…") and their checkboxes
                    'inlineEditable' => false,
                    'canHaveDrafts' => true, // the status menu's "Drafts" option only takes effect with this
                    'batchSize' => $this->pageSize ?? 20,
                    'storageKey' => 'elementRelations',
                ],
            ]);
            self::$toolbarMenus = [];
            Usage::$listedTypes = null;
            self::bindSearch($indexId);
            if ($viewer = ElementRelations::restrictedViewer()) {
                // so nobody takes the element for unused when the usages are only hidden from them
                $hidden = UsageQuery::hiddenCount([$value->getElementId()], $viewer, targetSiteId: $value->getSiteId());
                if ($hidden) {
                    $html .= Html::tag('p', Html::encode(t('restricted-count-input', ['count' => $hidden], 'element-relations')), ['class' => 'light', 'data-element-relations-hidden' => $hidden]);
                }
            }
            if (ElementRelations::upsells()) {
                $html .= self::upsellHtml('upsell-replace');
            }
        }
        if ($globalUsages) {
            $html .= Html::tag('h3', Html::encode(t('field-headline-global', category: 'element-relations')), ['class' => 'element-relations-heading', 'style' => 'margin-top: var(--m);'])
                . ElementSummary::globalUsagesHtml($globalUsages);
        }
        $html .= $otherSites;
        // the element the list belongs to, for the "Replace…" action on its ticked usages (Elements\Actions\ReplaceUsages)
        return Html::tag('div', $html, ['class' => 'element-relations', 'data' => [
            'element-relations-from' => $value->getElementId(),
            'element-relations-site' => $element->siteId,
        ]]);
    }

    /**
     * Craft 6's toolbar wraps the search input in `<craft-input>`, where the classic index (`children("input:first")`)
     * does not find it: typing searched nothing. Binds it the way the index does.
     */
    private static function bindSearch(string $indexId): void
    {
        HtmlStack::js(<<<'JS'
            window.ElementRelationsSearch = window.ElementRelationsSearch || function (id, tries = 0) {
                const index = $('#' + id).closest('.element-index').add($('#' + id).find('.element-index')).first().data('elementIndex');
                if (!index) { return tries < 40 && setTimeout(() => ElementRelationsSearch(id, tries + 1), 50); }
                if (index.$search.length) { return; } // Craft found it
                index.$search = index.$searchContainer.find('input').first();
                index.addListener(index.$search, 'input', () => {
                    if (!index.searching && index.$search.val()) { index.startSearching(); } else if (index.searching && !index.$search.val()) { index.stopSearching(); }
                    clearTimeout(index.searchTimeout);
                    index.searchTimeout = setTimeout(index.updateElementsIfSearchTextChanged.bind(index), 500);
                });
                index.addListener(index.$search, 'keypress', (event) => {
                    if (event.keyCode === Garnish.RETURN_KEY) { event.preventDefault(); clearTimeout(index.searchTimeout); index.updateElementsIfSearchTextChanged(); }
                });
            };
            JS, key: 'element-relations-search');
        HtmlStack::js('ElementRelationsSearch(' . json_encode($indexId) . ');');
    }

    /** HTML id of the field layout tab containing this field, so links can deep-link to it. */
    public function getTabHash(ElementInterface $element): ?string
    {
        /** @var FieldLayoutTab|null $tab */
        $tab = collect($element->getFieldLayout()?->getTabs() ?? [])
            ->first(fn(FieldLayoutTab $tab): bool => collect($tab->getElements())
                ->contains(function (mixed $layoutElement): bool {
                    try {
                        return $layoutElement instanceof CustomField && (int)$layoutElement->getField()->id === (int)$this->id;
                    } catch (\Throwable) {
                        return false; // field deleted but still referenced in the layout
                    }
                }));
        return $tab?->getHtmlId();
    }

    /**
     * The wizard's review: how many of the usages (all, or the ticked ones) keep no revision when replaced (element
     * types without revisions, sections with versioning off); empty when all get one.
     * @param list<int>|null $onlyOwnerIds
     */
    public static function revisionHintFor(ElementInterface $element, ?array $onlyOwnerIds = null): string
    {
        $value = new RelationsModel((int)$element->id, (int)$element->siteId);
        if ($onlyOwnerIds === null && ($viewer = ElementRelations::restrictedViewer())) {
            $onlyOwnerIds = array_values(array_map(intval(...), self::usageQuery($value, $viewer)->ids())); // what this user may replace in
        }
        $coverage = ReplaceService::revisionCoverage($value->getElementId(), $onlyOwnerIds);
        $without = $coverage['unsupported'] + $coverage['versioningOff'];
        return $without ? t('replace-revision-hint', ['count' => $without], 'element-relations') : '';
    }

    /**
     * The type dropdown of the field's index, only when the list has more than one type; the counts are what this
     * user's list shows (UsageQuery restricts by view permissions).
     * @return list<array{param: string, label: string, allLabel: string, options: array<string|int, array{label: string, count: int}>, total: int}>
     */
    private static function toolbarMenus(RelationsModel $value): array
    {
        $viewer = ElementRelations::restrictedViewer();
        $types = $value->getCountsByType();
        if ($viewer) {
            $types = array_filter(array_map(fn(string $type): int => self::usageQuery($value, $viewer)->elementTypes($type)->count(), array_combine(array_keys($types), array_keys($types))));
        }
        // "All …": the list's elements, each once
        $total = $viewer ? self::usageQuery($value, $viewer)->count() : $value->getCountFast([]);
        $menus = [];
        if (count($types) > 1) {
            $options = [];
            foreach ($types as $type => $count) {
                // some plugin element types don't define a plural name and inherit "Elements"
                $label = $type::pluralDisplayName();
                $options[$type] = ['label' => $label === t('Elements') ? $type::displayName() : $label, 'count' => $count];
            }
            $menus[] = ['param' => 'elementTypes', 'label' => t('field-column-type', category: 'element-relations'), 'allLabel' => t('field-all-types', category: 'element-relations'), 'options' => $options, 'total' => $total];
        }
        return $menus;
    }

    /** The list's base condition: "uses element X in site S" (see UsesElementConditionRule). */
    private static function listCondition(int $elementId, int $siteId): \CraftCms\Cms\Element\Conditions\Contracts\ElementConditionInterface
    {
        $condition = Usage::createCondition();
        $condition->addConditionRule(new UsesElementConditionRule(['elementId' => $elementId, 'siteId' => $siteId]));
        return $condition;
    }

    /**
     * All usages of the element (forTargetSite(): of its version in that site; relating elements of every site, every
     * status, drafts included) as the given user may see them.
     */
    private static function usageQuery(RelationsModel $value, User $viewer): UsageQuery
    {
        return Usage::find()->allSites()->status(null)->drafts(null)->viewableBy($viewer)
            ->usesElement($value->getElementId())->usesElementSite($value->getTargetSiteId());
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
        $viewer = ElementRelations::restrictedViewer();
        $counts = $value->getOtherSiteCounts();
        foreach (app(Sites::class)->getAllSites(true) as $site) { // in the sites' own order
            $count = $counts[(int)$site->id] ?? 0;
            if (!$count) {
                continue;
            }
            // Craft's site chip: the site's name (linking to the element in that site) over the number of usages
            $name = Html::encode(t($site->getName(), category: 'site'));
            if (!$viewer || $viewer->can("editSite:$site->uid")) {
                $name = Html::a($name, Url::urlWithParams((string)$element->getCpEditUrl(), ['site' => $site->handle]) . ($tab ? "#$tab" : ''));
            }
            $items[] = app(ElementHtml::class)->chipHtml($site, ['autoReload' => false, 'labelHtml' => Html::tag('div', Html::tag('div', $name)
                . Html::tag('div', Html::encode(t('field-other-site-count', ['count' => $count], 'element-relations')), ['class' => ['smalltext', 'light']]), ['class' => 'chip-label'])]);
        }
        if (!$items) {
            return '';
        }
        $chips = Html::tag('div', implode('', $items), ['class' => 'flex flex-wrap gap-xs', 'data-element-relations-other-sites' => true]);
        if (!$usedHere) {
            // in place of the red "Unused" label: the same kind of label, then the sites
            return self::otherSitesLabel() . Html::tag('div', $chips, ['style' => 'margin-top: var(--s);']);
        }
        return Html::tag('h3', Html::encode(t('field-headline-other-sites', category: 'element-relations')), ['class' => 'element-relations-heading', 'style' => 'margin-top: var(--m);']) . $chips;
    }

    private static function otherSitesLabel(): string
    {
        return (string)app(StatusHtml::class)->statusLabelHtml(['color' => Color::Sky, 'icon' => 'globe', 'label' => t('preview-other-sites', category: 'element-relations')]);
    }

    private function unusedLabelHtml(): string
    {
        $html = (string)app(StatusHtml::class)->statusLabelHtml(['color' => Color::Red, 'label' => t('field-value-unused', category: 'element-relations')]);
        return ElementRelations::upsells() ? $html . self::upsellHtml('upsell-unused') : $html;
    }

    /** One quiet line under the list pointing out what Pro would do here, with the link to buy it (admins on Lite). */
    private static function upsellHtml(string $key): string
    {
        $icon = Html::tag('span', Html::svg(ElementRelations::iconPath()), ['class' => 'cp-icon small', 'aria-hidden' => 'true']);
        $link = Html::a(Html::encode(t('upsell-link', category: 'element-relations')), ElementRelations::upgradeUrl());
        return Html::tag('p', $icon . Html::tag('span', Html::encode(t($key, category: 'element-relations')) . ' ' . $link), [
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
