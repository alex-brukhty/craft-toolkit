<?php

namespace alexbrukhty\crafttoolkit\services;

use alexbrukhty\crafttoolkit\Toolkit;
use Craft;
use DrewM\MailChimp\MailChimp;
use Exception;
use yii\validators\EmailValidator;


class MailchimpService
{

    public MailChimp $mailChimpClient;
    public string $listId;

    /**
     * @throws Exception
     */
    public function __construct()
    {
        $settings = Toolkit::getInstance()->getSettings();

        if (empty($settings->mailchimpApiKey) || empty($settings->mailchimpListId)) {
            return;
        }

        $this->listId = $settings->mailchimpListId;

        $this->mailChimpClient = new MailChimp($settings->mailchimpApiKey);
    }

    public function subscribe(string $email, string $listId = null, $data = [])
    {

        if (!$email) {
            return ['success' => false, 'msg' => 'Email can\'t be empty'];
        }

        $validator = new EmailValidator();
        if (!$validator->validate($email)) {
            return ['success' => false, 'msg' => 'Invalid email format'];
        }

        $dataMC = [
            'email_address' => $email,
            'status' => 'subscribed',
//            'double_optin' => false
        ];

        if (isset($data["name"])) {
            // validate if name is only words no numbers or symbols
            if (!preg_match('/^[a-zA-Z\s]+$/', $data["name"])) {
                return ['success' => true, 'msg' => 'Name is weird'];
            }
            $name = array_pad(explode(" ", $data["name"], 2), 2, null);
            $dataMC = array_merge($dataMC, ['merge_fields' => ['FNAME' => $name[0], 'LNAME' => $name[1] ?? '']]);
        }

        if (isset($data["tags"])) {
            if (!preg_match('/^[a-zA-Z\s_-]+$/', $data["name"])) {
                return ['success' => true, 'msg' => 'Tags is weird'];
            }
            $dataMC = array_merge($dataMC, ['tags' => explode(',', $data["tags"])]);
        }

        if (isset($data["merge_fields"])) {
            $dataMC = array_merge($dataMC, ['merge_fields' => $data["merge_fields"]]);
        }

        if (isset($data["interests"]) && is_array($data["interests"]) && count($data["interests"]) > 0) {
            $interests = [];
            foreach ($data["interests"] as $interest) {
                $interests = [
                    ...$interests,
                    $interest => true
                ];
            }
            $dataMC = array_merge($dataMC, [
                'interests' => $interests
            ]);
        }

        try {
            $result = $this->mailChimpClient->post(
                method: "lists/" . ($listId ?? $this->listId) . "/members",
                args: $dataMC
            );
            if (in_array($result['status'], ['subscribed', 'pending'])) {
                return [
                    'success' => true,
                    'status' => $result['status'],
                    'msg' => 'Email subscribed successfully',
                    'id' => $result['contact_id']
                ];
            }
            return [
                'success' => false,
                'msg' => 'Mailchimp error: ' . $result['title'] . ' - ' . $result['detail'],
                'errors' => $result['errors'] ?? [],
                'data' => $dataMC,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage()
            ];
        }
    }

    public function getInterest()
    {
        $cache = Craft::$app->getCache();
        $cacheKey = 'mailchimp-interests';
        if ($cache->exists($cacheKey) && $cache->get($cacheKey)) {
            return $cache->get($cacheKey);
        }

        $listId = Toolkit::getInstance()->getSettings()->mailchimpListId;
        $categories = $this->mailChimpClient->get(
            'lists/' . $listId . '/interest-categories'
        );

        $grouped = [];
        foreach ($categories['categories'] as $category) {
            $interests = $this->mailChimpClient->get(
                'lists/' . $listId . '/interest-categories/'.$category['id'].'/interests'
            );
            $parsed = [];
            foreach ($interests['interests'] as $interest) {
                $parsed[] = [
                    'title' => $interest['name'],
                    'id' => $interest['id']
                ];
            }
            if (isset($interests['interests'])) {
                $grouped[] = [
                    'id' => $category['id'],
                    'title' => $category['title'],
                    'interests' => $parsed
                ];
            }
        }

        $cache->set($cacheKey, $grouped, 3600);

        return $grouped;
    }
}