<?php
/**
 * Payment Failure Handler
 *
 * This file handles:
 * 1. When payment fails at Stripe (card declined, insufficient funds, etc.)
 * 2. Updating the payment record status to 'failed'
 * 3. Logging the failure reason
 * 4. Displaying appropriate error message to user with option to retry
 *
 * This file can be called from Stripe webhook or redirect on failure
 */

require_once('roots.php');
require_once($root_path.'include/inc_db_makelink.php');
require_once('stripe_config.php');

// Get parameters
$session_id = trim($_GET['session_id'] ?? '');
$payment_intent_id = trim($_GET['payment_intent_id'] ?? '');
$error_code = trim($_GET['error_code'] ?? '');
$error_message = trim($_GET['error_message'] ?? '');

$display_error = '';
$display_reason = '';

if ($session_id || $payment_intent_id) {
    try {
        // Include Stripe PHP library
        require_once(__DIR__ . '/stripe-php/init.php');

        // Set Stripe API key
        \Stripe\Stripe::setApiKey(getStripeSecretKey());

        $status = 'failed';
        $failure_reason = '';
        $payment_id = null;

        // If we have a session ID, get the payment intent from it
        if ($session_id) {
            $checkout_session = \Stripe\Checkout\Session::retrieve($session_id);

            if ($checkout_session && $checkout_session->payment_intent) {
                $payment_intent_id = $checkout_session->payment_intent;

                // Get the Payment Intent to see why it failed
                try {
                    $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);

                    if ($payment_intent->last_payment_error) {
                        $failure_reason = $payment_intent->last_payment_error->message;
                        $error_code = $payment_intent->last_payment_error->code ?? '';
                    } else {
                        $failure_reason = 'Payment failed - no specific error message from Stripe';
                    }

                    // Get user-friendly error message
                    $display_error = getFriendlyErrorMessage($error_code, $failure_reason);
                } catch (Exception $e) {
                    $failure_reason = 'Payment failed - unable to retrieve details: ' . $e->getMessage();
                    $display_error = 'Your payment could not be processed. Please try again.';
                }
            }
        } elseif ($payment_intent_id) {
            // Direct payment intent failure (from webhook)
            try {
                $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);

                if ($payment_intent->last_payment_error) {
                    $failure_reason = $payment_intent->last_payment_error->message;
                    $error_code = $payment_intent->last_payment_error->code ?? '';
                } else {
                    $failure_reason = 'Payment failed - no specific error message from Stripe';
                }

                $display_error = getFriendlyErrorMessage($error_code, $failure_reason);
            } catch (Exception $e) {
                $failure_reason = 'Payment failed: ' . $e->getMessage();
                $display_error = 'Your payment could not be processed. Please try again.';
            }
        }

        // Update database record
        if ($session_id) {
            $session_id_safe = "'" . addslashes($session_id) . "'";
            $where_clause = "stripe_session_id = $session_id_safe";
        } elseif ($payment_intent_id) {
            $payment_intent_id_safe = "'" . addslashes($payment_intent_id) . "'";
            $where_clause = "stripe_payment_intent_id = $payment_intent_id_safe";
        } else {
            throw new Exception('No valid identifier provided');
        }

        $failure_reason_safe = "'" . addslashes($failure_reason) . "'";

        $update_query = "UPDATE stripe_payments SET
            status = '$status',
            failure_reason = $failure_reason_safe,
            updated_at = NOW()
        WHERE $where_clause";

        $db->Execute($update_query);

        // Get payment details for logging
        $payment_query = "SELECT * FROM stripe_payments WHERE $where_clause";
        $payment_result = $db->Execute($payment_query);

        if ($payment_result) {
            $payment_details = $payment_result->FetchRow();

            if ($payment_details) {
                $patient_name = htmlspecialchars($payment_details['patient_name']);
                $amount = number_format($payment_details['amount'], 2);
                $currency = $payment_details['currency'];
                $payment_id = $payment_details['id'];

                // Log to stripe_payment_logs table
                $log_message = "'Payment failed'";
                $log_type = "'payment_failed'";
                $log_data = "'" . addslashes(json_encode([
                    'session_id' => $session_id,
                    'payment_intent_id' => $payment_intent_id,
                    'error_code' => $error_code,
                    'failure_reason' => $failure_reason,
                    'amount' => $amount
                ])) . "'";

                $log_insert = "INSERT INTO stripe_payment_logs (payment_id, log_type, log_message, response_data, created_at)
                                VALUES ('$payment_id', $log_type, $log_message, $log_data, NOW())";
                $db->Execute($log_insert);
            }
        }

        // Log to file
        logStripeActivity('Payment failed', [
            'session_id' => $session_id,
            'payment_intent_id' => $payment_intent_id,
            'error_code' => $error_code,
            'failure_reason' => $failure_reason
        ]);

    } catch (Exception $e) {
        // Log error but continue to show failure page
        logStripeActivity('Error in failure handler', [
            'error' => $e->getMessage(),
            'session_id' => $session_id,
            'payment_intent_id' => $payment_intent_id
        ]);

        $display_error = 'An error occurred while processing your payment. Please try again.';
        $display_reason = 'If the problem persists, please contact support with your payment details.';
        $patient_name = '';
        $amount = '0.00';
        $currency = STRIPE_CURRENCY;
    }
} else {
    // No parameters - direct access or malformed URL
    $display_error = 'Payment Failed';
    $display_reason = 'Unable to retrieve payment details. Please start a new payment.';
    $patient_name = '';
    $amount = '0.00';
    $currency = STRIPE_CURRENCY;
}

