<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalfreetrial\Api;

/**
 * Free trial wizard endpoints for visitors who are not signed in yet.
 *
 * Every method returns the authoritative wizard state, so the browser never
 * decides which step it is on — it only renders what the server reports.
 */
class Guest extends \Api_Abstract
{
    /** Current wizard state, used on page load and after a browser refresh. */
    public function state($data = []): array
    {
        return $this->getService()->state();
    }

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

    public function set_account($data = []): array
    {
        return $this->getService()->setAccount(
            (string) ($data['first_name'] ?? ''),
            (string) ($data['last_name'] ?? ''),
            (string) ($data['password'] ?? ''),
            (string) ($data['password_confirm'] ?? '')
        );
    }

    /** Summary shown on the final review screen before anything is created. */
    public function review($data = []): array
    {
        return $this->getService()->review();
    }

    /**
     * Creates the account, orders the trial product and provisions it on
     * DirectAdmin. Answers with the service details URL to redirect to.
     */
    public function provision($data = []): array
    {
        return $this->getService()->provision();
    }

    public function reset($data = []): array
    {
        return $this->getService()->resetWizard();
    }
}
