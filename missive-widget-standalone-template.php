<?php
/**
 * Plugin Name: Tortelen Missive Widget (Standalone)
 * Description: Single-file Missive widget integration for WooCommerce - handles both UI and API in one file
 * Version: 1.0
 *
 * SETUP INSTRUCTIONS:
 * 1. Upload this file to /wp-content/plugins/
 * 2. (Optional) Change TORTELEN_WIDGET_ENDPOINT below to customize the URL
 * 3. Add TORTELEN_WIDGET_TOKEN to wp-config.php
 * 4. Activate the plugin in WordPress Admin
 * 5. Use URL: https://your-site.com/your-endpoint/?token=your-token
 */

// ============================================
// CONFIGURATION - Customize these settings
// ============================================

// Set the token in wp-config.php (required)
if (!defined('TORTELEN_WIDGET_TOKEN')) {
    define('TORTELEN_WIDGET_TOKEN', 'your-secret-token-here');
}

// Customize the endpoint URL (optional)
// Change 'missive-widget' to your preferred endpoint name
// Example: 'customer-widget', 'orders-widget', 'wc-customer', etc.
// After changing, deactivate and reactivate the plugin to apply changes
if (!defined('TORTELEN_WIDGET_ENDPOINT')) {
    define('TORTELEN_WIDGET_ENDPOINT', 'missive-widget');
}

// Register activation hook to add rewrite rules
register_activation_hook(__FILE__, 'tortelen_widget_activate');
function tortelen_widget_activate() {
    tortelen_widget_add_rewrite_rules();
    flush_rewrite_rules();
}

// Register deactivation hook to clean up
register_deactivation_hook(__FILE__, 'tortelen_widget_deactivate');
function tortelen_widget_deactivate() {
    flush_rewrite_rules();
}

// Add custom rewrite rule
add_action('init', 'tortelen_widget_add_rewrite_rules');
function tortelen_widget_add_rewrite_rules() {
    $endpoint = TORTELEN_WIDGET_ENDPOINT;
    add_rewrite_rule('^' . $endpoint . '/?$', 'index.php?tortelen_widget=1', 'top');
}

// Register query var
add_filter('query_vars', 'tortelen_widget_query_vars');
function tortelen_widget_query_vars($vars) {
    $vars[] = 'tortelen_widget';
    return $vars;
}

// Intercept requests to our endpoint
add_action('template_redirect', 'tortelen_widget_template_redirect');
function tortelen_widget_template_redirect() {
    if (get_query_var('tortelen_widget')) {
        // Handle AJAX requests
        if (isset($_GET['action']) || isset($_POST['action'])) {
            handle_ajax_request();
            exit;
        }

        // Validate token for page load
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';
        if ($token !== TORTELEN_WIDGET_TOKEN) {
            wp_die('Unauthorized: Invalid token', 'Unauthorized', ['response' => 401]);
        }

        // Output the widget HTML
        render_widget_page();
        exit;
    }
}

/**
 * Handle AJAX requests (search, cancel, refund)
 */
function handle_ajax_request() {
    // Validate token
    $token = isset($_REQUEST['token']) ? sanitize_text_field($_REQUEST['token']) : '';
    if ($token !== TORTELEN_WIDGET_TOKEN) {
        wp_send_json_error(['error' => 'Unauthorized: Invalid token'], 401);
    }

    $action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

    switch ($action) {
        case 'search-customer':
            search_customer_ajax();
            break;
        case 'cancel-order':
            cancel_order_ajax();
            break;
        case 'refund-order':
            refund_order_ajax();
            break;
        default:
            wp_send_json_error(['error' => 'Invalid action'], 400);
    }
}

/**
 * AJAX: Search customer by email or phone
 */
