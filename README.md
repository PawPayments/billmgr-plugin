# PawPayments for BillManager

Accept cryptocurrency payments and enable crypto top-ups in BillManager 6 via
PawPayments. Customers are redirected to the PawPayments paywall to choose an
asset and network; BillManager payments are marked as paid automatically once
the on-chain payment confirms.

---

## Features

- **Checkout** — standard BillManager payment flow. The customer clicks *Pay*
  and is redirected to the PawPayments paywall.
- **Top-up** — the standard BillManager flow: the client opens *Payments →
  Add funds* and picks the PawPayments method. It is a regular payment
  (checkout), so BillManager keeps the account currency and converts it.
  The separate top-up page of 2.0.3 and older is removed (the installer
  deletes it); its webhook receiver stays only for invoices it already issued.
- **Signature verification** — `X-Paw-Signature` (HMAC-SHA256 of the raw body)
  is checked against the API key of the payment's own PawPayments method (each
  method may have its own key), before the payload is trusted; invalid requests
  are refused, and while that key is empty the webhook answers HTTP 503.
- **Amount check** — a `success` webhook marks the payment paid only when it
  is in the payment's currency and covers its amount (within the invoice's
  underpay tolerance); otherwise HTTP 409 and an error in the log.
- **Idempotent** — checkout checks payment status before calling
  `payment.setpaid`; the legacy top-up receiver checks `externalid`.
- Webhooks with `permanent_address_id` are acknowledged (200 OK) with no action.
- Currency / network selection happens on the PawPayments paywall — the
  plugin does not need to know about supported assets.

---

## Requirements

| Component   | Minimum version |
| ----------- | --------------- |
| BillManager | 6.x (tested on 5.437+) |
| PHP         | 7.4 (BillManager bundles PHP 7.4 by default; PHP 8.x also works) |
| Shell access | `root` on the BillManager host (the install script writes into `/usr/local/mgr5/`) |
| HTTPS       | Required — PawPayments only delivers webhooks over TLS |

You must already have:

- A working BillManager 6 installation reachable over HTTPS (typically through
  the bundled `ihttpd` on port `1500`, optionally fronted by Nginx).
- `mgrctl` available in `/usr/local/mgr5/sbin/`.
- A PawPayments merchant account with an API key.

---

## 1. Plugin layout

After installation the files end up at these locations under
`/usr/local/mgr5/`:

```
etc/xml/billmgr_mod_pmpawpayments.xml          ← plugin manifest + form
paymethods/pmpawpayments                       ← config CLI handler (executable)
cgi/pawpaymentspayment.php                     ← checkout: create invoice + redirect
cgi/pawpaymentsresult.php                      ← checkout webhook receiver
cgi/pawpaymentstopup_result.php                ← legacy top-up webhook receiver
include/php/pawpayments_util.php               ← helpers (LocalQuery, logging)
include/php/pawpayments_rules.php              ← webhook rules (no side effects)
include/php/vendor/pawpayments/sdk/...         ← vendored PHP SDK
skins/...                                      ← icon assets (optional)
```

---

## 2. Install the plugin

### Run the bundled installer

1. Copy the plugin folder to the server, for example `/root/pawpayments-billmgr/`.
2. Make sure the marker file exists (the installer refuses to run otherwise):

   ```bash
   touch /root/pawpayments-billmgr/.pawpayments
   ```

3. Execute the installer as `root`:

   ```bash
   cd /root/pawpayments-billmgr
   chmod +x install.sh
   ./install.sh
   ```

   The script copies `etc/`, `paymethods/`, `cgi/`, `include/` and `skins/`
   into `/usr/local/mgr5/`, sets executable bits on the CLI/CGI scripts, and
   restarts the BillManager `core` worker.

### Install manually

```bash
cp -r etc       /usr/local/mgr5/
cp -r paymethods /usr/local/mgr5/
cp -r cgi        /usr/local/mgr5/
cp -r include    /usr/local/mgr5/
cp -r skins      /usr/local/mgr5/
chmod 755 /usr/local/mgr5/paymethods/pmpawpayments
chmod 755 /usr/local/mgr5/cgi/pawpayments*.php
killall core              # gracefully reloads the BillManager core
```

After install, BillManager re-reads the XML manifest. The new payment method
shows up as **PawPayments** under **Settings → Payment methods**.

