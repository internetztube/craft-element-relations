<?php

use craft\commerce\elements\Product;
use internetztube\elementRelations\models\RelationsModel;

return function (array $ctx): void {
    $product = Product::find()->title('Test Product')->status(null)->one();
    check($product !== null, 'Test Product exists');
    $variant = $product->getDefaultVariant(true);
    checkRelation($product, 'Page via Products field', 'Products field');
    checkRelation($variant, 'Page via Variants field', 'Variants field');
    checkRelation($ctx['target'], 'Test Product', 'product field layout');
    checkRelation($ctx['asset'], 'Test Product', 'variant field layout (attributed to the product)');
    $sourceIds = (new RelationsModel($ctx['asset']->id, $ctx['asset']->siteId))->getSourceElementIds([]);
    check(!in_array($variant->id, $sourceIds, true), 'variant itself is not a source (only its product)');
};
