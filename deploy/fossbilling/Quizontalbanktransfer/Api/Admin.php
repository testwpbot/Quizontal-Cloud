<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalbanktransfer\Api;

use FOSSBilling\InformationException;

/** FOSSBilling 0.7-compatible administrator API. */
class Admin extends \Api_Abstract
{
    public function get_list($data): array
    {
        $this->checkPermissions('quizontalbanktransfer', 'view');
        return $this->getService()->search((array) $data);
    }

    public function get($data): array
    {
        $this->checkPermissions('quizontalbanktransfer', 'view');
        if (empty($data['id'])) throw new InformationException('Submission ID is required.');
        return $this->getService()->get((int) $data['id']);
    }

    public function approve($data): bool
    {
        $this->checkPermissions('quizontalbanktransfer', 'manage');
        if (empty($data['id'])) throw new InformationException('Submission ID is required.');
        if (empty($data['transaction_id'])) throw new InformationException('Bank transaction ID is required.');
        return $this->getService()->approve((int) $data['id'], (string) $data['transaction_id'], (string) ($data['note'] ?? ''));
    }

    public function reject($data): bool
    {
        $this->checkPermissions('quizontalbanktransfer', 'manage');
        if (empty($data['id'])) throw new InformationException('Submission ID is required.');
        if (empty($data['note'])) throw new InformationException('Rejection reason is required.');
        return $this->getService()->reject((int) $data['id'], (string) $data['note']);
    }
}
