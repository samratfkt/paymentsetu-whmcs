<?php
/**
 * PaymentSetu WHMCS Payment Gateway Module
 *
 * Third Party Payment Gateway module for PaymentSetu UPI payments.
 * Customers are redirected to PaymentSetu's payment page to complete
 * payment via UPI QR, and WHMCS is notified via webhook callback.
 *
 * @see https://developers.whmcs.com/payment-gateways/third-party-gateway/
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * Module metadata.
 *
 * @return array
 */
function paymentsetu_MetaData()
{
    return array(
        'DisplayName' => 'PaymentSetu',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * Gateway configuration options.
 *
 * Defines the fields shown in WHMCS Admin > Setup > Payment Gateways
 * when configuring this module.
 *
 * @return array
 */
function paymentsetu_config()
{
    $systemUrl = \WHMCS\Config\Setting::getValue('SystemURL');
    $webhookUrl = rtrim($systemUrl, '/') . '/modules/gateways/callback/paymentsetu.php';

    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'PaymentSetu',
        ),
        'webhookUrl' => array(
            'FriendlyName' => 'Webhook URL',
            'Type' => '',
            'Description' => '<code style="display:inline-block;padding:6px 12px;background:#f5f5f5;border:1px solid #ddd;border-radius:4px;font-size:13px;user-select:all;">'
                . htmlspecialchars($webhookUrl)
                . '</code><br>Copy this URL and paste it in your PaymentSetu dashboard webhook settings.',
        ),
        'apiKey' => array(
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Enter your PaymentSetu API Key from the API Credentials page.',
        ),
        'blockNonINR' => array(
            'FriendlyName' => 'Block Non-INR Transactions',
            'Type' => 'yesno',
            'Description' => 'Tick to block all transactions that are not in INR currency.',
        ),
    );
}

/**
 * Payment link.
 *
 * Called when an invoice is viewed. Creates an order via the PaymentSetu API
 * and returns an HTML button that redirects the customer to the payment page.
 *
 * @param array $params Payment Gateway Module Parameters
 *
 * @return string HTML output for the invoice page
 */
function paymentsetu_link($params)
{
    // Gateway configuration
    $apiKey = $params['apiKey'];
    $blockNonINR = $params['blockNonINR'];

    // Invoice parameters
    $invoiceId = $params['invoiceid'];
    $amount = $params['amount']; // Format: xxx.xx
    $currencyCode = $params['currency'];

    // Block non-INR transactions if the toggle is enabled
    if ($blockNonINR === 'on' && strtoupper($currencyCode) !== 'INR') {
        return '<div class="alert alert-danger" style="padding:15px;margin:10px 0;border-radius:5px;">'
            . '<strong>Transaction Blocked:</strong> This invoice is in <strong>' . htmlspecialchars($currencyCode) . '</strong>. '
            . 'PaymentSetu only supports INR transactions. Please contact support to change the currency.'
            . '</div>';
    }

    // Client parameters
    $firstName = $params['clientdetails']['firstname'];
    $lastName = $params['clientdetails']['lastname'];
    $email = $params['clientdetails']['email'];
    $phone = $params['clientdetails']['phonenumber'];

    // System parameters
    $systemUrl = $params['systemurl'];
    $returnUrl = $params['returnurl'];
    $langPayNow = $params['langpaynow'];

    // Convert amount from xxx.xx to paisa (integer)
    $amountInPaisa = (string) intval(round($amount * 100));

    // Sanitize phone number to 10 digits
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($cleanPhone) > 10) {
        $cleanPhone = substr($cleanPhone, -10);
    }

    // Build unique order ID
    $orderId = 'INV-' . $invoiceId;

    // Prepare API request payload
    $payload = array(
        'order_id' => $orderId,
        'amount' => $amountInPaisa,
        'customer_mobile' => '9999999999',
        'redirect_url' => $returnUrl,
    );

    // Add optional fields
    $customerName = trim($firstName . ' ' . $lastName);
    if (!empty($customerName)) {
        $payload['customer_name'] = $customerName;
    }
    if (!empty($email)) {
        $payload['customer_email'] = $email;
    }

    // Make API call to PaymentSetu
    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => 'https://paymentsetu.com/api/create_order',
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ),
        CURLOPT_TIMEOUT => 30,
    ));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log the API call for debugging
    logTransaction('paymentsetu', array(
        'request' => $payload,
        'response' => $response,
        'http_code' => $httpCode,
        'curl_error' => $curlError,
    ), 'Create Order');

    // Handle cURL errors
    if ($curlError) {
        return '<div class="alert alert-danger">Payment gateway connection error. Please try again later.</div>';
    }

    // Parse response
    $result = json_decode($response, true);

    if (!$result) {
        return '<div class="alert alert-danger">Invalid response from payment gateway. Please try again later.</div>';
    }

    // Handle API errors
    if (empty($result['status']) || $result['status'] !== true) {
        $errorMsg = isset($result['msg']) ? htmlspecialchars($result['msg']) : 'Unable to create payment link. Please try again later.';
        return '<div class="alert alert-danger">' . $errorMsg . '</div>';
    }

    // Success - return a button that redirects to the payment URL
    $paymentUrl = htmlspecialchars($result['payment_url']);

    $htmlOutput = '<a href="' . $paymentUrl . '" target="_blank" class="btn btn-primary btn-lg" '
        . 'style="display:inline-block;padding:10px 30px;font-size:16px;'
        . 'background-color:#4CAF50;color:#fff;border:none;border-radius:5px;'
        . 'text-decoration:none;cursor:pointer;">'
        . $langPayNow
        . '</a>';

    return $htmlOutput;
}
