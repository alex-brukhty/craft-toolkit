<?php

namespace alexbrukhty\crafttoolkit\controllers;

use alexbrukhty\crafttoolkit\Toolkit;
use craft\web\Controller;

class MailchimpController extends Controller
{
    public $defaultAction = 'subscribe';

    public function actionSubscribe()
    {
        $this->requirePostRequest();

        $email = $this->request->getRequiredBodyParam('email');
        $listId = $this->request->getBodyParam('listId');
        $tags = $this->request->getBodyParam('tags');
        $name = $this->request->getBodyParam('name');
        $data = [];

        if ($tags) {
            $data['tags'] = $tags;
        }

        if ($name) {
            $data['name'] = $name;
        }

        $result = Toolkit::getInstance()->mailchimpService->subscribe($email, $listId, $data);

        return $this->asJson($result);
    }
}