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
    /** Chips shown in element index columns before Craft's "+N" badge takes over. */
    private const PREVIEW_LIMIT = 10;

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
     * Sort option for element indexes: number of relating elements (all sites). Craft only takes a string here (it
     * appends the direction), so the subquery is built with the query builder and passed as its SQL.
     * @return array{label: string, orderBy: string, attribute: string, defaultDir: string}
     */
    public function getSortOption(): array
    {
        $count = (new \craft\db\Query())
            ->select(['COUNT(*)'])
            ->from(['elementrelations_cache' => '{{%elementrelations_cache}}'])
            // not the usages of trashed elements (plugin settings, without a source element, do count)
            ->leftJoin(['sources' => \craft\db\Table::ELEMENTS], '[[sources.id]] = [[elementrelations_cache.sourcePrimaryOwnerId]]')
            ->where(['elementrelations_cache.targetElementId' => new \yii\db\Expression('[[elements.id]]')])
            ->andWhere(['or', ['elementrelations_cache.sourceElementId' => null], ['and', ['not', ['sources.id' => null]], ['sources.dateDeleted' => null]]]);
        return [
            'label' => Craft::t('site', (string)$this->name),
            'orderBy' => '(' . $count->createCommand()->getRawSql() . ')',
            'attribute' => "field:$this->uid",
            'defaultDir' => 'desc',
        ];
    }

    /**
     * Element index column: the relating elements of the element's site as Craft chips (with Craft's "+N" popover).
     * @param RelationsModel|null $value
     */
    public function getPreviewHtml(mixed $value, ElementInterface $element): string
    {
        if (!$value instanceof RelationsModel) {
            return '';
        }
        if (!$value->getIsInUseFast([])) {
            return $element->getIsUnpublishedDraft() ? '' : $this->unusedLabelHtml();
        }

        $siteId = $element->siteId;
        $viewer = self::restrictedViewer();
        if ($viewer) {
            // control panel users with limited permissions: only what they may view, plus how much they may not
            $visibleQuery = self::usageQuery($value, $viewer)->siteId($siteId);
            $elements = (clone $visibleQuery)->limit(self::PREVIEW_LIMIT)->all();
            $total = (int)$visibleQuery->count();
            $hidden = UsageQuery::hiddenCount([$value->getElementId()], $viewer);
        } else {
            $elements = $value->getElements($siteId, self::PREVIEW_LIMIT);
            $total = $value->getCountFast($siteId);
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
        if (!$elements && !$viewer && $value->getSitesFast()) {
            $html .= Html::tag('span', Craft::t('element-relations', 'preview-other-sites'), ['class' => 'light']);
        }
        if ($value->getIsUsedInSeomaticGlobalSettings()) {
            $html .= Html::tag('div', Craft::t('element-relations', 'field-value-seomatic-global'), ['class' => 'light']);
        }
        return $html;
    }

    /**
     * The field: Craft's own element index over the virtual Usage type, i.e. one list of everything that uses the
     * element across all element types and sites, with Craft's search, sorting, status/drafts menu and pagination.
     * Below it: non-element usages (plugin settings) as Craft chips.
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

        $globalUsages = $value->getGlobalUsages();
        if (!$value->getIsInUseFast([]) && !$globalUsages) {
            return $this->unusedLabelHtml();
        }

        $html = '';
        if ($value->getIsInUseFast([])) {
            self::$toolbarMenus = self::toolbarMenus($value);
            $html .= Cp::elementIndexHtml(Usage::class, [
                'context' => 'embedded-index',
                'id' => 'element-relations-' . StringHelper::randomString(8),
                'sources' => false,
                'showStatusMenu' => $this->showStatusMenu,
                // Craft's index is per site (its JS always sends a siteId); a usage list wants every site at once, with
                // the Site column saying which, so no site menu and `allSites` on the query (see UsageQuery).
                'showSiteMenu' => false,
                'defaultViewMode' => $this->viewMode,
                'jsSettings' => [
                    'criteria' => ['usesElement' => $value->getElementId(), 'allSites' => true],
                    // not `static`: that also drops the actions (Pro: "Replace…") and their checkboxes
                    'inlineEditable' => false,
                    'canHaveDrafts' => true, // the status menu's "Drafts" option only takes effect with this
                    'batchSize' => $this->pageSize ?? 20,
                    'storageKey' => 'elementRelations',
                ],
            ]);
            self::$toolbarMenus = [];
            if ($viewer = self::restrictedViewer()) {
                // so nobody takes the element for unused when the usages are only hidden from them
                $hidden = UsageQuery::hiddenCount([$value->getElementId()], $viewer);
                if ($hidden) {
                    $html .= Html::tag('p', Html::encode(Craft::t('element-relations', 'restricted-count-input', ['count' => $hidden])), ['class' => 'light', 'data-element-relations-hidden' => $hidden]);
                }
            }
        }
        if ($globalUsages) {
            // Craft's `flex` utility would center (and shrink) the index, so plain block layout with a spaced heading
            $html .= Html::tag('h3', Html::encode(Craft::t('element-relations', 'field-headline-global')), ['class' => 'element-relations-heading', 'style' => 'margin-top: var(--m);'])
                . ElementSummary::globalUsagesHtml($globalUsages);
        }
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
        return $without ? Craft::t('element-relations', 'replace-revision-hint', [
            'count' => $without,
            'unsupported' => $coverage['unsupported'],
            'versioningOff' => $coverage['versioningOff'],
        ]) : '';
    }

    /**
     * The type and site dropdowns of the field's index, each only when the list has more than one; the counts are what
     * this user's list shows (UsageQuery restricts by view permissions).
     * @return list<array{param: string, label: string, allLabel: string, options: array<string|int, array{label: string, count: int}>}>
     */
    private static function toolbarMenus(RelationsModel $value): array
    {
        $viewer = self::restrictedViewer();
        $types = $value->getCountsByType();
        $sites = $value->getCountsBySite();
        if ($viewer) {
            $types = array_filter(array_map(fn(string $type): int => (int)self::usageQuery($value, $viewer)->elementTypes($type)->count(), array_combine(array_keys($types), array_keys($types))));
            $sites = array_filter(array_map(fn(int $siteId): int => (int)self::usageQuery($value, $viewer)->inSites($siteId)->count(), array_combine(array_keys($sites), array_keys($sites))));
        }
        $menus = [];
        if (count($types) > 1) {
            $options = [];
            foreach ($types as $type => $count) {
                // some plugin element types don't define a plural name and inherit "Elements"
                $label = $type::pluralDisplayName();
                $options[$type] = ['label' => $label === Craft::t('app', 'Elements') ? $type::displayName() : $label, 'count' => $count];
            }
            $menus[] = ['param' => 'elementTypes', 'label' => Craft::t('element-relations', 'field-column-type'), 'allLabel' => Craft::t('element-relations', 'field-all-types'), 'options' => $options];
        }
        if (count($sites) > 1) {
            $options = [];
            foreach ($sites as $siteId => $count) {
                $options[$siteId] = ['label' => Craft::$app->getSites()->getSiteById($siteId, true)?->getName() ?? (string)$siteId, 'count' => $count];
            }
            $menus[] = ['param' => 'inSites', 'label' => Craft::t('app', 'Site'), 'allLabel' => Craft::t('app', 'All sites'), 'options' => $options];
        }
        return $menus;
    }

    /** All usages of the element (all sites, every status, drafts included) as the given user may see them. */
    private static function usageQuery(RelationsModel $value, \craft\elements\User $viewer): UsageQuery
    {
        $query = Usage::find()->allSites()->status(null)->drafts(null)->viewableBy($viewer);
        Craft::configure($query, ['usesElement' => $value->getElementId()]); // behavior param, see ElementQueryBehavior
        return $query;
    }

    /** The logged-in non-admin user of a control panel request, whose view permissions apply; null otherwise. */
    private static function restrictedViewer(): ?\craft\elements\User
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
        return (string)Cp::statusLabelHtml(['color' => Color::Red, 'label' => Craft::t('element-relations', 'field-value-unused')]);
    }

    private function hintHtml(string $text): string
    {
        return Html::tag('p', Html::encode($text), ['class' => 'light']);
    }
}
