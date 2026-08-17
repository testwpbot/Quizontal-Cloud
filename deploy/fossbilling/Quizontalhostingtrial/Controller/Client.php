<?php
declare(strict_types=1);

namespace Box\Mod\Quizontalhostingtrial\Controller;

use FOSSBilling\InformationException;

/** Customer-facing, contextual free-trial start screen. */
class Client implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function register(\Box_App &$app): void
    {
        $app->get('/hosting-trial/start/:product', 'get_start', ['product' => '[0-9]+'], static::class);
        $app->post('/hosting-trial/start/:product', 'post_start', ['product' => '[0-9]+'], static::class);
        // Kept for backwards-compatible bookmarks; it never appears in navigation.
        $app->post('/hosting-trial/whatsapp', 'post_whatsapp', [], static::class);
    }

    public function get_start(\Box_App $app, $product): string
    {
        $this->di['is_client_logged'];
        $product = $this->trialProduct((int) $product);
        $client = $this->di['loggedin_client'];
        $service = $this->di['mod_service']('quizontalhostingtrial');
        $status = $service->clientStatus((int) $client->id);

        return $app->render('mod_quizontalhostingtrial_start', [
            'product' => $this->di['mod_service']('Product')->toApiArray($product, true, $client),
            'email_verified' => !empty($status['email_verified']),
            'trial_used' => !empty($status['trials']),
            'whatsapp' => (string) ($status['whatsapp'] ?? ''),
            'error' => (string) $this->di['request']->query->get('error', ''),
        ]);
    }

    public function post_start(\Box_App $app, $product)
    {
        $this->di['is_client_logged'];
        $this->csrf();
        $product = $this->trialProduct((int) $product);
        $client = $this->di['loggedin_client'];
        $service = $this->di['mod_service']('quizontalhostingtrial');
        try {
            $service->saveWhatsapp((int) $client->id, (string) $this->di['request']->request->get('whatsapp', ''));
            $service->assertClientCanStartTrial((int) $client->id);
        } catch (InformationException $e) {
            return $app->redirect('hosting-trial/start/'.$product->id.'?error='.rawurlencode($e->getMessage()));
        }
        // The billing product remains the source of truth for plan/domain configuration.
        return $app->redirect('order?product='.(int) $product->id.'&trial=1');
    }

    public function post_whatsapp(\Box_App $app)
    {
        $this->di['is_client_logged']; $this->csrf();
        $this->di['mod_service']('quizontalhostingtrial')->saveWhatsapp((int) $this->di['loggedin_client']->id, (string) $this->di['request']->request->get('whatsapp', ''));
        return $app->redirect('client/profile?whatsapp_saved=1');
    }

    private function trialProduct(int $id): \Model_Product
    {
        $product = $this->di['db']->load('Product', $id);
        if (!$product instanceof \Model_Product || !$this->di['mod_service']('quizontalhostingtrial')->isTrialProduct($product)) throw new InformationException('This hosting package does not offer a free trial.');
        return $product;
    }
    private function csrf(): void
    {
        $token = (string) $this->di['request']->request->get('CSRFToken', '');
        $expected = (string) $this->di['session']->get('csrf_token'); if ($expected === '') $expected = session_id() !== '' ? md5(session_id()) : '';
        if ($token === '' || $expected === '' || !hash_equals($expected, $token)) throw new InformationException('CSRF token invalid', null, 403);
    }
}
