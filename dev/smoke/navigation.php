<?php
return function (array $ctx): void {
    checkRelation($ctx['target'], 'Main', 'Navigation entry node (shown as its menu)');
    checkRelation($ctx['asset'], 'Main', 'Navigation asset node (shown as its menu)');
};
