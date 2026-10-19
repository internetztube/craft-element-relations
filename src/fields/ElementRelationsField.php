<?php

namespace internetztube\elementRelations\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\base\FieldInterface;
use craft\base\FieldLayoutElement;
use craft\base\PreviewableFieldInterface;
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
use internetztube\elementRelations\gql\types\Relations as RelationsType;
use internetztube\elementRelations\helpers\ElementSummary;
use internetztube\elementRelations\models\RelationsModel;
use yii\base\InvalidConfigException;

/**
 * Read-only field that shows where the element is used. It stores nothing; the value is computed from the cache table.
 * The control panel UI is Craft's own: element indexes for the field, element chips for index columns.
 */
class ElementRelationsField extends Field implements PreviewableFieldInterface
{
    /** Chips shown in element index columns before Craft's "+N" badge takes over. */
    private const PREVIEW_LIMIT = 10;

    /**
     * Element types (with counts) of the index being rendered right now; picked up by the `cp.elements.toolbar`
     * hook (see ElementRelations::init()) to render the type dropdown. Craft offers no other way to add toolbar items.
     * @var array<class-string<ElementInterface>, array{label: string, count: int}>|null
     */
    public static ?array $toolbarTypes = null;

    public const VIEW_MODE_TABLE = 'table';
    public const VIEW_MODE_CARDS = 'cards';

    /** Initial view mode of the index; authors can switch it and Craft remembers their choice. */
    public string $viewMode = self::VIEW_MODE_TABLE;
    public bool $showStatusMenu = true;
    public bool $showSiteMenu = true;
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
        if (Craft::$app->getIsMultiSite()) {
            $html .= Cp::lightswitchFieldHtml([
                'label' => Craft::t('app', 'Show the site menu'),
                'id' => 'showSiteMenu',
                'name' => 'showSiteMenu',
                'on' => $this->showSiteMenu,
            ]);
        }
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
        return new RelationsModel($element->getCanonicalId() ?? $element->id, $element->siteId);
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
        $ids = $value->getSourceElementIds([]);
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

    public function getContentGqlType(): Type|array
    {
        return [
            'name' => $this->handle,
            'type' => RelationsType::getType(),
            'resolve' => fn($source) => $source->{$this->handle},
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
        $elements = $value->getElements($siteId, self::PREVIEW_LIMIT);
        $html = Cp::elementPreviewHtml($elements, showThumb: false);

        $total = $value->getCountFast($siteId);
        if ($total > count($elements)) {
            $html .= Html::a(
                Craft::t('element-relations', 'preview-total', ['count' => Craft::$app->getFormatter()->asInteger($total)]),
                $element->getCpEditUrl() . (($tab = $this->getTabHash($element)) ? "#$tab" : ''),
                ['class' => 'light smalltext'],
            );
        }
        if (!$elements && $value->getSitesFast()) {
            $html .= Html::tag('span', Craft::t('element-relations', 'preview-other-sites'), ['class' => 'light']);
        }
        if ($value->getIsUsedInSeomaticGlobalSettings()) {
            $html .= Html::tag('div', Craft::t('element-relations', 'field-value-seomatic-global'), ['class' => 'light']);
        }
        return $html;
    }

    /**
     * The field: Craft's own element index over the virtual Usage type, i.e. one list of everything that uses the
     * element across all element types, with Craft's search, sorting, status/drafts menu, site menu and pagination.
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
            $counts = $value->getCountsByType();
            self::$toolbarTypes = null;
            if (count($counts) > 1) {
                foreach ($counts as $type => $count) {
                    // some plugin element types don't define a plural name and inherit "Elements"
                    $label = $type::pluralDisplayName();
                    if ($label === Craft::t('app', 'Elements')) {
                        $label = $type::displayName();
                    }
                    self::$toolbarTypes[$type] = ['label' => $label, 'count' => $count];
                }
            }
            $html .= Cp::elementIndexHtml(Usage::class, [
                'context' => 'embedded-index',
                'id' => 'element-relations-' . StringHelper::randomString(8),
                'sources' => false,
                'showStatusMenu' => $this->showStatusMenu,
                'showSiteMenu' => $this->showSiteMenu ? 'auto' : false,
                'defaultViewMode' => $this->viewMode,
                'jsSettings' => [
                    'criteria' => ['usesElement' => $value->getElementId(), 'site' => '*'],
                    'static' => true,
                    'canHaveDrafts' => true, // the status menu's "Drafts" option only takes effect with this
                    'batchSize' => $this->pageSize ?? 20,
                    'storageKey' => 'elementRelations',
                ],
            ]);
            self::$toolbarTypes = null;
        }
        if ($globalUsages) {
            // Craft's `flex` utility would center (and shrink) the index, so plain block layout with a spaced heading
            $html .= Html::tag('h3', Html::encode(Craft::t('element-relations', 'field-headline-global')), ['class' => 'element-relations-heading', 'style' => 'margin-top: var(--m);'])
                . ElementSummary::globalUsagesHtml($globalUsages);
        }
        return Html::tag('div', $html, ['class' => 'element-relations']);
    }

    /**
     * HTML id of the field layout tab containing this field, so links can deep-link to it.
     */
    public function getTabHash(ElementInterface $element): ?string
    {
        return collect($element->getFieldLayout()?->getTabs() ?? [])
            ->first(function (FieldLayoutTab $tab) {
                return collect($tab->getElements())
                    ->map(function (FieldLayoutElement $layoutElement) {
                        try {
                            return $layoutElement instanceof CustomField ? $layoutElement->getField() : null;
                        } catch (FieldNotFoundException|InvalidConfigException) {
                            return null; // field deleted but still referenced in the layout
                        }
                    })
                    ->contains(fn(?FieldInterface $field) => $field?->id === $this->id);
            })
            ?->getHtmlId();
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
