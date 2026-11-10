<?php
session_start();

require_once "../config/db.php";

// Check admin login
if (!isset($_SESSION["user_id"]) || $_SESSION["user_role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

$message = "";
$error = "";

// CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Add or update trainer
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (!hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"] ?? "")) {
        die("Invalid request.");
    }

    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $speciality = trim($_POST["speciality"] ?? "");
    $bio = trim($_POST["bio"] ?? "");
    $trainer_id = (int)($_POST["trainer_id"] ?? 0);

    if ($name === "" || $speciality === "") {
        $error = "Name and speciality are required.";
    } elseif ($email !== "" && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } else {
        try {
            if ($trainer_id > 0) {

                $stmt = $conn->prepare("
                    UPDATE trainers
                    SET name = ?, email = ?, phone = ?,
                        speciality = ?, bio = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $name,
                    $email ?: null,
                    $phone,
                    $speciality,
                    $bio,
                    $trainer_id
                ]);

                $message = "Trainer updated successfully!";

            } else {

                $stmt = $conn->prepare("
                    INSERT INTO trainers
                    (name, email, phone, speciality, bio, status)
                    VALUES (?, ?, ?, ?, ?, 'active')
                ");

                $stmt->execute([
                    $name,
                    $email ?: null,
                    $phone,
                    $speciality,
                    $bio
                ]);

                $message = "Trainer added successfully!";
            }

        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Activate or deactivate trainer
if (isset($_GET["toggle"])) {

    $trainer_id = (int)$_GET["toggle"];

    $stmt = $conn->prepare("
        UPDATE trainers
        SET status = IF(status = 'active', 'inactive', 'active')
        WHERE id = ?
    ");

    $stmt->execute([$trainer_id]);

    header("Location: manage-trainers.php");
    exit;
}

// Load trainer for editing
$edit_trainer = null;

if (isset($_GET["edit"])) {

    $trainer_id = (int)$_GET["edit"];

    $stmt = $conn->prepare("
        SELECT * FROM trainers WHERE id = ?
    ");

    $stmt->execute([$trainer_id]);
    $edit_trainer = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get all trainers
$stmt = $conn->query("
    SELECT * FROM trainers
    ORDER BY id DESC
");

$trainers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Trainers | True Fit Gym</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 40px;
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

        h2 {
            margin-top: 0;
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
            border: none;
            background: #702dcc;
            color: white;
            padding: 12px 20px;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
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
            padding: 13px;
            background: #d1e7dd;
            color: #155724;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .error {
            padding: 13px;
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
            min-width: 700px;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            background: #f5f0ff;
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
                padding: 20px;
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

    <a class="back-link" href="dashboard.php">
        ← Back to Admin Dashboard
    </a>

    <h1>Manage Trainers</h1>

    <p class="subtitle">
        Add trainers and manage their information and status.
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


    <!-- Add / Edit Trainer Form -->

    <div class="card">

        <h2>
            <?= $edit_trainer ? "Edit Trainer" : "Add New Trainer" ?>
        </h2>

        <form method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
            >

            <input
                type="hidden"
                name="trainer_id"
                value="<?= $edit_trainer["id"] ?? 0 ?>"
            >

            <div class="form-grid">

                <div class="form-group">
                    <label>Trainer Name *</label>

                    <input
                        type="text"
                        name="name"
                        placeholder="Enter trainer name"
                        value="<?= htmlspecialchars($edit_trainer["name"] ?? "") ?>"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Email</label>

                    <input
                        type="email"
                        name="email"
                        placeholder="trainer@example.com"
                        value="<?= htmlspecialchars($edit_trainer["email"] ?? "") ?>"
                    >
                </div>

                <div class="form-group">
                    <label>Phone</label>

                    <input
                        type="text"
                        name="phone"
                        placeholder="Enter phone number"
                        value="<?= htmlspecialchars($edit_trainer["phone"] ?? "") ?>"
                    >
                </div>

                <div class="form-group">
                    <label>Speciality *</label>

                    <input
                        type="text"
                        name="speciality"
                        placeholder="e.g. Yoga Instructor"
                        value="<?= htmlspecialchars($edit_trainer["speciality"] ?? "") ?>"
                        required
                    >
                </div>

                <div class="form-group full">
                    <label>Trainer Bio</label>

                    <textarea
                        name="bio"
                        placeholder="Write about the trainer..."
                    ><?= htmlspecialchars($edit_trainer["bio"] ?? "") ?></textarea>
                </div>

                <div class="form-group full">

                    <div>
                        <button type="submit">
                            <?= $edit_trainer ? "Update Trainer" : "Add Trainer" ?>
                        </button>

                        <?php if ($edit_trainer): ?>
                            <a href="manage-trainers.php" class="btn btn-secondary">
                                Cancel
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

            </div>

        </form>

    </div>


    <!-- Trainers List -->

    <div class="card">

        <h2>All Trainers</h2>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Speciality</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (count($trainers) > 0): ?>

                        <?php foreach ($trainers as $trainer): ?>

                            <tr>

                                <td><?= $trainer["id"] ?></td>

                                <td>
                                    <?= htmlspecialchars($trainer["name"]) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($trainer["email"] ?? "-") ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($trainer["speciality"]) ?>
                                </td>

                                <td>
                                    <span class="status <?= htmlspecialchars($trainer["status"]) ?>">
                                        <?= ucfirst(htmlspecialchars($trainer["status"])) ?>
                                    </span>
                                </td>

                                <td>

                                    <div class="actions">

                                        <a
                                            href="?edit=<?= $trainer["id"] ?>"
                                            class="btn"
                                        >
                                            Edit
                                        </a>

                                        <a
                                            href="?toggle=<?= $trainer["id"] ?>"
                                            class="btn <?= $trainer["status"] === "active" ? "btn-danger" : "btn-success" ?>"
                                            onclick="return confirm('Change this trainer status?')"
                                        >
                                            <?= $trainer["status"] === "active" ? "Deactivate" : "Activate" ?>
                                        </a>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="6">
                                No trainers found. Add your first trainer above.
                            </td>
                        </tr>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</body>
</html>