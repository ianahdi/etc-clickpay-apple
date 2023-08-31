<?php
/*
Plugin Name: etc.. - ClickPay Apple Pay Direct for WooCommerce
Description: Integrates Apple Pay Direct payment method with WooCommerce.
Version: 1.0
Author: etc... for Digital Solutions https://etcdots.com
*/

// Include necessary files from the GitHub repository
include_once('includes/env.php');
include_once('includes/applepay.php');
include_once('includes/_config.php');
include_once('includes/clickpay_core.php');
include_once('includes/applepay_payment.php');

// Add the settings page to the WordPress dashboard
add_action('admin_menu', 'apple_pay_direct_menu');

function apple_pay_direct_menu() {
    add_options_page('Apple Pay Direct Settings', 'Apple Pay Direct', 'manage_options', 'apple-pay-direct', 'apple_pay_direct_settings_page');
}

// Display the settings page content
function apple_pay_direct_settings_page() {
    // Check user permissions
    if (!current_user_can('manage_options')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    // Nonce for security
    $nonce = wp_create_nonce('apple_pay_action');

    ?>
    <div class="wrap">
        <h1>Apple Pay Direct Settings</h1>
        <form method="post" action="options.php" enctype="multipart/form-data">
            <?php
            settings_fields('apple-pay-direct-settings-group');
            do_settings_sections('apple-pay-direct-settings-group');
            ?>
            <input type="hidden" name="apple_pay_nonce" value="<?php echo $nonce; ?>">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Apple Merchant ID</th>
                    <td><input type="text" name="apple_merchant_id" value="<?php echo esc_attr(get_option('apple_merchant_id')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Apple SSL Cert File</th>
                    <td><input type="file" name="apple_ssl_cert_file" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Apple Cert Key File</th>
                    <td><input type="file" name="apple_cert_key_file" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">ClickPay API Key</th>
                    <td><input type="text" name="clickpay_api_key" value="<?php echo esc_attr(get_option('clickpay_api_key')); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Verified Domain URL</th>
                    <td><input type="text" name="verified_domain_url" value="<?php echo esc_attr(get_option('verified_domain_url')); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

// Register the settings
add_action('admin_init', 'apple_pay_direct_register_settings');

function apple_pay_direct_register_settings() {
    register_setting('apple-pay-direct-settings-group', 'apple_merchant_id');
    register_setting('apple-pay-direct-settings-group', 'clickpay_api_key');
    register_setting('apple-pay-direct-settings-group', 'verified_domain_url');

    // Verify nonce for security
    if (!wp_verify_nonce($_POST['apple_pay_nonce'], 'apple_pay_action')) {
        die('Invalid request.');
    }

    // Handle certificate and key file uploads
    if (isset($_FILES['apple_ssl_cert_file']) && $_FILES['apple_ssl_cert_file']['error'] == UPLOAD_ERR_OK) {
        $upload_overrides = array('test_form' => false);
        $movefile = wp_handle_upload($_FILES['apple_ssl_cert_file'], $upload_overrides);

        if ($movefile && !isset($movefile['error'])) {
            update_option('apple_ssl_cert_file_path', $movefile['file']);
        } else {
            echo $movefile['error'];
        }
    }

    if (isset($_FILES['apple_cert_key_file']) && $_FILES['apple_cert_key_file']['error'] == UPLOAD_ERR_OK) {
        $upload_overrides = array('test_form' => false);
        $movefile = wp_handle_upload($_FILES['apple_cert_key_file'], $upload_overrides);

        if ($movefile && !isset($movefile['error'])) {
            update_option('apple_cert_key_file_path', $movefile['file']);
        } else {
            echo $movefile['error'];
        }
    }
}

function display_apple_pay_button() {
    ?>
    <script src="https://applepaydemo.apple.com/jsapi/apple-pay-latest.js"></script>
    <script>
        if (window.ApplePaySession && ApplePaySession.canMakePayments()) {
            // Display the Apple Pay button
            document.write('<button id="apple-pay-button" class="apple-pay-button"></button>');
            
            document.getElementById("apple-pay-button").addEventListener('click', function() {
                // Fetch WooCommerce details and the verified domain URL
                var countryCode = '<?php echo get_option('woocommerce_default_country'); ?>';
                var currencyCode = '<?php echo get_woocommerce_currency(); ?>';
                var totalAmount = '<?php echo WC()->cart->total; ?>';
                var domainURL = '<?php echo get_option('verified_domain_url'); ?>';

                // Create a payment request
                var paymentRequest = {
                    countryCode: countryCode,
                    currencyCode: currencyCode,
                    total: {
                        label: 'Your Store Name',
                        amount: totalAmount
                    },
                    supportedNetworks: ['visa', 'masterCard', 'amex', 'discover', 'mada'],
                    merchantCapabilities: ['supports3DS'],
                    requiredShippingContactFields: ['postalAddress', 'email']
                };

                // Initiate an Apple Pay session
                var session = new ApplePaySession(1, paymentRequest);

                // Handle the validateMerchant event
                session.onvalidatemerchant = function(event) {
                    var promise = validateMerchant(event.validationURL);
                    promise.then(function(merchantSession) {
                        session.completeMerchantValidation(merchantSession);
                    });
                };

                // Handle the paymentauthorized event
                session.onpaymentauthorized = function(event) {
                    var promise = processPayment(event.payment);
                    promise.then(function(success) {
                        if (success) {
                            session.completePayment(ApplePaySession.STATUS_SUCCESS);
                        } else {
                            session.completePayment(ApplePaySession.STATUS_FAILURE);
                        }
                    });
                };

                // Start the Apple Pay session
                session.begin();
            });
        }
    </script>
    <?php
}

// Display on Product Page
add_action('woocommerce_after_add_to_cart_button', 'display_apple_pay_button');

// Display on Checkout Page
add_action('woocommerce_review_order_before_payment', 'display_apple_pay_button');

// Display on Cart Page
add_action('woocommerce_proceed_to_checkout', 'display_apple_pay_button');

// Handle AJAX request for merchant validation
add_action('wp_ajax_validate_merchant', 'apple_pay_direct_validate_merchant');
add_action('wp_ajax_nopriv_validate_merchant', 'apple_pay_direct_validate_merchant');

function apple_pay_direct_validate_merchant() {
    // Get the validation URL from the POST request
    $validationURL = $_POST['validationURL'];

    // Fetch the API Key from WordPress options
    $apiKey = get_option('clickpay_api_key');

    // Use the ApplePay class from the GitHub repository to validate the merchant
    $applePay = new ApplePay(get_option('apple_merchant_id'), get_option('apple_ssl_cert_file_path'), get_option('apple_cert_key_file_path'), $apiKey);
    $merchantSession = $applePay->getApplePaySession($validationURL, $domainURL);

    // Return the merchant session
    wp_send_json(array(
        'merchantSession' => $merchantSession
    ));
}

// Handle AJAX request for payment processing
add_action('wp_ajax_process_payment', 'apple_pay_direct_process_payment');
add_action('wp_ajax_nopriv_process_payment', 'apple_pay_direct_process_payment');

function apple_pay_direct_process_payment() {
    // Implement the backend code from the GitHub repository to process the payment
    // For now, this is a placeholder. You'll integrate the logic from applepay.php here.
    wp_send_json(array(
        'success' => $success
    ));
}

// Simple logging function
function apple_pay_direct_log($message) {
    if (WP_DEBUG === true) {
        if (is_array($message) || is_object($message)) {
            error_log(print_r($message, true));
        } else {
            error_log($message);
        }
    }
}
