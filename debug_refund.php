<?php
/**
 * Debug page for refund_request.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<html><head><title>Debug Refund</title></head><body>";
echo "<h2>Refund Request Debug</h2>";

// Step 1: Test roots.php
echo "<h3>Step 1: Loading roots.php...</h3>";
try {
    require_once('roots.php');
    global $root_path;
    echo "<p style='color:green'>✓ roots.php loaded. root_path = " . htmlspecialchars($root_path) . "</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    die();
}

// Step 2: Test database connection
echo "<h3>Step 2: Testing database connection...</h3>";
try {
    require_once($root_path.'include/inc_db_makelink.php');
    global $db;
    if ($db) {
        echo "<p style='color:green'>✓ Database connected</p>";
    } else {
        echo "<p style='color:red'>✗ Database object is null</p>";
        die();
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    die();
}

// Step 3: Test stripe_config.php
echo "<h3>Step 3: Loading stripe_config.php...</h3>";
try {
    require_once('stripe_config.php');
    echo "<p style='color:green'>✓ stripe_config.php loaded</p>";
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Failed: " . htmlspecialchars($e->getMessage()) . "</p>";
    die();
}

// Step 4: Check stripe_refund_reasons table
echo "<h3>Step 4: Checking stripe_refund_reasons table...</h3>";
try {
    $check_query = "SHOW TABLES LIKE 'stripe_refund_reasons'";
    $check_result = $db->Execute($check_query);

    if ($check_result && $check_result->RecordCount() > 0) {
        echo "<p style='color:green'>✓ Table exists</p>";

        // Try to query it
        $reasons_query = "SELECT * FROM stripe_refund_reasons WHERE is_active = 1 ORDER BY display_order ASC";
        $reasons_result = $db->Execute($reasons_query);

        if ($reasons_result) {
            echo "<p style='color:green'>✓ Query successful. Found " . $reasons_result->RecordCount() . " reasons.</p>";
            echo "<ul>";
            while ($row = $reasons_result->FetchRow()) {
                echo "<li>" . htmlspecialchars($row['reason_code']) . " - " . htmlspecialchars($row['reason_text']) . "</li>";
            }
            echo "</ul>";
        } else {
            echo "<p style='color:red'>✗ Query failed: " . htmlspecialchars($db->ErrorMsg()) . "</p>";
        }
    } else {
        echo "<p style='color:red'>✗ Table does NOT exist</p>";
        echo "<p><strong>Action:</strong> Run the refund_tables.sql file to create the table.</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
}

// Step 5: Check stripe_payments table
echo "<h3>Step 5: Checking stripe_payments table...</h3>";
try {
    $check_query = "SHOW TABLES LIKE 'stripe_payments'";
    $check_result = $db->Execute($check_query);

    if ($check_result && $check_result->RecordCount() > 0) {
        echo "<p style='color:green'>✓ Table exists</p>";

        // Check for refunded_amount column
        $describe_query = "DESCRIBE stripe_payments";
        $describe_result = $db->Execute($describe_query);

        $has_refunded_amount = false;
        while ($row = $describe_result->FetchRow()) {
            if ($row['Field'] === 'refunded_amount') {
                $has_refunded_amount = true;
                break;
            }
        }

        if ($has_refunded_amount) {
            echo "<p style='color:green'>✓ refunded_amount column exists</p>";
        } else {
            echo "<p style='color:red'>✗ refunded_amount column missing</p>";
            echo "<p><strong>Action:</strong> Run the refund_tables.sql to add this column.</p>";
        }
    } else {
        echo "<p style='color:red'>✗ Table does NOT exist</p>";
    }
} catch (Exception $e) {
    echo "<p style='color:red'>✗ Exception: " . htmlspecialchars($e->getMessage()) . "</p>";
}

echo "<hr>";
echo "<p><strong>Debug completed at:</strong> " . date('Y-m-d H:i:s') . "</p>";
echo "</body></html>";
?>
