<?php
/**
 * Commerce (craftcms/commerce): product type "Test Products" with the Entries field on its product and variant layouts,
 * "Test Product" (product relates to Page C, default variant relates to the asset), and Products / Variants fields
 * on Pages with "Page via Products field" / "Page via Variants field" relating to the product / its variant.
 */

use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\fields\Products;
use craft\commerce\fields\Variants;
use craft\commerce\models\ProductType;
use craft\commerce\models\ProductTypeSite;
use craft\commerce\Plugin as Commerce;
use craft\fieldlayoutelements\CustomField;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;

return function (array $ctx): void {
    $types = Commerce::getInstance()->getProductTypes();
    $related = Craft::$app->getFields()->getFieldByHandle('relatedEntries');
    $assets = ensureField(\craft\fields\Assets::class, ['name' => 'Variant Assets', 'handle' => 'variantAssets']);

    if (!$type = $types->getProductTypeByHandle('testProducts')) {
        $type = new ProductType([
            'name' => 'Test Products',
            'handle' => 'testProducts',
            'hasVariantTitleField' => true,
            'variantTitleFormat' => '{product.title}',
            'skuFormat' => '',
        ]);
        $type->setSiteSettings([$ctx['siteId'] => new ProductTypeSite([
            'siteId' => $ctx['siteId'],
            'hasUrls' => false,
            'enabledByDefault' => true,
        ])]);
        foreach (['productFieldLayout' => [Product::class, $related], 'variantFieldLayout' => [Variant::class, $assets]] as $behavior => [$class, $field]) {
            $layout = new FieldLayout(['type' => $class]);
            $tab = new FieldLayoutTab(['name' => 'Content', 'layout' => $layout]);
            $tab->setElements([new CustomField($field)]);
            $layout->setTabs([$tab]);
            $type->getBehavior($behavior)->setFieldLayout($layout);
        }
        $types->saveProductType($type) or fail($type);
    }

    $product = Product::find()->type('testProducts')->title('Test Product')->status(null)->one();
    $product ??= new Product(['typeId' => $type->id, 'title' => 'Test Product', 'enabled' => true]);
    if ($product->getVariants(true)->isEmpty()) {
        $product->setVariants([new Variant(['title' => 'Test Product', 'sku' => 'TEST-1', 'basePrice' => 10, 'isDefault' => true])]);
        // setVariants() alone doesn't mark them dirty, so the variant manager wouldn't save them
        $product->setDirtyAttributes(['variants']);
    }
    $product->setFieldValues(['relatedEntries' => [$ctx['target']->id]]);
    Craft::$app->getElements()->saveElement($product) or fail($product);

    $variant = $product->getDefaultVariant(true) ?? throw new RuntimeException('Test Product has no variant');
    $variant->setFieldValues(['variantAssets' => [$ctx['asset']->id]]);
    Craft::$app->getElements()->saveElement($variant) or fail($variant);

    $productsField = ensureField(Products::class, ['name' => 'Products', 'handle' => 'products']);
    addFieldToLayout($productsField, $ctx['entryType']);
    $variantsField = ensureField(Variants::class, ['name' => 'Variants', 'handle' => 'commerceVariants']);
    addFieldToLayout($variantsField, $ctx['entryType']);
    ensureEntry('Page via Products field', ['products' => [$product->id]]);
    ensureEntry('Page via Variants field', ['commerceVariants' => [$variant->id]]);
};
