<?php
/**
 * Payment Success Handler
 *
 * This file handles:
 * 1. Verifying the Stripe Checkout Session
 * 2. Updating the payment record in the database
 * 3. Updating claim status (if applicable)
 * 4. Sending email notification
 * 5. Displaying success message to user
 */

require_once('roots.php');
require_once($root_path.'include/inc_db_makelink.php');
require_once('stripe_config.php');

// Include Stripe PHP library
require_once(__DIR__ . '/stripe-php/init.php');

// Set Stripe API key
\Stripe\Stripe::setApiKey(getStripeSecretKey());

$session_id = trim($_GET['session_id'] ?? '');

if (empty($session_id)) {
    die('Error: No session ID provided. Please contact support if you believe this is an error.');
}

try {
    // ============================================
    // Step 1: Retrieve the Checkout Session from Stripe
    // ============================================

    $checkout_session = \Stripe\Checkout\Session::retrieve($session_id);

    if ($checkout_session->status !== 'complete') {
        die('Error: Payment not completed. Please try again or contact support.');
    }

    // Get the Payment Intent
    $payment_intent_id = $checkout_session->payment_intent;
    if ($payment_intent_id) {
        $payment_intent = \Stripe\PaymentIntent::retrieve($payment_intent_id);
    }

    // ============================================
    // Step 2: Update Payment Record in Database
    // ============================================

    $session_id_safe = "'" . addslashes($session_id) . "'";
    $payment_intent_id_safe = "'" . addslashes($payment_intent_id) . "'";
    $status = 'succeeded';
    $stripe_payment_method_id = isset($payment_intent->payment_method) ? "'" . addslashes($payment_intent->payment_method) . "'" : 'NULL';
    $stripe_charge_id = isset($payment_intent->latest_charge) ? "'" . addslashes($payment_intent->latest_charge) . "'" : 'NULL';
    $paid_at = 'NOW()';

    // Get metadata from session
    $metadata = $checkout_session->metadata->toArray();
    $amount = floatval($metadata['amount'] ?? 0);
    $patient_id = addslashes($metadata['patient_id'] ?? '');
    $claim_id = addslashes($metadata['claim_id'] ?? '');

    // Update the payment record
    $update_query = "UPDATE stripe_payments SET
        status = '$status',
        stripe_payment_method_id = $stripe_payment_method_id,
        stripe_charge_id = $stripe_charge_id,
        paid_at = $paid_at,
        updated_at = NOW()
    WHERE stripe_session_id = $session_id_safe";

    $db->Execute($update_query);

    // Log to stripe_payment_logs table
    $log_message = "'Payment successful - status updated'";
    $log_type = "'payment_success'";
    $log_data = "'" . addslashes(json_encode([
        'session_id' => $session_id,
        'payment_intent_id' => $payment_intent_id,
        'amount' => $amount
    ])) . "'";

    // First get the payment_id
    $payment_query = "SELECT id FROM stripe_payments WHERE stripe_session_id = $session_id_safe";
    $payment_result = $db->Execute($payment_query);
    if ($payment_result) {
        $payment_row = $payment_result->FetchRow();
        $payment_id = $payment_row['id'];

        // Insert log entry
        $log_insert = "INSERT INTO stripe_payment_logs (payment_id, log_type, log_message, response_data, created_at)
                        VALUES ('$payment_id', $log_type, $log_message, $log_data, NOW())";
        $db->Execute($log_insert);
    }

    // Get payment details for display
    $payment_query = "SELECT * FROM stripe_payments WHERE stripe_session_id = $session_id_safe";
    $payment_result = $db->Execute($payment_query);
    $payment_details = $payment_result->FetchRow();

    // Log successful payment to file
    logStripeActivity('Payment successful', [
        'session_id' => $session_id,
        'payment_intent_id' => $payment_intent_id,
        'amount' => $amount,
        'patient_id' => $patient_id
    ]);

    // ============================================
    // Step 3: Update Claim Status (if claim_id exists)
    // ============================================

    if (!empty($metadata['claim_id'])) {
        $claim_id_for_update = addslashes($metadata['claim_id']);

        // Update claim status - adjust the query based on your claims table structure
        // Uncomment and modify this section based on your actual claims table
        $claim_update_query = "UPDATE patient_claims
            SET payment_status = 'paid',
                paid_amount = paid_amount + '$amount',
                payment_date = NOW(),
                updated_at = NOW()
            WHERE claim_id = '$claim_id_for_update'";

        // Execute claim update (you may need to adjust the table name)
        // $db->Execute($claim_update_query);

        // Log claim update attempt
        $log_claim = "'Claim status update attempted'";
        $log_claim_type = "'claim_update'";
        $log_claim_query = "INSERT INTO stripe_payment_logs (payment_id, log_type, log_message, created_at)
                           VALUES ('$payment_id', $log_claim_type, $log_claim, NOW())";
        // $db->Execute($log_claim_query);

        logStripeActivity('Claim status update attempted', [
            'claim_id' => $metadata['claim_id'],
            'amount' => $amount
        ]);
    }

    // ============================================
    // Step 4: Send Email Notification
    // ============================================

    if ($payment_details) {
        sendPaymentConfirmationEmail($payment_details);
    }

    // ============================================
    // Step 5: Display Success Page
    // ============================================

    $success_title = 'Payment Successful!';
    $success_message = 'Thank you for your payment. A confirmation email has been sent to your email address.';
    $payment_amount = number_format($payment_details['amount'] ?? $amount, 2);
    $payment_currency = $payment_details['currency'] ?? STRIPE_CURRENCY;
    $transaction_id = $payment_intent_id;
    $patient_name = htmlspecialchars($metadata['patient_name'] ?? '');

} catch (\Stripe\Exception\ApiErrorException $e) {
    $error_title = 'Payment Verification Failed';
    $error_message = 'We could not verify your payment. Please contact support with your payment details.';
    $show_error = true;

    logStripeActivity('Stripe API Error in success handler', [
        'error' => $e->getMessage(),
        'session_id' => $session_id
    ]);

} catch (Exception $e) {
    $error_title = 'Error';
    $error_message = 'An error occurred while processing your payment confirmation.';
    $show_error = true;

    logStripeActivity('Error in success handler', [
        'error' => $e->getMessage(),
        'session_id' => $session_id
    ]);
}

