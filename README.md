# WooGHL Bridge

WooGHL Bridge sends WooCommerce order events to a custom HighLevel workflow and syncs verified billing contacts in that workflow’s sub-account. WooCommerce remains the system of record for orders. For HighLevel's native order, transaction or catalog import, use its built-in WooCommerce integration. [HighLevel WooCommerce integration](https://help.gohighlevel.com/support/solutions/articles/155000004714)

By Previn Kalisetty Appadu. [Author website](https://previn.me)

## Install

Requirements are single-site WordPress 6.5 or newer, WooCommerce 8.2 or newer, PHP 8.3 or newer, and a working WooCommerce Scheduled Actions runner. The bridge supports classic and block checkout and both HPOS and legacy order storage.

1. Download the repository ZIP and extract it. Compress the inner `wooghl-bridge` folder as `wooghl-bridge.zip`. The ZIP should contain `wooghl-bridge/wooghl-bridge.php` and `wooghl-bridge/includes/`.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin**, choose the plugin ZIP, then click **Install Now → Activate Plugin**. If you deploy by file transfer, copy the inner `wooghl-bridge` folder into `wp-content/plugins/` and activate it on the **Plugins** screen.
3. Open **WooCommerce → WooGHL Bridge**. Delivery starts paused.

## Configure HighLevel and WordPress

1. In a HighLevel test sub-account, create a Private Integration with only the `contacts.write` scope. Configure matching by email and copy the sub-account’s location ID. [Private integrations](https://marketplace.gohighlevel.com/docs/Authorization/PrivateIntegrationsToken/), [Contact matching](https://marketplace.gohighlevel.com/docs/2021-07-28/ghl/contacts/upsert-contact/)
2. Add an **Inbound Webhook** trigger to a staging workflow and copy the generated URL. It must have the form `https://services.leadconnectorhq.com/hooks/LOCATION_ID/webhook-trigger/TRIGGER_ID`. The location ID in the URL must match your sub-account. HighLevel classifies this as a premium trigger. [Inbound Webhook trigger](https://help.gohighlevel.com/support/solutions/articles/48001237383)
3. Set `WOOGHL_LOCATION_ID`, `WOOGHL_PRIVATE_TOKEN` and `WOOGHL_WORKFLOW_URL` in your hosting secret settings. Make them available to both web PHP and the process that runs Scheduled Actions. Add these lines inside `wp-config.php`, above the line that loads `wp-settings.php`. Keep the token and workflow URL out of version control.

   ```php
   define( 'WOOGHL_LOCATION_ID', getenv( 'WOOGHL_LOCATION_ID' ) );
   define( 'WOOGHL_PRIVATE_TOKEN', getenv( 'WOOGHL_PRIVATE_TOKEN' ) );
   define( 'WOOGHL_WORKFLOW_URL', getenv( 'WOOGHL_WORKFLOW_URL' ) );
   ```

4. In **WooCommerce → WooGHL Bridge**, enable delivery and queue a snapshot for a test order. Check the `wooghl-bridge` group under **Scheduled Actions** and the same source in **WooCommerce logs**. The snapshot sends a real webhook request to the configured staging workflow.
5. In HighLevel, select the snapshot as the trigger’s mapping reference. Filter on `event_type` and test each branch with staging orders before enabling customer-facing actions. If a workflow needs a contact, find it by `contact_id` and handle the empty value with a separate branch. [Find Contact workflow action](https://help.gohighlevel.com/support/solutions/articles/155000002686-workflow-action-find-contact)

Use separate HighLevel credentials and trigger URLs for staging and production. On production, repeat the setup and enable delivery only after confirming the intended sub-account and workflow.

## Sync contacts safely

Order events run contactless by default. For a manual snapshot, confirm that the order’s billing identity is correct, then select **I verified this order’s billing identity** when queuing it. If the approved billing email or names change before delivery, the job fails and can be queued again after review.

For automatic sync, a trusted site integration can register the `wooghl_billing_email_verified` WordPress filter. It receives the default decision (`false`), the `WC_Order` and the exact current billing email. Return `true` only when the store’s email verification system confirms ownership of that exact address for that order. An entered address, an account login or payment success alone is insufficient. Without a trusted verifier the bridge sends the order event with an empty `contact_id`.

When approved, contact upsert sends the billing email and available billing names to the configured sub-account. The workflow receives the CRM contact ID. The bridge leaves marketing-consent fields alone and keeps credentials and billing details out of its queue and logs.

## Events

| `event_type` | When it is queued |
| --- | --- |
| `order.submitted` | WooCommerce accepts a classic or block checkout submission, before payment. Repeated callbacks for the same order in the current activation are coalesced while the original job remains in Action Scheduler history. |
| `order.status_changed` | An order changes to a non-draft status. The original `previous_status` and `new_status` accompany the current `order_status`. |
| `order.payment_completed` | WooCommerce fires `woocommerce_payment_complete`. Manually changing a status to `processing` or `completed` is a status event. |
| `order.refunded` | WooCommerce records a partial or full refund. `refund_id` and `refund_amount` identify that refund. |
| `order.snapshot` | An administrator queues the current state of one existing order. |

The payload includes `schema_version`, `event_id`, `event_type`, `occurred_at`, `store_url`, `order_id`, `contact_id`, `order_status`, `previous_status`, `new_status`, `created_at`, `paid_at`, `currency`, `order_total`, `refunded_total`, `refund_id`, `refund_amount` and `items`. Each item includes `product_id`, `variation_id`, `quantity` and `line_total`. Amounts are decimal strings in the order currency. Timestamps use UTC ISO 8601. Inapplicable status, timestamp, contact and amount fields are empty strings. An inapplicable refund ID is `0`.

## Delivery and recovery

- `event_id` stays the same across automatic retries. A timeout can occur after HighLevel accepts an event, so deduplicate this identifier before non-repeatable actions. Use `store_url` and `order_id` together to correlate separate events about the same order.
- Delivery can be out of order. `occurred_at` is the order creation time for submissions and the event time for other events. Order totals, line items and current status are read when the worker runs. Use `order.payment_completed` for gateway completion and expect a separate status event for the same payment or a full refund.
- Transient transport, 408, 429 and 5xx errors get up to four retries. Backoff starts at one minute and honors `Retry-After` values up to 24 hours. A longer requested delay fails the job for operator review. Repeated contact upserts follow the sub-account’s duplicate-contact rules.
- Permanent failures appear in **Scheduled Actions** and **WooCommerce logs**. The log entry identifies the contact or workflow step and the HTTP status or fixed failure code. After fixing the cause, queue a fresh snapshot if the workflow can handle a snapshot in place of the original event.
- Pausing or deactivating invalidates pending jobs. The WordPress home URL, workflow URL and activation session are bound together. Re-enable delivery explicitly after changing the home or workflow URL or upgrading from version 0.1.0. Token-only rotation preserves pending jobs. Jobs already running can finish during a pause.

See [the design and security decisions](DESIGN.md) for the integration boundaries.
