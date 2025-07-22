<?php

namespace internetztube\elementRelations\controllers;

use Craft;
use craft\web\Controller;
use internetztube\elementRelations\models\RelationsModel;

class ElementRelationsController extends Controller
{
    public $enableCsrfValidation = false;
    protected array|int|bool $allowAnonymous = false;

    public function actionPaginate()
    {
        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getParam('elementId');
        $siteId = (int)$request->getParam('siteId');
        $limit = max(1, (int)$request->getParam('limit', 10));  // at least 1
        $pageParam = max(0, (int)$request->getParam('page', 0));    // zero‑indexed input

        // element must exist
        if (!Craft::$app->elements->getElementById($elementId, null, $siteId)) {
            return $this->asJson(['error' => 'Element not found'], 404);
        }

        // model + count
        $relationsModel = new RelationsModel($elementId);
        $totalCount = $relationsModel->getCount($siteId);

        // build the paginator
        $paginationClass = \yii\data\Pagination::class;
        /** @var \yii\data\Pagination $pagination */
        $pagination = new $paginationClass([
            'totalCount' => $totalCount,
            'defaultPageSize' => $limit,
            'pageSizeLimit' => [1, $limit],
            'validatePage' => true,      // clamp automatically
        ]);

        // set user’s requested page (will be clamped by validatePage)
        $pagination->page = $pageParam;

        // now pull exactly the slice we need
        $elements = $relationsModel->getElements(
            $siteId,
            $limit,
            $pagination->getOffset()
        );

        // render HTML snippet if desired
        $template = !empty($elements) ? Craft::$app->getView()->renderTemplate(
            'element-relations/_components/fields/paginate',
            [
                'elements' => $elements,
                'count' => $relationsModel
            ]
        ) : "";

        // respond using *only* the paginator’s own values
        return $this->asJson([
            'html' => $template,
            'page' => $pagination->getPage(),        // clamped, zero‐indexed
            'perPage' => $pagination->getPageSize(),
            'offset' => $pagination->getOffset(),
            'totalCount' => $pagination->totalCount,
            'totalPages' => $pagination->getPageCount(),
            'prevPage' => $pagination->getPage() > 0
                ? $pagination->getPage() - 1
                : null,
            'nextPage' => $pagination->getPage() + 1 < $pagination->getPageCount()
                ? $pagination->getPage() + 1
                : null,
        ]);
    }
}
