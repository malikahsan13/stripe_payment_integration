# Stripe Refund System - Complete Guide

## Overview

The refund system allows billing staff to process both full and partial refunds for patient payments through a review workflow.

## Refund Workflow

```
┌──────────────────┐
│ Refund Request    │ → status='pending'
│ (billing staff)   │
└─────────┬─────────┘
          │
          ▼
┌──────────────────┐
│ Review & Decide  │ → Approve/Reject
│ (billing staff)   │
└─────────┬─────────┘
          │
          ├───► APPROVED ────► status='approved'
          │
          └───► REJECTED ────► status='rejected'
          │
          ▼
┌──────────────────┐
│ Process Refund   │ → status='completed'
│ (via Stripe API) │
└──────────────────┘
```

## Files Created

| File | Purpose |
|------|---------|
| [refund_tables.sql](refund_tables.sql) | Database tables for refund system |
| [refund_request.php](refund_request.php) | Form to create refund requests |
| [refund_review.php](refund_review.php) | Dashboard to review & process refunds |
| [webhooks.php](webhooks.php) | Handles Stripe refund webhooks |

## Database Tables

### stripe_refund_requests
Main table for refund requests with full audit trail.

**Key Fields:**
- `payment_id` - Links to original payment
- `payment_intent_id` - Stripe payment intent
- `charge_id` - Stripe charge ID (required for refunds)
- `refund_amount` - Amount to refund
- `refund_type` - 'full' or 'partial'
- `reason` - Reason for refund
- `status` - Workflow status
- `requested_by` - Who requested the refund
- `reviewed_by` - Who approved/rejected
- `stripe_refund_id` - Stripe refund ID (after processing)

### stripe_refund_reasons
Predefined refund reasons for dropdown:
- Duplicate payment
- Service cancelled
- Service not rendered
- Customer request
- Billing error
- Price adjustment
- Insurance adjustment
- Other

### stripe_payments (updated)
Added columns:
- `refunded_amount` - Total amount refunded
- `refundable_amount` - Amount available for refund
- `total_refunds` - Number of refund transactions
- `last_refund_date` - Date of most recent refund

## Status Values

| Status | Description | Can Change To |
|--------|-------------|---------------|
| `pending` | Initial status after request | approved, rejected |
| `approved` | Approved by reviewer | processing, completed |
| `rejected` | Rejected by reviewer | - (final) |
| `processing` | Being processed via Stripe | completed, failed |
| `completed` | Successfully refunded | - (final) |
| `failed` | Refund processing failed | - (final) |

## Access Control

The refund pages check for:
```php
session_start();
if (!isset($_SESSION['sess_login_userid'])) {
    die('Access denied. Please log in.');
}
```

**Customize the roles check in the files by modifying:**
```php
$allowed_users = ['admin', 'billing', 'finance'];
```

## Usage Instructions

### Step 1: Create Database Tables

Run the SQL script:
```bash
mysql -u your_user -p your_database < modules/pms/zBackPre/stripe_integration/refund_tables.sql
```

### Step 2: Access Refund Request Page

Navigate to:
```
http://yourdomain/modules/pms/zBackPre/stripe_integration/refund_request.php
```

**Features:**
- Search payments by ID, patient ID, or date range
- View payment details
- Select full or partial refund
- Choose refund reason
- Add optional notes

### Step 3: Review Refund Requests

Navigate to:
```
http://yourdomain/modules/pms/zBackPre/stripe_integration/refund_review.php
```

