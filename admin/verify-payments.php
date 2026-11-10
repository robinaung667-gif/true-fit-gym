<?php
session_start();

require_once __DIR__ . '/../config/db.php';

// Admin authentication
if (
    !isset($_SESSION['user_id']) ||
    $_SESSION['user_role'] !== 'admin'
) {
    header("Location: ../auth/login.php");
    exit;
}

$adminId = (int) $_SESSION['user_id'];

$message = "";

// Approve or reject payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $paymentId = (int) ($_POST['payment_id'] ?? 0);
    $action = $_POST['action'] ?? '';
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($paymentId > 0 && in_array($action, ['approve', 'reject'], true)) {

        try {
            $conn->beginTransaction();

            $stmt = $conn->prepare("
                SELECT *
                FROM payments
                WHERE id = ?
                AND status = 'pending'
                FOR UPDATE
            ");

            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                throw new Exception("Payment not found or already processed.");
            }

            if ($action === 'approve') {

                // Check whether the member already has an active membership
                $stmt = $conn->prepare("
                    SELECT id
                    FROM memberships
                    WHERE user_id = ?
                    AND status = 'active'
                    AND end_date >= CURDATE()
                    FOR UPDATE
                ");

                $stmt->execute([$payment['user_id']]);

                if ($stmt->fetch()) {
                    throw new Exception(
                        "This member already has an active membership. Review the existing membership before approving another."
                    );
                }

                // Get plan duration
                $stmt = $conn->prepare("
                    SELECT plan_name, duration_days
                    FROM plans
                    WHERE id = ?
                    AND status = 'active'
                ");

                $stmt->execute([$payment['plan_id']]);
                $plan = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$plan) {
                    throw new Exception("Membership plan is unavailable.");
                }

                $startDate = date('Y-m-d');
                $endDate = date(
                    'Y-m-d',
                    strtotime("+".((int)$plan['duration_days'] - 1)." days")
                );

                // Create active membership
                $stmt = $conn->prepare("
                    INSERT INTO memberships
                    (
                        user_id,
                        plan_id,
                        start_date,
                        end_date,
                        status,
                        approved_by
                    )
                    VALUES (?, ?, ?, ?, 'active', ?)
                ");

                $stmt->execute([
                    $payment['user_id'],
                    $payment['plan_id'],
                    $startDate,
                    $endDate,
                    $adminId
                ]);

                // Update payment status
                $stmt = $conn->prepare("
                    UPDATE payments
                    SET status = 'approved',
                        verified_by = ?,
                        admin_note = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $adminId,
                    $adminNote,
                    $paymentId
                ]);

                // Create notification
                $stmt = $conn->prepare("
                    INSERT INTO notifications
                    (
                        user_id,
                        title,
                        message,
                        notification_type
                    )
                    VALUES (?, ?, ?, 'membership')
                ");

                $stmt->execute([
                    $payment['user_id'],
                    'Membership Approved',
                    'Your ' . $plan['plan_name'] .
                    ' membership payment has been verified. Your membership is now active.'
                ]);

                $message = "Payment approved and membership activated.";

            } else {

                // Reject payment
                $stmt = $conn->prepare("
                    UPDATE payments
                    SET status = 'rejected',
                        verified_by = ?,
                        admin_note = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $adminId,
                    $adminNote,
                    $paymentId
                ]);

                $stmt = $conn->prepare("
                    INSERT INTO notifications
                    (
                        user_id,
                        title,
                        message,
                        notification_type
                    )
                    VALUES (?, ?, ?, 'membership')
                ");

                $stmt->execute([
                    $payment['user_id'],
                    'Payment Rejected',
                    'Your payment could not be approved. Please contact the gym administrator.'
                ]);

                $message = "Payment rejected.";
            }

            $conn->commit();

        } catch (Exception $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $message = $e->getMessage();
        }
    }
}

// Get all payment records
$stmt = $conn->prepare("
    SELECT
        p.*,
        u.name AS member_name,
        u.email AS member_email,
        pl.plan_name
    FROM payments p
    JOIN users u ON p.user_id = u.id
    JOIN plans pl ON p.plan_id = pl.id
    ORDER BY p.created_at DESC
");

$stmt->execute();
$payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Verify Payments | True Fit Gym</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f6f4fc;
            color: #29243b;
        }

        .admin-header {
            background: #251044;
            color: white;
            padding: 22px 6%;
        }

        .admin-header h2 {
            margin: 0;
        }

        .container {
            max-width: 1200px;
            margin: 45px auto;
            padding: 20px;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: #5425e8;
        }

        .message {
            background: #e8f8ed;
            color: #23733a;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .payment-card {
            background: white;
            padding: 25px;
            margin-bottom: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 25px rgba(0,0,0,0.05);
        }

        .payment-card h3 {
            color: #5425e8;
            margin-top: 0;
        }

        .payment-details {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin: 20px 0;
        }

        .payment-details p {
            margin: 0;
            color: #666;
            line-height: 1.6;
        }

        .payment-proof {
            width: 100%;
            max-width: 350px;
            border-radius: 10px;
            border: 1px solid #ddd;
            margin: 15px 0;
        }

        .status {
            display: inline-block;
            padding: 7px 15px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
        }

        .pending {
            background: #fff3cd;
            color: #856404;
        }

        .approved {
            background: #d4edda;
            color: #155724;
        }

        .rejected {
            background: #f8d7da;
            color: #721c24;
        }

        .admin-note {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            margin: 15px 0;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
        }

        .action-buttons button {
            padding: 12px 22px;
            border: none;
            border-radius: 8px;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        .approve-btn {
            background: #198754;
        }

        .reject-btn {
            background: #dc3545;
        }

        .empty-message {
            background: white;
            padding: 35px;
            border-radius: 12px;
            text-align: center;
            color: #777;
        }

        @media(max-width: 650px) {

            .payment-details {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

        }

    </style>

</head>

<body>

<header class="admin-header">
    <h2>TRUE FIT GYM | Payment Verification</h2>
</header>

<main class="container">

    <div class="page-heading">
        <h1>Payment Management</h1>
        <p>Review member payments and verify KBZPay transactions.</p>
    </div>

    <?php if ($message): ?>

        <div class="message">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>


    <?php if (empty($payments)): ?>

        <div class="empty-message">
            <h3>No Payment Records Yet</h3>
            <p>Member payment submissions will appear here.</p>
        </div>

    <?php else: ?>

        <?php foreach ($payments as $payment): ?>

            <div class="payment-card">

                <h3>
                    <?= htmlspecialchars($payment['member_name']) ?>
                </h3>

                <span class="status <?= htmlspecialchars($payment['status']) ?>">
                    <?= strtoupper(htmlspecialchars($payment['status'])) ?>
                </span>

                <div class="payment-details">

                    <p>
                        <strong>Email:</strong><br>
                        <?= htmlspecialchars($payment['member_email']) ?>
                    </p>

                    <p>
                        <strong>Membership:</strong><br>
                        <?= htmlspecialchars($payment['plan_name']) ?>
                    </p>

                    <p>
                        <strong>Amount:</strong><br>
                        <?= number_format((float)$payment['amount']) ?> MMK
                    </p>

                    <p>
                        <strong>Payment Method:</strong><br>
                        <?= htmlspecialchars($payment['payment_method']) ?>
                    </p>

                    <p>
                        <strong>Transaction Reference:</strong><br>
                        <?= htmlspecialchars($payment['transaction_reference']) ?>
                    </p>

                    <p>
                        <strong>Submitted:</strong><br>
                        <?= htmlspecialchars($payment['created_at']) ?>
                    </p>

                </div>

                <h4>Payment Screenshot</h4>

                <?php
                $proofPath = realpath(
                    __DIR__ . '/../' . $payment['payment_proof']
                );

                $receiptDirectory = realpath(
                    __DIR__ . '/../assets/payments/receipts'
                );
                ?>

                <?php if (
                    $proofPath &&
                    $receiptDirectory &&
                    strpos($proofPath, $receiptDirectory . DIRECTORY_SEPARATOR) === 0
                ): ?>

                    <img
                        src="../<?= htmlspecialchars($payment['payment_proof']) ?>"
                        alt="Payment Proof"
                        class="payment-proof"
                    >

                <?php else: ?>

                    <p>Payment screenshot is unavailable.</p>

                <?php endif; ?>


                <?php if ($payment['status'] === 'pending'): ?>

                    <form method="POST">

                        <input
                            type="hidden"
                            name="payment_id"
                            value="<?= (int)$payment['id'] ?>"
                        >

                        <textarea
                            name="admin_note"
                            class="admin-note"
                            placeholder="Admin note (optional)"
                            rows="2"
                        ></textarea>

                        <div class="action-buttons">

                            <button
                                type="submit"
                                name="action"
                                value="approve"
                                class="approve-btn"
                                onclick="return confirm('Have you verified the actual KBZPay transaction? Approve this payment?')"
                            >
                                Approve Payment
                            </button>

                            <button
                                type="submit"
                                name="action"
                                value="reject"
                                class="reject-btn"
                                onclick="return confirm('Are you sure you want to reject this payment?')"
                            >
                                Reject Payment
                            </button>

                        </div>

                    </form>

                <?php else: ?>

                    <p>
                        <strong>Admin Note:</strong>
                        <?= htmlspecialchars($payment['admin_note'] ?? 'No note') ?>
                    </p>

                <?php endif; ?>

            </div>

        <?php endforeach; ?>

    <?php endif; ?>

</main>

</body>
</html>