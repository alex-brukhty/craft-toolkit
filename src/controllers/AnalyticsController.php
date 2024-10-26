<?php

namespace alexbrukhty\crafttoolkit\controllers;

use alexbrukhty\crafttoolkit\jobs\PageViewJob;
use alexbrukhty\crafttoolkit\Toolkit;
use Craft;
use craft\helpers\Queue;
use craft\web\Controller;
use yii\web\BadRequestHttpException;

class AnalyticsController extends Controller
{
    public $defaultAction = 'index';

    protected int|bool|array $allowAnonymous = true;

    public function actionIndex()
    {
        $analytics = Toolkit::getInstance()->analyticsService;
        $analytics->pageViewEvent(
            Craft::$app->getSites()->currentSite->baseUrl,
            'Home'
        );

        $request = $analytics->sendDebugEvent();

        return $this->asJson([
            'status' => $request->getStatusCode(),
            'message' => $request->getValidationMessages(),
        ]);
    }

    /**
     * @throws BadRequestHttpException
     */
    public function actionTrackView()
    {
        $title = $this->request->getRequiredQueryParam('title');
        $url = $this->request->getRequiredQueryParam('url');

        Queue::push(new PageViewJob([
            'url' => $url,
            'title' => $title,
        ]));

        return $this->asRaw('Page View Event Sent');
    }
}
