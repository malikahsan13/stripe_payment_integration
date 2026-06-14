<?php
/**
 * Stripe Webhook Handler
 *
 * This file handles webhook events from Stripe:
 * 1. Verifies webhook signatures
 * 2. Processes payment_intent.succeeded events
 * 3. Processes payment_intent.payment_failed events
 * 4. Processes checkout.session.completed events
 * 5. Logs all events for audit trail
 *
 * Setup: Configure this endpoint in your Stripe Dashboard:
 * https://dashboard.stripe.com/webhooks
 */

require_once('roots.php');
require_once($root_path.'include/inc_db_makelink.php');
require_once('stripe_config.php');

// Include Stripe PHP library
require_once(__DIR__ . '/stripe-php/init.php');

// Set Stripe API key
\Stripe\Stripe::setApiKey(getStripeSecretKey());

// Get the raw POST data
$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$endpoint_secret = getStripeWebhookSecret();

$event = null;

try {
    // ============================================
    // Step 1: Verify Webhook Signature
    // ============================================

    if ($endpoint_secret) {
        $event = \Stripe\Webhook::constructEvent(
            $payload, $sig_header, $endpoint_secret
        );
    } else {
        // If no webhook secret is configured, skip verification (not recommended for production)
        $event = json_decode($payload, true);
        $event = \Stripe\Event::constructFrom($event);
    }

    // Log the received event
    logStripeActivity('Webhook event received', [
        'event_id' => $event->id,
        'event_type' => $event->type
    ]);

    // ============================================
    // Step 2: Store Event in Database
    // ============================================

    $stripe_event_id = "'" . addslashes($event->id) . "'";
    $event_type = "'" . addslashes($event->type) . "'";
    $payment_intent_id = '';
    $event_data = "'" . addslashes(json_encode($event->toArray())) . "'";

    // Extract payment intent ID if available
    if (isset($event->data->object->payment_intent)) {
        $payment_intent_id = $event->data->object->payment_intent;
    } elseif (isset($event->data->object->id) && strpos($event->type, 'payment_intent') !== false) {
        $payment_intent_id = $event->data->object->id;
    }

    if ($payment_intent_id) {
        $payment_intent_id = "'" . addslashes($payment_intent_id) . "'";
    } else {
        $payment_intent_id = 'NULL';
    }

    // Insert webhook event (ignore if duplicate)
    $insert_webhook_query = "INSERT IGNORE INTO stripe_webhook_events (
        stripe_event_id,
        event_type,
        payment_intent_id,
        event_data,
        created_at
    ) VALUES (
        $stripe_event_id,
        $event_type,
        $payment_intent_id,
        $event_data,
        NOW()
    )";

    $db->Execute($insert_webhook_query);

    // ============================================
    // Step 3: Handle Different Event Types
    // ============================================

    switch ($event->type) {

        // ----------------------------------------
        // Payment Intent Succeeded
        // ----------------------------------------
        case 'payment_intent.succeeded':
            handlePaymentIntentSucceeded($event->data->object);
            break;

        // ----------------------------------------
        // Payment Intent Failed
        // ----------------------------------------
        case 'payment_intent.payment_failed':
            handlePaymentIntentFailed($event->data->object);
            break;

        // ----------------------------------------
        // Checkout Session Completed
        // ----------------------------------------
        case 'checkout.session.completed':
            handleCheckoutSessionCompleted($event->data->object);
            break;

        // ----------------------------------------
        // Charge Refunded
        // ----------------------------------------
        case 'charge.refunded':
            handleChargeRefunded($event->data->object);
            break;

        // ----------------------------------------
        // Charge Disputed
        // ----------------------------------------
        case 'charge.dispute.created':
            handleChargeDisputed($event->data->object);
            break;

        // ----------------------------------------
        // Default: Log unhandled events
        // ----------------------------------------
        default:
            logStripeActivity('Unhandled webhook event', [
                'event_type' => $event->type,
                'event_id' => $event->id
            ]);
    }

    // Mark event as processed
    $update_webhook_query = "UPDATE stripe_webhook_events
        SET processed = 1,
        processed_at = NOW()
    WHERE stripe_event_id = $stripe_event_id";

    $db->Execute($update_webhook_query);

    // Return 200 OK to Stripe
    http_response_code(200);
    echo json_encode(['status' => 'success']);

} catch(\UnexpectedValueException $e) {
    // Invalid payload
    http_response_code(400);
    echo json_encode(['error' => 'Invalid payload']);
    logStripeActivity('Webhook error: Invalid payload', [
        'error' => $e->getMessage()
    ]);
    exit;

} catch(\Stripe\Exception\SignatureVerificationException $e) {
    // Invalid signature
    http_response_code(400);
    echo json_encode(['error' => 'Invalid signature']);
    logStripeActivity('Webhook error: Invalid signature', [
        'error' => $e->getMessage()
    ]);
    exit;

} catch(Exception $e) {
    // General error
    http_response_code(500);
    echo json_encode(['error' => 'Webhook handler error']);
    logStripeActivity('Webhook error', [
        'error' => $e->getMessage()
    ]);
    exit;
}

/**
 * Handle Payment Intent Succeeded
 */
