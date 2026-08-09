<?php

declare(strict_types=1);

namespace Box\Mod\Quizontalbanktransfer;

use FOSSBilling\InformationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class Service implements \FOSSBilling\InjectionAwareInterface
{
    protected ?\Pimple\Container $di = null;

    public function setDi(\Pimple\Container $di): void { $this->di = $di; }
    public function getDi(): ?\Pimple\Container { return $this->di; }

    public function getModulePermissions(): array
    {
        return [
            'view' => ['type' => 'bool', 'display_name' => 'View bank transfer receipts', 'description' => 'View submitted bank transfer receipts.'],
            'manage' => ['type' => 'bool', 'display_name' => 'Manage bank transfer receipts', 'description' => 'Approve or reject bank transfer receipts.'],
            'manage_settings' => [],
        ];
    }

    public function install(): bool
    {
        $this->di['db']->exec("CREATE TABLE IF NOT EXISTS `quizontal_bank_transfer` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `client_id` BIGINT UNSIGNED NOT NULL,
            `invoice_id` BIGINT UNSIGNED NOT NULL,
            `amount` DECIMAL(18,2) NOT NULL,
            `currency` VARCHAR(10) NOT NULL,
            `reference` VARCHAR(191) NOT NULL,
            `original_name` VARCHAR(255) NOT NULL,
            `stored_name` VARCHAR(255) NOT NULL,
            `mime_type` VARCHAR(100) NOT NULL,
            `file_size` INT UNSIGNED NOT NULL,
            `status` ENUM('pending','processing','approved','rejected') NOT NULL DEFAULT 'pending',
            `admin_note` TEXT NULL,
            `transaction_id` VARCHAR(191) NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `quizontal_bank_transfer_invoice_unique` (`invoice_id`),
            KEY `quizontal_bank_transfer_status_index` (`status`),
            KEY `quizontal_bank_transfer_client_index` (`client_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $this->ensureUploadDirectory();
        return true;
    }

    public function uninstall(): bool
    {
        // Financial audit records and receipt files are deliberately retained.
        return true;
    }

    public function getConfig(): array
    {
        $config = (array) $this->di['mod_config']('quizontalbanktransfer');
        return array_merge([
            'bank_name' => '', 'account_name' => 'Quizontal Cloud', 'account_number' => '',
            'branch' => '', 'swift_code' => '', 'instructions' => '', 'max_file_mb' => 5,
            'admin_notification_email' => '', 'wallet_only_checkout' => true,
        ], $config);
    }

    public function createFundsInvoice(\Model_Client $client, mixed $amount): string
    {
        if (!is_numeric($amount) || (float) $amount <= 0) throw new InformationException('Enter a valid deposit amount.');
        $this->ensureClientCurrency($client);
        $invoiceService = $this->di['mod_service']('Invoice');
        $invoice = $invoiceService->generateFundsInvoice($client, (float) $amount);
        $invoiceService->approveInvoice($invoice, ['id' => $invoice->id]);
        return (string) $invoice->hash;
    }

    public function submit(\Model_Client $client, mixed $amount, string $reference, ?UploadedFile $file, ?string $invoiceHash = null): array
    {
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 191) throw new InformationException('Enter a valid bank transfer reference.');
        $this->validateUpload($file);
        $this->ensureClientCurrency($client);

        $invoiceService = $this->di['mod_service']('Invoice');
        if ($invoiceHash !== null && $invoiceHash !== '') {
            $invoice = $this->di['db']->findOne('Invoice', 'hash = ? AND client_id = ?', [$invoiceHash, $client->id]);
            if (!$invoice instanceof \Model_Invoice) throw new InformationException('Deposit invoice not found.');
            if ($invoice->status !== \Model_Invoice::STATUS_UNPAID) throw new InformationException('Only unpaid deposit invoices can receive a receipt.');
            if (!$invoiceService->isInvoiceTypeDeposit($invoice)) throw new InformationException('The selected invoice is not a wallet deposit invoice.');
            if ($this->fetchOne('SELECT id FROM quizontal_bank_transfer WHERE invoice_id=?', [(int) $invoice->id])) throw new InformationException('A receipt was already submitted for this invoice.');
            $amount = $invoiceService->getTotalWithTax($invoice);
        } else {
            if (!is_numeric($amount) || (float) $amount <= 0) throw new InformationException('Enter a valid deposit amount.');
            $invoice = $invoiceService->generateFundsInvoice($client, (float) $amount);
            $invoiceService->approveInvoice($invoice, ['id' => $invoice->id]);
        }

        $gateway = $this->getManualGateway();
        $invoice->gateway_id = (int) $gateway->id;
        $this->di['db']->store($invoice);

        $mimeType = (string) $file->getMimeType();
        $originalName = basename((string) $file->getClientOriginalName());
        $fileSize = (int) $file->getSize();
        $storedName = bin2hex(random_bytes(24)).'.'.$this->extensionForMime($mimeType);
        $file->move($this->ensureUploadDirectory(), $storedName);
        $now = date('Y-m-d H:i:s');
        $statement = $this->di['pdo']->prepare('INSERT INTO quizontal_bank_transfer (client_id, invoice_id, amount, currency, reference, original_name, stored_name, mime_type, file_size, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([
            (int) $client->id, (int) $invoice->id, number_format((float) $amount, 2, '.', ''),
            (string) $client->currency, $reference, $originalName, $storedName, $mimeType,
            $fileSize, 'pending', $now, $now,
        ]);
        $submissionId = (int) $this->di['pdo']->lastInsertId();
        try {
            $this->sendReceiptSubmittedEmails($client, $submissionId, $invoice);
        } catch (\Throwable $exception) {
            error_log('Receipt submission email could not be queued: '.$exception->getMessage());
        }
        return ['id' => $submissionId, 'invoice_hash' => (string) $invoice->hash];
    }

    public function setAdminNotificationEmail(string $email): bool
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new InformationException('A valid administrator notification email is required.');
        $extension = $this->di['mod_service']('extension');
        $config = (array) $extension->getConfig('mod_quizontalbanktransfer');
        $config['ext'] = 'mod_quizontalbanktransfer';
        $config['admin_notification_email'] = $email;
        return $extension->setConfig($config);
    }

    private function sendReceiptSubmittedEmails(\Model_Client $client, int $submissionId, \Model_Invoice $invoice): void
    {
        $submission = $this->get($submissionId);
        $invoiceData = $this->di['mod_service']('Invoice')->toApiArray($invoice, true, $client);
        $customer = $this->di['mod_service']('Client')->toApiArray($client);
        $emailService = $this->di['mod_service']('Email');
        $common = [
            'code' => 'mod_quizontalbanktransfer_receipt_submitted',
            'submission' => $submission,
            'invoice' => $invoiceData,
            'customer' => $customer,
            'review_url' => $this->di['url']->adminLink('quizontalbanktransfer/'.$submissionId),
            'wallet_url' => $this->di['url']->link('client/balance'),
            'send_now' => true,
        ];
        $emailService->sendTemplate($common + [
            'to_client' => (int) $client->id,
            'recipient_type' => 'customer',
        ]);
        $adminEmail = (string) ($this->getConfig()['admin_notification_email'] ?? '');
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $emailService->sendTemplate($common + [
                'to' => $adminEmail,
                'to_name' => 'Quizontal Cloud Administrator',
                'recipient_type' => 'admin',
            ]);
        }
    }

    public function search(array $data): array
    {
        $params = [];
        $sql = 'SELECT r.*, i.hash AS invoice_hash, CONCAT(COALESCE(i.serie, ""), COALESCE(i.nr, "")) AS serie_nr, i.status AS invoice_status, CONCAT(c.first_name, " ", c.last_name) AS client_name, c.email AS client_email FROM quizontal_bank_transfer r JOIN invoice i ON i.id=r.invoice_id JOIN client c ON c.id=r.client_id WHERE 1';
        if (!empty($data['client_id'])) { $sql .= ' AND r.client_id=:client_id'; $params[':client_id'] = (int) $data['client_id']; }
        if (!empty($data['status'])) { $sql .= ' AND r.status=:status'; $params[':status'] = (string) $data['status']; }
        $sql .= ' ORDER BY r.id DESC';
        return $this->di['pager']->getPaginatedResultSet($sql, $params, (int) ($data['per_page'] ?? 50), (int) ($data['page'] ?? 1));
    }

    public function get(int $id): array
    {
        $statement = $this->di['pdo']->prepare('SELECT r.*, i.hash AS invoice_hash, CONCAT(COALESCE(i.serie, ""), COALESCE(i.nr, "")) AS serie_nr, i.status AS invoice_status, CONCAT(c.first_name, " ", c.last_name) AS client_name, c.email AS client_email FROM quizontal_bank_transfer r JOIN invoice i ON i.id=r.invoice_id JOIN client c ON c.id=r.client_id WHERE r.id=?');
        $statement->execute([$id]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if (!$row) throw new InformationException('Bank transfer submission not found.');
        return $row;
    }

    public function approve(int $id, string $transactionId, string $note = ''): bool
    {
        $row = $this->get($id);
        if ($row['status'] !== 'pending') throw new InformationException('Only pending submissions can be approved.');
        $transactionId = trim($transactionId);
        if ($transactionId === '') throw new InformationException('A bank transaction ID is required.');
        $duplicate = $this->fetchOne('SELECT id FROM quizontal_bank_transfer WHERE transaction_id=? AND id<>?', [$transactionId, $id]);
        if ($duplicate) throw new InformationException('This bank transaction ID was already used.');

        $invoice = $this->di['db']->getExistingModelById('Invoice', (int) $row['invoice_id'], 'Deposit invoice not found.');
        if ($invoice->status === \Model_Invoice::STATUS_PAID) throw new InformationException('This deposit invoice is already paid.');
        if (!$this->di['mod_service']('Invoice')->isInvoiceTypeDeposit($invoice)) throw new InformationException('The linked invoice is not a wallet deposit invoice.');

        $this->updateSubmission( ['status' => 'processing', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        try {
            $gateway = $this->getManualGateway();
            $invoice->gateway_id = (int) $gateway->id;
            $this->di['db']->store($invoice);
            $invoiceService = $this->di['mod_service']('Invoice');

            if (method_exists($invoiceService, 'markAsPaidByAdmin')) {
                // FOSSBilling 0.8+: admin confirmation no longer credits deposit invoices itself.
                $client = $this->di['mod_service']('Client')->get(['id' => (int) $row['client_id']]);
                $description = 'Quizontal Cloud bank transfer '.$transactionId;
                $existingCredit = $this->fetchOne(
                    'SELECT id FROM client_balance WHERE client_id=? AND type=? AND rel_id=?',
                    [(int) $row['client_id'], 'quizontal_bank_transfer', (string) $invoice->id]
                );
                if (!$existingCredit) {
                    $this->di['mod_service']('Client')->addFunds($client, (float) $row['amount'], $description, ['type' => 'quizontal_bank_transfer', 'rel_id' => (int) $invoice->id]);
                }
                $invoiceService->markAsPaidByAdmin($invoice, ['gateway_id' => (int) $gateway->id, 'transactionId' => $transactionId, 'execute' => true]);
            } else {
                // FOSSBilling 0.7: the Custom adapter credits the deposit while processing.
                $this->di['api_admin']->invoice_mark_as_paid(['id' => (int) $invoice->id, 'transactionId' => $transactionId, 'execute' => true]);
            }

            // Apply the newly confirmed wallet credit to any existing unpaid order
            // or renewal invoices. New checkouts and newly generated renewals already
            // request credit payment automatically in FossBilling.
            $invoiceService->doBatchPayWithCredits(['client_id' => (int) $row['client_id']]);

            $this->updateSubmission( ['status' => 'approved', 'transaction_id' => $transactionId, 'admin_note' => trim($note), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            return true;
        } catch (\Throwable $e) {
            $this->updateSubmission( ['status' => 'pending', 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
            throw $e;
        }
    }

    public function reject(int $id, string $note): bool
    {
        $row = $this->get($id);
        if ($row['status'] !== 'pending') throw new InformationException('Only pending submissions can be rejected.');
        if (trim($note) === '') throw new InformationException('A rejection reason is required.');
        $this->updateSubmission( ['status' => 'rejected', 'admin_note' => trim($note), 'updated_at' => date('Y-m-d H:i:s')], ['id' => $id]);
        return true;
    }

    public function receiptPath(array $row): string { return $this->ensureUploadDirectory().DIRECTORY_SEPARATOR.basename((string) $row['stored_name']); }

    public static function onBeforeClientCheckout(\Box_Event $event): void
    {
        $di = $event->getDi();
        $config = (array) $di['mod_config']('quizontalbanktransfer');
        if (array_key_exists('wallet_only_checkout', $config) && !$config['wallet_only_checkout']) return;
        $params = $event->getParameters();
        $client = $di['db']->getExistingModelById('Client', (int) $params['client_id']);
        $cart = $di['db']->getExistingModelById('Cart', (int) $params['cart_id']);
        $total = (float) $di['mod_service']('Cart')->toApiArray($cart)['total'];
        $balance = (float) $di['mod_service']('Client', 'Balance')->getClientBalance($client);
        if ($balance < $total) throw new InformationException('Please add enough funds to your Quizontal Cloud wallet before placing this order.');
    }

    private function fetchOne(string $sql, array $params): mixed
    {
        $statement = $this->di['pdo']->prepare($sql);
        $statement->execute($params);
        return $statement->fetchColumn();
    }

    private function updateSubmission(array $data, array $criteria): void
    {
        $allowed = ['status', 'transaction_id', 'admin_note', 'updated_at'];
        $sets = [];
        $values = [];
        foreach ($data as $column => $value) {
            if (!in_array($column, $allowed, true)) throw new \InvalidArgumentException('Invalid receipt update column.');
            $sets[] = "`{$column}` = ?";
            $values[] = $value;
        }
        $values[] = (int) $criteria['id'];
        $statement = $this->di['pdo']->prepare('UPDATE quizontal_bank_transfer SET '.implode(', ', $sets).' WHERE id = ?');
        $statement->execute($values);
    }

    private function ensureClientCurrency(\Model_Client $client): void
    {
        if ($client->currency) return;
        $currencyService = $this->di['mod_service']('currency');
        $currency = method_exists($currencyService, 'getDefault') ? $currencyService->getDefault() : $currencyService->getCurrencyRepository()->findDefault();
        if ($currency === null) throw new InformationException('A default currency must be configured first.');
        $client->currency = method_exists($currency, 'getCode') ? $currency->getCode() : $currency->code;
        $this->di['db']->store($client);
    }

    private function getManualGateway(): \Model_PayGateway
    {
        $gateway = $this->di['db']->findOne('PayGateway', 'gateway = ? AND enabled = 1', ['Custom']);
        if (!$gateway instanceof \Model_PayGateway) throw new InformationException('Enable and configure the Custom gateway as Manual Bank Transfer first.');
        return $gateway;
    }

    private function validateUpload(?UploadedFile $file): void
    {
        if (!$file instanceof UploadedFile || !$file->isValid()) throw new InformationException('A valid payment receipt is required.');
        $max = max(1, (int) $this->getConfig()['max_file_mb']) * 1024 * 1024;
        if ($file->getSize() <= 0 || $file->getSize() > $max) throw new InformationException('The receipt file is too large.');
        if (!in_array($file->getMimeType(), ['image/jpeg', 'image/png', 'application/pdf'], true)) throw new InformationException('Only JPG, PNG, and PDF receipts are accepted.');
    }

    private function extensionForMime(string $mime): string { return ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'][$mime]; }
    private function ensureUploadDirectory(): string
    {
        $dir = PATH_DATA.DIRECTORY_SEPARATOR.'uploads'.DIRECTORY_SEPARATOR.'quizontal-bank-transfer';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new \RuntimeException('Could not create the private receipt directory.');
        return $dir;
    }
}
