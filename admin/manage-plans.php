<?php
session_start();

require_once "../config/db.php";

// Admin access only
if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

$message = "";
$error = "";

// CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


// Add or update membership plan
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_plan"])) {

    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        die("Invalid request. Please refresh the page.");
    }

    $plan_id = (int)($_POST["plan_id"] ?? 0);
    $plan_name = trim($_POST["plan_name"] ?? "");
    $price = $_POST["price"] ?? "";
    $duration_days = (int)($_POST["duration_days"] ?? 0);
    $description = trim($_POST["description"] ?? "");

    if ($plan_name === "" || !is_numeric($price) || $price < 0 || $duration_days < 1) {

        $error = "Please enter valid plan information.";

    } else {

        try {

            if ($plan_id > 0) {

                $stmt = $conn->prepare("
                    UPDATE plans
                    SET plan_name = ?,
                        price = ?,
                        duration_days = ?,
                        description = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $plan_name,
                    $price,
                    $duration_days,
                    $description,
                    $plan_id
                ]);

                $message = "Membership plan updated successfully!";

            } else {

                $stmt = $conn->prepare("
                    INSERT INTO plans
                    (plan_name, price, duration_days, description, status)
                    VALUES (?, ?, ?, ?, 'active')
                ");

                $stmt->execute([
                    $plan_name,
                    $price,
                    $duration_days,
                    $description
                ]);

                $message = "Membership plan added successfully!";
            }

        } catch (PDOException $e) {

            $error = "Database error: " . $e->getMessage();

        }
    }
}


// Activate or deactivate a plan
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["toggle_plan"])) {

    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        die("Invalid request.");
    }

    $plan_id = (int)($_POST["plan_id"] ?? 0);

    $stmt = $conn->prepare("
        UPDATE plans
        SET status = IF(status = 'active', 'inactive', 'active')
        WHERE id = ?
    ");

    $stmt->execute([$plan_id]);

    $message = "Plan status updated successfully!";
}


// Get plan for editing
$edit_plan = null;

