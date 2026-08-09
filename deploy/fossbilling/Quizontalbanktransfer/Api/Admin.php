<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalbanktransfer\Api;

use FOSSBilling\Validation\Api\RequiredParams;

class Admin extends \FOSSBilling\Api\AbstractApi
{
    public function get_list($data): array
    {
        $this->checkPermissions('quizontalbanktransfer', 'view');
        return $this->getService()->search((array) $data);
    }

    #[RequiredParams(['id' => 'Submission ID is required'])]
    public function get($data): array
    {
        $this->checkPermissions('quizontalbanktransfer', 'view');
        return $this->getService()->get((int) $data['id']);
    }

    #[RequiredParams(['id' => 'Submission ID is required', 'transaction_id' => 'Bank transaction ID is required'])]
    public function approve($data): bool
    {
        $this->checkPermissions('quizontalbanktransfer', 'manage');
        return $this->getService()->approve((int) $data['id'], (string) $data['transaction_id'], (string) ($data['note'] ?? ''));
    }

    #[RequiredParams(['id' => 'Submission ID is required', 'note' => 'Rejection reason is required'])]
    public function reject($data): bool
    {
        $this->checkPermissions('quizontalbanktransfer', 'manage');
        return $this->getService()->reject((int) $data['id'], (string) $data['note']);
    }
}
