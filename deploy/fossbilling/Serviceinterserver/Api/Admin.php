<?php

declare(strict_types=1);

namespace Box\Mod\Serviceinterserver\Api;

use FOSSBilling\InformationException;

class Admin extends \Api_Abstract
{
    public function diagnose_order($data): array
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('serviceinterserver', 'manage');
        if (empty($data['order_id'])) throw new InformationException('Order ID is required.');
        return $this->getService()->diagnoseOrder((int) $data['order_id']);
    }

    public function activate_paid_order($data): bool
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('serviceinterserver', 'manage');
        if (empty($data['order_id'])) throw new InformationException('Order ID is required.');
        return $this->getService()->activatePaidOrder((int) $data['order_id']);
    }

    public function set_credentials($data): bool
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('serviceinterserver', 'manage');
        if (empty($data['api_url']) || empty($data['api_key'])) throw new InformationException('API URL and key are required.');
        return $this->getService()->setCredentials((string) $data['api_url'], (string) $data['api_key']);
    }

    public function configure_product($data): bool
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('serviceinterserver', 'manage');
        foreach (['id', 'platform', 'slices', 'expected_cost_usd'] as $field) if (!isset($data[$field])) throw new InformationException("Missing product mapping field: {$field}.");
        $product = $this->di['db']->getExistingModelById('Product', (int) $data['id'], 'Product not found.');
        if (!str_starts_with((string) $product->slug, 'interserver-')) throw new InformationException('Only synchronized InterServer products can be configured.');
        if (!in_array($data['platform'], ['kvm', 'kvmstorage', 'hyperv'], true)) throw new InformationException('Invalid InterServer platform.');
        $slices = (int) $data['slices'];
        if ($slices < 1 || $slices > 32) throw new InformationException('Invalid slice quantity.');
        $config = json_decode($product->config ?? '', true) ?? [];
        $config = array_merge($config, [
            'provider' => 'interserver', 'platform' => (string) $data['platform'],
            'slices' => $slices, 'expected_cost_usd' => round((float) $data['expected_cost_usd'], 2),
        ]);
        $product->type = 'interserver';
        $product->config = json_encode($config);
        $product->setup = 'after_payment';
        $product->updated_at = date('Y-m-d H:i:s');
        $this->di['db']->store($product);
        return true;
    }

    public function retry_validation($data): bool
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('serviceinterserver', 'manage');
        if (empty($data['order_id'])) throw new InformationException('Order ID is required.');
        $order = $this->di['db']->getExistingModelById('ClientOrder', (int) $data['order_id'], 'Order not found.');
        if ($order->service_type !== 'interserver') throw new InformationException('This is not a cloud VPS order.');
        $service = $this->di['db']->load('service_interserver', (int) $order->service_id);
        if ($service && in_array($service->status, ['submitting', 'manual_review'], true)) {
            return $this->getService()->reconcileExisting($order, $service);
        }
        $this->getService()->activate($order, $service);
        return true;
    }
}
