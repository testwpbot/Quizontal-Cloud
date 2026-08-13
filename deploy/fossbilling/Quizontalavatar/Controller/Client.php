<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalavatar\Controller;

use FOSSBilling\InformationException;

class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function register(\Box_App &$app): void
    {
        $app->post('/quizontalavatar/upload', 'post_upload', [], static::class);
        $app->get('/quizontalavatar/avatar/:id', 'get_avatar', ['id' => '[0-9]+'], static::class);
    }

    public function post_upload(\Box_App $app)
    {
        $this->di['is_client_logged'];
        $this->checkCsrf();
        $request = $this->di['request'];
        $this->di['mod_service']('quizontalavatar')->saveAvatar(
            (int) $this->di['loggedin_client']->id,
            $request->files->get('avatar')
        );
        return $app->redirect('client/profile?avatar_updated=1');
    }

    public function get_avatar(\Box_App $app, $id)
    {
        $this->di['is_client_logged'];
        $service = $this->di['mod_service']('quizontalavatar');
        $service->serveAvatar((int) $id);
        return '';
    }

    private function checkCsrf(): void
    {
        $token = (string) $this->di['request']->request->get('CSRFToken', '');
        $expected = (string) $this->di['session']->get('csrf_token');

        if ($expected === '') {
            $sessionId = session_status() === PHP_SESSION_ACTIVE
                ? session_id()
                : (string) ($_COOKIE['PHPSESSID'] ?? '');
            $expected = $sessionId !== '' ? md5($sessionId) : '';
        }

        if ($token === '' || $expected === '' || !hash_equals($expected, $token)) {
            throw new InformationException('CSRF token invalid', null, 403);
        }
    }
}
