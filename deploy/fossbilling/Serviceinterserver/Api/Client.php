<?php

declare(strict_types=1);

namespace Box\Mod\Serviceinterserver\Api;

use FOSSBilling\InformationException;

class Client extends \Api_Abstract
{
    public function sync($data): bool
    {
        [, $service] = $this->ownedService($data);
        return $this->getService()->syncService($service);
    }

    public function start($data): bool { [, $service] = $this->ownedService($data); return $this->getService()->powerAction($service, 'start'); }
    public function stop($data): bool { [, $service] = $this->ownedService($data); return $this->getService()->powerAction($service, 'stop'); }
    public function restart($data): bool { [, $service] = $this->ownedService($data); return $this->getService()->powerAction($service, 'restart'); }

    public function reveal_password($data): string
    {
        [, $service] = $this->ownedService($data);
        return $this->getService()->revealPassword($service);
    }

    private function ownedService(array $data): array
    {
        if (empty($data['order_id'])) throw new InformationException('Order ID is required.');
        $order = $this->di['db']->findOne('ClientOrder', 'id = ? AND client_id = ?', [(int) $data['order_id'], (int) $this->getIdentity()->id]);
        if (!$order instanceof \Model_ClientOrder || $order->service_type !== 'interserver') throw new InformationException('Cloud service not found.');
        $service = $this->di['db']->load('service_interserver', (int) $order->service_id);
        if (!$service) throw new InformationException('Cloud service details not found.');
        return [$order, $service];
    }
}
