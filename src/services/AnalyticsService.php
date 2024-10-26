<?php

namespace alexbrukhty\crafttoolkit\services;

use alexbrukhty\crafttoolkit\events\PageViewEvent;
use alexbrukhty\crafttoolkit\Toolkit;
use Br33f\Ga4\MeasurementProtocol\Dto\Event\BaseEvent;
use Br33f\Ga4\MeasurementProtocol\Service;
use Br33f\Ga4\MeasurementProtocol\Dto\Request\BaseRequest;

class AnalyticsService
{
    private Service $service;
    private BaseRequest $baseRequest;

    public function __construct()
    {
        $this->service = new Service($this->getSettings()->ga4MeasurementSecret);
        $this->service->setMeasurementId($this->getSettings()->ga4MeasurementId);

        $this->baseRequest = new BaseRequest();
        $this->baseRequest->setClientId($this->gaGenUUID());
    }

    public function getSettings()
    {
        return Toolkit::getInstance()->getSettings();
    }

    public function pageViewEvent($url, $title)
    {
        $event = new PageViewEvent();
        $event->setPageTitle($title);
        $event->setPageLocation($url);

        $this->baseRequest->addEvent($event);

        return $event;
    }

    public function sendEvent()
    {
        $this->service->send($this->baseRequest);
    }

    public function sendDebugEvent()
    {
        return $this->service->sendDebug($this->baseRequest);
    }

    /**
     * gaGenUUID Generate UUID v4 function - needed to generate a CID when one
     * isn't available
     *
     * @return string The generated UUID
     */
    protected static function gaGenUUID()
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            // 32 bits for "time_low"
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            // 16 bits for "time_mid"
            mt_rand(0, 0xffff),
            // 16 bits for "time_hi_and_version",
            // four most significant bits holds version number 4
            mt_rand(0, 0x0fff) | 0x4000,
            // 16 bits, 8 bits for "clk_seq_hi_res",
            // 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            mt_rand(0, 0x3fff) | 0x8000,
            // 48 bits for "node"
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}