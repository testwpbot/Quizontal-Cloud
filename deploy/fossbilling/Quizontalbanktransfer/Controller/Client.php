<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalbanktransfer\Controller;

use FOSSBilling\InformationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function register(\Box_App &$app): void
    {
        $app->get('/quizontalbanktransfer', 'get_index', [], static::class);
        $app->get('/quizontalbanktransfer/invoice/:hash', 'get_invoice', ['hash' => '[a-z0-9]+'], static::class);
        $app->post('/quizontalbanktransfer/submit', 'post_submit', [], static::class);
        $app->get('/quizontalbanktransfer/receipt/:id', 'get_receipt', ['id' => '[0-9]+'], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_client_logged'];
        $hash = trim((string) $this->di['request']->query->get('invoice_hash', ''));
        if ($hash !== '') return $this->renderInvoice($app, $hash);

        return $app->render('mod_quizontalbanktransfer_index', ['config' => $this->di['mod_service']('quizontalbanktransfer')->getConfig()]);
    }

    public function get_invoice(\Box_App $app, $hash): string
    {
        $this->di['is_client_logged'];
        return $this->renderInvoice($app, (string) $hash);
    }

    private function renderInvoice(\Box_App $app, string $hash): string
    {
        $invoice = $this->di['db']->findOne('Invoice', 'hash = ? AND client_id = ?', [$hash, $this->di['loggedin_client']->id]);
        if (!$invoice instanceof \Model_Invoice || !$this->di['mod_service']('Invoice')->isInvoiceTypeDeposit($invoice)) {
            throw new InformationException('Deposit invoice not found.');
        }
        return $app->render('mod_quizontalbanktransfer_index', [
            'config' => $this->di['mod_service']('quizontalbanktransfer')->getConfig(),
            'deposit_invoice' => $this->di['mod_service']('Invoice')->toApiArray($invoice, true, $this->di['loggedin_client']),
        ]);
    }

    public function post_submit(\Box_App $app)
    {
        $this->di['is_client_logged'];
        $this->checkCsrf();
        $request = $this->di['request'];
        $result = $this->di['mod_service']('quizontalbanktransfer')->submit(
            $this->di['loggedin_client'],
            $request->request->get('amount'),
            (string) $request->request->get('reference', ''),
            $request->files->get('receipt'),
            ($hash = trim((string) $request->request->get('invoice_hash', ''))) !== '' ? $hash : null
        );
        return $app->redirect('quizontalbanktransfer?submitted='.$result['id']);
    }

    public function get_receipt(\Box_App $app, $id): BinaryFileResponse
    {
        $this->di['is_client_logged'];
        $service = $this->di['mod_service']('quizontalbanktransfer');
        $row = $service->get((int) $id);
        if ((int) $row['client_id'] !== (int) $this->di['loggedin_client']->id) throw new InformationException('You cannot access this receipt.');
        return (new BinaryFileResponse($service->receiptPath($row)))->setContentDisposition('inline', $row['original_name']);
    }

    private function checkCsrf(): void
    {
        $token = (string) $this->di['request']->request->get('CSRFToken', '');
        $expected = (string) $this->di['session']->get('csrf_token');
        if ($token === '' || $expected === '' || !hash_equals($expected, $token)) throw new InformationException('CSRF token invalid', null, 403);
    }
}
