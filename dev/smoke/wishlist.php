<?php
return function (array $ctx): void {
    checkRelation($ctx['target'], 'Admin wishlist', 'Wishlist item (shown as its list)');
};
