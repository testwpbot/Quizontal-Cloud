<?php

declare(strict_types=1);

namespace Box\Mod\Quizontaldomains\Api;

use FOSSBilling\InformationException;

/**
 * Customer DNS API. FOSSBilling's client role gate keeps these endpoint behind
 * an active client session; the service layer additionally proves the order
 * belongs to that exact client before any record is touched.
 */
class Client extends \Api_Abstract
{
    /** Theme probe: may the manage page show the DNS tab for this order? */
    public function supported($data): array
    {
        return $this->getService()->supported((int) $this->getIdentity()->id, $this->orderId($data));
    }

    public function records($data): array
    {
        return $this->getService()->listRecords((int) $this->getIdentity()->id, $this->orderId($data));
    }

    public function create($data): array
    {
        return $this->getService()->createRecord((int) $this->getIdentity()->id, $this->orderId($data), (array) $data);
    }

    public function update($data): array
    {
        return $this->getService()->editRecord((int) $this->getIdentity()->id, $this->orderId($data), $this->recordId($data), (array) $data);
    }

    public function delete($data): bool
    {
        return $this->getService()->deleteRecord((int) $this->getIdentity()->id, $this->orderId($data), $this->recordId($data));
    }

    private function orderId(array $data): int
    {
        if (empty($data['order_id'])) throw new InformationException('Order ID is required.');
        return (int) $data['order_id'];
    }

    private function recordId(array $data): string
    {
        $id = preg_replace('/\D/', '', (string) ($data['record_id'] ?? ''));
        if ($id === '') throw new InformationException('Record ID is required.');
        return $id;
    }
}