---

## 3. Configure the payment method

### Option A — UI

1. Log in to BillManager admin.
2. Go to **Settings → Payment methods → Add**.
3. **Module**: select **PawPayments**.
4. Fill the wizard:

   | Field                | Description                                                      |
   | -------------------- | ---------------------------------------------------------------- |
   | **Name**             | Visible name (e.g. *PawPayments Crypto*).                        |
   | **Currency**         | The fiat currency you bill in (e.g. USD).                        |
   | **Project**          | The BillManager project this method is available in.             |
   | **API Key**          | Your PawPayments API key from the merchant dashboard.            |
   | **API Base URL**     | Leave empty or set to `https://api.pawpayments.com`.              |
   | **Invoice TTL (s)**  | Lifetime of generated invoices. Default `3600`.                  |
   | **Top-up currency**  | Only for top-up invoices issued by the removed top-up page (2.0.3 and older): ISO code (e.g. `USD`) of the currency client balances are kept in. |

5. Activate the method.

### Option B — Direct DB seed (for headless / scripted deployments)

If you have a fresh BillManager and want to bypass the wizard, you can insert
the row directly. **Use with caution** — the wizard normally validates many
fields. Adjust `currency`, `id`, project links and group links to fit your
environment.

```bash
mysql billmgr <<'SQL'
SET @next_id := (SELECT COALESCE(MAX(id), 0) + 1 FROM paymethod);
SET @xmlp := '<doc><SECRET_KEY>YOUR_API_KEY</SECRET_KEY><API_BASE_URL>https://api.pawpayments.com</API_BASE_URL><DEFAULT_TTL>3600</DEFAULT_TTL><TOPUP_CURRENCY>USD</TOPUP_CURRENCY></doc>';
INSERT INTO paymethod (id, orderpriority, name, active, currency, module, xmlparams, recurring, allowrefund, profiletype)
VALUES (@next_id, 10, 'PawPayments Crypto', 'on',
        (SELECT id FROM currency WHERE iso = 'USD' LIMIT 1),
        'pmpawpayments', @xmlp, 'off', 'off', '');
SQL
```

You will still need to attach the new `paymethod` to your project (table
`paymethod2project`) and to a group (`paymethod2group`) for it to appear in
the customer area.

---

## 4. Webhook URLs

Configure these in your PawPayments merchant dashboard (or rely on the
`notify_url` automatically embedded by the plugin in each invoice):

| Purpose   | URL                                                            |
| --------- | -------------------------------------------------------------- |
| Checkout  | `https://<your-server>/mancgi/pawpaymentsresult.php`           |
| Top-up (legacy, invoices issued by 2.0.3 and older) | `https://<your-server>/mancgi/pawpaymentstopup_result.php` |

Both endpoints must be reachable over HTTPS. If you front BillManager with
Nginx, make sure the `/mancgi/` path is proxied to BillManager's `ihttpd`:

```nginx
location / {
    proxy_pass https://<server-ip>:1500;
    proxy_ssl_verify off;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

---

## 5. Test the integration

### Smoke test the webhook endpoint

```bash
curl -X POST "https://<your-server>/mancgi/pawpaymentsresult.php" \
     -H "Content-Type: application/json" \
     -d '{}' \
     -w "\nHTTP %{http_code}\n"
```

Expected: HTTP 400 `Not a PawPayments webhook` — confirms the script loads (a
request without a well-formed `X-Paw-Signature` is refused before BillManager
is queried). The signed check below exercises the key.

### End-to-end (UI)

1. In BillManager admin, create a project, a customer account, and a payment
   profile (BillManager will not let you create payments without these).
2. Log in as the customer and choose **Top up balance**.
3. Pick **PawPayments Crypto** and enter an amount.
4. You are redirected to a `https://paw.now/invoice#…` paywall.
5. Pay a small amount with any supported cryptocurrency.
6. After the on-chain confirmation, the webhook calls `payment.setpaid` and
   the BillManager payment is marked as **Paid**, with `externalid` set to the
   PawPayments `order_id`.

### Sanity check via curl (simulating a webhook with valid signature)

