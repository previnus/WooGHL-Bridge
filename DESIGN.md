# WooCommerce to HighLevel bridge design

## Release security decisions

- Automatic order events run contactless unless trusted WordPress code confirms ownership of the exact current billing address. A manual snapshot can upsert a contact after an administrator confirms the billing identity. The manual job stores only a keyed digest of the approved fields, so an edit before delivery invalidates the approval. WooCommerce checkout, account login and payment status alone do not prove mailbox ownership.
- A bridge activation is bound to the configured WordPress home and workflow URL. A destination change, a clone at a different home URL, or an upgrade from the old activation format requires explicit reactivation. Token rotation for the same location and workflow keeps pending jobs.
- Repeated checkout callbacks on the same order use one submission identity per activation. Action Scheduler coalesces matching pending jobs and checks retained action history before inserting another. A new order still goes through WooCommerce checkout admission. Legitimate status, payment and refund events retain their own identities.

## Scope and platform choice

The store sends order events to one custom HighLevel workflow and upserts billing contacts in the workflow's HighLevel sub-account. WooCommerce owns order and payment state. HighLevel already provides a native WooCommerce import for commerce records, so the custom integration is for order-driven automation. The plugin runs inside WordPress and uses WooCommerce's order API and bundled Action Scheduler. A single-site, single-location deployment uses a scoped private integration token.

## Event and delivery plan

1. Capture classic and block checkout submissions, order status transitions, payment completions and refunds. The admin can also queue a snapshot of an existing order.
2. Queue an event identifier, event time, order ID, destination fingerprint and event-specific identifiers or statuses. Checkout and payment callbacks return without waiting for an HTTP request.
3. Load the current order through `wc_get_order()`. Upsert the contact by billing email only after a trusted verifier or an administrator approves the identity. Deliver the order summary and the returned contact ID to the workflow. Other orders enter a contactless workflow branch.
4. Retry transient HTTP and transport failures with a bounded backoff. Expose failure status in Scheduled Actions and record operational metadata in WooCommerce logs. An administrator can queue a fresh snapshot after resolving a permanent error.

Checkout submissions have a stable identity for each order and activation. A payment or full refund can also produce a separate status change. The order snapshot reflects current state at delivery. Retries can arrive out of order and can repeat an accepted webhook if the response was lost. A workflow with side effects can use the stable `event_id` for deduplication. A manual snapshot is a separate event. HighLevel contact matching follows its location's duplicate-contact rules.

## Security design and threat review

- **Authorization.** WordPress administrators with `manage_options` can enable, pause or manually trigger the bridge using nonce-protected POSTs. A manual dispatch also requires order edit access. WooCommerce application hooks are the only automatic entry points. The HighLevel token is a sub-account token scoped to `contacts.write`. The trigger path itself is a bearer secret. Both values are supplied as WordPress constants, which `wp-config.php` can populate from the hosting secret configuration.
- **Data and retention.** Contact upsert sends billing email and non-empty first and last names. Workflow JSON contains a CRM contact ID, store URL, numeric IDs, statuses, timestamps, currency, quantities and monetary summaries. It excludes free-text order and line-item metadata, payment credentials, shipping and billing addresses and marketing-consent fields. Scheduled jobs contain identifiers and event metadata. Logs use event IDs, order IDs, attempt counts and fixed reason codes. WooCommerce and Action Scheduler handle local log and job retention. HighLevel owns retention and erasure for its copies. The bridge can be paused while the merchant handles an incident or changes credentials.
- **Outbound trust boundary.** The API origin is fixed. The trigger must use the documented HTTPS origin and exact path shape with the configured location ID. Redirects are disabled, TLS verification stays on, and WordPress rejects unsafe URLs. The API bearer token is attached only to contact upsert. Request and response bodies are bounded. The worker validates the contact ID and rejects a conflicting response location before webhook delivery.
- **Configuration changes and retries.** Activation binds the site home and workflow. Queue fingerprints also include the activation session. Pausing and enabling again starts a new session. Pending jobs from an old session or destination are skipped, and rotating only the API token preserves pending jobs. Jobs already running may finish during a pause. Contact upsert can be repeated after a webhook error. Action Scheduler claims local jobs, and retries preserve the event ID. Permanent failures remain visible for operator review.
- **Abuse and integrity.** The server constructs event data from WooCommerce records. It validates the manual ID and verifies a refund belongs to the order. A finite retry budget and response caps limit outage amplification. WooCommerce access controls and the hosting platform protect `wp-config.php`, the queue and logs. The staging plan uses test customer data and a workflow without customer-facing actions.
- **Dependencies and deployment.** Use WordPress core and WooCommerce APIs, with no additional runtime packages or custom tables. Keep the bridge disabled until configured and activate one store at a time. Validate both order storage engines, the contact API contract and the workflow mapping before a production rollout.

## Review and staging

- The pre-release audit identified unverified contact association and repeated checkout queue amplification in the prototype. Verified contact admission and stable checkout submission identity address those paths.
- The plugin relies on WooCommerce checkout admission for separate new orders. A webhook timeout can occur after HighLevel accepted the request, so workflows that have non-repeatable effects still need event ID deduplication.
- The live check uses a staging sub-account, test billing data and a workflow without customer-facing actions. Verify contact matching, trigger mapping and each intended event branch before enabling production delivery.

## References

- [HighLevel native WooCommerce integration](https://help.gohighlevel.com/support/solutions/articles/155000004714)
- [HighLevel contact upsert API](https://marketplace.gohighlevel.com/docs/2021-07-28/ghl/contacts/upsert-contact/)
- [HighLevel inbound workflow webhook](https://help.gohighlevel.com/support/solutions/articles/48001237383)
- [WooCommerce HPOS compatibility](https://developer.woocommerce.com/docs/features/high-performance-order-storage/recipe-book/)
- [Action Scheduler API](https://actionscheduler.org/api/)
