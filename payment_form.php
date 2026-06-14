<?php
require_once('roots.php');
require_once($root_path.'include/inc_db_makelink.php');
require_once('stripe_config.php');

// Initialize variables
$patient_id = '';
$patient_name = '';
$patient_email = '';
$claim_id = '';
$encounter_id = '';
$amount = '';
$error_message = '';
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Get and sanitize form data
    $patient_id = trim($_POST['patient_id'] ?? '');
    $patient_name = trim($_POST['patient_name'] ?? '');
    $patient_email = trim($_POST['patient_email'] ?? '');
    $claim_id = trim($_POST['claim_id'] ?? '');
    $encounter_id = trim($_POST['encounter_id'] ?? '');
    $amount = trim($_POST['amount'] ?? '');

    // Validation
    if (empty($patient_id)) {
        $error_message = 'Patient ID is required.';
    } elseif (empty($amount) || !is_numeric($amount) || floatval($amount) <= 0) {
        $error_message = 'Please enter a valid amount greater than 0.';
    } elseif (empty($patient_name)) {
        $error_message = 'Patient Name is required.';
    } elseif (empty($patient_email) || !filter_var($patient_email, FILTER_VALIDATE_EMAIL)) {
        $error_message = 'Please enter a valid email address.';
    } else {
        // If validation passes, redirect to payment processing
        header('Location: payment_process.php?' . http_build_query([
            'patient_id' => $patient_id,
            'patient_name' => $patient_name,
            'patient_email' => $patient_email,
            'claim_id' => $claim_id,
            'encounter_id' => $encounter_id,
            'amount' => $amount
        ]));
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Payment - Stripe Integration</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .payment-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 500px;
            width: 100%;
            padding: 40px;
        }

        .payment-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .payment-header h1 {
            color: #333;
            font-size: 28px;
            margin-bottom: 10px;
        }

        .payment-header p {
            color: #666;
            font-size: 14px;
        }

        .stripe-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-top: 15px;
        }

        .stripe-badge img {
            height: 25px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            color: #333;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 14px;
        }

        .form-group input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .form-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .form-group input[readonly] {
            background-color: #f5f5f5;
            cursor: not-allowed;
        }

        .form-row {
            display: flex;
            gap: 15px;
        }

        .form-row .form-group {
            flex: 1;
        }

        .amount-input-group {
            position: relative;
        }

        .amount-input-group span {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #666;
            font-weight: 600;
        }

        .amount-input-group input {
            padding-left: 35px;
            font-size: 18px;
            font-weight: 600;
            color: #667eea;
        }

        .required-field {
            color: #e74c3c;
        }

        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        .alert-error {
            background-color: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }

        .alert-success {
            background-color: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }

        .btn-pay {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 10px;
        }

        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.3);
        }

        .btn-pay:disabled {
            background: #ccc;
            cursor: not-allowed;
            transform: none;
        }

        .payment-methods {
            display: flex;
            justify-content: center;
            gap: 15px;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }

        .payment-methods img {
            height: 30px;
            opacity: 0.7;
            transition: opacity 0.3s ease;
        }

        .payment-methods img:hover {
            opacity: 1;
        }

        .secure-badge {
            text-align: center;
            margin-top: 20px;
            color: #999;
            font-size: 12px;
        }

        .secure-badge svg {
            width: 16px;
            height: 16px;
            vertical-align: middle;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="payment-container">
        <div class="payment-header">
            <h1>Patient Payment</h1>
            <p>Secure payment processing powered by Stripe</p>
            <div class="stripe-badge">
                <span style="color: #635bff; font-weight: 600; font-size: 14px;">Powered by </span>
                <span style="color: #635bff; font-weight: 700; font-size: 16px; margin-left: 5px;">Stripe</span>
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

        <form method="POST" action="">
            <div class="form-row">
                <div class="form-group">
                    <label for="patient_id">Patient ID <span class="required-field">*</span></label>
                    <input
                        type="text"
                        id="patient_id"
                        name="patient_id"
                        value="<?php echo htmlspecialchars($patient_id); ?>"
                        required
                        placeholder="Enter Patient ID">
                </div>
                <div class="form-group">
                    <label for="encounter_id">Encounter ID</label>
                    <input
                        type="text"
                        id="encounter_id"
                        name="encounter_id"
                        value="<?php echo htmlspecialchars($encounter_id); ?>"
                        placeholder="Optional">
                </div>
            </div>

            <div class="form-group">
                <label for="patient_name">Patient Name <span class="required-field">*</span></label>
                <input
                    type="text"
                    id="patient_name"
                    name="patient_name"
                    value="<?php echo htmlspecialchars($patient_name); ?>"
                    required
                    placeholder="Enter full name">
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label for="patient_email">Email Address <span class="required-field">*</span></label>
                    <input
                        type="email"
                        id="patient_email"
                        name="patient_email"
                        value="<?php echo htmlspecialchars($patient_email); ?>"
                        required
                        placeholder="email@example.com">
                </div>
                <div class="form-group">
                    <label for="claim_id">Claim ID</label>
                    <input
                        type="text"
                        id="claim_id"
                        name="claim_id"
                        value="<?php echo htmlspecialchars($claim_id); ?>"
                        placeholder="Optional">
                </div>
            </div>

            <div class="form-group">
                <label for="amount">Payment Amount <span class="required-field">*</span></label>
                <div class="amount-input-group">
                    <span>$</span>
                    <input
                        type="number"
                        id="amount"
                        name="amount"
                        value="<?php echo htmlspecialchars($amount); ?>"
                        required
                        min="0.01"
                        step="0.01"
                        placeholder="0.00">
                </div>
            </div>

            <button type="submit" class="btn-pay">
                Pay Now
            </button>
        </form>

        <div class="payment-methods">
            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 50 35'%3E%3Crect fill='%231A1F71' width='50' height='35' rx='4'/%3E%3Ctext x='25' y='22' text-anchor='middle' fill='white' font-size='10' font-family='Arial'%3EVisa%3C/text%3E%3C/svg%3E" alt="Visa">
            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 50 35'%3E%3Crect fill='%23EB001B' width='50' height='35' rx='4'/%3E%3Ccircle cx='20' cy='17.5' r='10' fill='%23EB001B'/%3E%3Ccircle cx='30' cy='17.5' r='10' fill='%23F79E1B'/%3E%3C/svg%3E" alt="Mastercard">
            <img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 50 35'%3E%3Crect fill='%2300A1E4' width='50' height='35' rx='4'/%3E%3Ctext x='25' y='22' text-anchor='middle' fill='white' font-size='8' font-family='Arial'%3EAMEX%3C/text%3E%3C/svg%3E" alt="Amex">
        </div>

        <div class="secure-badge">
            <svg fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/>
            </svg>
            Secure 256-bit SSL encrypted payment
        </div>
    </div>

    <script>
        // Client-side validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const amount = document.getElementById('amount').value;
            const email = document.getElementById('patient_email').value;
            const patientId = document.getElementById('patient_id').value;
            const patientName = document.getElementById('patient_name').value;

            if (!patientId.trim()) {
                e.preventDefault();
                alert('Please enter a Patient ID');
                return false;
            }

            if (!patientName.trim()) {
                e.preventDefault();
                alert('Please enter Patient Name');
                return false;
            }

            if (!email.trim() || !email.includes('@')) {
                e.preventDefault();
                alert('Please enter a valid email address');
                return false;
            }

            if (!amount || parseFloat(amount) <= 0) {
                e.preventDefault();
                alert('Please enter a valid amount');
                return false;
            }
        });

        // Format amount as user types
        document.getElementById('amount').addEventListener('blur', function() {
            const value = parseFloat(this.value);
            if (!isNaN(value)) {
                this.value = value.toFixed(2);
            }
        });
    </script>
</body>
</html>
