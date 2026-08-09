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
        $app->get('/quizontal-bank-transfer', 'get_index', [], static::class);
        $app->post('/quizontal-bank-transfer/submit', 'post_submit', [], static::class);
        $app->get('/quizontal-bank-transfer/receipt/:id', 'get_receipt', ['id' => '[0-9]+'], static::class);
    }

    public function get_index(\Box_App $app): string
    {
        $this->di['is_client_logged'];
        return $app->render('mod_quizontalbanktransfer_index', ['config' => $this->di['mod_service']('quizontalbanktransfer')->getConfig()]);
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
            $request->files->get('receipt')
        );
        return $app->redirect('quizontal-bank-transfer?submitted='.$result['id']);
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
