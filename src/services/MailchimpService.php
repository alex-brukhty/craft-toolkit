<?php

namespace alexbrukhty\crafttoolkit\services;

use alexbrukhty\crafttoolkit\Toolkit;
use DrewM\MailChimp\MailChimp;
use Exception;


class MailchimpService
{

    private MailChimp $mailChimpClient;
    private string $listId;

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

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'msg' => 'Invalid email format'];
        }

        $dataMC = [
            'email_address' => $email,
            'status' => 'subscribed',
//            'double_optin' => false
        ];

        if (isset($data["name"])) {
            $name = array_pad(explode(" ", $data["name"], 2), 2, null);
            $dataMC = array_merge($dataMC, ['merge_fields' => ['FNAME' => $name[0], 'LNAME' => $name[1] ?? '']]);
        }

        if (isset($data["tags"])) {
            $dataMC = array_merge($dataMC, ['tags' => explode(',', $data["tags"])]);
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
                'msg' => 'Mailchimp error: ' . $result['title']
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'msg' => $e->getMessage()
            ];
        }
    }
}