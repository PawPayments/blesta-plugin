# PawPayments for Blesta

Accept cryptocurrency payments in [Blesta](https://www.blesta.com/) via
PawPayments. Customers are redirected to the hosted PawPayments paywall to
choose an asset and network; invoices are reconciled automatically once the
on-chain payment confirms and PawPayments delivers a signed webhook to Blesta's
gateway callback URL.

---

## Features

- **Checkout** — pay any Blesta invoice with cryptocurrency.
- **Hosted paywall** — currency / network selection happens on PawPayments, so
  the gateway never needs to know which assets are supported.
- **Signature verification** — every webhook is verified via `X-Paw-Signature`
  (HMAC-SHA256 of the raw body); unsigned or tampered requests are rejected with
  HTTP 401.
- **Idempotent** — the PawPayments `order_id` is used as the Blesta
  `transaction_id`, so webhook retries and the browser return reconcile to the
  same transaction instead of double-crediting.
- Webhooks carrying a `permanent_address_id` (not bound to a Blesta invoice) are
  silently acknowledged.

---

## Requirements

| Component | Minimum version |
| --------- | --------------- |
| Blesta    | 4.x / 5.x       |
| PHP       | 7.4 (8.1+ recommended) |
| PHP extensions | `curl`, `json`, `openssl` |

You must already have:

- A working Blesta installation reachable over **HTTPS** (PawPayments requires
  TLS and a publicly resolvable host for webhook delivery — private, loopback,
  and link-local hosts are refused).
- A PawPayments merchant account with an API key.

---

## Plugin contents

The plugin mirrors the Blesta install tree — a single non-merchant gateway:

```
components/
└── gateways/
    └── nonmerchant/
        └── pawpayments/
            ├── pawpayments.php         ← gateway class
            ├── config.json             ← gateway metadata + supported fiats
            ├── init.php                ← loads the vendored SDK
            ├── language/en_us/…        ← language strings
            ├── views/default/          ← settings + redirect templates
            ├── vendor/pawpayments/sdk/ ← vendored PHP SDK
            └── tests/                  ← PHPUnit tests
```

---

## Installation

1. Copy the `pawpayments` directory into your Blesta install at
   `components/gateways/nonmerchant/pawpayments/` (or upload the zip via the
   Blesta installer).
2. In the Blesta admin, go to **Settings → Payment Gateways → Available** and
   click **Install** next to *PawPayments (Crypto)*.
3. Open the gateway settings and enter:
   - **API Key** — your PawPayments merchant API key (stored encrypted).
   - **API Base URL** — leave as `https://api.pawpayments.com` unless told
     otherwise.
   - **Invoice TTL** — how long a payment invoice stays open, in seconds
     (300–86400, default 3600).
4. Under **Settings → Company → Currencies**, make sure the currencies you
   invoice in are enabled — the gateway supports the fiat currencies listed in
   `config.json`.

The webhook URL is shown on the settings page and is configured automatically
by PawPayments on each invoice (`notify_url`); no manual webhook setup is
required:

```
https://yourdomain.com/callback/gw/{company_id}/pawpayments/
```

---

## How it works

| Step | Method | What happens |
| ---- | ------ | ------------ |
| Client clicks **Pay** | `buildProcess()` | Creates a PawPayments invoice (`POST /api/v2/invoices`, `billing_type=VARY`) and redirects the browser to `payment_url`. The Blesta invoice ids + client id ride along in `metadata`; the client id is also set as `extra`. |
| Payment confirms | `validate()` | PawPayments POSTs a signed webhook to the callback URL. The raw body is verified against `X-Paw-Signature`, the status is mapped, and the transaction is applied to the originating invoices. |
| Client returns | `success()` | The browser lands on the Blesta return URL (`on_paid_url`) with the order id and invoice data appended, reconciling to the same `transaction_id` as the webhook. |

### Status mapping

| PawPayments status | Blesta status |
| ------------------ | ------------- |
| `success`, `paid_over` | `approved` |
| `confirming`, `partially_paid` | `pending` |
| `failed`, `cancelled`, `high_risk` | `declined` |
| anything else | ignored (no transaction recorded) |

---

## Tests

The bundled PHPUnit suite exercises the framework-independent logic (invoice
serialization, status mapping, the `success()` return parser, and the vendored
webhook signature check):

```bash
cd components/gateways/nonmerchant/pawpayments/tests
phpunit -c phpunit.xml.dist
```
