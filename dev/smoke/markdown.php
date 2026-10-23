<?php

declare(strict_types=1);

use CraftCms\Cms\Entry\Elements\Entry;
use CraftCms\Cms\Field\Field;
use CraftCms\Cms\Support\Facades\Elements;
use CraftCms\Cms\Support\Facades\Fields;
use CraftCms\Cms\Support\Facades\Sites;
use Internetztube\ElementRelations\Extractors\FieldExtractorRichTextService;
use Internetztube\ElementRelations\Models\CacheRecord;

return function (array $ctx): void {
    $field = Fields::getFieldByHandle('markdown');
    assert($field instanceof Field);
    $raw = fn($e): string => (string)$field->serializeValue($e->getFieldValue('markdown'), $e);
    $entry = Entry::find()->title('Page via Markdown')->one() ?? throw new RuntimeException('Page via Markdown');
    check(str_contains($raw($entry), '{entry:') && str_contains($raw($entry), '{asset:'), 'stored Markdown keeps the reference tags');
    check(str_contains($raw($entry), 'fast || cheap'), 'the content also has `||` and braces in plain text (must not break the extractor)');
    checkRelation($ctx['target'], 'Page via Markdown', 'Markdown entry link');
    checkRelation($ctx['asset'], 'Page via Markdown', 'Markdown image');
    $found = \Internetztube\ElementRelations\Services\ExtractorService::targetIds($entry, $field);
    sort($found);
    $want = [(int)$ctx['target']->id, (int)$ctx['asset']->id];
    sort($want);
    check($found === $want, 'reference tags are matched as a whole: the two tags, nothing from the plain text (' . json_encode($found) . ')');

    replaceRoundTrip($entry, $ctx['target'], 'Markdown entry reference', fn($e, $t) => str_contains($raw($e), '{entry:' . $t->id . '@'));
    replaceRoundTrip($entry, $ctx['asset'], 'Markdown asset reference', fn($e, $t) => str_contains($raw($e), '{asset:' . $t->id . ':'));

    // other forms of reference tags: another site (by id), an asset transform; the fallback URL follows the
    // replacement, in the tag's site
    foreach (Entry::find()->title('Page via Markdown (site, transform)')->status(null)->all() as $old) { // an aborted run
        Elements::deleteElement($old, true);
    }
    $c = $ctx['target'];
    $de = (int)Sites::getSiteByHandle('de')?->id;
    $a = $ctx['asset'];
    $page = new Entry(['sectionId' => $entry->sectionId, 'typeId' => $entry->typeId, 'title' => 'Page via Markdown (site, transform)']);
    $page->setFieldValue('markdown', '[Page C]({entry:' . $c->id . '@' . $de . ':url||https://old.example/page-c}) ![]({asset:' . $a->id . ':transform:thumb||https://old.example/asset.jpg})');
    Elements::saveElement($page) or fail($page);
    flushQueue();
    checkRelation($c, 'Page via Markdown (site, transform)', 'Markdown entry link to another site');
    checkRelation($a, 'Page via Markdown (site, transform)', 'Markdown image with a transform');
    replaceRoundTrip($page, $c, 'Markdown entry reference to another site', function ($e, $t) use ($raw, $de) {
        // its URL in that site; when it does not exist there, in its own site (better than the old element's URL)
        $url = Elements::getElementById((int)$t->id, null, $de)?->getUrl() ?? $t->getUrl();
        return str_contains($raw($e), '{entry:' . $t->id . '@' . $de . ':url||') && (!$url ? str_contains($raw($e), 'old.example/page-c') : str_contains($raw($e), '||' . $url . '}'));
    });
    replaceRoundTrip($page, $a, 'Markdown asset transform reference', fn($e, $t) => str_contains($raw($e), '{asset:' . $t->id . ':transform:thumb||'));
    Elements::deleteElement($page, true);

    // a reference tag naming its site by handle points into that site; one naming a site that does not exist, nowhere.
    // Read from the value in memory, as the extractor gets it
    $deSite = Sites::getSiteByHandle('de') ?? throw new RuntimeException('de');
    $legacy = new Entry(['sectionId' => $entry->sectionId, 'typeId' => $entry->typeId, 'siteId' => $entry->siteId]);
    $legacy->setFieldValue('markdown', '[German]({entry:' . $c->id . '@' . $deSite->handle . ':url||https://example.test/de}) [gone]({entry:' . $a->id . '@nosuchsite:url||https://example.test/gone})');
    $records = FieldExtractorRichTextService::getRelations($field, $legacy, new CacheRecord());
    $rows = array_map(fn($record) => [(int)$record->targetElementId, (int)$record->targetSiteId], $records ?: []);
    check($rows === [[(int)$c->id, (int)$deSite->id]], 'a site handle in a reference tag points into that site, an unknown site nowhere (' . json_encode($rows) . ')');
};
