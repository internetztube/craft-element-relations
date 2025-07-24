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
        $pageParam = max(1, (int)$request->getParam('page', 1));

        // element must exist
        if (!Craft::$app->elements->getElementById($elementId, null, $siteId)) {
            return $this->asJson(['error' => 'Element not found'], 404);
        }

        // model + count
        $relationsModel = new RelationsModel($elementId, $siteId);
        $totalCount = $relationsModel->getCount();

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
        $pagination->page = $pageParam - 1;

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

        $currentPage = $pagination->getPage() + 1;
        $totalPages  = $pagination->getPageCount();

        return $this->asJson([
            '_totalCount' => $totalCount,
            'html'        => $template,
            'page'        => $currentPage,
            'perPage'     => $pagination->getPageSize(),
            'offset'      => $pagination->getOffset(),
            'totalCount'  => $pagination->totalCount,
            'totalPages'  => $totalPages,
            'prevPage'    => $currentPage > 1 ? $currentPage - 1 : null,
            'nextPage'    => $currentPage < $totalPages ? $currentPage + 1 : null,
        ]);
    }
}
