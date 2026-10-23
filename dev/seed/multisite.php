<?php

declare(strict_types=1);

/**
 * Multi-site: a section propagating to both sites with a non-localized and a localized (per-site) Entries field, and
 * entries whose relations differ per site:
 *  - "Multi Source" relates to "Multi Target" on both sites (relatedEntries, not localized) and, on the German site
 *    only, to "Multi Target" and "Multi Target (de only)" via relatedEntriesLocalized.
 */

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Entries as EntriesField;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sites;

return function (array $ctx): void {
    $de = Sites::getSiteByHandle('de') ?? throw new RuntimeException('de'); // created by seed.php before any other content

    $related = Fields::getFieldByHandle('relatedEntries') ?? throw new RuntimeException('relatedEntries');
    $localized = ensureField(EntriesField::class, 'relatedEntriesLocalized', 'Related Entries (per site)', ['localizeRelations' => true]);
    $relations = Fields::getFieldByHandle('relations') ?? throw new RuntimeException('relations');
    $entryType = ensureEntryType('multi', 'Multi', [$related, $localized, $relations]);
    $section = ensureSection('multi', 'Multi', $entryType, Sites::getAllSiteIds()->map(fn($id): int => (int)$id)->values()->all());

    $ensure = function (string $title, array $fieldValues = []) use ($section): Entry {
        $entry = Entry::find()->section('multi')->title($title)->status(null)->one()
            ?? new Entry(['sectionId' => $section->id, 'typeId' => $section->getEntryTypes()[0]->id, 'title' => $title]);
        $entry->setFieldValues($fieldValues);
        Elements::saveElement($entry) or fail($entry);
        return $entry;
    };
    $target = $ensure('Multi Target');
    $targetDe = $ensure('Multi Target (de only)');
    $source = $ensure('Multi Source', ['relatedEntries' => [$target->id], 'relatedEntriesLocalized' => []]);

    $sourceDe = Entry::find()->id($source->id)->siteId($de->id)->status(null)->one() ?? throw new RuntimeException('Multi Source (de)');
    $sourceDe->setFieldValue('relatedEntriesLocalized', [$target->id, $targetDe->id]);
    Elements::saveElement($sourceDe) or fail($sourceDe);
};
