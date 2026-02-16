<?php
/**
 * PaymentSetu WHMCS Webhook Callback Handler
 *
 * Receives payment notifications from PaymentSetu, verifies the HMAC SHA256
 * signature, validates the invoice, and applies the payment in WHMCS.
 *
 * @see https://developers.whmcs.com/payment-gateways/callbacks/
 */

// Require libraries
require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

// Module name
$gatewayModuleName = 'paymentsetu';

// Fetch gateway configuration
$gatewayParams = getGatewayVariables($gatewayModuleName);

// Die if module is not active
if (!$gatewayParams['type']) {
    die("Module Not Activated");
}

$apiKey = $gatewayParams['apiKey'];

// Read raw POST body
$payload = file_get_contents('php://input');

// Read security headers
$signature = isset($_SERVER['HTTP_X_PAYMENTSETU_SIGNATURE']) ? $_SERVER['HTTP_X_PAYMENTSETU_SIGNATURE'] : '';
$timestamp = isset($_SERVER['HTTP_X_PAYMENTSETU_TIMESTAMP']) ? $_SERVER['HTTP_X_PAYMENTSETU_TIMESTAMP'] : '';

// Verify HMAC SHA256 signature
if (!empty($signature) && !empty($timestamp)) {
    $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $payload, $apiKey);

    if (!hash_equals($expectedSignature, $signature)) {
        logTransaction($gatewayModuleName, array(
            'payload' => $payload,
            'signature' => $signature,
            'expected' => $expectedSignature,
        ), 'Invalid Signature');

        http_response_code(401);
        die('Invalid signature');
    }
}

// Parse webhook payload
$data = json_decode($payload, true);

if (!$data) {
    logTransaction($gatewayModuleName, $payload, 'Invalid Payload');
    http_response_code(400);
    die('Invalid payload');
}

// Extract fields from webhook
$orderId = isset($data['order_id']) ? $data['order_id'] : '';
$amount = isset($data['amount']) ? $data['amount'] : 0;
$status = isset($data['status']) ? $data['status'] : '';
$transactionUtr = isset($data['transaction_utr']) ? $data['transaction_utr'] : '';
$transactionTime = isset($data['transaction_time']) ? $data['transaction_time'] : '';

// Log the raw webhook data
logTransaction($gatewayModuleName, $data, 'Webhook Received - Status: ' . $status);

// Only process successful payments
if ($status !== 'success') {
    logTransaction($gatewayModuleName, $data, 'Payment Not Successful: ' . $status);
    http_response_code(200);
    die('OK - Status: ' . $status);
}

// Extract WHMCS invoice ID from order_id (strip "INV-" prefix)
if (strpos($orderId, 'INV-') === 0) {
    $invoiceId = (int) substr($orderId, 4);
} else {
    $invoiceId = (int) $orderId;
}

// Convert amount from paisa to currency (xxx.xx)
$paymentAmount = $amount / 100;

// Validate the invoice ID
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayModuleName);

// Check for duplicate transaction
checkCbTransID($transactionUtr);

// Log the successful transaction
logTransaction($gatewayModuleName, $data, 'Success');

// Apply payment to the invoice
addInvoicePayment(
    $invoiceId,
    $transactionUtr,
    $paymentAmount,
    0, // No fee
    $gatewayModuleName
);

http_response_code(200);
echo 'OK';
