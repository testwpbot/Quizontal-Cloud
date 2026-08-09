<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalbanktransfer\Api;

/** FOSSBilling 0.7-compatible client API. */
class Client extends \Api_Abstract
{
    public function get_list($data): array
    {
        $data = (array) $data;
        $data['client_id'] = (int) $this->getIdentity()->id;
        return $this->getService()->search($data);
    }

    public function config($data): array
    {
        $config = $this->getService()->getConfig();
        return array_intersect_key($config, array_flip(['bank_name', 'account_name', 'account_number', 'branch', 'swift_code', 'instructions', 'max_file_mb']));
    }
}
