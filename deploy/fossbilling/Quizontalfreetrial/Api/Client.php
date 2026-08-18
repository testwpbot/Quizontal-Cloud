<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalfreetrial\Api;

/**
 * Signed-in customers use the same wizard, minus the email-code steps: their
 * address is already proven by the session.
 */
class Client extends \Api_Abstract
{
    public function state($data = []): array
    {
        return $this->getService()->state();
    }

    public function set_whatsapp($data = []): array
    {
        return $this->getService()->setWhatsapp((string) ($data['whatsapp'] ?? ''));
    }

    public function set_domain($data = []): array
    {
        return $this->getService()->setDomain((string) ($data['domain'] ?? ''));
    }

    public function review($data = []): array
    {
        return $this->getService()->review();
    }

    public function provision($data = []): array
    {
        return $this->getService()->provision();
    }

    public function reset($data = []): array
    {
        return $this->getService()->resetWizard();
    }
}
