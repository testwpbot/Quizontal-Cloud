<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalfreetrial\Api;

use FOSSBilling\InformationException;

/** FOSSBilling 0.7/0.8-compatible administrator API for the free trial register. */
class Admin extends \Api_Abstract
{
    public function get_list($data = []): array
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('quizontalfreetrial', 'view');

        return $this->getService()->search((array) $data);
    }

    public function get($data = []): array
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('quizontalfreetrial', 'view');
        if (empty($data['id'])) {
            throw new InformationException('Trial ID is required.');
        }

        return $this->getService()->get((int) $data['id']);
    }

    public function stats($data = []): array
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('quizontalfreetrial', 'view');

        return $this->getService()->stats();
    }

    /**
     * Configuration self-check: product 98 exists, is an enabled hosting
     * product, and points at a DirectAdmin server plus a hosting plan.
     */
    public function diagnose($data = []): array
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('quizontalfreetrial', 'view');

        return $this->getService()->diagnose();
    }

    public function extend($data = []): array
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('quizontalfreetrial', 'manage');
        if (empty($data['id'])) {
            throw new InformationException('Trial ID is required.');
        }

        return $this->getService()->extendTrial((int) $data['id'], (int) ($data['days'] ?? 7));
    }

    public function terminate($data = []): bool
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('quizontalfreetrial', 'manage');
        if (empty($data['id'])) {
            throw new InformationException('Trial ID is required.');
        }

        return $this->getService()->terminateTrial((int) $data['id']);
    }

    /**
     * Rewrites any email template row FOSSBilling generated blind (a subject
     * derived from the action code, a placeholder body) from the files shipped
     * with this module. Returns the codes that were repaired.
     */
    public function repair_email_templates($data = []): array
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('quizontalfreetrial', 'manage');

        return $this->getService()->repairEmailTemplates();
    }

    /**
     * Runs reminder/suspension/termination immediately instead of waiting for
     * the next cron tick. Safe to call repeatedly — every step is idempotent.
     */
    public function run_lifecycle($data = []): array
    {
        $this->di['mod_service']('Staff')->checkPermissionsAndThrowException('quizontalfreetrial', 'manage');

        return $this->getService()->runLifecycle();
    }
}
