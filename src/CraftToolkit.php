<?php
namespace AlexBrukhty\CraftToolkit;

use Craft;

use AlexBrukhty\CraftToolkit\services\CacheService;
use AlexBrukhty\CraftToolkit\twigextensions\Extensions;
use craft\base\Plugin;

class CraftToolkit extends Plugin
{
    /**
     * Initializes the module.
     */
    public function init()
    {
        // Set a @modules alias pointed to the modules/ directory
        Craft::setAlias('@modules', __DIR__);

        // Set the controllerNamespace based on whether this is a console or web request
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'modules\\console\\controllers';
        } else {
            $this->controllerNamespace = 'modules\\controllers';
        }

        parent::init();

        $ext = new Extensions();
        Craft::$app->view->registerTwigExtension($ext);

        $cache = new CacheService();
        $cache->registerEvents();

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            $cache->registerClearCaches();
        }
    }
}
