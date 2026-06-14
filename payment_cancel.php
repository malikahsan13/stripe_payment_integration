<?php
/**
 * Payment Cancel Handler
 *
 * This file handles:
 * 1. When user cancels payment at Stripe Checkout
 * 2. Updating the payment record status in database
 * 3. Displaying cancellation message to user with option to retry
 */

require_once('roots.php');
require_once($root_path.'include/inc_db_makelink.php');
require_once('stripe_config.php');

// Get session ID if available
$session_id = trim($_GET['session_id'] ?? '');

if ($session_id) {
    try {
        // Include Stripe PHP library
        require_once(__DIR__ . '/stripe-php/init.php');

        // Set Stripe API key
        \Stripe\Stripe::setApiKey(getStripeSecretKey());

        // Retrieve the Checkout Session from Stripe
        $checkout_session = \Stripe\Checkout\Session::retrieve($session_id);

        // Update payment record status
        $session_id_safe = "'" . addslashes($session_id) . "'";
        $status = 'cancelled';
        $failure_reason = "'Payment cancelled by user at Stripe Checkout'";

        $update_query = "UPDATE stripe_payments SET
            status = '$status',
            failure_reason = $failure_reason,
            updated_at = NOW()
        WHERE stripe_session_id = $session_id_safe";

        $db->Execute($update_query);

        // Get payment details
        $payment_query = "SELECT * FROM stripe_payments WHERE stripe_session_id = $session_id_safe";
        $payment_result = $db->Execute($payment_query);
        $payment_details = $payment_result->FetchRow();

        if ($payment_details) {
            $patient_name = htmlspecialchars($payment_details['patient_name']);
            $amount = number_format($payment_details['amount'], 2);
            $currency = $payment_details['currency'];
            $payment_id = $payment_details['id'];

            // Log to stripe_payment_logs table
            $log_message = "'Payment cancelled by user'";
            $log_type = "'payment_cancelled'";
            $log_data = "'" . addslashes(json_encode([
                'session_id' => $session_id,
                'amount' => $amount,
                'currency' => $currency
            ])) . "'";

            $log_insert = "INSERT INTO stripe_payment_logs (payment_id, log_type, log_message, response_data, created_at)
                            VALUES ('$payment_id', $log_type, $log_message, $log_data, NOW())";
            $db->Execute($log_insert);
        }

        // Log to file
        logStripeActivity('Payment cancelled by user', [
            'session_id' => $session_id,
            'payment_id' => $payment_id ?? 'unknown'
        ]);

    } catch (Exception $e) {
        // Log error but continue to show cancel page
        logStripeActivity('Error in cancel handler', [
            'error' => $e->getMessage(),
            'session_id' => $session_id
        ]);
        $patient_name = '';
        $amount = '0.00';
        $currency = STRIPE_CURRENCY;
    }
} else {
    $patient_name = '';
    $amount = '0.00';
    $currency = STRIPE_CURRENCY;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Cancelled</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #ff6b6b 0%, #feca57 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .cancel-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }

        .cancel-icon {
            width: 80px;
            height: 80px;
            background: #ff6b6b;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .cancel-icon svg {
            width: 40px;
            height: 40px;
            fill: white;
        }

        .cancel-container h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 15px;
        }

        .cancel-container p {
            color: #666;
            font-size: 16px;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .info-box {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 8px;
            padding: 15px;
            margin: 20px 0;
            text-align: left;
        }

        .info-box h3 {
            color: #856404;
            font-size: 16px;
            margin-bottom: 10px;
        }

        .info-box p {
            font-size: 14px;
            color: #856404;
            margin: 0;
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
    </style>
</head>
<body>
    <div class="cancel-container">
        <div class="cancel-icon">
            <svg viewBox="0 0 24 24">
                <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
            </svg>
        </div>
        <h1>Payment Cancelled</h1>
        <p>
            Your payment has been cancelled. No charges were made to your card.
        </p>

        <div class="info-box">
            <h3>What happens next?</h3>
            <p>
                Your payment information is safe. You can try again whenever you're ready.
                If you have any questions, please contact our billing department.
            </p>
        </div>

        <div class="btn-group">
            <a href="payment_form.php" class="btn btn-retry">Try Again</a>
            <a href="/" class="btn btn-home">Go to Home</a>
        </div>
    </div>
</body>
</html>