```bash
KEY="<your_api_key>"
ORDER_ID="<paw_order_id>"
ELID="<billmgr_payment_id>"
# fiat_amount / fiat_currency must match the payment's amount and currency
BODY="{\"order_id\":\"$ORDER_ID\",\"extra\":\"$ELID\",\"status\":\"success\",\"fiat_amount\":\"25\",\"fiat_currency\":\"USD\",\"asset\":\"USDT\"}"
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$KEY" | awk '{print $2}')
curl -X POST "https://<your-server>/mancgi/pawpaymentsresult.php" \
     -H "Content-Type: application/json" \
     -H "X-Paw-Signature: $SIG" \
     -d "$BODY"
```

Expected: `OK` with HTTP 200 (HTTP 503 `Gateway not configured` means the
payment's PawPayments method has no API key).

---

## 6. Troubleshooting

| Symptom | Cause / Fix |
| ------- | ----------- |
| **PawPayments** does not appear in **Add payment method** | XML manifest not loaded. Run `killall core` (BillManager auto-restarts the worker) or `service ihttpd restart`. Re-check that `/usr/local/mgr5/etc/xml/billmgr_mod_pmpawpayments.xml` exists. |
| `mgrctl paymethod.add ... ERROR missed(currency)` | The `mgrctl` wizard for payment methods is stateful — pass parameters in the right step or use the UI / direct DB seed in section 3 (Option B). |
| Webhook returns `Payment not found` | The BillManager payment row referenced by `extra` does not exist, or it has no project/profile (`payment.info` then errors with `missed(project)`). Ensure projects, profiles and accounts are properly configured. |
| Webhook returns HTTP 503 `Gateway not configured` | The **API Key** of the PawPayments payment method is empty. Fill it in. |
| Legacy top-up webhook returns 503 `Top-up currency not configured` | A top-up invoice issued by the removed page was paid. Set **Top-up currency** on the payment method to the ISO code your client balances use; the webhook is retried. A deposit in another currency gets 409 and an error in the log: credit it by hand. |
| Webhook returns 409 `Amount or currency does not match the payment` | The paid invoice is in another currency or for less than the BillManager payment. The payment is not marked paid; check the log line and credit by hand if appropriate. |
| Webhook returns `Invalid signature` | The API key configured for the payment method does not match the key used to issue the invoice. Update **Settings → Payment methods → PawPayments → API Key** and re-issue. |
| Legacy top-up not crediting funds | The receiver creates a BillManager **payment** with status *paid*, which BillManager itself converts into account credit on the next billing cycle event. Inspect logs with `tail -f /usr/local/mgr5/var/billmgr.log`. |
| Nginx returns `502 Bad Gateway` for `/mancgi/...` | `ihttpd` is not listening on the address Nginx is proxying to. Confirm `ss -tlnp \| grep 1500` and update `proxy_pass` accordingly (often the public IP, not `127.0.0.1`). |

Plugin debug messages are written to BillManager's main log:

```bash
tail -f /usr/local/mgr5/var/billmgr.log | grep -i pawpayments
```

---

## 7. Uninstall

```bash
rm -f /usr/local/mgr5/etc/xml/billmgr_mod_pmpawpayments.xml
rm -f /usr/local/mgr5/paymethods/pmpawpayments
rm -f /usr/local/mgr5/cgi/pawpayments*.php
rm -rf /usr/local/mgr5/include/php/vendor/pawpayments
rm -f  /usr/local/mgr5/include/php/pawpayments_util.php
rm -rf /usr/local/mgr5/skins/orion/images/pawpayments  # if present

mysql billmgr -e "DELETE FROM paymethod WHERE module='pmpawpayments';"

killall core
```

This removes the module without touching existing payment history (BillManager
keeps `payment` rows even after the method is gone).

---

## Tests

`tests/` (not copied by `install.sh`) holds a PHPUnit suite for
`include/php/pawpayments_rules.php`, where the money rules live apart from
`pawpayments_util.php` (which opens the module log and shells out to `mgrctl`
the moment it is loaded). It covers which payment a checkout webhook is about
and which method's key verifies it, when `payment.setpaid` is called
(payable status, currency and amount check), and when the legacy top-up
webhook calls `payment.add` (verifying method, top-up currency, fiat amount
only, repeated deliveries).

```bash
cd tests
phpunit -c phpunit.xml.dist        # PHPUnit 9; runs on PHP 7.4 and 8.x
```
