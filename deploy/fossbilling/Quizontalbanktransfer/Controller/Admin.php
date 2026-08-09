<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalbanktransfer\Controller;

class Admin implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function fetchNavigation(): array
    {
        return ['subpages' => [[
            'location' => 'invoice', 'label' => 'Bank Transfer Receipts', 'index' => 850,
            'uri' => $this->di['url']->adminLink('quizontalbanktransfer'), 'class' => '',
        ]]];
    }

    public function register(\Box_App &$app): void
    {
        $app->get('/quizontalbanktransfer', 'get_index', [], static::class);
        $app->get('/quizontalbanktransfer/:id', 'get_view', ['id' => '[0-9]+'], static::class);
        $app->get('/quizontalbanktransfer/receipt/:id', 'get_receipt', ['id' => '[0-9]+'], static::class);
    }

    public function get_index(\Box_App $app): string { $this->di['is_admin_logged']; return $app->render('mod_quizontalbanktransfer_index'); }
    public function get_view(\Box_App $app, $id): string { $this->di['is_admin_logged']; return $app->render('mod_quizontalbanktransfer_view', ['submission_id' => (int) $id]); }

    public function get_receipt(\Box_App $app, $id): string
    {
        $this->di['is_admin_logged'];
        $service = $this->di['mod_service']('quizontalbanktransfer');
        $row = $service->get((int) $id);
        return $this->streamReceipt($service->receiptPath($row), $row);
    }

    private function streamReceipt(string $path, array $row): string
    {
        if (!is_file($path) || !is_readable($path)) throw new \FOSSBilling\InformationException('Receipt file not found.');
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string) $row['original_name']));
        header('Content-Type: '.(string) $row['mime_type']);
        header('Content-Length: '.(string) filesize($path));
        header('Content-Disposition: inline; filename="'.$filename.'"');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        return '';
    }
}
