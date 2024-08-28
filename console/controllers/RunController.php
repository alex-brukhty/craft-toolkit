<?php

namespace modules\toolkit\console\controllers;

use Craft;
use craft\elements\Entry;
use yii\console\Controller;
use yii\console\ExitCode;

class RunController extends Controller
{
    public $queue = false;

    public $defaultAction = 'index';

    public function actionIndex(string $titles, $sectionId): int
    {

        $count = 0;
        foreach (explode(',', $titles) as $title) {
            $entry = new Entry([
                'title' => $title,
                'sectionId' => $sectionId,
                'authorId' => Craft::$app->getUser()->getId(),
            ]);

            Craft::$app->elements->saveElement($entry);
            $count = $count + 1;
        }

        $this->stdout('Added: ' . json_encode($count) . PHP_EOL);

        return ExitCode::OK;
    }
}
