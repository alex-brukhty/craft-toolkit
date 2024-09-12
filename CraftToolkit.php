<?php
namespace modules\toolkit;

use Craft;
use modules\toolkit\twigextensions\Extensions;
use modules\toolkit\services\CacheService;
use yii\base\Module;

class CraftToolkit extends Module
{
    /**
     * Initializes the module.
     */
    public function init()
    {
        // Set a @modules alias pointed to the modules/ directory
        Craft::setAlias('@modules/toolkit', __DIR__);

        // Set the controllerNamespace based on whether this is a console or web request
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->controllerNamespace = 'modules\\toolkit\\console\\controllers';
        } else {
            $this->controllerNamespace = 'modules\\toolkit\\controllers';
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
