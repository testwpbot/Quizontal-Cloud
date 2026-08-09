<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalbanktransfer\Api;

class Client extends \FOSSBilling\Api\AbstractApi
{
    public function get_list($data): array
    {
        $data['client_id'] = (int) $this->getIdentity()->id;
        return $this->getService()->search((array) $data);
    }

    public function config($data): array
    {
        $config = $this->getService()->getConfig();
        return array_intersect_key($config, array_flip(['bank_name', 'account_name', 'account_number', 'branch', 'swift_code', 'instructions', 'max_file_mb']));
    }
}
