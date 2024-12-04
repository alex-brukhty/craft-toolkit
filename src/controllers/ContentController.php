<?php

namespace alexbrukhty\crafttoolkit\controllers;

use Craft;
use craft\web\Controller;

class ContentController extends Controller
{
    public int|bool|array $allowAnonymous = ['csrf'];

    public function actionCsrf()
    {
        return $this->asJson([
            'name' => Craft::$app->getConfig()->general->csrfTokenName,
            'value' => $this->request->getCsrfToken(),
        ]);
    }
}