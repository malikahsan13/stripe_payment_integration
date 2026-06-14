<?php
/**
 * Run this file to install the refund system tables
 * Access via: http://your-site/modules/pms/zBackPre/stripe_integration/install_refund_tables.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once('roots.php');
require_once($root_path.'include/inc_db_makelink.php');

global $db;

echo "<!DOCTYPE html><html><head><title>Install Refund Tables</title></head><body>";
echo "<h1>Installing Refund System Tables</h1>";
echo "<style>body{font-family:sans-serif;max-width:800px;margin:20px auto;padding:20px;}
.success{color:green;padding:10px;background:#e8ffe8;margin:10px 0;}
.error{color:red;padding:10px;background:#ffe8e8;margin:10px 0;}
.info{color:#666;padding:10px;background:#f0f0f0;margin:10px 0;}</style>";

// SQL commands to run
$sqlCommands = [
    // Create stripe_refund_requests table
    "CREATE TABLE IF NOT EXISTS `stripe_refund_requests` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `payment_id` INT(11) NOT NULL,
      `payment_intent_id` VARCHAR(255) NOT NULL,
      `charge_id` VARCHAR(255) DEFAULT NULL,
      `original_amount` DECIMAL(10,2) NOT NULL,
      `refund_amount` DECIMAL(10,2) NOT NULL,
      `refund_type` ENUM('full', 'partial') DEFAULT 'partial',
      `reason` VARCHAR(255) DEFAULT NULL,
      `notes` TEXT DEFAULT NULL,
      `status` ENUM('pending', 'approved', 'rejected', 'processing', 'completed', 'failed') DEFAULT 'pending',
      `requested_by` VARCHAR(100) DEFAULT NULL,
      `reviewed_by` VARCHAR(100) DEFAULT NULL,
      `reviewed_at` DATETIME DEFAULT NULL,
      `stripe_refund_id` VARCHAR(255) DEFAULT NULL,
      `failure_reason` TEXT DEFAULT NULL,
      `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
      `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      `processed_at` DATETIME DEFAULT NULL,
      PRIMARY KEY (`id`),
      KEY `idx_payment_id` (`payment_id`),
      KEY `idx_payment_intent_id` (`payment_intent_id`),
      KEY `idx_status` (`status`),
      KEY `idx_requested_by` (`requested_by`),
      KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // Create stripe_refund_reasons table
    "CREATE TABLE IF NOT EXISTS `stripe_refund_reasons` (
      `id` INT(11) NOT NULL AUTO_INCREMENT,
      `reason_code` VARCHAR(50) NOT NULL,
      `reason_text` VARCHAR(255) NOT NULL,
      `is_active` TINYINT(1) DEFAULT 1,
      `display_order` INT(11) DEFAULT 0,
      PRIMARY KEY (`id`),
      UNIQUE KEY `idx_reason_code` (`reason_code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

    // Insert refund reasons
    "INSERT IGNORE INTO `stripe_refund_reasons` (`reason_code`, `reason_text`, `display_order`) VALUES
    ('duplicate_payment', 'Duplicate payment', 1),
    ('service_cancelled', 'Service cancelled', 2),
    ('service_not_rendered', 'Service not rendered', 3),
    ('customer_request', 'Customer request - Other', 4),
    ('billing_error', 'Billing error', 5),
    ('price_adjustment', 'Price adjustment', 6),
    ('insurance_adjustment', 'Insurance adjustment', 7),
    ('other', 'Other (requires notes)', 99)"
];

$successCount = 0;
$errorCount = 0;

foreach ($sqlCommands as $index => $sql) {
    echo "<div class='info'>Running SQL command #" . ($index + 1) . "...</div>";

    try {
        $result = $db->Execute($sql);

        if ($result) {
            echo "<div class='success'>✓ Command #" . ($index + 1) . " executed successfully</div>";
            $successCount++;
        } else {
            $errorMsg = $db->ErrorMsg();
            // Some errors are OK (like "already exists")
            if (stripos($errorMsg, 'duplicate') !== false || stripos($errorMsg, 'already exists') !== false) {
                echo "<div class='success'>✓ Command #" . ($index + 1) . " - Object already exists (OK)</div>";
                $successCount++;
            } else {
                echo "<div class='error'>✗ Command #" . ($index + 1) . " failed: " . htmlspecialchars($errorMsg) . "</div>";
                $errorCount++;
            }
        }
    } catch (Exception $e) {
        echo "<div class='error'>✗ Command #" . ($index + 1) . " exception: " . htmlspecialchars($e->getMessage()) . "</div>";
        $errorCount++;
    }

    echo "<div style='height:10px;'></div>";
}

// Add columns to stripe_payments table (one at a time to handle "already exists" gracefully)
$columnsToAdd = [
    'refunded_amount' => "DECIMAL(10,2) DEFAULT 0.00",
    'refundable_amount' => "DECIMAL(10,2) DEFAULT 0.00",
    'total_refunds' => "INT(11) DEFAULT 0",
    'last_refund_date' => "DATETIME DEFAULT NULL"
];

echo "<h2>Adding columns to stripe_payments table</h2>";

// Check if stripe_payments exists first
$checkTable = $db->Execute("SHOW TABLES LIKE 'stripe_payments'");
if ($checkTable && $checkTable->RecordCount() > 0) {
    // Get existing columns
    $existingCols = [];
    $describe = $db->Execute("DESCRIBE stripe_payments");
    while ($row = $describe->FetchRow()) {
        $existingCols[] = $row['Field'];
    }

    foreach ($columnsToAdd as $colName => $colDef) {
        if (in_array($colName, $existingCols)) {
            echo "<div class='success'>✓ Column '$colName' already exists</div>";
        } else {
            $alterSql = "ALTER TABLE `stripe_payments` ADD COLUMN `$colName` $colDef";
            $result = $db->Execute($alterSql);
            if ($result) {
                echo "<div class='success'>✓ Added column '$colName'</div>";
                $successCount++;
            } else {
                echo "<div class='error'>✗ Failed to add column '$colName': " . htmlspecialchars($db->ErrorMsg()) . "</div>";
                $errorCount++;
            }
        }
    }
} else {
    echo "<div class='error'>✗ stripe_payments table does not exist!</div>";
}

echo "<hr>";
echo "<h2>Installation Summary</h2>";
echo "<p>Successful: <strong>$successCount</strong></p>";
echo "<p>Errors: <strong>$errorCount</strong></p>";

if ($errorCount === 0) {
    echo "<div class='success'><h3>✓ Installation Complete!</h3><p>You can now use the refund system.</p>";
    echo "<p><a href='refund_request.php'>Go to Refund Request Page</a></p></div>";
} else {
    echo "<div class='error'><h3>⚠ Installation completed with some errors</h3><p>Please review the errors above.</p></div>";
}

echo "</body></html>";
?>
