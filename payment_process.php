<?php
/**
 * Payment Process Handler
 *
 * This file handles:
 * 1. Creating payment records in the database
 * 2. Creating Stripe Checkout Sessions
 * 3. Redirecting to Stripe for payment
 */

require_once('roots.php');
require_once($root_path.'include/inc_db_makelink.php');
require_once('stripe_config.php');

// Include Stripe PHP library
require_once(__DIR__ . '/stripe-php/init.php');

// Set Stripe API key
\Stripe\Stripe::setApiKey(getStripeSecretKey());

// Get parameters from query string or POST
$patient_id = trim($_GET['patient_id'] ?? $_POST['patient_id'] ?? '');
$patient_name = trim($_GET['patient_name'] ?? $_POST['patient_name'] ?? '');
$patient_email = trim($_GET['patient_email'] ?? $_POST['patient_email'] ?? '');
$claim_id = trim($_GET['claim_id'] ?? $_POST['claim_id'] ?? '');
$encounter_id = trim($_GET['encounter_id'] ?? $_POST['encounter_id'] ?? '');
$amount = trim($_GET['amount'] ?? $_POST['amount'] ?? '');

// Validation
if (empty($patient_id) || empty($patient_name) || empty($patient_email) || empty($amount)) {
    die('Error: Missing required parameters. Please go back and try again.');
}

if (!is_numeric($amount) || floatval($amount) <= 0) {
    die('Error: Invalid amount. Please enter a valid amount greater than 0.');
}

if (!filter_var($patient_email, FILTER_VALIDATE_EMAIL)) {
    die('Error: Invalid email address.');
}

// Sanitize inputs - escape for SQL using addslashes (codebase pattern)
$patient_id_sql = addslashes($patient_id);
$patient_name_sql = addslashes($patient_name);
$patient_email_sql = addslashes($patient_email);
$claim_id_sql = $claim_id ? "'" . addslashes($claim_id) . "'" : 'NULL';
$encounter_id_sql = $encounter_id ? "'" . addslashes($encounter_id) . "'" : 'NULL';
$amount = floatval($amount);

// Generate unique payment reference
$payment_reference = 'PAY' . date('YmdHis') . rand(1000, 9999);

// Prepare payment description
$description = sprintf(STRIPE_PAYMENT_DESCRIPTION,
    $patient_id,
    $claim_id ?: 'N/A');