if (isset($_GET["edit"])) {

    $plan_id = (int)$_GET["edit"];

    $stmt = $conn->prepare("
        SELECT * FROM plans WHERE id = ?
    ");

    $stmt->execute([$plan_id]);

    $edit_plan = $stmt->fetch(PDO::FETCH_ASSOC);
}


// Get all plans
$stmt = $conn->query("
    SELECT * FROM plans
    ORDER BY id DESC
");

$plans = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Manage Plans | True Fit Gym</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 40px 20px;
    font-family: Arial, sans-serif;
    background: #f7f5ff;
    color: #333;
}

.container {
    max-width: 1100px;
    margin: auto;
}

.back-link {
    color: #702dcc;
    text-decoration: none;
    font-weight: bold;
}

h1 {
    color: #702dcc;
    margin-bottom: 8px;
}

.subtitle {
    color: #777;
    margin-bottom: 30px;
}

.card {
    background: white;
    padding: 25px;
    border-radius: 14px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.full {
    grid-column: 1 / -1;
}

label {
    font-weight: bold;
    margin-bottom: 8px;
}

input, textarea {
    padding: 13px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 15px;
    width: 100%;
}

textarea {
    min-height: 100px;
    resize: vertical;
}

button, .btn {
    display: inline-block;
    padding: 12px 18px;
    border: none;
    border-radius: 8px;
    background: #702dcc;
    color: white;
    cursor: pointer;
    text-decoration: none;
    font-weight: bold;
    font-size: 14px;
}

.btn-secondary {
    background: #777;
}

.btn-danger {
    background: #dc3545;
}

.btn-success {
    background: #198754;
}

.message {
    padding: 14px;
    background: #d1e7dd;
    color: #155724;
    border-radius: 8px;
    margin-bottom: 20px;
}

.error {
    padding: 14px;
    background: #f8d7da;
    color: #842029;
    border-radius: 8px;
    margin-bottom: 20px;
}

.table-wrapper {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 650px;
}

th, td {
    padding: 15px;
    text-align: left;
    border-bottom: 1px solid #eee;
}

th {
    background: #f5f0ff;
    color: #702dcc;
}

.status {
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}

.active {
    background: #d1e7dd;
    color: #155724;
}

.inactive {
    background: #f8d7da;
    color: #842029;
}

.actions {
    display: flex;
    gap: 8px;
}

@media (max-width: 700px) {

    body {
        padding: 20px 15px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .full {
        grid-column: auto;
    }

}

</style>

</head>

<body>

<div class="container">

    <a href="dashboard.php" class="back-link">
        ← Back to Admin Dashboard
    </a>

    <h1>Manage Membership Plans</h1>

    <p class="subtitle">
        Create and manage gym membership plans.
    </p>


    <?php if ($message): ?>

        <div class="message">
            <?= htmlspecialchars($message) ?>
        </div>

    <?php endif; ?>


    <?php if ($error): ?>

        <div class="error">
            <?= htmlspecialchars($error) ?>
        </div>

    <?php endif; ?>


    <!-- Add or Edit Plan -->

    <div class="card">

        <h2>
            <?= $edit_plan ? "Edit Membership Plan" : "Add New Membership Plan" ?>
        </h2>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
            >

            <input
                type="hidden"
                name="plan_id"
                value="<?= $edit_plan["id"] ?? 0 ?>"
            >

            <div class="form-grid">

                <div class="form-group">

                    <label>Plan Name *</label>

                    <input
                        type="text"
                        name="plan_name"
                        placeholder="e.g. Free Membership"
                        value="<?= htmlspecialchars($edit_plan["plan_name"] ?? "") ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>Price ($) *</label>

                    <input
                        type="number"
                        name="price"
                        min="0"
                        step="0.01"
                        value="<?= htmlspecialchars((string)($edit_plan["price"] ?? "0.00")) ?>"
                        required
                    >

                </div>


                <div class="form-group">

                    <label>Duration (Days) *</label>

                    <input
                        type="number"
                        name="duration_days"
                        min="1"
                        value="<?= htmlspecialchars((string)($edit_plan["duration_days"] ?? 30)) ?>"
                        required
                    >

                </div>


                <div class="form-group full">

                    <label>Description</label>

                    <textarea
                        name="description"
                        placeholder="Describe the membership plan..."
                    ><?= htmlspecialchars($edit_plan["description"] ?? "") ?></textarea>

                </div>


                <div class="form-group full">

                    <div>

                        <button type="submit" name="save_plan">

                            <?= $edit_plan ? "Update Plan" : "Add Plan" ?>

                        </button>

                        <?php if ($edit_plan): ?>

                            <a href="manage-plans.php" class="btn btn-secondary">
                                Cancel
                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </form>

    </div>


    <!-- Plans List -->

    <div class="card">

        <h2>All Membership Plans</h2>

        <div class="table-wrapper">

            <table>

                <thead>

                    <tr>
                        <th>ID</th>
                        <th>Plan Name</th>
                        <th>Price</th>
                        <th>Duration</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>

                </thead>

                <tbody>

                    <?php if (count($plans) > 0): ?>

                        <?php foreach ($plans as $plan): ?>

                            <tr>

                                <td><?= (int)$plan["id"] ?></td>

                                <td>
                                    <?= htmlspecialchars($plan["plan_name"]) ?>
                                </td>

                                <td>
                                    $<?= number_format((float)$plan["price"], 2) ?>
                                </td>

                                <td>
                                    <?= (int)$plan["duration_days"] ?> Days
                                </td>

                                <td>

                                    <span class="status <?= htmlspecialchars($plan["status"]) ?>">

                                        <?= ucfirst(htmlspecialchars($plan["status"])) ?>

                                    </span>

                                </td>

                                <td>

                                    <div class="actions">

                                        <a
                                            href="?edit=<?= (int)$plan["id"] ?>"
                                            class="btn"
                                        >
                                            Edit
                                        </a>

                                        <form method="POST">

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="plan_id"
                                                value="<?= (int)$plan["id"] ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="toggle_plan"
                                                class="<?= $plan["status"] === "active" ? "btn-danger" : "btn-success" ?>"
                                                onclick="return confirm('Change this plan status?')"
                                            >

                                                <?= $plan["status"] === "active" ? "Deactivate" : "Activate" ?>

                                            </button>

                                        </form>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6">No membership plans found.</td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</body>

</html>