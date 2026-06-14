# Stripe Payment Integration Module

## Overview

This module provides secure Stripe payment processing for patient payments. It supports both Stripe Checkout (redirect to Stripe) and is designed to be easily integrated into the existing Clinical7 system.

## File Structure

```
stripe_integration/
├── composer.json            # Composer dependencies
├── database_table.sql       # Database table creation script
├── refund_tables.sql        # Refund system tables
├── stripe_config.php        # Stripe API configuration
├── roots.php                # Root path configuration
├── payment_form.php         # Patient payment input form
├── payment_process.php      # Payment intent creation & redirect
├── payment_success.php      # Success callback handler
├── payment_cancel.php       # Cancel callback handler
├── payment_failure.php      # Failure callback handler
├── refund_request.php       # Refund request form
├── refund_review.php        # Refund review dashboard
├── webhooks.php              # Stripe webhook handler
├── logs/                    # Activity logs (auto-created)
├── REFUND_GUIDE.md         # Refund system documentation
├── STATUS_HANDLING.md      # Payment status handling guide
├── TESTING_GUIDE.md        # Testing documentation
└── README.md               # This file
```

## Installation

### Step 1: Install Dependencies

Install the Stripe PHP library via Composer:

```bash
composer install
```

### Step 2: Database Setup

Run the SQL scripts to create the required tables:

```bash
mysql -u your_user -p your_database < database_table.sql
mysql -u your_user -p your_database < refund_tables.sql
```

```bash
mysql -u your_user -p your_database < database_table.sql
```

This creates:

- `stripe_payments` - Main payment transaction records
- `stripe_payment_logs` - Audit trail for debugging
- `stripe_webhook_events` - Webhook event logs
- `stripe_config` - Configuration storage

### Step 2: Configure Stripe API Keys

Edit `stripe_config.php` and add your Stripe API keys:

```php
// Test Mode Keys (for development)
define('STRIPE_PUBLISHABLE_KEY_TEST', 'pk_test_YOUR_KEY');
define('STRIPE_SECRET_KEY_TEST', 'sk_test_YOUR_KEY');

// Live Mode Keys (for production)
define('STRIPE_PUBLISHABLE_KEY_LIVE', 'pk_live_YOUR_KEY');
define('STRIPE_SECRET_KEY_LIVE', 'sk_live_YOUR_KEY');

// Webhook Secret (from Stripe Dashboard > Webhooks)
define('STRIPE_WEBHOOK_SECRET_TEST', 'whsec_YOUR_SECRET');
define('STRIPE_WEBHOOK_SECRET_LIVE', 'whsec_YOUR_SECRET');
```

To get these keys:

1. Go to https://dashboard.stripe.com/apikeys
2. Copy your publishable and secret keys
3. For webhooks, go to https://dashboard.stripe.com/webhooks

### Step 3: Configure Webhook

In your Stripe Dashboard:

1. Go to https://dashboard.stripe.com/webhooks
2. Click "Add endpoint"
3. Enter your webhook URL: `https://yourdomain.com/stripe_integration/webhook.php`
4. Select events to listen for:
   - `payment_intent.succeeded`
   - `payment_intent.payment_failed`
   - `checkout.session.completed`
   - `charge.refunded`
   - `charge.dispute.created`
5. Copy the webhook signing secret and add it to `stripe_config.php`

### Step 4: Test Mode vs Live Mode

In `stripe_config.php`, set the mode:

```php
// Set to true for testing, false for live payments
define('STRIPE_TEST_MODE', true);
```

## Usage

### Making a Payment

1. Access the payment form:

   ```
   https://yourdomain.com/stripe_integration/payment_form.php
   ```

2. Fill in the payment details:
   - Patient ID (required)
   - Patient Name (required)
   - Email Address (required)
   - Encounter ID (optional)
   - Claim ID (optional)
   - Amount (required)

3. Click "Pay Now"

4. User is redirected to Stripe's secure checkout page

5. After payment, user returns to success/cancel page

### Database Tables

#### stripe_payments

