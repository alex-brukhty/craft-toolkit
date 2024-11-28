<?php

namespace alexbrukhty\crafttoolkit;

use Craft;
use alexbrukhty\crafttoolkit\services\CacheService;
use alexbrukhty\crafttoolkit\services\ImageTransformService;
use alexbrukhty\crafttoolkit\services\AnalyticsService;
use alexbrukhty\crafttoolkit\models\Settings;
use alexbrukhty\crafttoolkit\twigextensions\Extensions;
use craft\base\Model;
use craft\base\Plugin;

/**
 * Toolkit plugin
 *
 * @method static Toolkit getInstance()
 * @method Settings getSettings()
 * @property-read CacheService $cacheService
 * @property-read ImageTransformService $imageTransformService
 * @property-read AnalyticsService $analyticsService
 */
class Toolkit extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public static function config(): array
    {
        return [
            'components' => [
                'cacheService' => ['class' => CacheService::class],
                'imageTransformService' => ['class' => ImageTransformService::class],
                'analyticsService' => ['class' => AnalyticsService::class],
            ],
        ];
    }

    public function init(): void
    {
        parent::init();

        $this->attachEventHandlers();

        // Any code that creates an element query or loads Twig should be deferred until
        // after Craft is fully initialized, to avoid conflicts with other plugins/modules
        Craft::$app->onInit(function() {
            $ext = new Extensions();
            Craft::$app->view->registerTwigExtension($ext);
        });
    }

    protected function createSettingsModel(): ?Model
    {
        return Craft::createObject(Settings::class);
    }

    private function attachEventHandlers(): void
    {
        $this->cacheService->registerEvents();
        ImageTransformService::registerEvents();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $this->cacheService->registerClearCaches();
        }
    }
}
