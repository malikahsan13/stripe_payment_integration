# Stripe Payment Integration - Status Handling

## Payment Status Flow

```
┌─────────────────┐
│  Payment Init   │ → status='pending'
└────────┬────────┘
         │
         ├───► Success ────► status='succeeded'
         │                   (+ paid_at timestamp)
         │
         ├───► Cancel ────► status='cancelled'
         │                   (+ failure_reason)
         │
         └───► Failure ────► status='failed'
                             (+ failure_reason)
```

## All Payment Statuses

| Status | Description | Handler | Database Update |
|--------|-------------|---------|-----------------|
| `pending` | Payment initiated, waiting for completion | [payment_process.php](payment_process.php) | Insert on creation |
| `succeeded` | Payment successfully completed | [payment_success.php](payment_success.php) + [webhooks.php](webhooks.php) | Update status + paid_at |
| `cancelled` | User cancelled at Stripe Checkout | [payment_cancel.php](payment_cancel.php) | Update status + failure_reason |
| `failed` | Payment failed (card declined, etc.) | [payment_failure.php](payment_failure.php) + [webhooks.php](webhooks.php) | Update status + failure_reason |
| `refunded` | Full refund processed | [webhooks.php](webhooks.php) | Update status |
| `partially_refunded` | Partial refund processed | [webhooks.php](webhooks.php) | Update status |
| `disputed` | Chargeback initiated | [webhooks.php](webhooks.php) | Update status |

## Handlers

### 1. Payment Initiation
**File:** `payment_process.php`
- Creates record with `status='pending'`
- Logs to `stripe_payment_logs` with `log_type='intent_created'`
- Redirects to Stripe Checkout

### 2. Payment Success
**File:** `payment_success.php`
- Updates status to `succeeded`
- Sets `paid_at` timestamp
- Saves `stripe_payment_method_id` and `stripe_charge_id`
- Logs to `stripe_payment_logs` with `log_type='payment_success'`
- Optional: Updates claim status (commented out, needs customization)
- Optional: Sends email confirmation (commented out)

### 3. Payment Cancellation
**File:** `payment_cancel.php`
- Updates status to `cancelled`
- Sets `failure_reason='Payment cancelled by user'`
- Logs to `stripe_payment_logs` with `log_type='payment_cancelled'`
- Shows retry option to user

### 4. Payment Failure
**File:** `payment_failure.php`
- Updates status to `failed`
- Saves specific `failure_reason` from Stripe
- Logs to `stripe_payment_logs` with `log_type='payment_failed'`
- Shows user-friendly error message based on error code:
  - `card_declined` → Card declined message
  - `insufficient_funds` → Insufficient funds message
  - `expired_card` → Card expired message
  - `incorrect_cvc` → CVC error message
  - etc.

### 5. Webhook Handler
**File:** `webhooks.php`
- Handles Stripe webhook events:
  - `payment_intent.succeeded` → Updates to `succeeded`
  - `payment_intent.payment_failed` → Updates to `failed`
  - `checkout.session.completed` → Updates to `succeeded`
  - `charge.refunded` → Updates to `refunded` or `partially_refunded`
  - `charge.dispute.created` → Updates to `disputed`
- Stores all events in `stripe_webhook_events` table
- Marks events as processed

## Database Tables

### stripe_payments
Main payment records with status tracking.

### stripe_payment_logs
Audit trail for all payment events:
- `intent_created` - When payment intent was created
- `payment_success` - When payment succeeded
- `payment_cancelled` - When user cancelled
- `payment_failed` - When payment failed
- `claim_update` - When claim status was updated

### stripe_webhook_events
All Stripe webhook events received:
- Raw event data
- Processing status
- Timestamps

## Error Code Mapping

User-friendly messages for common Stripe errors:

| Stripe Error Code | User Message |
|------------------|--------------|
| `card_declined` | Your card was declined. Please try a different card. |
| `insufficient_funds` | Your card has insufficient funds. |
| `expired_card` | Your card has expired. Please use a different card. |
| `incorrect_cvc` | Your card's security code is incorrect. |
| `processing_error` | An error occurred while processing your card. |
| `rate_limit` | Too many requests. Please wait and try again. |

## Testing Status Changes

Use these Stripe test cards to test different statuses:

### Success
- Card: `4242 4242 4242 4242`
- Result: `succeeded` status

### Card Declined
- Card: `4000 0000 0000 0002`
- Result: `failed` status with decline message

### Insufficient Funds
- Card: `4000 0000 0000 9995`
- Result: `failed` status with insufficient funds message

### Expired Card
- Card: `4000 0000 0000 0069`
- Result: `failed` status with expired card message

### Cancellation
- Click "Cancel" button in Stripe Checkout
- Result: `cancelled` status

## Query Examples

### Check payment status
```sql
SELECT id, patient_id, amount, status, paid_at, failure_reason, created_at
FROM stripe_payments
ORDER BY id DESC
LIMIT 10;
```

### Get all failed payments
```sql
SELECT * FROM stripe_payments WHERE status = 'failed';
```

### Get payment logs for specific payment
```sql
SELECT * FROM stripe_payment_logs WHERE payment_id = [payment_id]
ORDER BY created_at DESC;
```

### Statistics
```sql
SELECT status, COUNT(*) as count, SUM(amount) as total_amount
FROM stripe_payments
GROUP BY status;
```

## URLs Configuration

All configured in `stripe_config.php`:

```php
define('STRIPE_SUCCESS_URL', STRIPE_MODULE_URL . 'payment_success.php');
define('STRIPE_CANCEL_URL', STRIPE_MODULE_URL . 'payment_cancel.php');
define('STRIPE_FAILURE_URL', STRIPE_MODULE_URL . 'payment_failure.php');
```

Update these if you need to redirect to different locations.
