<?php
declare(strict_types=1);
namespace Box\Mod\Quizontalhostingtrial\Controller;
use FOSSBilling\InformationException;
class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }
    public function register(\Box_App &$app): void
    {
        $app->get('/hosting-trial', 'get_index', [], static::class);
        $app->post('/hosting-trial/whatsapp', 'post_whatsapp', [], static::class);
    }
    public function get_index(\Box_App $app): string
    {
        $this->di['is_client_logged'];
        return $app->render('mod_quizontalhostingtrial_index', [
            'trial' => $this->di['mod_service']('quizontalhostingtrial')->clientStatus((int) $this->di['loggedin_client']->id),
            'error' => (string) $this->di['request']->query->get('error', ''),
        ]);
    }
    public function post_whatsapp(\Box_App $app)
    {
        $this->di['is_client_logged'];
        $this->csrf();
        try {
            $this->di['mod_service']('quizontalhostingtrial')->saveWhatsapp(
                (int) $this->di['loggedin_client']->id,
                (string) $this->di['request']->request->get('whatsapp', '')
            );
        } catch (InformationException $e) {
            return $app->redirect('hosting-trial?error='.rawurlencode($e->getMessage()));
        }
        return $app->redirect('hosting-trial?contact_saved=1');
    }
    private function csrf(): void
    {
        $token = (string) $this->di['request']->request->get('CSRFToken', '');
        $expected = (string) $this->di['session']->get('csrf_token');
        if ($expected === '') $expected = session_id() !== '' ? md5(session_id()) : '';
        if ($token === '' || $expected === '' || !hash_equals($expected, $token)) throw new InformationException('CSRF token invalid', null, 403);
    }
}
