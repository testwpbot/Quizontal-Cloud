<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalbanktransfer\Controller;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Admin implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function fetchNavigation(): array
    {
        return ['subpages' => [[
            'location' => 'invoices', 'label' => 'Bank Transfer Receipts', 'index' => 850,
            'uri' => $this->di['url']->adminLink('quizontal-bank-transfer'), 'class' => '',
        ]]];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/quizontal-bank-transfer', 'get_index', [], static::class);
        $app->get('/quizontal-bank-transfer/:id', 'get_view', ['id' => '[0-9]+'], static::class);
        $app->get('/quizontal-bank-transfer/receipt/:id', 'get_receipt', ['id' => '[0-9]+'], static::class);
    }

    public function get_index(\Box_App $app): string { $this->di['is_admin_logged']; return $app->render('mod_quizontalbanktransfer_index'); }
    public function get_view(\Box_App $app, $id): string { $this->di['is_admin_logged']; return $app->render('mod_quizontalbanktransfer_view', ['submission_id' => (int) $id]); }

    public function get_receipt(\Box_App $app, $id): BinaryFileResponse
    {
        $this->di['is_admin_logged'];
        $service = $this->di['mod_service']('quizontalbanktransfer');
        $row = $service->get((int) $id);
        return (new BinaryFileResponse($service->receiptPath($row)))->setContentDisposition('inline', $row['original_name']);
    }
}
