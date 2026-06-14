<?php
/**
 * Database Connection Test
 *
 * Run this file to check:
 * 1. Database connection
 * 2. Table existence
 * 3. Write permissions
 */

require_once('roots.php');
require_once($root_path.'include/inc_db_makelink.php');

echo "<html><head><title>Stripe DB Test</title></head><body>";
echo "<h2>Stripe Integration Database Test</h2>";

// Test 1: Database Connection
echo "<h3>1. Testing Database Connection...</h3>";
if ($db) {
    echo "<p style='color: green;'>✓ Database connection successful</p>";
} else {
    echo "<p style='color: red;'>✗ Database connection failed</p>";
    die("Cannot proceed without database connection.");
}

// Test 2: Check if stripe_payments table exists
echo "<h3>2. Checking stripe_payments table...</h3>";
$tables_query = "SHOW TABLES LIKE 'stripe_payments'";
$tables_result = $db->Execute($tables_query);

if ($tables_result && $tables_result->RecordCount() > 0) {
    echo "<p style='color: green;'>✓ stripe_payments table exists</p>";

    // Show table structure
    $structure_query = "DESCRIBE stripe_payments";
    $structure_result = $db->Execute($structure_query);

    echo "<table border='1' cellpadding='5' style='margin: 10px 0;'>";
    echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    while ($row = $structure_result->FetchRow()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['Field']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Type']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Null']) . "</td>";
        echo "<td>" . htmlspecialchars($row['Key']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";

    // Show record count
    $count_query = "SELECT COUNT(*) as count FROM stripe_payments";
    $count_result = $db->Execute($count_query);
    $count_row = $count_result->FetchRow();
    echo "<p><strong>Total Records:</strong> " . $count_row['count'] . "</p>";

} else {
    echo "<p style='color: red;'>✗ stripe_payments table does NOT exist</p>";
    echo "<p><strong>Action Required:</strong> Run the SQL script to create tables:</p>";
    echo "<pre>mysql -u your_user -p your_database < database_table.sql</pre>";
}

// Test 3: Check if stripe_payment_logs table exists
echo "<h3>3. Checking stripe_payment_logs table...</h3>";
$logs_query = "SHOW TABLES LIKE 'stripe_payment_logs'";
$logs_result = $db->Execute($logs_query);

if ($logs_result && $logs_result->RecordCount() > 0) {
    echo "<p style='color: green;'>✓ stripe_payment_logs table exists</p>";
} else {
    echo "<p style='color: red;'>✗ stripe_payment_logs table does NOT exist</p>";
}

// Test 4: Check if stripe_webhook_events table exists
echo "<h3>4. Checking stripe_webhook_events table...</h3>";
$webhooks_query = "SHOW TABLES LIKE 'stripe_webhook_events'";
$webhooks_result = $db->Execute($webhooks_query);

if ($webhooks_result && $webhooks_result->RecordCount() > 0) {
    echo "<p style='color: green;'>✓ stripe_webhook_events table exists</p>";
} else {
    echo "<p style='color: red;'>✗ stripe_webhook_events table does NOT exist</p>";
}

// Test 5: Try to insert a test record
echo "<h3>5. Testing Write Permissions (Insert Test Record)...</h3>";
try {
    $test_query = "INSERT INTO stripe_payments (
        patient_id,
        patient_name,
        patient_email,
        amount,
        currency,
        status,
        description,
        created_at
    ) VALUES (
        'TEST001',
        'Test Patient',
        'test@example.com',
        '10.00',
        'USD',
        'test',
        'Test payment record',
        NOW()
    )";

    $test_result = $db->Execute($test_query);

    if ($test_result) {
        $test_id = $db->Insert_ID();
        echo "<p style='color: green;'>✓ Write permission OK (Test record ID: $test_id)</p>";

        // Clean up test record
        $delete_query = "DELETE FROM stripe_payments WHERE id = '$test_id'";
        $db->Execute($delete_query);
        echo "<p style='color: gray;'>→ Test record deleted</p>";
    } else {
        echo "<p style='color: red;'>✗ Insert failed: " . htmlspecialchars($db->ErrorMsg()) . "</p>";
    }
} catch (Exception $e) {
    echo "<p style='color: red;'>✗ Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Test 6: Check recent entries
echo "<h3>6. Recent Payment Records (if any):</h3>";
$recent_query = "SELECT * FROM stripe_payments ORDER BY id DESC LIMIT 5";
$recent_result = $db->Execute($recent_query);

if ($recent_result && $recent_result->RecordCount() > 0) {
    echo "<table border='1' cellpadding='5' style='margin: 10px 0;'>";
    echo "<tr><th>ID</th><th>Patient ID</th><th>Amount</th><th>Status</th><th>Created</th></tr>";
    while ($row = $recent_result->FetchRow()) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($row['id']) . "</td>";
        echo "<td>" . htmlspecialchars($row['patient_id']) . "</td>";
        echo "<td>$" . htmlspecialchars($row['amount']) . "</td>";
        echo "<td>" . htmlspecialchars($row['status']) . "</td>";
        echo "<td>" . htmlspecialchars($row['created_at']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p style='color: gray;'>No payment records found</p>";
}

echo "<hr>";
echo "<p><strong>Test completed at:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "</body></html>";
?>
