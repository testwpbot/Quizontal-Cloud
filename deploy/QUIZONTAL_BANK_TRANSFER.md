# Quizontal Cloud wallet-first bank transfers

See [`fossbilling/Quizontalbanktransfer/README.md`](fossbilling/Quizontalbanktransfer/README.md) for installation and operation.

## Workflow

1. Customer visits `/quizontalbanktransfer` while logged into FossBilling.
2. Customer enters an LKR amount, transfers it to the configured bank account, and uploads a receipt.
3. The module creates a FossBilling deposit invoice and records the submission as `pending`.
4. Admin opens **Bank Transfer Receipts**, verifies the bank account, and enters the real bank transaction ID.
5. Approval credits the customer's wallet and marks the deposit invoice paid.
6. The customer can order a VPS only when their wallet balance covers the cart total.
7. FossBilling deducts the order total from wallet balance through its built-in ClientBalance flow.

Always test using a small amount and a test customer before production use.
