<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalfreetrial\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
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

    public function fetchNavigation(): array
    {
        // The Orders group registers itself under the location key 'order'
        // (singular) — 'orders' is only its CSS class. A subpage pointing at a
        // location that does not exist is dropped without a visible error.
        return ['subpages' => [[
            'location' => 'order',
            'label' => 'Free Trials',
            'index' => 300,
            'uri' => $this->di['url']->adminLink('quizontalfreetrial'),
            'class' => '',
        ]]];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/quizontalfreetrial', 'get_index', [], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_admin_logged'];

        return $app->render('mod_quizontalfreetrial_index');
    }
}
