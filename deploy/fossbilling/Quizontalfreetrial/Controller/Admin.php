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
        return ['subpages' => [[
            'location' => 'orders',
            'label' => 'Free Trials',
            'index' => 860,
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
