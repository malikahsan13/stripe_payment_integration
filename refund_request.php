<?php
/**
 * Refund Request Form
 *
 * This page allows billing staff to:
 * 1. Search for payments by various criteria
 * 2. View payment details
 * 3. Create refund requests (full or partial)
 * 4. Add reason and notes for the refund
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once('roots.php');
require_once($root_path.'include/inc_db_makelink.php');
require_once('stripe_config.php');

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Note: Authorization disabled for POC
// TODO: Add authorization for production

global $db;

// Get search parameters (from both GET and POST)
$search_payment_id = trim($_REQUEST['payment_id'] ?? '');
$search_patient_id = trim($_REQUEST['patient_id'] ?? '');
$search_date_from = trim($_REQUEST['date_from'] ?? '');
$search_date_to = trim($_REQUEST['date_to'] ?? '');

// Get refund reasons from database
try {
    $reasons_query = "SELECT * FROM stripe_refund_reasons WHERE is_active = 1 ORDER BY display_order ASC";
    $reasons_result = $db->Execute($reasons_query);
    $refund_reasons = [];

    if ($reasons_result) {
        while ($row = $reasons_result->FetchRow()) {
            $refund_reasons[] = $row;
        }
    }

    // If no reasons found, add default ones
    if (empty($refund_reasons)) {
        $refund_reasons = [
            ['reason_code' => 'duplicate', 'reason_text' => 'Duplicate payment'],
            ['reason_code' => 'service_cancelled', 'reason_text' => 'Service cancelled'],
            ['reason_code' => 'customer_request', 'reason_text' => 'Customer request'],
            ['reason_code' => 'other', 'reason_text' => 'Other']
        ];
    }
} catch (Exception $e) {
    // If tables don't exist, use default reasons
    $refund_reasons = [
        ['reason_code' => 'duplicate', 'reason_text' => 'Duplicate payment'],
        ['reason_code' => 'service_cancelled', 'reason_text' => 'Service cancelled'],
        ['reason_code' => 'customer_request', 'reason_text' => 'Customer request'],
        ['reason_code' => 'other', 'reason_text' => 'Other']
    ];
}

// Handle form submission
$error_message = '';
$success_message = '';
$payment_for_refund = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
error_log("Form submitted with action: $action");
    if ($action === 'search') {
        error_log("Search parameters - Payment ID: $search_payment_id, Patient ID: $search_patient_id, Date From: $search_date_from, Date To: $search_date_to");
        // Search for payments
        $where_conditions = ['status = "succeeded"'];

        if (!empty($search_payment_id)) {
            $where_conditions[] = "id = '" . addslashes($search_payment_id) . "'";
        }
        if (!empty($search_patient_id)) {
            $where_conditions[] = "patient_id = '" . addslashes($search_patient_id) . "'";
        }
        if (!empty($search_date_from)) {
            $where_conditions[] = "created_at >= '" . addslashes($search_date_from) . "'";
        }
        if (!empty($search_date_to)) {
            $where_conditions[] = "created_at <= '" . addslashes($search_date_to) . " 23:59:59'";
        }

        $where_clause = implode(' AND ', $where_conditions);
        $search_query = "SELECT * FROM stripe_payments WHERE $where_clause ORDER BY created_at DESC LIMIT 50";
        error_log("Executing search query: $search_query");
        $search_result = $db->Execute($search_query);

    } elseif ($action === 'create_refund') {
        // Create refund request
        $payment_id = intval($_POST['payment_id'] ?? 0);
        $refund_amount = floatval($_POST['refund_amount'] ?? 0);
        $refund_type = $_POST['refund_type'] ?? 'partial';
        $reason_code = $_POST['reason_code'] ?? '';
        $notes = trim($_POST['notes'] ?? '');
        $requested_by = $_SESSION['sess_login_userid'] ?? 'system';

        // Validate
        if ($payment_id <= 0) {
            $error_message = 'Invalid payment ID.';
        } else {
            // Get payment details
            $payment_query = "SELECT * FROM stripe_payments WHERE id = '$payment_id' AND status = 'succeeded'";
            $payment_result = $db->Execute($payment_query);

            if ($payment_result && $payment_result->RecordCount() > 0) {
                $payment_data = $payment_result->FetchRow();

                // Calculate refundable amount
                $original_amount = floatval($payment_data['amount']);
                $already_refunded = floatval($payment_data['refunded_amount'] ?? 0);
                $refundable_amount = $original_amount - $already_refunded;

                if ($refund_type === 'full') {
                    $refund_amount = $refundable_amount;
                }

                if ($refund_amount <= 0) {
                    $error_message = 'Refund amount must be greater than zero.';
                } elseif ($refund_amount > $refundable_amount) {
                    $error_message = 'Refund amount cannot exceed refundable amount ($' . number_format($refundable_amount, 2) . ').';
                } else {
                    // Check if charge_id exists (required for Stripe refund)
                    if (empty($payment_data['stripe_charge_id'])) {
                        $error_message = 'Cannot refund: No charge ID found for this payment. It may have been refunded already or processed outside Stripe.';
                    } else {
                        // Insert refund request
                        $reason_text = '';
                        foreach ($refund_reasons as $reason) {
                            if ($reason['reason_code'] === $reason_code) {
                                $reason_text = $reason['reason_text'];
                                break;
                            }
                        }

                        $refund_insert = "INSERT INTO stripe_refund_requests (
                            payment_id,
                            payment_intent_id,
                            charge_id,
                            original_amount,
                            refund_amount,
                            refund_type,
                            reason,
                            notes,
                            status,
                            requested_by,
                            created_at
                        ) VALUES (
                            '$payment_id',
                            '" . addslashes($payment_data['stripe_payment_intent_id']) . "',
                            '" . addslashes($payment_data['stripe_charge_id']) . "',
                            '$original_amount',
                            '$refund_amount',
                            '$refund_type',
                            '" . addslashes($reason_text) . "',
                            '" . addslashes($notes) . "',
                            'pending',
                            '" . addslashes($requested_by) . "',
                            NOW()
                        )";

                        if ($db->Execute($refund_insert)) {
                            $success_message = 'Refund request created successfully! It will be reviewed by billing staff.';
                            logStripeActivity('Refund request created', [
                                'payment_id' => $payment_id,
                                'refund_amount' => $refund_amount,
                                'requested_by' => $requested_by
                            ]);
                        } else {
                            $error_message = 'Failed to create refund request. Please try again.';
                        }
                    }
                }
            } else {
                $error_message = 'Payment not found or not eligible for refund.';
            }
        }
    } elseif ($action === 'load_payment') {
        // Load payment for refund
        $payment_id = intval($_POST['payment_id'] ?? 0);

        if ($payment_id > 0) {
            $payment_query = "SELECT * FROM stripe_payments WHERE id = '$payment_id'";
            $payment_result = $db->Execute($payment_query);

            if ($payment_result && $payment_result->RecordCount() > 0) {
                $payment_for_refund = $payment_result->FetchRow();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Request - Stripe Integration</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .header h1 {
            color: #333;
            font-size: 24px;
            margin-bottom: 5px;
        }

        .header p {
            color: #666;
            font-size: 14px;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .alert-error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }

        .alert-success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }

        .card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }

        .card-header {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            font-weight: 600;
            color: #333;
        }

        .card-body {
            padding: 20px;
        }

        .search-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group label {
            color: #666;
            font-size: 14px;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .btn-primary {
            background: #667eea;
            color: white;
        }

        .btn-primary:hover {
            background: #5568d3;
        }

        .btn-success {
            background: #38ef7d;
            color: white;
        }

        .btn-success:hover {
            background: #2ed166;
        }

        .btn-secondary {
            background: #f5f5f5;
            color: #333;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th,
        table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        table th {
            background: #f9f9f9;
            font-weight: 600;
            color: #555;
            font-size: 13px;
            text-transform: uppercase;
        }

        table td {
            font-size: 14px;
            color: #666;
        }

        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }

        .status-succeeded {
            background: #d4edda;
            color: #155724;
        }

        .status-pending {
            background: #fff3cd;
            color: #856404;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .refund-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }

        .refund-modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 10px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-header h2 {
            color: #333;
            font-size: 20px;
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 24px;
            cursor: pointer;
            color: #999;
        }

        .modal-body {
            padding: 20px;
        }

        .payment-summary {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .payment-summary-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
        }

        .payment-summary-row label {
            color: #666;
            font-size: 14px;
        }

        .payment-summary-row .value {
            font-weight: 600;
            color: #333;
        }

        .payment-summary-row .value.amount {
            color: #38ef7d;
            font-size: 16px;
        }

        .radio-group {
            display: flex;
            gap: 20px;
            margin: 10px 0;
        }

        .radio-option {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .nav-tabs {
            display: flex;
            gap: 5px;
            margin-bottom: 20px;
        }

        .nav-tab {
            padding: 10px 20px;
            background: #f5f5f5;
            border: none;
            border-radius: 6px 6px 0 0;
            cursor: pointer;
            font-weight: 500;
            color: #666;
        }

        .nav-tab.active {
            background: white;
            color: #667eea;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💳 Refund Management</h1>
            <p>Process refunds for patient payments</p>
        </div>

        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success">
                <?php echo htmlspecialchars($success_message); ?>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">Search Payments</div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="search">
                    <div class="search-form">
                        <div class="form-group">
                            <label>Payment ID</label>
                            <input type="text" name="payment_id" value="<?php echo htmlspecialchars($search_payment_id); ?>" placeholder="Enter payment ID">
                        </div>
                        <div class="form-group">
                            <label>Patient ID</label>
                            <input type="text" name="patient_id" value="<?php echo htmlspecialchars($search_patient_id); ?>" placeholder="Enter patient ID">
                        </div>
                        <div class="form-group">
                            <label>Date From</label>
                            <input type="date" name="date_from" value="<?php echo htmlspecialchars($search_date_from); ?>">
                        </div>
                        <div class="form-group">
                            <label>Date To</label>
                            <input type="date" name="date_to" value="<?php echo htmlspecialchars($search_date_to); ?>">
                        </div>
                        <div class="form-group" style="justify-content: flex-end;">
                            <button type="submit" class="btn btn-primary">Search Payments</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if (isset($search_result) && $search_result && $search_result->RecordCount() > 0): ?>
            <div class="card">
                <div class="card-header">Search Results (<?php echo $search_result->RecordCount(); ?> found)</div>
                <div class="card-body" style="padding: 0;">
                    <table>
                        <thead>
                            <tr>
                                <th>Payment ID</th>
                                <th>Patient</th>
                                <th>Email</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $search_result->FetchRow()): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($row['id']); ?></td>
                                    <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                    <td><?php echo htmlspecialchars($row['patient_email']); ?></td>
                                    <td>$<?php echo number_format($row['amount'], 2); ?></td>
                                    <td><span class="status-badge status-<?php echo htmlspecialchars($row['status']); ?>"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                    <td><?php echo date('M j, Y', strtotime($row['created_at'])); ?></td>
                                    <td>
                                        <button onclick="loadPaymentForRefund(<?php echo $row['id']; ?>)" class="btn btn-secondary" style="padding: 6px 12px; font-size: 13px;">Request Refund</button>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif (isset($search_result)): ?>
            <div class="card">
                <div class="card-body" style="text-align: center; color: #999;">
                    No payments found matching your criteria.
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Refund Modal -->
    <div id="refundModal" class="refund-modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Request Refund</h2>
                <button onclick="closeRefundModal()" class="modal-close">&times;</button>
            </div>
            <div class="modal-body">
                <?php if ($payment_for_refund): ?>
                    <div class="payment-summary">
                        <div class="payment-summary-row">
                            <label>Patient:</label>
                            <span class="value"><?php echo htmlspecialchars($payment_for_refund['patient_name']); ?></span>
                        </div>
                        <div class="payment-summary-row">
                            <label>Original Amount:</label>
                            <span class="value">$<?php echo number_format($payment_for_refund['amount'], 2); ?></span>
                        </div>
                        <div class="payment-summary-row">
                            <label>Already Refunded:</label>
                            <span class="value">$<?php echo number_format($payment_for_refund['refunded_amount'] ?? 0, 2); ?></span>
                        </div>
                        <div class="payment-summary-row">
                            <label>Refundable Amount:</label>
                            <span class="value amount">$<?php echo number_format(($payment_for_refund['amount'] - ($payment_for_refund['refunded_amount'] ?? 0)), 2); ?></span>
                        </div>
                    </div>

                    <form method="POST" action="" id="refundForm">
                        <input type="hidden" name="action" value="create_refund">
                        <input type="hidden" name="payment_id" value="<?php echo $payment_for_refund['id']; ?>">

                        <div class="form-group">
                            <label>Refund Type *</label>
                            <div class="radio-group">
                                <div class="radio-option">
                                    <input type="radio" name="refund_type" value="full" id="refundFull" onchange="toggleRefundAmount()">
                                    <label for="refundFull">Full Refund ($<?php echo number_format(($payment_for_refund['amount'] - ($payment_for_refund['refunded_amount'] ?? 0)), 2); ?>)</label>
                                </div>
                                <div class="radio-option">
                                    <input type="radio" name="refund_type" value="partial" id="refundPartial" checked onchange="toggleRefundAmount()">
                                    <label for="refundPartial">Partial Refund</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-group" id="refundAmountGroup" style="margin-top: 15px;">
                            <label>Refund Amount *</label>
                            <input type="number" name="refund_amount" id="refundAmount" step="0.01" min="0.01" max="<?php echo ($payment_for_refund['amount'] - ($payment_for_refund['refunded_amount'] ?? 0)); ?>" value="" placeholder="Enter amount to refund">
                            <small style="color: #999;">Maximum: $<?php echo number_format(($payment_for_refund['amount'] - ($payment_for_refund['refunded_amount'] ?? 0)), 2); ?></small>
                        </div>

                        <div class="form-group" style="margin-top: 15px;">
                            <label>Reason for Refund *</label>
                            <select name="reason_code" required>
                                <option value="">Select a reason...</option>
                                <?php foreach ($refund_reasons as $reason): ?>
                                    <option value="<?php echo htmlspecialchars($reason['reason_code']); ?>"><?php echo htmlspecialchars($reason['reason_text']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="margin-top: 15px;">
                            <label>Notes (Optional)</label>
                            <textarea name="notes" placeholder="Add any additional details about this refund..."></textarea>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button onclick="closeRefundModal()" class="btn btn-secondary">Cancel</button>
                <button onclick="document.getElementById('refundForm').submit()" class="btn btn-success">Submit Refund Request</button>
            </div>
        </div>
    </div>

    <script>
        function loadPaymentForRefund(paymentId) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '';

            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'action';
            actionInput.value = 'load_payment';

            const paymentIdInput = document.createElement('input');
            paymentIdInput.type = 'hidden';
            paymentIdInput.name = 'payment_id';
            paymentIdInput.value = paymentId;

            form.appendChild(actionInput);
            form.appendChild(paymentIdInput);
            document.body.appendChild(form);
            form.submit();
        }

        function closeRefundModal() {
            document.getElementById('refundModal').classList.remove('active');
        }

        function toggleRefundAmount() {
            const isFull = document.getElementById('refundFull').checked;
            const amountGroup = document.getElementById('refundAmountGroup');
            const amountInput = document.getElementById('refundAmount');

            if (isFull) {
                amountGroup.style.display = 'none';
            } else {
                amountGroup.style.display = 'flex';
                amountInput.required = true;
            }
        }

        // Show modal if payment is loaded
        <?php if ($payment_for_refund): ?>
            document.getElementById('refundModal').classList.add('active');
        <?php endif; ?>

        // Form validation (only if form exists)
        const refundForm = document.getElementById('refundForm');
        if (refundForm) {
            refundForm.addEventListener('submit', function(e) {
                const refundType = document.querySelector('input[name="refund_type"]:checked').value;
                const refundAmount = document.getElementById('refundAmount').value;

                if (refundType === 'partial' && (!refundAmount || parseFloat(refundAmount) <= 0)) {
                    e.preventDefault();
                    alert('Please enter a valid refund amount.');
                    return false;
                }
            });
        }
    </script>
</body>
</html>
