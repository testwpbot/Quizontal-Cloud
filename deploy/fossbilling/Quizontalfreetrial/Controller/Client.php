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
        // FOSSBilling resolves the module from the FIRST URL segment and only
        // then registers that module's routes (Box_AppClient::init), so a route
        // like '/free-trial' declared here is never reachable — nothing loads
        // this controller for a URL that does not start with the module name.
        // The pretty '/free-trial' path is provided by a core Redirect module
        // entry instead, which the activation helper creates.
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