function handlePaymentIntentSucceeded($payment_intent) {
    global $db;

    $intent_id = "'" . addslashes($payment_intent->id) . "'";
    $amount = formatAmountFromStripe($payment_intent->amount);
    $status = 'succeeded';

    // Get metadata
    $metadata = $payment_intent->metadata->toArray();

    logStripeActivity('Processing payment_intent.succeeded', [
        'payment_intent_id' => $payment_intent->id,
        'amount' => $amount
    ]);

    // Update payment record
    $update_query = "UPDATE stripe_payments SET
        status = '$status',
        stripe_payment_intent_id = $intent_id,
        updated_at = NOW(),
        paid_at = NOW()
    WHERE stripe_payment_intent_id = $intent_id";

    $db->Execute($update_query);

    // Also try to update by session ID if available in metadata
    if (isset($metadata['payment_reference'])) {
        $reference = addslashes($metadata['payment_reference']);

        $update_by_ref_query = "UPDATE stripe_payments SET
            status = '$status',
            stripe_payment_intent_id = $intent_id,
            updated_at = NOW(),
            paid_at = NOW()
        WHERE metadata LIKE '%$reference%' AND stripe_payment_intent_id IS NULL";

        $db->Execute($update_by_ref_query);
    }

    logStripeActivity('Payment record updated from webhook', [
        'payment_intent_id' => $payment_intent->id
    ]);
}

/**
 * Handle Payment Intent Failed
 */
function handlePaymentIntentFailed($payment_intent) {
    global $db;

    $intent_id = "'" . addslashes($payment_intent->id) . "'";
    $status = 'failed';

    // Get error details
    $last_payment_error = $payment_intent->last_payment_error ?? null;
    $failure_reason = 'Payment failed';

    if ($last_payment_error) {
        $failure_reason = $last_payment_error->message ?? 'Unknown error';
    }

    $failure_reason_safe = "'" . addslashes($failure_reason) . "'";

    logStripeActivity('Processing payment_intent.payment_failed', [
        'payment_intent_id' => $payment_intent->id,
        'reason' => $failure_reason
    ]);

    // Update payment record
    $update_query = "UPDATE stripe_payments SET
        status = '$status',
        stripe_payment_intent_id = $intent_id,
        failure_reason = $failure_reason_safe,
        updated_at = NOW()
    WHERE stripe_payment_intent_id = $intent_id";

    $db->Execute($update_query);

    // Also try to update by metadata
    $metadata = $payment_intent->metadata->toArray();
    if (isset($metadata['payment_reference'])) {
        $reference = addslashes($metadata['payment_reference']);

        $update_by_ref_query = "UPDATE stripe_payments SET
            status = '$status',
            stripe_payment_intent_id = $intent_id,
            failure_reason = $failure_reason_safe,
            updated_at = NOW()
        WHERE metadata LIKE '%$reference%' AND stripe_payment_intent_id IS NULL";

        $db->Execute($update_by_ref_query);
    }
}

/**
 * Handle Checkout Session Completed
 */
function handleCheckoutSessionCompleted($session) {
    global $db;

    $session_id = "'" . addslashes($session->id) . "'";
    $payment_intent_id = $session->payment_intent ? "'" . addslashes($session->payment_intent) . "'" : 'NULL';
    $status = 'succeeded';
    $customer_email = $session->customer_email ?? '';
    $amount = formatAmountFromStripe($session->amount_total ?? 0);

    logStripeActivity('Processing checkout.session.completed', [
        'session_id' => $session->id,
        'payment_intent_id' => $payment_intent_id
    ]);

    // Update payment record with session completion
    $update_query = "UPDATE stripe_payments SET
        status = '$status',
        stripe_payment_intent_id = $payment_intent_id,
        updated_at = NOW()
    WHERE stripe_session_id = $session_id";

    $db->Execute($update_query);
}

/**
 * Handle Charge Refunded
 */
function handleChargeRefunded($charge) {
    global $db;

    $charge_id = "'" . addslashes($charge->id) . "'";
    $payment_intent_id = $charge->payment_intent ? "'" . addslashes($charge->payment_intent) . "'" : 'NULL';
    $amount_refunded = formatAmountFromStripe($charge->amount_refunded);

    logStripeActivity('Processing charge.refunded', [
        'charge_id' => $charge->id,
        'amount_refunded' => $amount_refunded
    ]);

    // Update payment record to mark as refunded (full or partial)
    $status = ($amount_refunded >= $charge->amount) ? 'refunded' : 'partially_refunded';

    $update_query = "UPDATE stripe_payments SET
        status = '$status',
        updated_at = NOW()
    WHERE stripe_charge_id = $charge_id OR stripe_payment_intent_id = $payment_intent_id";

    $db->Execute($update_query);
}

/**
 * Handle Charge Disputed
 */
function handleChargeDisputed($charge) {
    global $db;

    $charge_id = "'" . addslashes($charge->id) . "'";
    $payment_intent_id = $charge->payment_intent ? "'" . addslashes($charge->payment_intent) . "'" : 'NULL';

    logStripeActivity('Processing charge.dispute.created', [
        'charge_id' => $charge->id
    ]);

    // Update payment record to mark as disputed
    $update_query = "UPDATE stripe_payments SET
        status = 'disputed',
        updated_at = NOW()
    WHERE stripe_charge_id = $charge_id OR stripe_payment_intent_id = $payment_intent_id";

    $db->Execute($update_query);
}
?>
