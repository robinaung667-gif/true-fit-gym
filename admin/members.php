<?php

session_start();

require_once "../config/db.php";

// Check admin login
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

// Only admin can access this page
if (($_SESSION["user_role"] ?? "") !== "admin") {
    exit("Access denied. Admin only.");
}

$admin_id = (int)$_SESSION["user_id"];

$message = "";
$message_type = "";

// CSRF protection
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


// Handle approve or reject
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {

        $message = "Invalid request. Please refresh the page.";
        $message_type = "error";

    } else {

        $membership_id = (int)($_POST["membership_id"] ?? 0);
        $action = $_POST["action"] ?? "";

        try {

            // Find pending membership
            $check = $conn->prepare("
                SELECT
                    memberships.id,
                    memberships.user_id,
                    memberships.status,
                    plans.plan_name,
                    plans.duration_days
                FROM memberships
                JOIN plans
                    ON memberships.plan_id = plans.id
                WHERE memberships.id = ?
                AND memberships.status = 'pending'
            ");

            $check->execute([$membership_id]);

            $membership = $check->fetch(PDO::FETCH_ASSOC);


            if (!$membership) {

                $message = "Membership application not found or already processed.";
                $message_type = "error";

            } elseif ($action === "approve") {

                // Calculate membership dates
                $start_date = date("Y-m-d");

                $end_date = date(
                    "Y-m-d",
                    strtotime("+" . (int)$membership["duration_days"] . " days")
                );

                // Begin transaction
                $conn->beginTransaction();

                // Approve membership
                $update = $conn->prepare("
                    UPDATE memberships
                    SET
                        status = 'active',
                        start_date = ?,
                        end_date = ?,
                        approved_by = ?
                    WHERE id = ?
                    AND status = 'pending'
                ");

                $update->execute([
                    $start_date,
                    $end_date,
                    $admin_id,
                    $membership_id
                ]);

                if ($update->rowCount() === 1) {

                    // Create approval notification
                    $notification = $conn->prepare("
                        INSERT INTO notifications
                        (
                            user_id,
                            title,
                            message,
                            notification_type,
                            is_read,
                            created_at
                        )
                        VALUES (?, ?, ?, 'membership', 0, NOW())
                    ");

                    $notification->execute([
                        $membership["user_id"],
                        "Membership Approved!",
                        "Congratulations! Your " .
                        $membership["plan_name"] .
                        " has been activated for " .
                        $membership["duration_days"] .
                        " days. Your membership expires on " .
                        $end_date .
                        ". You can now book gym classes."
                    ]);

                    $conn->commit();

                    $message = "Membership approved and notification sent successfully!";
                    $message_type = "success";

                } else {

                    $conn->rollBack();

                    $message = "Could not approve this application.";
                    $message_type = "error";

                }

            } elseif ($action === "reject") {

                $conn->beginTransaction();

                // Reject membership
                $update = $conn->prepare("
                    UPDATE memberships
                    SET
                        status = 'rejected',
                        approved_by = ?
                    WHERE id = ?
                    AND status = 'pending'
                ");

                $update->execute([
                    $admin_id,
                    $membership_id
                ]);

                if ($update->rowCount() === 1) {

                    // Create rejection notification
                    $notification = $conn->prepare("
                        INSERT INTO notifications
                        (
                            user_id,
                            title,
                            message,
                            notification_type,
                            is_read,
                            created_at
                        )
                        VALUES (?, ?, ?, 'membership', 0, NOW())
                    ");

                    $notification->execute([
                        $membership["user_id"],
                        "Membership Application Rejected",
                        "Your " .
                        $membership["plan_name"] .
                        " application was not approved. Please contact the gym administrator for more information."
                    ]);

                    $conn->commit();

                    $message = "Membership rejected and notification sent successfully.";
                    $message_type = "success";

                } else {

                    $conn->rollBack();

                    $message = "Could not reject this application.";
                    $message_type = "error";

                }

            } else {

                $message = "Invalid action.";
                $message_type = "error";

            }

        } catch (PDOException $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $message = "Database error. Please try again.";
            $message_type = "error";

        } catch (Exception $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $message = "Something went wrong. Please try again.";
            $message_type = "error";

        }

    }
}


// Get all membership applications
$sql = "
    SELECT
        memberships.id AS membership_id,
        memberships.status,
        memberships.start_date,
        memberships.end_date,
        memberships.created_at,
        users.name,
        users.email,
        users.phone,
        plans.plan_name,
        plans.duration_days

    FROM memberships

    JOIN users
        ON memberships.user_id = users.id

    JOIN plans
        ON memberships.plan_id = plans.id

    ORDER BY memberships.created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute();

$applications = $stmt->fetchAll(PDO::FETCH_ASSOC);


// Count pending applications
$count_stmt = $conn->prepare("
    SELECT COUNT(*)
    FROM memberships
    WHERE status = 'pending'
");

$count_stmt->execute();

$pending_count = $count_stmt->fetchColumn();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Members | True Fit Gym</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            background: #f8f5ff;
            padding: 30px;
            color: #333;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        h1 {
            color: #702bd1;
            margin-bottom: 10px;
        }

        .subtitle {
            color: #777;
            margin-bottom: 25px;
        }

        .summary {
            background: white;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .summary h2 {
            color: #702bd1;
            font-size: 30px;
            margin-top: 8px;
        }

        .table-container {
            background: white;
            padding: 25px;
            border-radius: 12px;
            overflow-x: auto;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        h2 {
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            padding: 14px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f8f5ff;
            color: #702bd1;
        }

        .badge {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .pending {
            background: #fff3d6;
            color: #a66a00;
        }

        .active {
            background: #e1f7e8;
            color: #187532;
        }

        .rejected {
            background: #ffe5e5;
            color: #b42318;
        }

        .expired {
            background: #eee;
            color: #555;
        }

        .actions {
            display: flex;
            gap: 8px;
        }

        .actions form {
            display: inline;
        }

        button {
            padding: 9px 13px;
            border: none;
            border-radius: 6px;
            color: white;
            cursor: pointer;
            font-weight: bold;
        }

        .approve {
            background: #239b56;
        }

        .reject {
            background: #dc3545;
        }

        .approve:hover {
            background: #18753e;
        }

        .reject:hover {
            background: #b42318;
        }

        .message {
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .success {
            background: #e5f8e9;
            color: #187532;
        }

        .error {
            background: #ffe8e8;
            color: #b42318;
        }

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #702bd1;
            text-decoration: none;
            font-weight: bold;
        }

        .empty {
            text-align: center;
            padding: 30px;
            color: #888;
        }

        @media (max-width: 600px) {

            body {
                padding: 15px;
            }

            .table-container {
                padding: 15px;
            }

        }

    </style>

</head>

<body>

    <div class="container">

        <a href="dashboard.php" class="back-link">
            ← Back to Admin Dashboard
        </a>

        <h1>Manage Members</h1>

        <p class="subtitle">
            Review membership applications and manage member status.
        </p>


        <?php if ($message !== ""): ?>

            <div class="message <?php echo htmlspecialchars($message_type); ?>">

                <?php echo htmlspecialchars($message); ?>

            </div>

        <?php endif; ?>


        <div class="summary">

            <p>Pending Membership Applications</p>

            <h2><?php echo $pending_count; ?></h2>

        </div>


        <div class="table-container">

            <h2>Membership Applications</h2>

            <?php if (count($applications) > 0): ?>

                <table>

                    <thead>

                        <tr>
                            <th>ID</th>
                            <th>Member</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Plan</th>
                            <th>Status</th>
                            <th>Expiry Date</th>
                            <th>Actions</th>
                        </tr>

                    </thead>

                    <tbody>

                        <?php foreach ($applications as $app): ?>

                            <tr>

                                <td>
                                    <?php echo $app["membership_id"]; ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($app["name"]); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($app["email"]); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($app["phone"] ?? ""); ?>
                                </td>

                                <td>
                                    <?php echo htmlspecialchars($app["plan_name"]); ?>
                                </td>

                                <td>

                                    <span class="badge <?php echo htmlspecialchars($app["status"]); ?>">

                                        <?php echo htmlspecialchars($app["status"]); ?>

                                    </span>

                                </td>

                                <td>
                                    <?php echo htmlspecialchars($app["end_date"] ?? "-"); ?>
                                </td>

                                <td>

                                    <?php if ($app["status"] === "pending"): ?>

                                        <div class="actions">

                                            <form method="POST">

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="membership_id"
                                                    value="<?php echo $app["membership_id"]; ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="approve"
                                                >

                                                <button class="approve" type="submit">
                                                    Approve
                                                </button>

                                            </form>


                                            <form method="POST">

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?php echo htmlspecialchars($_SESSION["csrf_token"]); ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="membership_id"
                                                    value="<?php echo $app["membership_id"]; ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="reject"
                                                >

                                                <button class="reject" type="submit">
                                                    Reject
                                                </button>

                                            </form>

                                        </div>

                                    <?php else: ?>

                                        -

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            <?php else: ?>

                <div class="empty">
                    No membership applications found.
                </div>

            <?php endif; ?>

        </div>

    </div>

</body>

</html>