<?php

declare(strict_types=1);

namespace Box\Mod\Quizontaldomains\Api;

use FOSSBilling\InformationException;

/**
 * Staff-only maintenance endpoints for the domain manager. The flagship one
 * re-runs the branding pass (parking sweep + welcome records + nameserver
 * sync) on demand — the exact same routine the activation hook and the
 * throttled auto pass use, so results stay identical however it is triggered.
 */
class Admin extends \Api_Abstract
{
    /**
     * Re-apply Quizontal branding to a domain order now.
     *
     * @return array{trigger: string, domain: string, ns_synced: bool, swept: int, branded: bool, deferred: bool}
     */
    public function apply_branding($data): array
    {
        $orderId = (int) ($data['order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new InformationException('Order ID is required.');
        }
        $order = $this->di['db']->findOne('ClientOrder', 'id = ?', [$orderId]);
        if (!$order instanceof \Model_ClientOrder || !in_array((string) $order->service_type, ['domain', 'servicedomain'], true)) {
            throw new InformationException('Domain order not found.');
        }

        return $this->getService()->applyBrandingToService($order, 'admin');
    }
}
