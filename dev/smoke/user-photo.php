<?php
return function (array $ctx): void {
    checkRelation($ctx['asset'], 'Photo User', 'User photo');
};
