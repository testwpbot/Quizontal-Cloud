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

    /**
     * Signed-in customers whose account is not yet marked email-approved still
     * have to pass the code step, so these two mirror the guest endpoints. The
     * service ignores any address in the payload and uses the account's own.
     */
    public function request_code($data = []): array
    {
        return $this->getService()->requestCode((string) ($data['email'] ?? ''));
    }

    public function verify_code($data = []): array
    {
        return $this->getService()->verifyCode(
            (string) ($data['email'] ?? ''),
            (string) ($data['code'] ?? '')
        );
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
