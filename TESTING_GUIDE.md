# Stripe Refund Testing Guide

## Complete Refund Workflow

### Step 1: Create a Test Payment
Before testing refunds, you need a successful payment to refund.

1. Go to: `payment_form.php`
2. Fill in test data:
   - Patient ID: `TEST-001`
   - Patient Name: `Test Patient`
   - Patient Email: `test@example.com`
   - Amount: `100.00`
3. Complete the Stripe test payment using test card: `4242 4242 4242 4242`

### Step 2: Create a Refund Request
1. Go to: `refund_request.php`
2. Search for the payment (by Patient ID: `TEST-001`)
3. Click "Request Refund" on the payment
4. Fill in refund details:
   - Refund Type: Full or Partial
   - If Partial: Enter amount (e.g., `50.00`)
   - Reason: Select from dropdown
   - Notes: Optional
5. Click "Submit Refund Request"

**Status Check:** The refund request is created with status `pending` in `stripe_refund_requests` table.

### Step 3: Review and Approve
1. Go to: `refund_review.php`
2. Find your request in "Pending Refund Requests" section
3. Click "Approve" button

**Status Check:** Request status changes to `approved` in `stripe_refund_requests` table.

### Step 4: Process the Refund
1. On the same page (`refund_review.php`), find the approved request in "Approved Refund Requests" section
2. Click "Process Refund" button
3. Confirm the action

**What happens behind the scenes:**
1. ✅ Stripe API is called to create the refund
2. ✅ `stripe_refund_requests.status` changes to `completed`
3. ✅ `stripe_refund_requests.stripe_refund_id` is populated (e.g., `re_xxxxx`)
4. ✅ `stripe_payments.refunded_amount` is increased by refund amount
5. ✅ `stripe_payments.total_refunds` is incremented
6. ✅ `stripe_payments.last_refund_date` is set to NOW()

### Step 5: Verify the Refund

#### Database Verification:
```sql
-- Check refund request status
SELECT * FROM stripe_refund_requests WHERE id = [your_request_id];

-- Check payment refund status
SELECT id, patient_id, amount, refunded_amount, refundable_amount, total_refunds, last_refund_date
FROM stripe_payments WHERE id = [your_payment_id];
```

#### Stripe Dashboard Verification:
1. Go to: https://dashboard.stripe.com/test/payments
2. Find your payment by amount or patient ID
3. Click on the payment
4. You should see the refund listed

#### Expected Results After Successful Refund:
| Table | Field | Value |
|-------|-------|-------|
| `stripe_refund_requests` | `status` | `completed` |
| `stripe_refund_requests` | `stripe_refund_id` | `re_xxxxx...` |
| `stripe_refund_requests` | `processed_at` | `[timestamp]` |
| `stripe_payments` | `refunded_amount` | `100.00` (or partial amount) |
| `stripe_payments` | `total_refunds` | `1` |

## Where Status Updates Happen

### In `refund_review.php`:

**Lines 118-125:** Updates refund request to completed
```php
$update_query = "UPDATE stripe_refund_requests
    SET status = 'completed',
        stripe_refund_id = $stripe_refund_id,
        processed_at = NOW(),
        updated_at = NOW()
    WHERE id = '$request_id'";
```

**Lines 131-137:** Updates original payment record
```php
$update_payment = "UPDATE stripe_payments
    SET refunded_amount = refunded_amount + '$refund_amount',
        refundable_amount = amount - (refunded_amount + '$refund_amount'),
        total_refunds = total_refunds + 1,
        last_refund_date = NOW(),
        updated_at = NOW()
    WHERE id = '$payment_id'";
```

## Test Scenarios

### Scenario 1: Full Refund
- Original Amount: $100.00
- Refund Type: Full
- Expected: `refunded_amount` = $100.00, `refundable_amount` = $0.00

### Scenario 2: Partial Refund
- Original Amount: $100.00
- Refund Amount: $30.00
- Expected: `refunded_amount` = $30.00, `refundable_amount` = $70.00

### Scenario 3: Multiple Partial Refunds
- Original Amount: $100.00
- First Refund: $30.00
- Second Refund: $20.00
- Expected: `refunded_amount` = $50.00, `refundable_amount` = $50.00, `total_refunds` = 2

### Scenario 4: Reject a Refund Request
- Create refund request
- Go to `refund_review.php`
- Click "Reject" and provide reason
- Expected: `status` = `rejected`, reason appended with rejection details

## Common Issues & Solutions

### Issue: "No charge ID found for this payment"
**Cause:** The payment doesn't have a `stripe_charge_id`
**Solution:** Only test with payments that have succeeded through Stripe

### Issue: "Refund amount cannot exceed refundable amount"
**Cause:** Trying to refund more than available
**Solution:** Check `refunded_amount` column in `stripe_payments` table

### Issue: Stripe API Error
**Cause:** Invalid API key or network issue
**Solution:** Check `STRIPE_SECRET_KEY_TEST` in `stripe_config.php`

## Quick Test Commands

```sql
-- Quick check of all refund requests
SELECT rr.id, rr.payment_id, p.patient_name, rr.refund_amount,
       rr.status, rr.created_at, rr.processed_at
FROM stripe_refund_requests rr
JOIN stripe_payments p ON rr.payment_id = p.id
ORDER BY rr.created_at DESC;

-- Check pending refunds
SELECT COUNT(*) as pending_count FROM stripe_refund_requests WHERE status = 'pending';

-- Check total refunded amount
SELECT SUM(refund_amount) as total_refunded
FROM stripe_refund_requests
WHERE status = 'completed';
```

## Files Summary

| File | Purpose |
|------|---------|
| `refund_request.php` | Create new refund requests (status: pending) |
| `refund_review.php` | Review, approve, reject, and process refunds |
| `stripe_config.php` | Stripe API keys and helper functions |
| `install_refund_tables.php` | Setup refund tables (run once) |

## Test Cards for Stripe

Use these cards in Stripe test mode:
- **Success:** `4242 4242 4242 4242` (any future expiry, any CVC)
- **Insufficient Funds:** `4000 0025 0000 3155`
- **Expired Card:** `4000 0000 0000 0069`
- **Processing Error:** `4000 0000 0000 0119`

## Log Files

Check activity logs at:
```
modules/pms/zBackPre/stripe_integration/logs/stripe_activity.log
```

Look for entries like:
- `Refund request created`
- `Refund request approved`
- `Refund processed successfully`