/**
 * Get user-friendly error message based on Stripe error code
 */
function getFriendlyErrorMessage($error_code, $stripe_message) {
    $friendly_messages = [
        'card_declined' => 'Your card was declined. Please try a different card or contact your bank.',
        'insufficient_funds' => 'Your card has insufficient funds. Please use a different card or ensure sufficient funds.',
        'expired_card' => 'Your card has expired. Please use a different card.',
        'incorrect_cvc' => 'Your card\'s security code is incorrect. Please check and try again.',
        'processing_error' => 'An error occurred while processing your card. Please try again.',
        'rate_limit' => 'Too many requests. Please wait a moment and try again.',
        'invalid_expiry_month' => 'Your card\'s expiration month is invalid.',
        'invalid_expiry_year' => 'Your card\'s expiration year is invalid.',
        'invalid_number' => 'Your card number is invalid. Please check and try again.',
    ];

    if ($error_code && isset($friendly_messages[$error_code])) {
        return $friendly_messages[$error_code];
    }

    // Return a generic message if no specific match
    if (stripos($stripe_message, 'declined') !== false) {
        return 'Your card was declined. Please try a different card or contact your bank.';
    }

    return $stripe_message ?: 'Your payment could not be processed. Please try again or use a different payment method.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Failed</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #e74c3c 0%, #c0392b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .failure-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }

        .failure-icon {
            width: 80px;
            height: 80px;
            background: #e74c3c;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .failure-icon svg {
            width: 40px;
            height: 40px;
            fill: white;
        }

        .failure-container h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 15px;
        }

        .error-message {
            background: #fee;
            border: 1px solid #fcc;
            border-left: 4px solid #e74c3c;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
        }

        .error-message h3 {
            color: #c33;
            font-size: 16px;
            margin-bottom: 8px;
        }

        .error-message p {
            font-size: 14px;
            color: #666;
            margin: 0;
            line-height: 1.5;
        }

        .error-details {
            background: #f5f5f5;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
        }

        .error-details h3 {
            color: #333;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .error-details p {
            font-size: 13px;
            color: #666;
            margin: 0;
            line-height: 1.6;
        }

        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }

        .btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: transform 0.3s ease;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-retry {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        .btn-home {
            background: #f5f5f5;
            color: #333;
        }

        .help-text {
            margin-top: 20px;
            font-size: 13px;
            color: #999;
        }

        .help-text a {
            color: #667eea;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="failure-container">
        <div class="failure-icon">
            <svg viewBox="0 0 24 24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
            </svg>
        </div>
        <h1>Payment Failed</h1>

        <div class="error-message">
            <h3>We couldn't process your payment</h3>
            <p><?php echo htmlspecialchars($display_error ?: 'Your payment could not be processed. Please try again.'); ?></p>
        </div>

        <?php if ($display_reason): ?>
            <div class="error-details">
                <h3>What happened:</h3>
                <p><?php echo htmlspecialchars($display_reason); ?></p>
            </div>
        <?php endif; ?>

        <div class="btn-group">
            <a href="payment_form.php" class="btn btn-retry">Try Again</a>
            <a href="/" class="btn btn-home">Go to Home</a>
        </div>

        <p class="help-text">
            Need help? <a href="mailto:support@example.com">Contact Support</a>
        </p>
    </div>
</body>
</html>
