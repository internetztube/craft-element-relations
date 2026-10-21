<?php
return function (array $ctx): void {
    $raw = (string)\craft\elements\Entry::find()->title('Page via CKEditor')->one()?->getFieldValue('ckeditor')?->getRawContent();
    check(str_contains($raw, '{entry:') && str_contains($raw, '{asset:'), 'stored HTML keeps the reference tags');
    check(str_contains($raw, 'fast || cheap'), 'the content also has `||` and braces in plain text (must not break the extractor)');
    checkRelation($ctx['target'], 'Page via CKEditor', 'CKEditor entry link');
    checkRelation($ctx['asset'], 'Page via CKEditor', 'CKEditor image');

    $entry = \craft\elements\Entry::find()->title('Page via CKEditor')->one();
    replaceRoundTrip($entry, $ctx['target'], 'CKEditor entry reference', fn($e, $t) => str_contains($e->getFieldValue('ckeditor')->getRawContent(), '{entry:' . $t->id));
    replaceRoundTrip($entry, $ctx['asset'], 'CKEditor asset reference', fn($e, $t) => str_contains($e->getFieldValue('ckeditor')->getRawContent(), '{asset:' . $t->id));

    // the other forms CKEditor writes: another site (by id; its purifier keeps only numeric ones), an asset transform;
    // the fallback URL follows the replacement, in the tag's site
    $elements = Craft::$app->getElements();
    foreach (\craft\elements\Entry::find()->title('Page via CKEditor (site, transform)')->status(null)->all() as $old) { // an aborted run
        $elements->deleteElement($old, true);
    }
    $c = $ctx['target'];
    $de = Craft::$app->getSites()->getSiteByHandle('de')->id;
    $a = $ctx['asset'];
    $page = new \craft\elements\Entry(['sectionId' => $entry->sectionId, 'typeId' => $entry->typeId, 'title' => 'Page via CKEditor (site, transform)']);
    $page->setFieldValue('ckeditor', '<p><a href="{entry:' . $c->id . '@' . $de . ':url||https://old.example/page-c}">Page C</a></p><p><img src="{asset:' . $a->id . ':transform:thumb||https://old.example/asset.jpg}" alt=""></p>');
    $elements->saveElement($page) or throw new RuntimeException(json_encode($page->getErrors()));
    flushQueue();
    checkRelation($c, 'Page via CKEditor (site, transform)', 'CKEditor entry link to another site');
    checkRelation($a, 'Page via CKEditor (site, transform)', 'CKEditor image with a transform');
    $raw = fn($e) => (string)$e->getFieldValue('ckeditor')->getRawContent();
    replaceRoundTrip($page, $c, 'CKEditor entry reference to another site', function ($e, $t) use ($raw, $de) {
        // its URL in that site; when it does not exist there, in its own site (better than the old element's URL)
        $url = Craft::$app->getElements()->getElementById($t->id, null, $de)?->getUrl() ?? $t->getUrl();
        return str_contains($raw($e), '{entry:' . $t->id . '@' . $de . ':url||') && (!$url ? str_contains($raw($e), 'old.example/page-c') : str_contains($raw($e), '||' . $url . '}'));
    });
    replaceRoundTrip($page, $a, 'CKEditor asset transform reference', fn($e, $t) => str_contains($raw($e), '{asset:' . $t->id . ':transform:thumb||'));
    $elements->deleteElement($page, true);
};
