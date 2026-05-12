# PawPayments for BillManager

Accept cryptocurrency payments and enable crypto top-ups in BillManager 6 via
PawPayments. Customers are redirected to the PawPayments paywall to choose an
asset and network; BillManager payments are marked as paid automatically once
the on-chain payment confirms.

---

## Features

- **Checkout** — standard BillManager payment flow. The customer clicks *Pay*
  and is redirected to the PawPayments paywall.
- **Top-up** — separate CGI page where the client enters an amount and is
  redirected to PawPayments. On payment, funds are added to the account.
- **Signature verification** — `X-Paw-Signature` (HMAC-SHA256 of the raw body); requests without a valid header are rejected with HTTP 401.
- **Idempotent** — checkout checks payment status before calling
  `payment.setpaid`; top-up checks `externalid` for duplicates.
- Webhooks with `permanent_address_id` are silently acknowledged (200 OK).
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
cgi/pawpaymentstopup.php                       ← top-up CGI form
cgi/pawpaymentstopup_result.php                ← top-up webhook receiver
include/php/pawpayments_util.php               ← helpers (LocalQuery, logging)
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

5. Activate the method.

### Option B — Direct DB seed (for headless / scripted deployments)

If you have a fresh BillManager and want to bypass the wizard, you can insert
the row directly. **Use with caution** — the wizard normally validates many
fields. Adjust `currency`, `id`, project links and group links to fit your
environment.

```bash
mysql billmgr <<'SQL'
SET @next_id := (SELECT COALESCE(MAX(id), 0) + 1 FROM paymethod);
SET @xmlp := '<doc><SECRET_KEY>YOUR_API_KEY</SECRET_KEY><API_BASE_URL>https://api.pawpayments.com</API_BASE_URL><DEFAULT_TTL>3600</DEFAULT_TTL></doc>';
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
| Top-up    | `https://<your-server>/mancgi/pawpaymentstopup_result.php`     |

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

Expected: `Missing data` with HTTP 200 — confirms the script loads and parses
JSON.

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
BODY="{\"order_id\":\"$ORDER_ID\",\"extra\":\"$ELID\",\"status\":\"success\",\"fiat_amount\":\"25\",\"asset\":\"USDT\"}"
SIG=$(printf '%s' "$BODY" | openssl dgst -sha256 -hmac "$KEY" | awk '{print $2}')
curl -X POST "https://<your-server>/mancgi/pawpaymentsresult.php" \
     -H "Content-Type: application/json" \
     -H "X-Paw-Signature: $SIG" \
     -d "$BODY"
```

Expected: `OK` with HTTP 200.

---

## 6. Troubleshooting

| Symptom | Cause / Fix |
| ------- | ----------- |
| **PawPayments** does not appear in **Add payment method** | XML manifest not loaded. Run `killall core` (BillManager auto-restarts the worker) or `service ihttpd restart`. Re-check that `/usr/local/mgr5/etc/xml/billmgr_mod_pmpawpayments.xml` exists. |
| `mgrctl paymethod.add ... ERROR missed(currency)` | The `mgrctl` wizard for payment methods is stateful — pass parameters in the right step or use the UI / direct DB seed in section 3 (Option B). |
| Webhook returns `Payment not found` | The BillManager payment row referenced by `extra` does not exist, or it has no project/profile (`payment.info` then errors with `missed(project)`). Ensure projects, profiles and accounts are properly configured. |
| Webhook returns `Invalid signature` | The API key configured for the payment method does not match the key used to issue the invoice. Update **Settings → Payment methods → PawPayments → API Key** and re-issue. |
| Top-up not crediting funds | The CGI script creates a BillManager **payment** with status *paid*, which BillManager itself converts into account credit on the next billing cycle event. Inspect logs with `tail -f /usr/local/mgr5/var/billmgr.log`. |
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