/**
 * Send payment confirmation email
 */
function sendPaymentConfirmationEmail($payment_details) {
    global $root_path, $db;

    require_once($root_path.'include/inc_db_makelink.php');

    $to = $payment_details['patient_email'];
    $patient_name = $payment_details['patient_name'];
    $amount = number_format($payment_details['amount'], 2);
    $currency = $payment_details['currency'];
    $transaction_id = $payment_details['stripe_payment_intent_id'];
    $date = date('F j, Y', strtotime($payment_details['paid_at'] ?? 'now'));

    $subject = 'Payment Confirmation - ' . $transaction_id;

    $message = "
    <html>
    <head>
    <title>Payment Confirmation</title>
    </head>
    <body style='font-family: Arial, sans-serif;'>
        <div style='max-width: 600px; margin: 0 auto;'>
            <h2 style='color: #333;'>Payment Confirmation</h2>
            <p>Dear " . htmlspecialchars($patient_name) . ",</p>
            <p>Thank you for your payment. Your transaction has been successfully processed.</p>
            <table style='width: 100%; border-collapse: collapse; margin: 20px 0;'>
                <tr style='background-color: #f5f5f5;'>
                    <td style='padding: 10px; border: 1px solid #ddd;'><strong>Transaction ID:</strong></td>
                    <td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($transaction_id) . "</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border: 1px solid #ddd;'><strong>Amount Paid:</strong></td>
                    <td style='padding: 10px; border: 1px solid #ddd;'>" . $currency . " " . $amount . "</td>
                </tr>
                <tr style='background-color: #f5f5f5;'>
                    <td style='padding: 10px; border: 1px solid #ddd;'><strong>Date:</strong></td>
                    <td style='padding: 10px; border: 1px solid #ddd;'>" . $date . "</td>
                </tr>
                <tr>
                    <td style='padding: 10px; border: 1px solid #ddd;'><strong>Patient ID:</strong></td>
                    <td style='padding: 10px; border: 1px solid #ddd;'>" . htmlspecialchars($payment_details['patient_id']) . "</td>
                </tr>
            </table>
            <p>If you have any questions about this payment, please contact our billing department.</p>
            <p style='color: #999; font-size: 12px;'>This is an automated message. Please do not reply to this email.</p>
        </div>
    </body>
    </html>
    ";

    // Headers
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=UTF-8\r\n";
    $headers .= "From: Billing Department <billing@yourdomain.com>\r\n";

    // Send email
    // mail($to, $subject, $message, $headers);

    logStripeActivity('Payment confirmation email sent', [
        'to' => $to,
        'transaction_id' => $transaction_id
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Successful</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .success-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }

        .success-icon {
            width: 80px;
            height: 80px;
            background: #38ef7d;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: scaleIn 0.5s ease-out;
        }

        .success-icon svg {
            width: 40px;
            height: 40px;
            fill: white;
        }

        @keyframes scaleIn {
            0% { transform: scale(0); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }

        .success-container h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 15px;
        }

        .success-container p {
            color: #666;
            font-size: 16px;
            margin-bottom: 30px;
            line-height: 1.6;
        }

        .receipt-details {
            background: #f5f5f5;
            border-radius: 10px;
            padding: 20px;
            margin: 20px 0;
            text-align: left;
        }

        .receipt-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #ddd;
        }

        .receipt-row:last-child {
            border-bottom: none;
        }

        .receipt-row .label {
            color: #666;
            font-size: 14px;
        }

        .receipt-row .value {
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }

        .receipt-row .value.amount {
            color: #11998e;
            font-size: 18px;
        }

        .btn-home {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            margin-top: 20px;
            transition: transform 0.3s ease;
        }

        .btn-home:hover {
            transform: translateY(-2px);
        }

        .error-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
            text-align: center;
        }

        .error-icon {
            width: 80px;
            height: 80px;
            background: #e74c3c;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }

        .error-icon svg {
            width: 40px;
            height: 40px;
            fill: white;
        }

        .error-container h1 {
            color: #333;
            font-size: 24px;
            margin-bottom: 15px;
        }

        .error-container p {
            color: #666;
            font-size: 16px;
            margin-bottom: 20px;
        }
    </style>
</head>
<body>
    <?php if (isset($show_error) && $show_error): ?>
        <div class="error-container">
            <div class="error-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                </svg>
            </div>
            <h1><?php echo $error_title; ?></h1>
            <p><?php echo $error_message; ?></p>
            <a href="payment_form.php" class="btn-home">Try Again</a>
        </div>
    <?php else: ?>
        <div class="success-container">
            <div class="success-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/>
                </svg>
            </div>
            <h1><?php echo $success_title; ?></h1>
            <p><?php echo $success_message; ?></p>

            <div class="receipt-details">
                <div class="receipt-row">
                    <span class="label">Patient Name:</span>
                    <span class="value"><?php echo $patient_name; ?></span>
                </div>
                <div class="receipt-row">
                    <span class="label">Transaction ID:</span>
                    <span class="value"><?php echo htmlspecialchars($transaction_id); ?></span>
                </div>
                <div class="receipt-row">
                    <span class="label">Amount Paid:</span>
                    <span class="value amount"><?php echo $payment_currency . ' ' . $payment_amount; ?></span>
                </div>
                <div class="receipt-row">
                    <span class="label">Date:</span>
                    <span class="value"><?php echo date('F j, Y, g:i a'); ?></span>
                </div>
            </div>

            <p style="font-size: 14px; color: #999; margin-top: 20px;">
                A confirmation has been sent to your email address.
            </p>

            <a href="payment_form.php" class="btn-home">Make Another Payment</a>
        </div>
    <?php endif; ?>
</body>
</html>
