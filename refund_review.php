<?php
/**
 * Refund Review Page
 *
 * This page allows billing staff to:
 * 1. View all pending refund requests
 * 2. Review request details
 * 3. Approve or reject refund requests
 * 4. Process approved refunds via Stripe
 */

require_once('roots.php');
require_once($root_path.'include/inc_db_makelink.php');
require_once('stripe_config.php');

// Include Stripe PHP library
require_once(__DIR__ . '/stripe-php/init.php');

// Set Stripe API key
\Stripe\Stripe::setApiKey(getStripeSecretKey());

// Note: Authorization disabled for POC
// TODO: Add authorization for production

global $db;

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$success_message = '';
$error_message = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($action) {
        case 'approve':
            $request_id = intval($_POST['request_id'] ?? 0);
            $reviewed_by = $_SESSION['sess_login_userid'] ?? 'system';

            if ($request_id > 0) {
                // Update request status to approved
                $update_query = "UPDATE stripe_refund_requests
                    SET status = 'approved',
                        reviewed_by = '" . addslashes($reviewed_by) . "',
                        reviewed_at = NOW(),
                        updated_at = NOW()
                    WHERE id = '$request_id'";

                if ($db->Execute($update_query)) {
                    $success_message = 'Refund request approved successfully.';
                    logStripeActivity('Refund request approved', ['request_id' => $request_id]);
                } else {
                    $error_message = 'Failed to approve request.';
                }
            }
            break;

        case 'reject':
            $request_id = intval($_POST['request_id'] ?? 0);
            $rejection_reason = trim($_POST['rejection_reason'] ?? '');
            $reviewed_by = $_SESSION['sess_login_userid'] ?? 'system';

            if ($request_id > 0) {
                if (empty($rejection_reason)) {
                    $error_message = 'Please provide a reason for rejection.';
                } else {
                    // Update request status to rejected
                    $update_query = "UPDATE stripe_refund_requests
                        SET status = 'rejected',
                            reason = CONCAT(reason, ' | Rejected: " . addslashes($rejection_reason) . "'),
                            reviewed_by = '" . addslashes($reviewed_by) . "',
                            reviewed_at = NOW(),
                            updated_at = NOW()
                        WHERE id = '$request_id'";

                    if ($db->Execute($update_query)) {
                        $success_message = 'Refund request rejected.';
                        logStripeActivity('Refund request rejected', [
                            'request_id' => $request_id,
                            'reason' => $rejection_reason
                        ]);
                    } else {
                        $error_message = 'Failed to reject request.';
                    }
                }
            }
            break;

        case 'process':
            $request_id = intval($_POST['request_id'] ?? 0);

            if ($request_id > 0) {
                // Get refund request details
                $request_query = "SELECT * FROM stripe_refund_requests WHERE id = '$request_id'";
                $request_result = $db->Execute($request_query);

                if ($request_result && $request_result->RecordCount() > 0) {
                    $refund_request = $request_result->FetchRow();

                    if ($refund_request['status'] !== 'approved') {
                        $error_message = 'Refund request must be approved before processing.';
                    } else {
                        try {
                            // Process refund via Stripe
                            $refund_params = [
                                'charge' => $refund_request['charge_id'],
                                'amount' => formatAmountForStripe($refund_request['refund_amount']),
                                'reason' => 'requested_by_customer',
                                'metadata' => [
                                    'refund_request_id' => $request_id,
                                    'payment_id' => $refund_request['payment_id'],
                                    'original_amount' => $refund_request['original_amount']
                                ]
                            ];

                            $refund = \Stripe\Refund::create($refund_params);

                            // Update refund request
                            $stripe_refund_id = "'" . addslashes($refund->id) . "'";
                            $update_query = "UPDATE stripe_refund_requests
                                SET status = 'completed',
                                    stripe_refund_id = $stripe_refund_id,
                                    processed_at = NOW(),
                                    updated_at = NOW()
                                WHERE id = '$request_id'";

                            $db->Execute($update_query);

                            // Update original payment record
                            $payment_id = $refund_request['payment_id'];
                            $refund_amount = $refund_request['refund_amount'];

                            $update_payment = "UPDATE stripe_payments
                                SET refunded_amount = refunded_amount + '$refund_amount',
                                    refundable_amount = amount - (refunded_amount + '$refund_amount'),
                                    total_refunds = total_refunds + 1,
                                    last_refund_date = NOW(),
                                    updated_at = NOW()
                                WHERE id = '$payment_id'";

                            $db->Execute($update_payment);

                            // Log the refund
                            logStripeActivity('Refund processed successfully', [
                                'request_id' => $request_id,
                                'stripe_refund_id' => $refund->id,
                                'amount' => $refund_amount
                            ]);

                            $success_message = 'Refund processed successfully via Stripe!';
                        } catch (\Stripe\Exception\ApiErrorException $e) {
                            $error_message = 'Stripe Error: ' . $e->getMessage();

                            // Update request as failed
                            $failure_reason = "'" . addslashes($e->getMessage()) . "'";
                            $update_query = "UPDATE stripe_refund_requests
                                SET status = 'failed',
                                    failure_reason = $failure_reason,
                                    updated_at = NOW()
                                WHERE id = '$request_id'";

                            $db->Execute($update_query);

                            logStripeActivity('Refund processing failed', [
                                'request_id' => $request_id,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }
                } else {
                    $error_message = 'Refund request not found.';
                }
            }
            break;
    }
}

// Get pending refund requests
$pending_query = "SELECT rr.*, p.patient_name, p.patient_email, p.amount as original_payment_amount
                  FROM stripe_refund_requests rr
                  INNER JOIN stripe_payments p ON rr.payment_id = p.id
                  WHERE rr.status = 'pending'
                  ORDER BY rr.created_at DESC";
$pending_result = $db->Execute($pending_query);

// Get approved refund requests
$approved_query = "SELECT rr.*, p.patient_name, p.patient_email, p.amount as original_payment_amount
                   FROM stripe_refund_requests rr
                   INNER JOIN stripe_payments p ON rr.payment_id = p.id
                   WHERE rr.status = 'approved'
                   ORDER BY rr.reviewed_at DESC";
$approved_result = $db->Execute($approved_query);

// Get recent completed refunds
$completed_query = "SELECT rr.*, p.patient_name, p.patient_email
                    FROM stripe_refund_requests rr
                    INNER JOIN stripe_payments p ON rr.payment_id = p.id
                    WHERE rr.status IN ('completed', 'failed')
                    ORDER BY rr.updated_at DESC
                    LIMIT 10";
$completed_result = $db->Execute($completed_query);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Refund Review - Stripe Integration</title>
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
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            color: #333;
            font-size: 24px;
        }

        .header-nav a {
            color: #667eea;
            text-decoration: none;
            margin-left: 20px;
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
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            color: #333;
            font-size: 18px;
        }

        .card-body {
            padding: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        table th,
        table td {
            padding: 12px 15px;
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

        .status-pending { background: #fff3cd; color: #856404; }
        .status-approved { background: #d1ecf1; color: #0c5460; }
        .status-completed { background: #d4edda; color: #155724; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-failed { background: #f5c6cb; color: #721c24; }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-weight: 600;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s ease;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }

        .btn-success { background: #38ef7d; color: white; }
        .btn-success:hover { background: #2ed166; }

        .btn-danger { background: #e74c3c; color: white; }
        .btn-danger:hover { background: #c0392b; }

        .btn-primary { background: #667eea; color: white; }
        .btn-primary:hover { background: #5568d3; }

        .btn-secondary { background: #f5f5f5; color: #333; }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
        }

        .stat-card {
            background: #f9f9f9;
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #333;
        }

        .stat-label {
            font-size: 13px;
            color: #666;
            margin-top: 5px;
        }

        .empty-state {
            padding: 40px;
            text-align: center;
            color: #999;
        }

        .modal {
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

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: 10px;
            width: 100%;
            max-width: 500px;
        }

        .modal-header {
            padding: 20px;
            border-bottom: 1px solid #eee;
        }

        .modal-header h3 {
            color: #333;
        }

        .modal-body {
            padding: 20px;
        }

        .modal-footer {
            padding: 20px;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: #666;
            font-size: 14px;
        }

        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 6px;
            min-height: 80px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>💳 Refund Review Dashboard</h1>
            <div class="header-nav">
                <a href="refund_request.php">New Refund Request</a>
                <a href="payment_form.php">Payment Form</a>
            </div>
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

        <!-- Statistics -->
        <div class="card">
            <div class="stats-grid">
                <?php
                $stats_queries = [
                    'pending' => "SELECT COUNT(*) as count FROM stripe_refund_requests WHERE status = 'pending'",
                    'approved' => "SELECT COUNT(*) as count FROM stripe_refund_requests WHERE status = 'approved'",
                    'today_completed' => "SELECT COUNT(*) as count FROM stripe_refund_requests WHERE status = 'completed' AND DATE(processed_at) = CURDATE()",
                    'total_refunded' => "SELECT COALESCE(SUM(refund_amount), 0) as total FROM stripe_refund_requests WHERE status = 'completed'"
                ];

                foreach ($stats_queries as $key => $query) {
                    $result = $db->Execute($query);
                    $row = $result ? $result->FetchRow() : ['count' => 0];
                    $value = $key === 'total_refunded' ? '$' . number_format($row['total'] ?? 0, 2) : ($row['count'] ?? 0);
                    $label = ucwords(str_replace('_', ' ', $key));
                    echo "<div class='stat-card'>
                        <div class='stat-value'>$value</div>
                        <div class='stat-label'>$label</div>
                    </div>";
                }
                ?>
            </div>
        </div>

        <!-- Pending Requests -->
        <div class="card">
            <div class="card-header">
                <h2>⏳ Pending Refund Requests (<?php echo $pending_result ? $pending_result->RecordCount() : 0; ?>)</h2>
            </div>
            <div class="card-body">
                <?php if ($pending_result && $pending_result->RecordCount() > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Patient</th>
                                <th>Original Amount</th>
                                <th>Refund Amount</th>
                                <th>Type</th>
                                <th>Reason</th>
                                <th>Requested</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $pending_result->FetchRow()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                    <td>$<?php echo number_format($row['original_payment_amount'], 2); ?></td>
                                    <td><strong>$<?php echo number_format($row['refund_amount'], 2); ?></strong></td>
                                    <td><?php echo ucfirst($row['refund_type']); ?></td>
                                    <td><?php echo htmlspecialchars($row['reason']); ?></td>
                                    <td><?php echo date('M j, Y', strtotime($row['created_at'])); ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <button onclick="approveRefund(<?php echo $row['id']; ?>)" class="btn btn-success btn-sm">Approve</button>
                                            <button onclick="showRejectModal(<?php echo $row['id']; ?>)" class="btn btn-danger btn-sm">Reject</button>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">No pending refund requests</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Approved Requests -->
        <div class="card">
            <div class="card-header">
                <h2>✅ Approved Refund Requests (<?php echo $approved_result ? $approved_result->RecordCount() : 0; ?>)</h2>
            </div>
            <div class="card-body">
                <?php if ($approved_result && $approved_result->RecordCount() > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Patient</th>
                                <th>Refund Amount</th>
                                <th>Reviewed By</th>
                                <th>Reviewed At</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $approved_result->FetchRow()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                    <td><strong>$<?php echo number_format($row['refund_amount'], 2); ?></strong></td>
                                    <td><?php echo htmlspecialchars($row['reviewed_by']); ?></td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($row['reviewed_at'])); ?></td>
                                    <td>
                                        <form method="POST" action="" style="display: inline;">
                                            <input type="hidden" name="action" value="process">
                                            <input type="hidden" name="request_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('Process this refund via Stripe?')">Process Refund</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">No approved refund requests</div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="card">
            <div class="card-header">
                <h2>📋 Recent Activity</h2>
            </div>
            <div class="card-body">
                <?php if ($completed_result && $completed_result->RecordCount() > 0): ?>
                    <table>
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Patient</th>
                                <th>Refund Amount</th>
                                <th>Status</th>
                                <th>Updated At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($row = $completed_result->FetchRow()): ?>
                                <tr>
                                    <td>#<?php echo $row['id']; ?></td>
                                    <td><?php echo htmlspecialchars($row['patient_name']); ?></td>
                                    <td>$<?php echo number_format($row['refund_amount'], 2); ?></td>
                                    <td><span class="status-badge status-<?php echo $row['status']; ?>"><?php echo ucfirst($row['status']); ?></span></td>
                                    <td><?php echo date('M j, Y g:i A', strtotime($row['updated_at'])); ?></td>
                                </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="empty-state">No recent activity</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Reject Modal -->
    <div id="rejectModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Reject Refund Request</h3>
                <button onclick="closeRejectModal()" style="background:none;border:none;font-size:20px;cursor:pointer;">&times;</button>
            </div>
            <form method="POST" action="" id="rejectForm">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="request_id" id="rejectRequestId">
                <div class="modal-body">
                    <div class="form-group">
                        <label>Reason for Rejection *</label>
                        <textarea name="rejection_reason" required placeholder="Explain why this refund request is being rejected..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" onclick="closeRejectModal()" class="btn btn-secondary">Cancel</button>
                    <button type="submit" class="btn btn-danger">Reject Request</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function approveRefund(requestId) {
            if (confirm('Approve this refund request?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';

                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'action';
                actionInput.value = 'approve';

                const requestIdInput = document.createElement('input');
                requestIdInput.type = 'hidden';
                requestIdInput.name = 'request_id';
                requestIdInput.value = requestId;

                form.appendChild(actionInput);
                form.appendChild(requestIdInput);
                document.body.appendChild(form);
                form.submit();
            }
        }

        function showRejectModal(requestId) {
            document.getElementById('rejectRequestId').value = requestId;
            document.getElementById('rejectModal').classList.add('active');
        }

        function closeRejectModal() {
            document.getElementById('rejectModal').classList.remove('active');
        }

        // Auto-refresh every 30 seconds to show new requests
        setTimeout(function() {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