**Dashboard shows:**
- Statistics (pending, approved, today's completed, total refunded)
- Pending requests requiring action
- Approved requests ready to process
- Recent activity

**Actions:**
- **Approve** - Marks request as approved
- **Reject** - Marks request as rejected (requires reason)
- **Process** - Sends refund to Stripe API

### Step 4: Processing Refunds

When you click "Process Refund":
1. System connects to Stripe API
2. Creates refund using charge ID and amount
3. Updates refund request to `completed`
4. Updates original payment record with refund details
5. Logs the transaction

## API Integration

The refund processing uses Stripe's Refund API:

```php
$refund = \Stripe\Refund::create([
    'charge' => $charge_id,
    'amount' => $amount_in_cents,
    'reason' => 'requested_by_customer',
    'metadata' => [
        'refund_request_id' => $request_id,
        'payment_id' => $payment_id
    ]
]);
```

## Webhook Integration

The webhook handler ([webhooks.php](webhooks.php)) automatically handles:
- `charge.refunded` → Updates payment status to `refunded` or `partially_refunded`
- Tracks refunds processed outside this system

## Refund Limits

**Stripe Limits:**
- Refunds can be processed within 120 days (180 days for some cards)
- After this period, refunds must be processed outside Stripe

**Best Practices:**
- Process refunds promptly
- Document reasons clearly
- Keep audit trail
- Update claim statuses accordingly

## Query Examples

### View all refund requests
```sql
SELECT rr.*, p.patient_name, p.amount as original_amount
FROM stripe_refund_requests rr
INNER JOIN stripe_payments p ON rr.payment_id = p.id
ORDER BY rr.created_at DESC;
```

### Get refund statistics
```sql
SELECT
    status,
    COUNT(*) as count,
    SUM(refund_amount) as total_amount
FROM stripe_refund_requests
GROUP BY status;
```

### Check payment refund status
```sql
SELECT
    id,
    amount,
    refunded_amount,
    refundable_amount,
    total_refunds,
    last_refund_date
FROM stripe_payments
WHERE id = [payment_id];
```

### Track refunds per payment
```sql
SELECT
    p.id as payment_id,
    p.amount as original_amount,
    p.refunded_amount,
    rr.id as refund_id,
    rr.refund_amount,
    rr.status as refund_status,
    rr.reason
FROM stripe_payments p
LEFT JOIN stripe_refund_requests rr ON p.id = rr.payment_id
WHERE p.id = [payment_id];
```

## Error Handling

### Common Errors

| Error | Cause | Solution |
|-------|-------|----------|
| "No charge ID found" | Payment not processed through Stripe | Check stripe_charge_id in payments table |
| "Refund amount exceeds refundable" | Trying to refund more than available | Calculate: original - already_refunded |
| "Charge has already been refunded" | Full refund already processed | Check payment's refunded_amount |

### Stripe API Errors

| Error Code | Description |
|------------|-------------|
| `charge_already_refunded` | Charge already fully refunded |
| `refund_amount_exceeds_original` | Refund amount too high |
| `invalid_charge_id` | Charge ID not found |
| `refund_not_allowed` | Refund not permitted (expired, etc.) |

## Testing Refunds

### Test Scenario 1: Full Refund
1. Create a test payment
2. Request full refund
3. Approve and process
4. Verify: status='completed', payment updated

### Test Scenario 2: Partial Refund
1. Create a test payment for $100
2. Request partial refund of $50
3. Approve and process
4. Verify: $50 refunded, $50 refundable remains
5. Request another partial refund of $25
6. Verify: $75 total refunded, $25 refundable remains

### Test Scenario 3: Rejection
1. Create refund request
2. Reject with reason
3. Verify: status='rejected', reason updated

## Security Considerations

1. **Access Control** - Only authorized billing staff
2. **Audit Trail** - All actions logged with user/timestamp
3. **Double Verification** - Review before processing
4. **Stripe Verification** - Webhook signatures verified
5. **Database Constraints** - Foreign keys ensure data integrity

## Customization Points

### Add Your Own Access Control

In both refund files, replace:
```php
if (!isset($_SESSION['sess_login_userid'])) {
    die('Access denied. Please log in.');
}
```

With your custom authentication logic.

### Update Claim Status on Refund

Add to refund_review.php processing section:
```php
// Update claim status when refund is completed
$claim_update = "UPDATE patient_claims
    SET refund_status = 'refunded',
        refund_amount = refunded_amount + '$refund_amount'
    WHERE payment_id = '$payment_id'";
// $db->Execute($claim_update);
```

### Send Email Notifications

Add email notifications for:
- Refund requested
- Refund approved
- Refund completed

## Best Practices

1. **Always document reasons** - Helps with analytics
2. **Review before processing** - Catches errors
3. **Monitor webhook events** - Ensures Stripe sync
4. **Regular audits** - Review refund patterns
5. **Train staff** - Proper workflow usage

## Support

For issues or questions:
- Stripe Refund API: https://stripe.com/docs/refunds
- Your billing department procedures
- System administrator
