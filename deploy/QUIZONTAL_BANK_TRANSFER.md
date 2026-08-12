# Quizontal Cloud invoice-linked bank transfers

See [`fossbilling/Quizontalbanktransfer/README.md`](fossbilling/Quizontalbanktransfer/README.md) for installation and operation.

## Workflow (order payment — primary)

1. Customer checks out. When the wallet covers the total, one-click wallet payment provisions instantly; otherwise the customer chooses **Pay by bank transfer** and the order is created against an unpaid invoice.
2. The invoice page leads to the guided bank-details + slip-upload screen for that exact invoice (`/quizontalbanktransfer/invoice/<hash>`).
3. The customer transfers the invoice total and uploads the receipt; one receipt per invoice is enforced.
4. Admin opens **Bank Transfer Receipts**. The review screen labels the submission **Order payment**, with copy stating that approval marks that invoice paid and activates the service.
5. Approval records the manual transaction against the Custom gateway, marks the invoice paid with order execution, and FOSSBilling activates the linked order automatically. The wallet is never touched.
6. Customer emails (submitted / approved / rejected) reference the invoice and service activation.

## Workflow (wallet top-up — still supported)

1. Customer visits `/quizontalbanktransfer` (or the wallet page) while logged in, enters an LKR amount, transfers it, and uploads a receipt.
2. Approval credits the wallet, marks the deposit invoice paid, and retries any unpaid invoices against the new balance.

## Legacy setting

The old hard requirement that the wallet must cover the cart before checkout survives as the **Require full wallet balance before checkout** toggle in the module settings. It defaults to OFF; leave it off for the invoice-linked flow.

Always test using a small amount and a test customer before production use.
