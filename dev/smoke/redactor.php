<?php
return function (array $ctx): void {
    $raw = (string)\craft\elements\Entry::find()->title('Page via Redactor')->one()?->getFieldValue('redactor')?->getRawContent();
    check(str_contains($raw, '{entry:') && str_contains($raw, '{asset:'), 'stored HTML keeps the reference tags');
    checkRelation($ctx['target'], 'Page via Redactor', 'Redactor entry link');
    checkRelation($ctx['asset'], 'Page via Redactor', 'Redactor image');

    $entry = \craft\elements\Entry::find()->title('Page via Redactor')->one();
    replaceRoundTrip($entry, $ctx['target'], 'Redactor entry reference', fn($e, $t) => str_contains($e->getFieldValue('redactor')->getRawContent(), '{entry:' . $t->id));
    replaceRoundTrip($entry, $ctx['asset'], 'Redactor asset reference', fn($e, $t) => str_contains($e->getFieldValue('redactor')->getRawContent(), '{asset:' . $t->id));

    // a reference tag naming its site by handle points into that site; one naming a site that does not exist, nowhere.
    // Craft encodes such tags when it saves a field (it writes site ids), so they are in older content only: read here
    // from the value in memory, as the extractor gets it
    $c = $ctx['target'];
    $de = Craft::$app->getSites()->getSiteByHandle('de');
    $legacy = new \craft\elements\Entry(['sectionId' => $entry->sectionId, 'typeId' => $entry->typeId, 'siteId' => $entry->siteId]);
    $legacy->setFieldValue('redactor', '<p><a href="{entry:' . $c->id . '@' . $de->handle . ':url||https://example.test/de}">German</a> <a href="{entry:' . $ctx['asset']->id . '@nosuchsite:url||https://example.test/gone}">gone</a></p>');
    $records = \internetztube\elementRelations\services\extractors\FieldExtractorRedactorCkEditorService::getRelations(Craft::$app->getFields()->getFieldByHandle('redactor'), $legacy, new \internetztube\elementRelations\records\ElementRelationsCacheRecord());
    $rows = array_map(fn($record) => [(int)$record->targetElementId, (int)$record->targetSiteId], $records ?: []);
    check($rows === [[(int)$c->id, (int)$de->id]], 'Redactor: a site handle in a reference tag points into that site, an unknown site nowhere (' . json_encode($rows) . ')');
};
