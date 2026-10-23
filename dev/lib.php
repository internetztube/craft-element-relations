<?php

declare(strict_types=1);

/** Helpers the seed and the smoke test share (dev/seed.php, dev/smoke.php, dev/seed/*.php, dev/smoke/*.php). */

use CraftCms\Cms\Element\Contracts\ElementInterface;
use CraftCms\Cms\Entry\Data\EntryType;
use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Contracts\FieldInterface;
use CraftCms\Cms\FieldLayout\FieldLayout;
use CraftCms\Cms\FieldLayout\FieldLayoutTab;
use CraftCms\Cms\FieldLayout\LayoutElements\CustomField;
use CraftCms\Cms\FieldLayout\LayoutElements\Entries\EntryTitleField;
use CraftCms\Cms\Section\Data\Section;
use CraftCms\Cms\Section\Data\SectionSiteSettings;
use CraftCms\Cms\Section\Enums\SectionType;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\EntryTypes;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sections;

/** A 1×1 transparent PNG, base64. */
const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

/** `ONLY=matrix,multisite` limits the integration seeds/smoke tests to those names (default: all). */
function onlySelected(string $file): bool
{
    $only = array_filter(explode(',', (string)getenv('ONLY')));
    return !$only || in_array(basename($file, '.php'), $only, true);
}

function fail(object $model): never
{
    $errors = method_exists($model, 'errors') ? $model->errors()->all() : [];
    throw new RuntimeException($model::class . ': ' . json_encode($errors));
}

/**
 * Creates (or returns) a field.
 * @param class-string<FieldInterface> $type
 * @param array<string, mixed> $settings
 */
function ensureField(string $type, string $handle, string $name, array $settings = []): FieldInterface
{
    if ($field = Fields::getFieldByHandle($handle)) {
        return $field;
    }
    $field = Fields::createField(['type' => $type, 'name' => $name, 'handle' => $handle, 'settings' => $settings]);
    Fields::saveField($field) or fail($field);
    Fields::refreshFields();
    return Fields::getFieldByHandle($handle) ?? throw new RuntimeException("field $handle");
}

/**
 * A field layout with one "Content" tab: the title field (for entries) and the given fields.
 * @param class-string<ElementInterface> $type
 * @param list<FieldInterface> $fields
 */
function layoutFor(string $type, array $fields, bool $title = false): FieldLayout
{
    return FieldLayout::make($type)->tab('Content', function (FieldLayoutTab $tab) use ($fields, $title): void {
        if ($title) {
            $tab->add(new EntryTitleField(['required' => true]));
        }
        foreach ($fields as $field) {
            $tab->add(CustomField::make($field));
        }
    });
}

/**
 * Creates (or returns) an entry type with the title and the given fields.
 * @param list<FieldInterface> $fields
 */
function ensureEntryType(string $handle, string $name, array $fields): EntryType
{
    if ($entryType = EntryTypes::getEntryTypeByHandle($handle)) {
        return $entryType;
    }
    $entryType = new EntryType(['name' => $name, 'handle' => $handle]);
    $entryType->setFieldLayout(layoutFor(Entry::class, $fields, true));
    EntryTypes::saveEntryType($entryType) or fail($entryType);
    return EntryTypes::getEntryTypeByHandle($handle) ?? throw new RuntimeException("entry type $handle");
}

/** Appends a field to the end of the first tab of the entry type's field layout (no-op if already there). */
function addFieldToLayout(FieldInterface $field, EntryType $entryType): void
{
    $layout = $entryType->getFieldLayout();
    if ($layout->getFieldByHandle((string)$field->handle)) {
        return;
    }
    $layout->getTabs()[0]->add(CustomField::make($field));
    $entryType->setFieldLayout($layout);
    EntryTypes::saveEntryType($entryType) or fail($entryType);
}

/**
 * Creates (or returns) a channel section with one entry type, URLs on the given sites.
 * @param list<int> $siteIds
 */
function ensureSection(string $handle, string $name, EntryType $entryType, array $siteIds): Section
{
    if ($section = Sections::getSectionByHandle($handle)) {
        return $section;
    }
    $siteSettings = [];
    foreach ($siteIds as $siteId) {
        $siteSettings[$siteId] = new SectionSiteSettings(['siteId' => $siteId, 'hasUrls' => true, 'uriFormat' => "$handle/{slug}", 'template' => '']);
    }
    $section = new Section(['name' => $name, 'handle' => $handle, 'type' => SectionType::Channel, 'entryTypes' => [$entryType], 'siteSettings' => $siteSettings]);
    Sections::saveSection($section) or fail($section);
    return Sections::getSectionByHandle($handle) ?? throw new RuntimeException("section $handle");
}

/**
 * Creates an entry of the Pages section, or updates an existing one, with the given field values.
 * @param array<string, mixed> $fieldValues
 */
function ensureEntry(string $title, array $fieldValues = [], bool $enabled = true, string $sectionHandle = 'pages'): Entry
{
    $entry = Entry::find()->section($sectionHandle)->title($title)->status(null)->one();
    if ($entry instanceof Entry) {
        if ($fieldValues) {
            $entry->setFieldValues($fieldValues);
            Elements::saveElement($entry) or fail($entry);
        }
        return $entry;
    }
    $section = Sections::getSectionByHandle($sectionHandle) ?? throw new RuntimeException("section $sectionHandle");
    $entry = new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => $title, 'enabled' => $enabled]);
    $entry->setFieldValues($fieldValues);
    Elements::saveElement($entry) or fail($entry);
    return $entry;
}