function search_customer_ajax() {
    $email = isset($_GET['email']) ? sanitize_email($_GET['email']) : '';
    $phone = isset($_GET['phone']) ? sanitize_text_field($_GET['phone']) : '';

    if (!$email && !$phone) {
        wp_send_json_error(['error' => 'Email or phone required'], 400);
    }

    $args = ['orderby' => 'date', 'order' => 'DESC'];

    if ($email) {
        $args['billing_email'] = strtolower(trim($email));
    } elseif ($phone) {
        $args['meta_query'] = [[
            'key' => '_billing_phone',
            'value' => preg_replace('/\D+/', '', $phone),
            'compare' => '='
        ]];
    }

    $count_result = wc_get_orders($args + ['paginate' => true, 'return' => 'ids', 'limit' => 1]);
    $total = isset($count_result->total) ? $count_result->total : 0;

    $orders = wc_get_orders($args + ['limit' => 3]);

    if (empty($orders)) {
        wp_send_json(['customer' => null, 'orders' => []]);
    }

    $first_order = $orders[0];
    $customer_data = [
        'id' => $first_order->get_customer_id(),
        'first_name' => $first_order->get_billing_first_name(),
        'last_name' => $first_order->get_billing_last_name(),
        'email' => $first_order->get_billing_email(),
        'phone' => $first_order->get_billing_phone(),
        'total_orders' => $total
    ];

    $orders_data = array_map(function($order) {
        $items = $order->get_items();
        $first_item = reset($items);
        $product_name = $first_item ? $first_item->get_name() : 'N/A';

        return [
            'id' => $order->get_id(),
            'date_created' => $order->get_date_created()->date('c'),
            'status' => $order->get_status(),
            'total' => $order->get_total(),
            'product_name' => $product_name
        ];
    }, $orders);

    wp_send_json([
        'customer' => $customer_data,
        'orders' => $orders_data
    ]);
}

/**
 * AJAX: Cancel order
 */
function cancel_order_ajax() {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $order_id = isset($data['order_id']) ? intval($data['order_id']) : 0;

    if (!$order_id) {
        wp_send_json_error(['error' => 'order_id required'], 400);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['error' => 'Order not found'], 404);
    }

    $order->update_status('cancelled', 'Order cancelled via Missive widget');
    restock_order_items($order_id);

    wp_send_json([
        'success' => true,
        'order' => [
            'id' => $order->get_id(),
            'status' => $order->get_status()
        ]
    ]);
}

/**
 * AJAX: Refund order
 */
function refund_order_ajax() {
    $json = file_get_contents('php://input');
    $data = json_decode($json, true);
    $order_id = isset($data['order_id']) ? intval($data['order_id']) : 0;

    if (!$order_id) {
        wp_send_json_error(['error' => 'order_id required'], 400);
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        wp_send_json_error(['error' => 'Order not found'], 404);
    }

    // Load gateways and process refund through payment provider
    WC()->payment_gateways();
    $gateway = wc()->payment_gateways()->payment_gateways()[$order->get_payment_method()] ?? null;

    if (!$gateway || !$gateway->supports('refunds')) {
        wp_send_json_error(['error' => 'This payment method does not support API refunds'], 400);
    }

    $result = $gateway->process_refund(
        $order_id,
        $order->get_total(),
        'Refund requested via Missive widget'
    );

    if (is_wp_error($result)) {
        wp_send_json_error(['error' => $result->get_error_message()], 500);
    }

    // Record refund in WooCommerce
    $refund = wc_create_refund([
        'order_id'   => $order_id,
        'amount'     => $order->get_total(),
        'reason'     => 'Refund processed via Missive widget',
        'api_refund' => false
    ]);

    if (is_wp_error($refund)) {
        wp_send_json_error(['error' => $refund->get_error_message()], 500);
    }

    restock_order_items($order_id);

    wp_send_json([
        'success' => true,
        'order' => [
            'id' => $order->get_id(),
            'status' => $order->get_status()
        ],
        'refund' => [
            'id' => $refund->get_id(),
            'amount' => $refund->get_amount()
        ]
    ]);
}

/**
 * Helper: Restock order items
 */
function restock_order_items($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) return false;

    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if ($product && $product->managing_stock()) {
            $qty = $item->get_quantity();
            wc_update_product_stock($product, $qty, 'increase');
        }
    }

    $order->add_order_note('Stock restored automatically via Missive widget');
    return true;
}

/**
 * Render the widget HTML page
 */