| Column                   | Type          | Description                                     |
| ------------------------ | ------------- | ----------------------------------------------- |
| id                       | INT           | Primary key                                     |
| patient_id               | VARCHAR(50)   | Patient identifier                              |
| patient_name             | VARCHAR(255)  | Patient full name                               |
| patient_email            | VARCHAR(255)  | Email for receipts                              |
| claim_id                 | VARCHAR(50)   | Associated claim ID                             |
| encounter_id             | VARCHAR(50)   | Associated encounter ID                         |
| amount                   | DECIMAL(10,2) | Payment amount                                  |
| currency                 | VARCHAR(3)    | Currency code (USD)                             |
| status                   | VARCHAR(50)   | pending, succeeded, failed, cancelled, refunded |
| stripe_payment_intent_id | VARCHAR(255)  | Stripe Payment Intent ID                        |
| stripe_session_id        | VARCHAR(255)  | Stripe Checkout Session ID                      |
| stripe_charge_id         | VARCHAR(255)  | Stripe Charge ID                                |
| created_at               | DATETIME      | Record creation time                            |
| paid_at                  | DATETIME      | Payment completion time                         |

#### Status Values

- `pending` - Payment initiated, waiting for completion
- `succeeded` - Payment successfully completed
- `failed` - Payment failed
- `cancelled` - Cancelled by user
- `refunded` - Fully refunded
- `partially_refunded` - Partially refunded
- `disputed` - Chargeback initiated

## API Endpoints

### Form Entry

- **URL**: `payment_form.php`
- **Method**: GET
- **Description**: Display payment input form

### Payment Process

- **URL**: `payment_process.php`
- **Method**: GET/POST
- **Parameters**:
  - `patient_id` (required)
  - `patient_name` (required)
  - `patient_email` (required)
  - `claim_id` (optional)
  - `encounter_id` (optional)
  - `amount` (required)

### Success Callback

- **URL**: `payment_success.php`
- **Method**: GET
- **Parameters**: `session_id` (from Stripe)

### Cancel Callback

- **URL**: `payment_cancel.php`
- **Method**: GET
- **Parameters**: `session_id` (from Stripe)

### Webhook Handler

- **URL**: `webhook.php`
- **Method**: POST
- **Description**: Handles Stripe webhook events

## Integration with Existing System

### Updating Claim Status

When a payment succeeds, you can update your claims table. In `payment_success.php`, modify the claim update section:

```php
if (!empty($metadata['claim_id'])) {
    // Adjust this query based on your claims table structure
    $claim_update_query = "UPDATE your_claims_table
        SET payment_status = 'paid',
            paid_amount = paid_amount + '$amount',
            payment_date = NOW()
        WHERE claim_id = '$claim_id'";

    $db->Execute($claim_update_query);
}
```

### Custom Email Configuration

Email sending is currently commented out in `payment_success.php`. To enable:

1. Uncomment the `mail()` call in `sendPaymentConfirmationEmail()`
2. Update the "From" email address

## Troubleshooting

### Check Logs

Activity logs are stored in `logs/stripe_activity.log`:

```bash
tail -f logs/stripe_activity.log
```

### Common Issues

1. **"No session ID provided" error**
   - User may have bookmarked the success page
   - Check that Stripe success_url is correctly configured

2. **"Invalid signature" webhook error**
   - Verify webhook secret in stripe_config.php
   - Ensure webhook endpoint URL matches Stripe Dashboard

3. **Payment not updating in database**
   - Check database connection
   - Verify stripe_payments table exists
   - Check logs for SQL errors

### Testing

Use Stripe test cards:

- **Success**: `4242 4242 4242 4242`
- **Failure**: `4000 0000 0000 0002`
- **Requires authentication**: `4000 0025 0000 3155`

More test cards: https://stripe.com/docs/testing

## Security Notes

1. **Never commit API keys to version control**
2. **Use HTTPS in production**
3. **Verify webhook signatures**
4. **Validate all user inputs**
5. **Keep Stripe PHP library updated**

## Support

For issues or questions:

- Stripe Documentation: https://stripe.com/docs
- Stripe Support: https://support.stripe.com

## Version History

- **v1.0** (2026-06-13) - Initial release
  - Stripe Checkout integration
  - Payment form
  - Success/Cancel handlers
  - Webhook support
  - Email notifications
  - Claim status updates
