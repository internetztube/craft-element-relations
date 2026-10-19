<?php
return function (array $ctx): void {
    $raw = (string)\craft\elements\Entry::find()->title('Page via CKEditor')->one()?->getFieldValue('ckeditor')?->getRawContent();
    check(str_contains($raw, '{entry:') && str_contains($raw, '{asset:'), 'stored HTML keeps the reference tags');
    checkRelation($ctx['target'], 'Page via CKEditor', 'CKEditor entry link');
    checkRelation($ctx['asset'], 'Page via CKEditor', 'CKEditor image');
};
