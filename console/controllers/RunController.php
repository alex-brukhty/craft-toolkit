<?php

namespace modules\toolkit\console\controllers;

use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\errors\ElementNotFoundException;
use craft\helpers\Console;
use GuzzleHttp\Exception\GuzzleException;
use modules\toolkit\services\ImageTransformService;
use Throwable;
use yii\base\ErrorException;
use yii\base\Exception;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\BaseConsole;

class RunController extends Controller
{
    public bool $queue = false;

    public $defaultAction = 'index';

    /**
     * @throws Throwable
     * @throws Exception
     * @throws ElementNotFoundException
     */
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

    /**
     * @throws Throwable
     * @throws GuzzleException
     * @throws ErrorException
     * @throws Exception
     */
    public function actionTransformImages(): void
    {
        $assets = Asset::find()->all();
        $counter = 0;

        $filtered = array_filter($assets, fn ($asset) => in_array(
            strtolower($asset->extension),
            ['svg', 'gif', 'webp', 'avif']
        ));

        if (count($filtered) > 0) {
            $this->stdout('Transforming assets' . PHP_EOL, BaseConsole::FG_YELLOW);

            Console::startProgress(0, count($filtered), '', 0.8);

            foreach ($filtered as $asset) {
                ImageTransformService::transformImage($asset->id);
                $counter = $counter + 1;
                Console::updateProgress($counter, count($filtered));
            }

            if ($counter === count($filtered)) {
                Console::endProgress();
            }
        } else {
            $this->stdout('No Assets to transform' . PHP_EOL, BaseConsole::FG_YELLOW);
        }
    }

    /**
     * @throws ElementNotFoundException
     * @throws Throwable
     * @throws Exception
     * @throws ErrorException
     */
    public function actionRemoveTransformImages(): void
    {
        $assets = Asset::find()->transformUrls(':notempty:')->all();
        $counter = 0;

        if (count($assets) > 0) {
            $this->stdout('Removing transforms' . PHP_EOL, BaseConsole::FG_YELLOW);

            Console::startProgress(0, count($assets), '', 0.8);

            foreach ($assets as $asset) {
                ImageTransformService::deleteTransformedImage($asset->id);
                $counter = $counter + 1;
                Console::updateProgress($counter, count($assets));
            }

            if ($counter === count($assets)) {
                Console::endProgress();
            }
        } else {
            $this->stdout('No Assets transforms to remove' . PHP_EOL, BaseConsole::FG_YELLOW);
        }
    }
}
