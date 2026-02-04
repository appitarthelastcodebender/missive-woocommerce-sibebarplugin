# missive-woocommerce-sibebarplugin
Upload plugin to have a woocommerce app in missive to see and interact with orders related to email or phonenumber
# Missive WooCommerce Widget

Single-file WordPress plugin that displays customer orders in Missive sidebar.

## TL;DR

1. Download `missive-widget-standalone-template.php`
2. Edit line 21: Add your secret token and/or change the endpoint url
3. Compress to zip and upload plugin
5. Add to Missive: `https://your-site.com/missive-widget/?token=YOUR_TOKEN`

Done!

---

## What You Need

- WordPress 5.0+ with WooCommerce installed
- FTP/SFTP access or WordPress plugin upload access
- A Missive account (Team plan or higher for iframe integrations)

## 5-Minute Setup

### Step 1: Download Plugin

Download `missive-widget-standalone-template.php` from this repository.

### Step 2: Add Your Token

Open the file and find line 21:

```php
define('XXX_WIDGET_TOKEN', 'your-secret-token-here');
```

Replace `'your-secret-token-here'` with a secure random token.

**Generate a secure token:**
```bash
openssl rand -hex 32
```

Or use: https://www.random.org/strings/ (generate 64-character alphanumeric string)

**Example:**
```php
define('TORTELEN_WIDGET_TOKEN', 'abc123...your-64-char-token-here...xyz789');
```

### Step 3: (Optional) Customize Endpoint

If you want a custom URL endpoint, edit line 29:

```php
define('TORTELEN_WIDGET_ENDPOINT', 'missive-widget');
```

Change to your preference: `'customer-orders'`, `'wc-widget'`, etc.

This changes the URL from `/missive-widget/` to `/your-custom-name/`

**Skip this step to use the default `/missive-widget/` URL**

### Step 4: Upload Plugin

Upload the edited file to `/wp-content/plugins/` on your WordPress site.

**Methods:**
- FTP/SFTP client
- WordPress Admin → Plugins → Add New → Upload Plugin
- cPanel File Manager

**ZIP for regular Upload**
Just compress to ZIP and use the wordpress admin method

### Step 5: Activate Plugin

WordPress Admin → Plugins → Activate "Tortelen Missive Widget (Standalone)"

**Important:** Activation creates the WordPress endpoint. Don't skip this!

### Step 6: Get Your Widget URL

Your widget URL is:
```
https://your-domain.com/missive-widget/?token=YOUR_TOKEN
```

Replace:
- `your-domain.com` with your WordPress site URL
- `missive-widget` with your custom endpoint (if changed in step 3)
- `YOUR_TOKEN` with the token from step 2

### Step 7: Add to Missive

1. Open Missive
2. **Settings → Integrations → Custom Integrations**
3. Click **"+ Add Integration"**
4. Choose **"Iframe"** type
5. Name: `WooCommerce Orders` (or whatever you prefer)
6. URL: Paste your widget URL from step 6
7. Position: **Sidebar**
8. Save

## Done!

Select a conversation in Missive and your widget loads in the sidebar showing customer orders.

## Features

- **Automatic customer lookup** - Shows orders when you select a conversation
- **Manual search** - Search any customer by email
- **Recent orders** - View last 3 orders with status and totals
- **Quick actions** - Cancel or refund orders directly from sidebar
- **Direct links** - Jump to orders in WooCommerce admin

## For Multiple Sites/Clients

To deploy to additional sites:

1. Download a fresh copy of `missive-widget-standalone-template.php`
2. Generate a **new unique token** for each site
3. Edit the token in the file (line 21)
4. Upload and activate on that site
5. Configure separate Missive integration for each site

Each site gets its own token and widget URL.

## Troubleshooting

### 404 Error
- **Deactivate and reactivate** the plugin (flushes WordPress rewrite rules)
- Or: WordPress Admin → Settings → Permalinks → Click "Save Changes"

### 401 Unauthorized
- Check that the token in the plugin file (line 21) matches the token in your Missive URL
- Ensure you saved the file after editing the token

### Widget Shows "No Orders"
- Verify customer has orders in WooCommerce
- Check that conversation email matches customer billing email
- Try manual search with customer's email

### Widget Not Loading
- Verify plugin is activated in WordPress Admin
- Check that your WordPress site uses HTTPS (required for Missive iframes)
- Test the URL directly in your browser

## What It Does

When you select a conversation in Missive:

1. Widget extracts customer email from conversation
2. Searches WooCommerce for matching orders
3. Displays customer info + last 3 orders
4. Provides action buttons (Cancel, Refund, View in WC)

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- PHP 7.2 or higher
- HTTPS enabled (recommended for production)

## Support & Documentation

- **Detailed docs:** See [PHP-IMPLEMENTATION.md](PHP-IMPLEMENTATION.md)
- **Issues:** [GitHub Issues](https://github.com/appitarthelastcodebender/missive-woocommerce-sibebarplugin/issues)
- **Questions:** [GitHub Discussions](https://github.com/appitarthelastcodebender/missive-woocommerce-sibebarplugin/discussions)
