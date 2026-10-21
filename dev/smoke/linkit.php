<?php
return function (array $ctx): void {
    checkRelation($ctx['target'], 'Page via LinkIt', 'LinkIt');
};
