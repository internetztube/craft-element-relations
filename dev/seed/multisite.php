<?php
/**
 * Multi-site: a second site ("de"), a section propagating to both sites with a non-localized and a localized
 * (per-site) Entries field, and entries whose relations differ per site:
 *  - "Multi Source" relates to "Multi Target" on both sites (relatedEntries, not localized) and, on the German site
 *    only, to "Multi Target" and "Multi Target (de only)" via relatedEntriesLocalized.
 */

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\Entries as EntriesField;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;

return function (array $ctx): void {
    $sites = Craft::$app->getSites();
    $entries = Craft::$app->getEntries();
    $elements = Craft::$app->getElements();

    if (!$de = $sites->getSiteByHandle('de')) {
        $de = new Site([
            'groupId' => $sites->getPrimarySite()->groupId,
            'name' => 'German',
            'handle' => 'de',
            'language' => 'de',
            'hasUrls' => true,
            'baseUrl' => '@web/de',
        ]);
        $sites->saveSite($de) or fail($de);
    }

    if (!$section = $entries->getSectionByHandle('multi')) {
        $related = Craft::$app->getFields()->getFieldByHandle('relatedEntries');
        $localized = ensureField(EntriesField::class, ['name' => 'Related Entries (per site)', 'handle' => 'relatedEntriesLocalized', 'localizeRelations' => true]);
        $relations = Craft::$app->getFields()->getFieldByHandle('relations');

        $layout = new FieldLayout(['type' => Entry::class]);
        $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
        $tab->setElements([new EntryTitleField(), new CustomField($related), new CustomField($localized), new CustomField($relations)]);
        $layout->setTabs([$tab]);
        $entryType = new EntryType(['name' => 'Multi', 'handle' => 'multi']);
        $entryType->setFieldLayout($layout);
        $entries->saveEntryType($entryType) or fail($entryType);

        $section = new Section([
            'name' => 'Multi',
            'handle' => 'multi',
            'type' => Section::TYPE_CHANNEL,
            'siteSettings' => array_map(fn(Site $site) => new Section_SiteSettings([
                'siteId' => $site->id,
                'hasUrls' => true,
                'uriFormat' => 'multi/{slug}',
                'template' => 'index',
            ]), $sites->getAllSites()),
        ]);
        $section->setEntryTypes([$entryType]);
        $entries->saveSection($section) or fail($section);
    }

    $ensure = function (string $title, array $fieldValues = []) use ($section, $elements): Entry {
        $entry = Entry::find()->section('multi')->title($title)->status(null)->one()
            ?? new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => $title]);
        $entry->setFieldValues($fieldValues);
        $elements->saveElement($entry) or fail($entry);
        return $entry;
    };
    $target = $ensure('Multi Target');
    $targetDe = $ensure('Multi Target (de only)');
    $source = $ensure('Multi Source', ['relatedEntries' => [$target->id], 'relatedEntriesLocalized' => []]);

    $sourceDe = Entry::find()->id($source->id)->siteId($de->id)->status(null)->one();
    $sourceDe->setFieldValue('relatedEntriesLocalized', [$target->id, $targetDe->id]);
    $elements->saveElement($sourceDe) or fail($sourceDe);
};