try {
    // ============================================
    // Step 1: Create Stripe Checkout Session
    // ============================================

    $session_params = [
        'payment_method_types' => ['card'],
        'line_items' => [[
            'price_data' => [
                'currency' => STRIPE_CURRENCY,
                'product_data' => [
                    'name' => 'Medical Services Payment',
                    'description' => $description,
                    'metadata' => [
                        'patient_id' => stripslashes($patient_id),
                        'claim_id' => stripslashes($claim_id) ?: '',
                        'encounter_id' => stripslashes($encounter_id) ?: ''
                    ]
                ],
                'unit_amount' => formatAmountForStripe($amount), // Amount in cents
            ],
            'quantity' => 1,
        ]],
        'mode' => 'payment',
        'success_url' => getBaseUrl() . STRIPE_SUCCESS_URL . '?session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => getBaseUrl() . STRIPE_CANCEL_URL,
        'customer_email' => stripslashes($patient_email),
        'metadata' => [
            'payment_reference' => $payment_reference,
            'patient_id' => stripslashes($patient_id),
            'patient_name' => stripslashes($patient_name),
            'patient_email' => stripslashes($patient_email),
            'claim_id' => stripslashes($claim_id) ?: '',
            'encounter_id' => stripslashes($encounter_id) ?: '',
            'amount' => $amount
        ],
        'payment_intent_data' => [
            'description' => $description,
            'metadata' => [
                'payment_reference' => $payment_reference,
                'patient_id' => stripslashes($patient_id),
                'claim_id' => stripslashes($claim_id) ?: '',
                'encounter_id' => stripslashes($encounter_id) ?: ''
            ]
        ]
    ];

    // Create the Checkout Session
    $checkout_session = \Stripe\Checkout\Session::create($session_params);

    // ============================================
    // Step 2: Save Payment Record to Database
    // ============================================

    $stripe_session_id = "'" . addslashes($checkout_session->id) . "'";
    $stripe_payment_intent_id = $checkout_session->payment_intent ? "'" . addslashes($checkout_session->payment_intent) . "'" : 'NULL';
    $description_db = "'" . addslashes($description) . "'";
    $metadata_json = "'" . addslashes(json_encode([
        'payment_reference' => $payment_reference,
        'stripe_session_id' => $checkout_session->id
    ])) . "'";

    $insert_query = "INSERT INTO stripe_payments (
        patient_id,
        patient_name,
        patient_email,
        claim_id,
        encounter_id,
        amount,
        currency,
        status,
        stripe_session_id,
        stripe_payment_intent_id,
        description,
        metadata,
        created_at
    ) VALUES (
        '$patient_id_sql',
        '$patient_name_sql',
        '$patient_email_sql',
        $claim_id_sql,
        $encounter_id_sql,
        '$amount',
        '" . STRIPE_CURRENCY . "',
        'pending',
        $stripe_session_id,
        $stripe_payment_intent_id,
        $description_db,
        $metadata_json,
        NOW()
    )";

    // Execute the insert and check for errors
    $result = $db->Execute($insert_query);

    if (!$result) {
        // Log the error
        $error_msg = 'Database insert failed: ' . $db->ErrorMsg();
        logStripeActivity('Database Error', [
            'error' => $error_msg,
            'query' => $insert_query
        ]);
        die('Error: Unable to save payment record. Please contact support.');
    }

    $payment_id = $db->Insert_ID();

    if (!$payment_id) {
        logStripeActivity('Insert ID Error', [
            'message' => 'No payment ID returned after insert',
            'query' => $insert_query
        ]);
        die('Error: Unable to get payment record ID. Please contact support.');
    }

    // Log to stripe_payment_logs table
    $log_message = "'Payment intent created and database record saved'";
    $log_type = "'intent_created'";
    $log_request_data = "'" . addslashes(json_encode([
        'stripe_session_id' => $checkout_session->id,
        'amount' => $amount
    ])) . "'";
    $log_response_data = "'" . addslashes(json_encode([
        'payment_reference' => $payment_reference,
        'stripe_payment_intent_id' => $checkout_session->payment_intent ?? ''
    ])) . "'";

    $log_query = "INSERT INTO stripe_payment_logs (payment_id, log_type, log_message, request_data, response_data, created_at)
                  VALUES ('$payment_id', $log_type, $log_message, $log_request_data, $log_response_data, NOW())";

    $db->Execute($log_query);

    // Log the payment initiation to file
    logStripeActivity('Payment initiated', [
        'payment_id' => $payment_id,
        'payment_reference' => $payment_reference,
        'stripe_session_id' => $checkout_session->id,
        'amount' => $amount,
        'currency' => STRIPE_CURRENCY
    ]);

    // ============================================
    // Step 3: Redirect to Stripe Checkout
    // ============================================

    header('Location: ' . $checkout_session->url);
    exit;

} catch (\Stripe\Exception\ApiErrorException $e) {
    // Stripe API error
    $error_message = 'Stripe Error: ' . $e->getMessage();
    logStripeActivity('Stripe API Error', [
        'error' => $e->getMessage(),
        'code' => $e->getStripeCode()
    ]);
    die($error_message);

} catch (Exception $e) {
    // General error
    $error_message = 'Error: ' . $e->getMessage();
    logStripeActivity('General Error', [
        'error' => $e->getMessage()
    ]);
    die($error_message);
}

/**
 * Get the base URL for the application
 */
function getBaseUrl() {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $domain = $_SERVER['HTTP_HOST'];

    // Get the base path from the current script
    // Current script is at: /clinical7_malik/modules/pms/zBackPre/stripe_integration/payment_process.php
    // We need to go back to: /clinical7_malik/
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']); // e.g., /clinical7_malik/modules/pms/zBackPre/stripe_integration

    // Go up 4 levels to reach clinical7_malik
    $basePath = dirname(dirname(dirname(dirname($scriptPath)))); // /clinical7_malik

    return $protocol . $domain . $basePath;
}
?>
