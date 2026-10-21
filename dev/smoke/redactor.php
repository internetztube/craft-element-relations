<?php
return function (array $ctx): void {
    $raw = (string)\craft\elements\Entry::find()->title('Page via Redactor')->one()?->getFieldValue('redactor')?->getRawContent();
    check(str_contains($raw, '{entry:') && str_contains($raw, '{asset:'), 'stored HTML keeps the reference tags');
    checkRelation($ctx['target'], 'Page via Redactor', 'Redactor entry link');
    checkRelation($ctx['asset'], 'Page via Redactor', 'Redactor image');
};