function render_widget_page() {
    // Construct the endpoint URL for API calls
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $endpoint = TORTELEN_WIDGET_ENDPOINT;
    $base_url = $protocol . "://" . $_SERVER['HTTP_HOST'] . "/" . $endpoint . "/";
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Tortelen - Missive Integration</title>

  <!-- Missive official stylesheet -->
  <link rel="stylesheet" href="https://integrations.missiveapp.com/missive.css">

  <style>
    body {
      margin: 0;
      padding: 16px;
      overflow-y: auto;
      min-height: 100vh;
      box-sizing: border-box;
    }

    html {
      overflow-y: auto;
    }

    .loading-container, .error-container, .no-data-container {
      text-align: center;
      padding: 48px 16px;
    }

    .spinner {
      border: 3px solid rgba(0, 0, 0, 0.1);
      border-top: 3px solid currentColor;
      border-radius: 50%;
      width: 40px;
      height: 40px;
      animation: spin 1s linear infinite;
      margin: 0 auto 16px;
    }

    @keyframes spin {
      0% { transform: rotate(0deg); }
      100% { transform: rotate(360deg); }
    }

    /* manual lookup styles */
    .search-wrapper {
      background: var(--background-secondary);
      border: 1px solid var(--border-primary);
      border-radius: 8px;
      padding: 12px 12px 8px;
      margin-bottom: 16px;
      position: relative;
      z-index: 100;
      width: 100%;
      box-sizing: border-box;
    }

    .search-label {
      font-size: 12px;
      font-weight: 600;
      color: var(--text-tertiary);
      margin-bottom: 6px;
      display: block;
    }

    .search-row {
      display: flex;
      gap: 8px;
      align-items: center;
    }

    .search-input {
      flex: 1;
      background: var(--background-primary);
      border: 1px solid var(--border-primary);
      border-radius: 6px;
      font-size: 13px;
      padding: 8px 10px;
      color: var(--text-primary);
      outline: none;
    }

    .search-input:focus {
      border-color: var(--accent-primary);
    }

    .search-btn {
      white-space: nowrap;
      padding: 8px 12px;
      border: 1px solid var(--border-primary);
      border-radius: 6px;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s;
      background: var(--background-primary);
      color: var(--text-primary);
    }

    .search-btn:hover {
      background: var(--background-tertiary);
    }

    .customer-card {
      background: var(--background-secondary);
      border-radius: 8px;
      padding: 16px;
      margin-bottom: 16px;
    }

    .customer-row {
      display: flex;
      justify-content: space-between;
      margin: 8px 0;
    }

    .order-card {
      background: var(--background-secondary);
      border-radius: 8px;
      padding: 12px;
      margin-bottom: 12px;
    }

    .order-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 8px;
    }

    .status-badge {
      padding: 4px 8px;
      border-radius: 4px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
    }

    .status-processing { background: #fef3c7; color: #92400e; }
    .status-completed { background: #d1fae5; color: #065f46; }
    .status-refunded { background: #fee2e2; color: #991b1b; }
    .status-cancelled { background: #e5e7eb; color: #374151; }
    .status-pending { background: #dbeafe; color: #1e40af; }

    .order-actions {
      display: flex;
      gap: 8px;
      margin-top: 12px;
    }

    .btn {
      flex: 1;
      padding: 8px 12px;
      border: 1px solid var(--border-primary);
      border-radius: 6px;
      font-size: 13px;
      font-weight: 500;
      cursor: pointer;
      transition: all 0.2s;
      background: var(--background-primary);
      color: var(--text-primary);
    }

    .btn:hover:not(:disabled) {
      background: var(--background-tertiary);
    }

    .btn:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .btn-danger {
      background: #ef4444;
      color: white;
      border-color: #dc2626;
    }

    .btn-danger:hover:not(:disabled) {
      background: #dc2626;
    }

    .btn-link {
      background: #3b82f6;
      color: white;
      border-color: #2563eb;
    }

    .btn-link:hover:not(:disabled) {
      background: #2563eb;
    }

    .mt-24 { margin-top: 24px; }
    .mb-12 { margin-bottom: 12px; }

    .footer {
      margin-top: 32px;
      padding-top: 16px;
      border-top: 1px solid var(--border-primary);
      text-align: center;
      font-size: 11px;
      color: var(--text-tertiary);
      opacity: 0.6;
    }
  </style>
</head>
<body>
  <!-- Fixed search bar - always visible -->
  <div class="search-wrapper">
    <label class="search-label text-small text-c">Zoek handmatig op email</label>

    <form id="manualLookupForm" class="search-row">
      <input
        id="manualLookupInput"
        type="email"
        class="search-input"
        placeholder="naam@example.com"
        autocomplete="off"
      />
      <button class="search-btn" type="submit">Zoek</button>
    </form>

    <div style="margin-top:6px;">
      <span class="text-tiny text-c">
        Dit overschrijft tijdelijk de gevonden duif in dit venster.
      </span>
    </div>
  </div>

  <!-- Content area - dynamically updated -->
  <div id="app">
    <div class="loading-container">
      <div class="spinner"></div>
      <p class="text-normal text-b">Waiting for conversation selection...</p>
    </div>
  </div>

  <script src="https://integrations.missiveapp.com/missive.js"></script>
  <script>
    // Configuration
    const API_BASE = '<?php echo esc_js($base_url); ?>';
    const SECRET_TOKEN = '<?php echo esc_js($_GET['token']); ?>';
    const STORE_DOMAIN = '<?php echo esc_js($_SERVER['HTTP_HOST']); ?>';

    // State
    let currentConversationId = null;

    // Helper: check if an email is from our own domain (not a customer)
    function isInternalEmail(email) {
      if (!email) return false;
      const lower = email.toLowerCase();
      return lower.endsWith('@tortelen.nl'); // Filter out internal staff emails
    }

    // Helper: get most recent external sender email from the conversation
    function getLastExternalSenderEmail(conversation) {
      if (!conversation.messages || conversation.messages.length === 0) {
        return null;
      }

      // Missive usually returns oldest-first, so reverse to check newest-first
      const reversed = [...conversation.messages].reverse();

      for (const msg of reversed) {
        if (msg.from_field && msg.from_field.address) {
          const candidate = msg.from_field.address;
          if (!isInternalEmail(candidate)) {
            return candidate;
          }
        }
      }

      return null;
    }

    // Initialize Missive integration - wait for SDK to load
    function initMissiveIntegration() {
      if (typeof Missive === 'undefined') {
        // SDK not ready yet, wait and retry
        setTimeout(initMissiveIntegration, 50);
        return;
      }

      Missive.on('change:conversations', async (conversations) => {
        console.log('Conversation changed:', conversations);

        // Handle single conversation selection
        if (conversations.length === 1) {
          const conversationId = conversations[0];

          // Avoid re-fetching same conversation
          if (conversationId === currentConversationId) return;
          currentConversationId = conversationId;

          await loadCustomerData(conversationId);
        } else if (conversations.length === 0) {
          showMessage('Waar zijn die orders dan duifie?', 'Klik een ticket aan.');
        } else {
          showMessage('Multiple conversations selected', 'Please select only one conversation');
        }
      });
    }

    // Start initialization
    initMissiveIntegration();

    // Fetch conversation details and extract email/phone
    async function loadCustomerData(conversationId) {
      showLoading();

      try {
        // Fetch conversation details from Missive API
        const conversations = await Missive.fetchConversations([conversationId]);

        if (!conversations || conversations.length === 0) {
          showError('Could not fetch conversation details');
          return;
        }

        const conversation = conversations[0];
        console.log('Full conversation object:', JSON.stringify(conversation, null, 2));

        // Try multiple ways to extract email
        let email = null;
        let phone = null;

        // Method 1: last external sender in the message list (not @tortelen.nl)
        email = getLastExternalSenderEmail(conversation);

        // Method 2: From contacts array (AddressField / phone)
        const contacts = conversation.contacts || [];
        for (const contact of contacts) {
          // only take contact.address if we don't have an email yet and it's not internal
          if (!email && contact.address && !isInternalEmail(contact.address)) {
            email = contact.address;
          }
          if (!phone && contact.phone_numbers && contact.phone_numbers.length > 0) {
            phone = contact.phone_numbers[0];
          }
        }

        // Method 3: From first message as last resort (only if not internal)
        if (!email && conversation.messages && conversation.messages.length > 0) {
          const firstMessage = conversation.messages[0];
          if (
            firstMessage.from_field &&
            firstMessage.from_field.address &&
            !isInternalEmail(firstMessage.from_field.address)
          ) {
            email = firstMessage.from_field.address;
          }
        }

        console.log('Extracted:', { email, phone });

        if (!email && !phone) {
          showMessage('No contact info', 'No email or phone number found in this conversation');
          return;
        }

        // Search for customer in WooCommerce
        await searchCustomer(email, phone);

      } catch (error) {
        console.error('Error loading customer data:', error);
        showError(`Failed to load data: ${error.message}`);
      }
    }

    // Search for customer via backend proxy
    // Exposed globally for manual search form
    window.searchCustomer = async function searchCustomer(email, phone) {
      try {
        const params = new URLSearchParams({
          action: 'search-customer',
          token: SECRET_TOKEN
        });
        if (email) params.append('email', email);
        if (phone) params.append('phone', phone);

        const response = await fetch(`${API_BASE}?${params}`);

        if (!response.ok) {
          throw new Error(`API error: ${response.status}`);
        }

        const data = await response.json();

        if (data.error) {
          showError(data.error);
          return;
        }

        if (!data.customer) {
          showMessage('Dit is misschien een gierig duifje', 'kan geen bestellingen vinden');
          return;
        }

        // Render customer data
        renderCustomerData(data);

      } catch (error) {
        console.error('Error searching customer:', error);
        showError(`Search failed: ${error.message}`);
      }
    }

    // Render customer information and orders
    function renderCustomerData(data) {
      const { customer, orders } = data;

      const html = `
        <div class="customer-card">
          <h2 class="text-large text-700 text-a mb-12">${customer.first_name} ${customer.last_name}</h2>

          <div class="customer-row">
            <span class="text-small text-c">Email</span>
            <span class="text-small text-a text-600">${customer.email || 'N/A'}</span>
          </div>

          <div class="customer-row">
            <span class="text-small text-c">Phone</span>
            <span class="text-small text-a text-600">${customer.phone || 'N/A'}</span>
          </div>

          <div class="customer-row">
            <span class="text-small text-c">Totale Tortels</span>
            <span class="text-small text-a text-600">${customer.total_orders}</span>
          </div>
        </div>

        <div class="mt-24">
          <h3 class="text-normal text-700 text-a mb-12">Recente Tortels</h3>
          ${orders && orders.length > 0 ? orders.map(order => renderOrder(order)).join('') : '<p class="text-small text-c">Dit misschien een gierig duifje, kan geen bestellingen vinden</p>'}
        </div>

        ${getFooter()}
      `;

      document.getElementById('app').innerHTML = html;

      // Attach event listeners
      attachOrderActions();
    }

    // Render single order card
    function renderOrder(order) {
      const statusClass = `status-${order.status.toLowerCase().replace(' ', '-')}`;
      const date = new Date(order.date_created).toLocaleDateString();

      // Determine which actions to show
      const canCancel = order.status !== 'cancelled' && order.status !== 'refunded';
      const canRefund = order.status === 'completed' || order.status === 'processing';

      return `
        <div class="order-card" data-order-id="${order.id}">
          <div class="order-header">
            <span class="text-normal text-700 text-a">#${order.id}</span>
            <span class="status-badge ${statusClass}">${order.status}</span>
          </div>

          <div class="text-small text-b" style="margin: 4px 0;">
            ${date} • ${order.product_name}
          </div>

          <div class="text-normal text-700 text-a" style="margin: 8px 0;">
            €${order.total}
          </div>

          <div class="order-actions">
            ${canCancel ? `<button class="btn btn-cancel" data-order-id="${order.id}">Cancel</button>` : ''}
            ${canRefund ? `<button class="btn btn-danger btn-refund" data-order-id="${order.id}">Refund</button>` : ''}
            <button class="btn btn-link btn-view" data-order-id="${order.id}">View in WC</button>
          </div>
        </div>
      `;
    }

    // Attach event listeners to order action buttons
    function attachOrderActions() {
      // Cancel buttons
      document.querySelectorAll('.btn-cancel').forEach(btn => {
        btn.addEventListener('click', async (e) => {
          const orderId = e.target.dataset.orderId;
          if (confirm(`Ga je deze duif zomaar cancelen?`)) {
            await cancelOrder(orderId);
          }
        });
      });

      // Refund buttons
      document.querySelectorAll('.btn-refund').forEach(btn => {
        btn.addEventListener('click', async (e) => {
          const orderId = e.target.dataset.orderId;
          if (confirm(`Hooold your duifjes! Zeker dat je terugbetaald?`)) {
            await refundOrder(orderId);
          }
        });
      });

      // View in WooCommerce buttons
      document.querySelectorAll('.btn-view').forEach(btn => {
        btn.addEventListener('click', (e) => {
          const orderId = e.target.dataset.orderId;
          const url = `https://${STORE_DOMAIN}/wp-admin/admin.php?page=wc-orders&action=edit&id=${orderId}`;
          Missive.openURL(url);
        });
      });
    }

    // Cancel order (change status to cancelled)
    async function cancelOrder(orderId) {
      try {
        const response = await fetch(`${API_BASE}?action=cancel-order`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ order_id: orderId, token: SECRET_TOKEN })
        });

        if (!response.ok) {
          throw new Error(`API error: ${response.status}`);
        }

        const data = await response.json();

        if (data.error) {
          alert(`Error: ${data.error}`);
          return;
        }

        alert('Rooekoee! Geannuleerd!');
        // Reload current conversation data
        if (currentConversationId) {
          await loadCustomerData(currentConversationId);
        }

      } catch (error) {
        console.error('Error cancelling order:', error);
        alert(`Failed to cancel order: ${error.message}`);
      }
    }

    // Refund order (process refund + change status to refunded)
    async function refundOrder(orderId) {
      try {
        const response = await fetch(`${API_BASE}?action=refund-order`, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json'
          },
          body: JSON.stringify({ order_id: orderId, token: SECRET_TOKEN })
        });

        if (!response.ok) {
          throw new Error(`API error: ${response.status}`);
        }

        const data = await response.json();

        if (data.error) {
          alert(`Error: ${data.error}`);
          return;
        }

        alert('Rooekoee! Terugbetaald!');
        // Reload current conversation data
        if (currentConversationId) {
          await loadCustomerData(currentConversationId);
        }

      } catch (error) {
        console.error('Error refunding order:', error);
        alert(`Failed to refund order: ${error.message}`);
      }
    }

    // UI Helper functions
    function getFooter() {
      return '<div class="footer">Gebouwd in 1 nacht door Appitarthelastcodebender</div>';
    }

    // Exposed globally for manual search form
    window.showLoading = function showLoading() {
      document.getElementById('app').innerHTML = `
        <div class="loading-container">
          <div class="spinner"></div>
          <p class="text-normal text-b">Loading customer data...</p>
        </div>
        ${getFooter()}
      `;
    }

    // Exposed globally for manual search form
    window.showError = function showError(message) {
      document.getElementById('app').innerHTML = `
        <div class="error-container">
          <p class="text-large text-a text-700">⚠️ Error</p>
          <p class="text-normal text-c">${message}</p>
        </div>
        ${getFooter()}
      `;
    }

    function showMessage(title, message) {
      document.getElementById('app').innerHTML = `
        <div class="no-data-container">
          <p class="text-large text-a text-700">${title}</p>
          <p class="text-normal text-c">${message}</p>
        </div>
        ${getFooter()}
      `;
    }

    // Attach manual search handler on page load
    window.addEventListener('DOMContentLoaded', () => {
      const form = document.getElementById('manualLookupForm');
      const input = document.getElementById('manualLookupInput');

      if (form && input) {
        form.addEventListener('submit', async (e) => {
          e.preventDefault();
          const manualEmail = input.value.trim();
          if (!manualEmail) return;

          // Show loading state
          if (window.showLoading) {
            showLoading();
          }

          // Store manual email for later refresh if needed
          window.lastManualEmail = manualEmail;

          // Call search function
          if (window.searchCustomer) {
            try {
              await searchCustomer(manualEmail, null);
            } catch (err) {
              console.error('Manual search failed:', err);
              if (window.showError) {
                showError('Search failed: ' + err.message);
              }
            }
          }
        });
      }
    });
  </script>
</body>
</html>
<?php
}
