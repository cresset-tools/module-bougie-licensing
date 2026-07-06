# Bougie Licensing for Magento 2

Sell and provision **Bougie Repo** (sconce) Composer license keys from a
Magento 2 store. Map a catalog product to a repository **edition** (SKU); when an
order is paid, the module issues a license key against that edition, stores it,
and shows it in the customer's account with copy-paste Composer install
instructions. Refunds revoke the key.

Magento owns commerce (catalog, tax, invoicing, subscriptions); Bougie just
**provisions and gates** — this module is the bridge, talking to the Bougie
management API (`/api/v1`).

- **Composer package:** `cresset-tools/magento2-bougie-licensing`
- **Magento module:** `Bougie_Licensing`
- **Requires:** Magento Open Source / Adobe Commerce 2.4.4+, PHP 8.1+

## How it works

```
 customer buys a product (attribute "Bougie edition" = e.g. "pro")
        │  order invoice paid  (sales_order_invoice_pay)
        ▼
 ProvisionOnInvoicePaid ─▶ Provisioner ─▶ POST /api/v1/repos/{org}/{repo}/license-keys
        │                                   Idempotency-Key: {orderIncrement}:{itemId}
        │                                   { "edition": "pro", "buyer": "<email>" }
        ▼
 stores {license_id, key, bound, packages} in `bougie_license`
        ▼
 customer account ▶ "My Licenses" ▶ key + Composer install snippet
```

- **Idempotent** on both ends: one row per order item locally, and the API's
  `Idempotency-Key` (the order id) means a retried/duplicated invoice event never
  mints a second key.
- **Non-blocking:** an API error is logged (`var/log/bougie_licensing.log`) and
  skipped — it never breaks checkout or invoicing. Re-invoicing re-runs it.
- **Refund → revoke:** a credit memo for a licensed item revokes its key
  (`sales_order_creditmemo_save_after`).

## Install

```bash
composer require cresset-tools/magento2-bougie-licensing
bin/magento module:enable Bougie_Licensing
bin/magento setup:upgrade          # creates the bougie_license table + product attribute
bin/magento setup:di:compile       # production mode only
bin/magento cache:flush
```

## Configure

### 1. On the Bougie (sconce) side

Create a repo-scoped **service token** for the store to authenticate with:

```bash
sconce service-token create --repo <org>/<repo> --label magento
# prints the token once — copy it
```

Define your editions (SKUs) if you haven't; the module maps products to them by
name or slug:

```bash
sconce edition create --repo <org>/<repo> --name Pro --slug pro --set <set> --bound time:12
sconce edition list --repo <org>/<repo>
```

### 2. In the Magento admin

**Stores → Configuration → Bougie → Licensing → Connection**

| Field           | Value                                                     |
|-----------------|-----------------------------------------------------------|
| Enable          | Yes                                                       |
| API base URL    | your endpoint, e.g. `https://packages.example.com`        |
| Organization    | the `{org}` slug                                          |
| Repository      | the `{repo}` slug                                         |
| Service token   | the token from step 1 (stored encrypted)                 |

### 3. Map products to editions

Edit a product → **Bougie Licensing** group → set **Bougie edition (SKU)** to the
edition's name or slug (e.g. `pro`). Products left blank are not licensed. Any
product type works; a **Virtual** or **Downloadable** product is the usual fit
for a pure license.

## Buyer experience

After paying, the customer sees the key under **My Account → My Licenses**, with
ready-to-paste commands:

```
composer config repositories.bougie composer https://packages.example.com/<org>/<repo>
composer config --auth http-basic.packages.example.com token <license-key>
composer require <entitled/package>
```

(The license key is the http-basic **password**; the username is ignored — the
Magento Marketplace convention. `bound` shows "updates until …" for a
time-bounded edition.)

## Management API used

All under `/api/v1/repos/{org}/{repo}`, authenticated with the service token as
`Authorization: Bearer`:

| Call                                   | When                              |
|----------------------------------------|-----------------------------------|
| `POST license-keys`                    | order invoice paid (idempotent)   |
| `DELETE license-keys/{id}`             | credit memo (refund) for the item |
| `GET license-keys/{id}`                | (available for inspect/sync)      |
| `POST license-keys/{id}/renew`         | (available for subscription renew)|
| `GET editions`                         | (available for product mapping)   |

Renewal extension and admin sync-back (a dashboard-side revoke reflecting in the
store) are supported by the API and left as follow-ups; the `renewLicense` /
`inspectLicense` / `listEditions` client methods are already in place.

## Notes & limitations

- Provisioning triggers on **invoice payment**. If you use offline payments that
  don't create/pay an invoice, provision by invoicing the order.
- The license key is stored by this module so the buyer can retrieve it,
  **encrypted at rest** with the Magento crypt key (like any other stored
  credential). If the module's DB row is lost, re-invoicing the order recovers
  the key via idempotent replay **when the Bougie server has key recovery enabled**
  (it stores keys encrypted at rest under `SCONCE_SECRET_KEY`). Without that,
  sconce shows a key only once, so a lost row can't be recovered and "My Licenses"
  shows a "contact support to re-issue" note rather than a key.
- One license is issued per **order line item** that maps to an edition (product
  quantity is not multiplied — a license is not a consumable).
