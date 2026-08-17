<?php
declare(strict_types=1);
namespace Box\Mod\Quizontalhostingtrial;

use FOSSBilling\InformationException;

/** A guarded, no-card, seven-day trial for normal FOSSBilling Servicehosting orders. */
class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;
    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function install(): bool
    {
        $this->di['db']->exec("CREATE TABLE IF NOT EXISTS quizontal_hosting_trial_profile (
            client_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, whatsapp VARCHAR(32) NOT NULL,
            verified_at DATETIME NULL, updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->di['db']->exec("CREATE TABLE IF NOT EXISTS quizontal_hosting_trial (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, client_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL,
            reminder_sent_at DATETIME NULL, continuation_invoice_id BIGINT UNSIGNED NULL,
            status ENUM('active','suspended','continued','cancelled') NOT NULL DEFAULT 'active',
            suspended_at DATETIME NULL, continued_at DATETIME NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL,
            UNIQUE KEY quizontal_hosting_trial_order (order_id), KEY quizontal_hosting_trial_due (status, ends_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        return true;
    }
    public function uninstall(): bool { return true; } // retain operational records
    public function getConfig(): array { return array_merge(['trial_days' => 7, 'retention_days' => 14], (array) $this->di['mod_config']('quizontalhostingtrial')); }

    /** A trial hosting product is explicitly opted in with JSON product config: {"trial_enabled":true,"trial_continuation_price":1500}. */
    public function isTrialProduct($product): bool
    {
        if (!$product || (string) ($product->type ?? '') !== 'hosting') return false;
        $config = json_decode((string) ($product->config ?? ''), true);
        return is_array($config) && !empty($config['trial_enabled']);
    }
    private function continuationPrice($product): float
    {
        $config = json_decode((string) ($product->config ?? ''), true) ?: [];
        $price = (float) ($config['trial_continuation_price'] ?? 0);
        if ($price <= 0) throw new InformationException('This free-trial hosting package is not configured with a continuation price yet. Please contact support.');
        return $price;
    }
    public function assertClientCanStartTrial(int $clientId): void
    {
        $client = $this->di['db']->load('Client', $clientId);
        if (!$client instanceof \Model_Client || empty($client->email_approved)) {
            throw new InformationException('Verify your email address before starting a free hosting trial. Open the verification email we sent, then return here.');
        }
        $profile = $this->profile($clientId);
        if (!$profile) throw new InformationException('Add your WhatsApp number before starting a free hosting trial. Open Hosting Trial in your client area.');
        $existing = $this->di['db']->findOne('quizontal_hosting_trial', 'client_id = ? AND status IN ("active", "suspended", "continued")', [$clientId]);
        if ($existing) throw new InformationException('Only one hosting trial is available per customer.');
    }
    public function saveWhatsapp(int $clientId, string $number): bool
    {
        $number = preg_replace('/[\s()\-]/', '', trim($number)) ?? '';
        if (!preg_match('/^\+?[1-9][0-9]{7,14}$/', $number)) throw new InformationException('Enter a valid WhatsApp number with country code, for example +94771234567.');
        $now = date('Y-m-d H:i:s');
        $stmt = $this->di['pdo']->prepare('INSERT INTO quizontal_hosting_trial_profile (client_id, whatsapp, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE whatsapp=VALUES(whatsapp), updated_at=VALUES(updated_at)');
        $stmt->execute([$clientId, $number, $now]);
        return true;
    }
    private function profile(int $clientId): ?array
    {
        $stmt = $this->di['pdo']->prepare('SELECT * FROM quizontal_hosting_trial_profile WHERE client_id=?'); $stmt->execute([$clientId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    public static function onBeforeClientCheckout(\Box_Event $event): void
    {
        $di = $event->getDi(); $params = $event->getParameters(); $cartId = (int) ($params['cart_id'] ?? 0);
        if (!$cartId) return;
        $service = $di['mod_service']('quizontalhostingtrial');
        foreach ($di['db']->find('CartProduct', 'cart_id=?', [$cartId]) as $item) {
            $product = $di['db']->load('Product', (int) $item->product_id);
            if ($service->isTrialProduct($product)) { $service->continuationPrice($product); $service->assertClientCanStartTrial((int) $params['client_id']); }
        }
    }
    public static function onAfterAdminOrderActivate(\Box_Event $event): void
    {
        $di = $event->getDi(); $order = $di['db']->load('ClientOrder', (int) ($event->getParameters()['id'] ?? 0));
        if (!$order instanceof \Model_ClientOrder || (string) $order->service_type !== 'hosting') return;
        $service = $di['mod_service']('quizontalhostingtrial'); $product = $di['db']->load('Product', (int) $order->product_id);
        if (!$service->isTrialProduct($product)) return;
        $service->startTrial($order);
    }
    public function startTrial(\Model_ClientOrder $order): void
    {
        if ($this->di['db']->findOne('quizontal_hosting_trial', 'order_id=?', [(int) $order->id])) return;
        $this->assertClientCanStartTrial((int) $order->client_id);
        $days = max(1, (int) $this->getConfig()['trial_days']); $now = date('Y-m-d H:i:s');
        $ends = date('Y-m-d H:i:s', time() + $days * 86400);
        $stmt = $this->di['pdo']->prepare('INSERT INTO quizontal_hosting_trial (client_id,order_id,starts_at,ends_at,created_at,updated_at) VALUES (?,?,?,?,?,?)');
        $stmt->execute([(int) $order->client_id, (int) $order->id, $now, $ends, $now, $now]);
        // The paid renewal must start when the free trial ends, rather than from
        // the original zero-price activation date. FOSSBilling's normal renewal
        // lifecycle then extends this timestamp by the product billing period.
        $order->expires_at = $ends;
        $order->updated_at = $now;
        $this->di['db']->store($order);
        $this->di['mod_service']('Order')->saveStatusChange($order, 'Seven-day verified hosting trial started.');
    }

    public static function onAfterAdminCronRun(\Box_Event $event): void { $event->getDi()['mod_service']('quizontalhostingtrial')->runLifecycle(); }
    public static function onAfterAdminInvoicePaymentReceived(\Box_Event $event): void
    {
        $di = $event->getDi(); $invoiceId = (int) ($event->getParameters()['id'] ?? 0); if ($invoiceId) $di['mod_service']('quizontalhostingtrial')->continueAfterPayment($invoiceId);
    }
    public function runLifecycle(): void
    {
        $rows = $this->di['db']->find('quizontal_hosting_trial', 'status IN ("active","suspended") ORDER BY id ASC LIMIT 100');
        foreach ($rows as $trial) {
            try {
                if ($trial->status === 'active' && empty($trial->reminder_sent_at) && strtotime((string) $trial->ends_at) <= time() + 86400) $this->sendReminder($trial);
                if ($trial->status === 'active' && strtotime((string) $trial->ends_at) <= time()) $this->suspendIfUnpaid($trial);
                if ($trial->status === 'suspended' && !empty($trial->continuation_invoice_id)) $this->continueAfterPayment((int) $trial->continuation_invoice_id);
            } catch (\Throwable $e) { $this->di['logger']->error('Hosting trial lifecycle failed for order #%s: %s', $trial->order_id, $e->getMessage()); }
        }
    }
    private function sendReminder($trial): void
    {
        $order = $this->di['db']->load('ClientOrder', (int) $trial->order_id); $product = $order ? $this->di['db']->load('Product', (int) $order->product_id) : null;
        if (!$order instanceof \Model_ClientOrder || !$this->isTrialProduct($product)) return;
        // Trial checkout is Rs.0. At reminder time switch this order to its normal recurring price before FOSSBilling creates the renewal invoice.
        $order->price = $this->continuationPrice($product); $this->di['db']->store($order);
        $invoiceId = (int) $this->di['mod_service']('Invoice')->renewInvoice($order, ['due_days' => 1]);
        $this->di['db']->exec('UPDATE quizontal_hosting_trial SET reminder_sent_at=NOW(), continuation_invoice_id=?, updated_at=NOW() WHERE id=?', [$invoiceId, $trial->id]);
        $invoice = $this->di['db']->load('Invoice', $invoiceId);
        if ($invoice instanceof \Model_Invoice) $this->di['mod_service']('email')->sendTemplate([
            'to_client' => (int) $trial->client_id, 'code' => 'mod_quizontalhostingtrial_reminder',
            'trial' => $this->toArray($trial), 'invoice' => $this->di['mod_service']('Invoice')->toApiArray($invoice, true, null, true), 'order' => $this->di['mod_service']('Order')->toApiArray($order),
        ]);
    }
    private function suspendIfUnpaid($trial): void
    {
        $paid = !empty($trial->continuation_invoice_id) && $this->invoicePaid((int) $trial->continuation_invoice_id);
        if ($paid) { $this->continueTrial($trial); return; }
        $order = $this->di['db']->load('ClientOrder', (int) $trial->order_id);
        if ($order instanceof \Model_ClientOrder && $order->status === \Model_ClientOrder::STATUS_ACTIVE) $this->di['mod_service']('Order')->suspendFromOrder($order, 'Free trial ended without payment');
        $this->di['db']->exec('UPDATE quizontal_hosting_trial SET status="suspended", suspended_at=NOW(), updated_at=NOW() WHERE id=?', [$trial->id]);
        $this->sendStatusEmail($trial, 'mod_quizontalhostingtrial_suspended');
    }
    public function continueAfterPayment(int $invoiceId): void
    {
        $trial = $this->di['db']->findOne('quizontal_hosting_trial', 'continuation_invoice_id=? AND status IN ("active","suspended")', [$invoiceId]);
        if ($trial && $this->invoicePaid($invoiceId)) $this->continueTrial($trial);
    }
    private function continueTrial($trial): void
    {
        $order = $this->di['db']->load('ClientOrder', (int) $trial->order_id);
        if ($order instanceof \Model_ClientOrder && $order->status === \Model_ClientOrder::STATUS_SUSPENDED) $this->di['mod_service']('Order')->unsuspendFromOrder($order);
        $this->di['db']->exec('UPDATE quizontal_hosting_trial SET status="continued", continued_at=NOW(), updated_at=NOW() WHERE id=?', [$trial->id]);
        $this->sendStatusEmail($trial, 'mod_quizontalhostingtrial_continued');
    }
    private function invoicePaid(int $id): bool { $invoice = $this->di['db']->load('Invoice', $id); return $invoice instanceof \Model_Invoice && $invoice->status === \Model_Invoice::STATUS_PAID; }
    private function sendStatusEmail($trial, string $code): void { try { $this->di['mod_service']('email')->sendTemplate(['to_client'=>(int)$trial->client_id,'code'=>$code,'trial'=>$this->toArray($trial)]); } catch (\Throwable) {} }
    private function toArray($trial): array { return ['order_id'=>(int)$trial->order_id,'starts_at'=>(string)$trial->starts_at,'ends_at'=>(string)$trial->ends_at,'status'=>(string)$trial->status,'continuation_invoice_id'=>(int)($trial->continuation_invoice_id ?? 0)]; }
    public function clientStatus(int $clientId): array
    {
        $profile = $this->profile($clientId); $client = $this->di['db']->load('Client', $clientId);
        $trials = $this->di['db']->find('quizontal_hosting_trial', 'client_id=? ORDER BY id DESC', [$clientId]);
        return ['email_verified'=>!empty($client->email_approved), 'whatsapp'=>$profile['whatsapp'] ?? '', 'trials'=>array_map(fn($t)=>$this->toArray($t), $trials)];
    }
}
