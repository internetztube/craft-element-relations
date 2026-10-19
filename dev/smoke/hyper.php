<?php
return function (array $ctx): void {
    checkRelation($ctx['target'], 'Page via Hyper', 'Hyper');
    checkRelation($ctx['asset'], 'Page via Hyper', 'Hyper (asset link)');
};
