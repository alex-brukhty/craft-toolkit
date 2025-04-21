<?php

namespace alexbrukhty\crafttoolkit\services;

use Craft;
use alexbrukhty\crafttoolkit\Toolkit;
use alexbrukhty\crafttoolkit\models\Settings;
use craft\helpers\Json;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\RequestException;

class CloudflareService
{
    public const API_BASE_URL = 'https://api.cloudflare.com/client/v4/';

    /**
     * @var ?Client
     */
    private ?Client $_client = null;

    public bool $enabled;
    private string $token;

    public string $zone;

    public function __construct()
    {
        $this->enabled = $this->getSettings()->cloudflareEnabled ?? false;
        $this->token = $this->getSettings()->cloudflareToken ?? '';
        $this->zone = $this->getSettings()->cloudflareZone ?? '';
    }

    public function getSettings(): Settings
    {
        return Toolkit::getInstance()->getSettings();
    }

    private function _getClientHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => sprintf(
                'Bearer %s',
                $this->token
            )
        ];
    }

    public function getClient(): ?Client
    {
        if ($this->_client === null && $this->enabled && $this->token) {
            $this->_client = Craft::createGuzzleClient([
                'base_uri' => self::API_BASE_URL,
                'headers' => $this->_getClientHeaders(),
                'verify' => false,
                'debug' => false,
            ]);
        }

        return $this->_client;
    }

    public function purgeZoneCache(): ?object
    {
        if (!$this->getClient()) {
            return null;
        }

        try {
            $response = $this->getClient()->delete(sprintf(
                'zones/%s/purge_cache',
                $this->zone
            ),
                ['body' => Json::encode(['purge_everything' => true])]
            );

            $responseBody = Json::decode($response->getBody(), false);

            if ($response->getStatusCode() !== 200 || $responseBody->success === false) {
                Craft::info(sprintf(
                    'Zone purge request failed: %s',
                    Json::encode($responseBody)
                ), 'cloudflare');

                return (object)[
                    'success' => false,
                    'message' => $response->getBody()->getContents(),
                    'result' => [],
                ];
            }

            Craft::warning(
                sprintf('Purged entire zone cache (%s)', $responseBody->result->id),
                'cloudflare-purger'
            );

            return $responseBody;
        } catch (ClientException|RequestException $exception) {
            return $this->_handleApiException($exception);
        }
    }

    public function purgeUrls(array $urls = []): mixed
    {
        if (!$this->getClient()) {
            return null;
        }

        if (count($urls) === 0) {
            return $this->_failureResponse(
                'Cannot purge; no valid URLs.'
            );
        }

        try {
            $response = $this->getClient()->delete(sprintf(
                'zones/%s/purge_cache',
                $this->zone
            ),
                ['body' => Json::encode(['files' => $urls])]
            );

            $responseBody = Json::decode($response->getBody(), false);

            if ($response->getStatusCode() !== 200) {
                Craft::warning(sprintf(
                    'Request failed: %s',
                    Json::encode($responseBody)
                ), 'cloudflare-purger');

                return (object)[
                    'success' => false,
                    'message' => $response->getBody()->getContents(),
                    'result' => [],
                ];
            }

            $urlString = implode(',', $urls);

            Craft::warning(sprintf(
                'Purged URLs (%d): %s',
                $responseBody?->result?->id || '',
                $urlString
            ), 'cloudflare-purger');

            return $responseBody;
        } catch (ClientException|RequestException $exception) {
            return $this->_handleApiException($exception, $urls);
        }
    }

    private function _failureResponse(string $message): object
    {
        Craft::error($message, 'cloudflare');

        return (object)[
            'success' => false,
            'message' => $message,
            'result' => [],
        ];
    }

    private function _handleApiException(mixed $exception, array $urls = []): object
    {
        if ($responseBody = Json::decode($exception->getResponse()->getBody(), false)) {
            $message = 'URL purge' . " failed.\n";

            if ($urls) {
                $message .= '- urls: ' . implode(',', $urls) . "\n";
            }

            foreach ($responseBody->errors as $error) {
                $message .= "- error code " . $error->code . ": " . $error->message . "\n";
            }

            Craft::warning($message, 'cloudflare-purger');

            return (object)[
                'success' => false,
                'errors' => $responseBody->errors ?? [],
                'result' => [],
            ];
        }

        // return a more generic failure if we don’t have better details
        return $this->_failureResponse(sprintf(
            'Request failed: %s',
            $this->_getExceptionReason($exception)
        ));
    }

    private function _getExceptionReason(RequestException $exception): string
    {
        if ($exception->hasResponse()) {
            return $exception->getResponse()->getBody()->getContents();
        }

        return $exception->getRequest()->getUri();
    }
}