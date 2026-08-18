<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalfreetrial\Controller;

/**
 * Public entry point for the trial wizard. The page itself is a thin shell —
 * all decisions are made by the guest/client API behind it.
 */
class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void
    {
        $this->di = $di;
    }

    public function getDi(): ?\Pimple\Container
    {
        return $this->di;
    }

    public function register(\Box_App &$app): void
    {
        // Marketing-friendly URL plus the module-namespaced one, so links in
        // emails and on the storefront never break if one of them is changed.
        $app->get('/free-trial', 'get_index', [], static::class);
        $app->get('/quizontalfreetrial', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $service = $this->di['mod_service']('quizontalfreetrial');

        return $app->render('mod_quizontalfreetrial_index', [
            'trial' => $service->state(),
        ]);
    }
}
