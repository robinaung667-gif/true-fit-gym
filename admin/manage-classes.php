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

// CSRF protection
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$message = "";
$message_type = "";


// ==========================================
// HANDLE FORM SUBMISSIONS
// ==========================================

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {

        $message = "Invalid request. Please refresh the page.";
        $message_type = "error";

    } else {

        $action = $_POST["action"] ?? "";

        try {

            // ==================================
            // ADD NEW CLASS
            // ==================================

            if ($action === "add") {

                $class_name = trim($_POST["class_name"] ?? "");

                $trainer_id = !empty($_POST["trainer_id"])
                    ? (int) $_POST["trainer_id"]
                    : null;

                $class_date = $_POST["class_date"] ?? "";
                $start_time = $_POST["start_time"] ?? "";
                $end_time = $_POST["end_time"] ?? "";
                $room = trim($_POST["room"] ?? "");
                $capacity = (int) ($_POST["capacity"] ?? 0);
                $description = trim($_POST["description"] ?? "");

                if (
                    $class_name === "" ||
                    $class_date === "" ||
                    $start_time === "" ||
                    $end_time === "" ||
                    $capacity < 1
                ) {

                    throw new Exception(
                        "Please complete all required fields."
                    );

                }

                if ($class_date < date("Y-m-d")) {

                    throw new Exception(
                        "Class date cannot be in the past."
                    );

                }

                if ($start_time >= $end_time) {

                    throw new Exception(
                        "End time must be later than start time."
                    );

                }

                // Check trainer
                if ($trainer_id !== null) {

                    $trainer_check = $conn->prepare("
                        SELECT id
                        FROM trainers
                        WHERE id = ?
                        AND status = 'active'
                    ");

                    $trainer_check->execute([$trainer_id]);

                    if (!$trainer_check->fetch()) {

                        throw new Exception(
                            "Please select an active trainer."
                        );

                    }

                }

                // Insert class
                $stmt = $conn->prepare("
                    INSERT INTO classes (
                        class_name,
                        trainer_id,
                        class_date,
                        start_time,
                        end_time,
                        room,
                        capacity,
                        description,
                        status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'scheduled')
                ");

                $stmt->execute([
                    $class_name,
                    $trainer_id,
                    $class_date,
                    $start_time,
                    $end_time,
                    $room,
                    $capacity,
                    $description
                ]);

                $message = "Class added successfully!";
                $message_type = "success";

            }


            // ==================================
            // UPDATE CLASS
            // ==================================

            elseif ($action === "update") {

                $class_id = (int) ($_POST["class_id"] ?? 0);

                $class_name = trim($_POST["class_name"] ?? "");

                $trainer_id = !empty($_POST["trainer_id"])
                    ? (int) $_POST["trainer_id"]
                    : null;

                $class_date = $_POST["class_date"] ?? "";
                $start_time = $_POST["start_time"] ?? "";
                $end_time = $_POST["end_time"] ?? "";
                $room = trim($_POST["room"] ?? "");
                $capacity = (int) ($_POST["capacity"] ?? 0);
                $description = trim($_POST["description"] ?? "");

                if (
                    $class_name === "" ||
                    $class_date === "" ||
                    $start_time === "" ||
                    $end_time === "" ||
                    $capacity < 1
                ) {

                    throw new Exception(
                        "Please complete all required fields."
                    );

                }

                if ($class_date < date("Y-m-d")) {

                    throw new Exception(
                        "Class date cannot be in the past."
                    );

                }

                if ($start_time >= $end_time) {

                    throw new Exception(
                        "End time must be later than start time."
                    );

                }

                // Check trainer
                if ($trainer_id !== null) {

                    $trainer_check = $conn->prepare("
                        SELECT id
                        FROM trainers
                        WHERE id = ?
                        AND status = 'active'
                    ");

                    $trainer_check->execute([$trainer_id]);

                    if (!$trainer_check->fetch()) {

                        throw new Exception(
                            "Please select an active trainer."
                        );

                    }

                }

                // Check existing bookings
                $booking_check = $conn->prepare("
                    SELECT COUNT(*)
                    FROM bookings
                    WHERE class_id = ?
                    AND status = 'booked'
                ");

                $booking_check->execute([$class_id]);

                $current_bookings = (int) $booking_check->fetchColumn();

                if ($capacity < $current_bookings) {

                    throw new Exception(
                        "Capacity cannot be lower than existing bookings."
                    );

                }

                // Update class
                $stmt = $conn->prepare("
                    UPDATE classes
                    SET
                        class_name = ?,
                        trainer_id = ?,
                        class_date = ?,
                        start_time = ?,
                        end_time = ?,
                        room = ?,
                        capacity = ?,
                        description = ?
                    WHERE id = ?
                    AND status = 'scheduled'
                ");

                $stmt->execute([
                    $class_name,
                    $trainer_id,
                    $class_date,
                    $start_time,
                    $end_time,
                    $room,
                    $capacity,
                    $description,
                    $class_id
                ]);

                $message = "Class updated successfully!";
                $message_type = "success";

            }


            // ==================================
            // CANCEL CLASS + NOTIFICATIONS
            // ==================================

            elseif ($action === "cancel") {

                $class_id = (int) ($_POST["class_id"] ?? 0);

                $reason = trim(
                    $_POST["cancellation_reason"] ?? ""
                );

                if ($reason === "") {

                    throw new Exception(
                        "Please enter a cancellation reason."
                    );

                }

                // Start database transaction
                $conn->beginTransaction();

                // Get class information
                $class_check = $conn->prepare("
                    SELECT
                        id,
                        class_name,
                        class_date
                    FROM classes
                    WHERE id = ?
                    AND status = 'scheduled'
                    FOR UPDATE
                ");

                $class_check->execute([$class_id]);

                $class_info = $class_check->fetch(PDO::FETCH_ASSOC);

                if (!$class_info) {

                    $conn->rollBack();

                    $message = "Class not found or already cancelled.";
                    $message_type = "error";

                } else {

                    // Cancel the class
                    $cancel_stmt = $conn->prepare("
                        UPDATE classes
                        SET
                            status = 'cancelled',
                            cancellation_reason = ?
                        WHERE id = ?
                        AND status = 'scheduled'
                    ");

                    $cancel_stmt->execute([
                        $reason,
                        $class_id
                    ]);

                    if ($cancel_stmt->rowCount() === 1) {

                        // Find members who booked this class
                        $booking_stmt = $conn->prepare("
                            SELECT user_id
                            FROM bookings
                            WHERE class_id = ?
                            AND status = 'booked'
                        ");

                        $booking_stmt->execute([$class_id]);

                        $booked_members = $booking_stmt->fetchAll(
                            PDO::FETCH_COLUMN
                        );

                        // Prepare notification query
                        $notification_stmt = $conn->prepare("
                            INSERT INTO notifications (
                                user_id,
                                title,
                                message,
                                notification_type,
                                is_read,
                                created_at
                            )
                            VALUES (?, ?, ?, 'class', 0, NOW())
                        ");

                        // Send notification to every booked member
                        foreach ($booked_members as $member_id) {

                            $notification_stmt->execute([
                                (int) $member_id,

                                "Class Cancelled: " .
                                $class_info["class_name"],

                                "The class " .
                                $class_info["class_name"] .
                                " scheduled for " .
                                $class_info["class_date"] .
                                " has been cancelled. Reason: " .
                                $reason
                            ]);

                        }

                        // Save cancellation and notifications
                        $conn->commit();

                        $message = "Class cancelled successfully. Notifications sent to " .
                            count($booked_members) .
                            " booked member(s).";

                        $message_type = "success";

                    } else {

                        $conn->rollBack();

                        $message = "Could not cancel this class.";
                        $message_type = "error";

                    }

                }

            }

        } catch (Exception $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $message = $e->getMessage();
            $message_type = "error";

        } catch (PDOException $e) {

            if ($conn->inTransaction()) {
                $conn->rollBack();
            }

            $message = "Database error. Please try again.";
            $message_type = "error";

        }

    }

}


// ==========================================
// GET ACTIVE TRAINERS
// ==========================================

$trainer_stmt = $conn->prepare("
    SELECT id, name, speciality
    FROM trainers
    WHERE status = 'active'
    ORDER BY name ASC
");

$trainer_stmt->execute();

$trainers = $trainer_stmt->fetchAll(PDO::FETCH_ASSOC);


// ==========================================
// GET CLASS FOR EDITING
// ==========================================

$edit_class = null;

if (isset($_GET["edit"])) {

    $edit_id = (int) $_GET["edit"];

    $edit_stmt = $conn->prepare("
        SELECT *
        FROM classes
        WHERE id = ?
        AND status = 'scheduled'
    ");

    $edit_stmt->execute([$edit_id]);

    $edit_class = $edit_stmt->fetch(PDO::FETCH_ASSOC);

}


// ==========================================
// GET ALL CLASSES
// ==========================================

$class_stmt = $conn->prepare("
    SELECT
        c.*,
        t.name AS trainer_name

    FROM classes c

    LEFT JOIN trainers t
        ON c.trainer_id = t.id

    ORDER BY c.class_date DESC, c.start_time DESC
");

$class_stmt->execute();

$classes = $class_stmt->fetchAll(PDO::FETCH_ASSOC);


// HTML escape function
function h($value) {

    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );

}

?>


<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Manage Classes | True Fit Gym</title>

    <style>

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: Arial, sans-serif;
        }

        body {
            background: #f8f5ff;
            color: #333;
            padding: 35px 20px;
        }

        .container {
            max-width: 1150px;
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

        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #702bd1;
            text-decoration: none;
            font-weight: bold;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 14px;
            margin-bottom: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }

        h2 {
            margin-bottom: 20px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 15px;
        }

        textarea {
            min-height: 90px;
            resize: vertical;
        }

        .full {
            grid-column: 1 / -1;
        }

        button,
        .button {
            display: inline-block;
            padding: 11px 17px;
            background: #702bd1;
            color: white;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            text-decoration: none;
        }

        button:hover,
        .button:hover {
            background: #5720a8;
        }

        .edit-button {
            background: #5b6470;
        }

        .cancel-button {
            background: #dc3545;
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

        .table-container {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 850px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 13px;
            border-bottom: 1px solid #eee;
            text-align: left;
        }

        th {
            background: #f8f5ff;
            color: #702bd1;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            background: #e5f8e9;
            color: #187532;
            font-size: 13px;
        }

        .status.cancelled {
            background: #ffe8e8;
            color: #b42318;
        }

        .actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        .inline-form {
            display: flex;
            gap: 6px;
        }

        .inline-form input {
            width: 150px;
        }

        @media (max-width: 650px) {

            body {
                padding: 20px 12px;
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

        <h1>Manage Classes</h1>

        <p class="subtitle">
            Create gym classes, assign trainers and manage schedules.
        </p>


        <?php if ($message !== ""): ?>

            <div class="message <?php echo h($message_type); ?>">

                <?php echo h($message); ?>

            </div>

        <?php endif; ?>


        <!-- ADD / EDIT CLASS FORM -->

        <section class="card">

            <h2>
                <?php echo $edit_class ? "Edit Class" : "Add New Class"; ?>
            </h2>


            <form
                method="POST"
                action="manage-classes.php<?php echo $edit_class ? "?edit=" . (int) $edit_class["id"] : ""; ?>"
            >

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?php echo h($_SESSION["csrf_token"]); ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="<?php echo $edit_class ? "update" : "add"; ?>"
                >


                <?php if ($edit_class): ?>

                    <input
                        type="hidden"
                        name="class_id"
                        value="<?php echo (int) $edit_class["id"]; ?>"
                    >

                <?php endif; ?>


                <div class="form-grid">


                    <div>

                        <label>Class Name *</label>

                        <input
                            type="text"
                            name="class_name"
                            placeholder="Example: Yoga"
                            value="<?php echo h($edit_class["class_name"] ?? ""); ?>"
                            required
                        >

                    </div>


                    <div>

                        <label>Select Trainer</label>

                        <select name="trainer_id">

                            <option value="">
                                No Trainer Assigned
                            </option>

                            <?php foreach ($trainers as $trainer): ?>

                                <option
                                    value="<?php echo (int) $trainer["id"]; ?>"
                                    <?php echo isset($edit_class["trainer_id"]) && (int) $edit_class["trainer_id"] === (int) $trainer["id"] ? "selected" : ""; ?>
                                >

                                    <?php echo h($trainer["name"]); ?>

                                    <?php if (!empty($trainer["speciality"])): ?>

                                        - <?php echo h($trainer["speciality"]); ?>

                                    <?php endif; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>


                    <div>

                        <label>Class Date *</label>

                        <input
                            type="date"
                            name="class_date"
                            min="<?php echo date("Y-m-d"); ?>"
                            value="<?php echo h($edit_class["class_date"] ?? ""); ?>"
                            required
                        >

                    </div>


                    <div>

                        <label>Room</label>

                        <input
                            type="text"
                            name="room"
                            placeholder="Example: Studio A"
                            value="<?php echo h($edit_class["room"] ?? ""); ?>"
                        >

                    </div>


                    <div>

                        <label>Start Time *</label>

                        <input
                            type="time"
                            name="start_time"
                            value="<?php echo h(isset($edit_class["start_time"]) ? substr($edit_class["start_time"], 0, 5) : ""); ?>"
                            required
                        >

                    </div>


                    <div>

                        <label>End Time *</label>

                        <input
                            type="time"
                            name="end_time"
                            value="<?php echo h(isset($edit_class["end_time"]) ? substr($edit_class["end_time"], 0, 5) : ""); ?>"
                            required
                        >

                    </div>


                    <div>

                        <label>Maximum Capacity *</label>

                        <input
                            type="number"
                            name="capacity"
                            min="1"
                            value="<?php echo h($edit_class["capacity"] ?? "20"); ?>"
                            required
                        >

                    </div>


                    <div class="full">

                        <label>Description</label>

                        <textarea
                            name="description"
                            placeholder="Describe this gym class..."
                        ><?php echo h($edit_class["description"] ?? ""); ?></textarea>

                    </div>


                    <div class="full">

                        <button type="submit">

                            <?php echo $edit_class ? "Save Changes" : "Add Class"; ?>

                        </button>


                        <?php if ($edit_class): ?>

                            <a
                                href="manage-classes.php"
                                class="button edit-button"
                            >
                                Cancel Editing
                            </a>

                        <?php endif; ?>

                    </div>


                </div>

            </form>

        </section>



        <!-- ALL CLASSES TABLE -->

        <section class="card">

            <h2>All Gym Classes</h2>


            <div class="table-container">

                <table>

                    <thead>

                        <tr>

                            <th>ID</th>
                            <th>Class</th>
                            <th>Trainer</th>
                            <th>Date</th>
                            <th>Time</th>
                            <th>Room</th>
                            <th>Capacity</th>
                            <th>Status</th>
                            <th>Actions</th>

                        </tr>

                    </thead>


                    <tbody>


                        <?php if (count($classes) > 0): ?>


                            <?php foreach ($classes as $class): ?>


                                <tr>


                                    <td>
                                        <?php echo (int) $class["id"]; ?>
                                    </td>


                                    <td>
                                        <?php echo h($class["class_name"]); ?>
                                    </td>


                                    <td>
                                        <?php echo h($class["trainer_name"] ?: "Not assigned"); ?>
                                    </td>


                                    <td>
                                        <?php echo h($class["class_date"]); ?>
                                    </td>


                                    <td>

                                        <?php echo h(substr($class["start_time"], 0, 5)); ?>

                                        -

                                        <?php echo h(substr($class["end_time"], 0, 5)); ?>

                                    </td>


                                    <td>
                                        <?php echo h($class["room"] ?: "-"); ?>
                                    </td>


                                    <td>
                                        <?php echo (int) $class["capacity"]; ?>
                                    </td>


                                    <td>

                                        <span class="status <?php echo $class["status"] === "cancelled" ? "cancelled" : ""; ?>">

                                            <?php echo h(ucfirst($class["status"])); ?>

                                        </span>

                                    </td>


                                    <td>


                                        <div class="actions">


                                            <?php if ($class["status"] === "scheduled"): ?>


                                                <a
                                                    href="manage-classes.php?edit=<?php echo (int) $class["id"]; ?>"
                                                    class="button edit-button"
                                                >
                                                    Edit
                                                </a>


                                                <form
                                                    method="POST"
                                                    class="inline-form"
                                                    onsubmit="return confirm('Are you sure you want to cancel this class?');"
                                                >

                                                    <input
                                                        type="hidden"
                                                        name="csrf_token"
                                                        value="<?php echo h($_SESSION["csrf_token"]); ?>"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="action"
                                                        value="cancel"
                                                    >

                                                    <input
                                                        type="hidden"
                                                        name="class_id"
                                                        value="<?php echo (int) $class["id"]; ?>"
                                                    >

                                                    <input
                                                        type="text"
                                                        name="cancellation_reason"
                                                        placeholder="Cancellation reason"
                                                        required
                                                    >

                                                    <button
                                                        type="submit"
                                                        class="cancel-button"
                                                    >
                                                        Cancel
                                                    </button>

                                                </form>


                                            <?php else: ?>


                                                <?php echo h($class["cancellation_reason"] ?: "-"); ?>


                                            <?php endif; ?>


                                        </div>


                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        <?php else: ?>


                            <tr>

                                <td colspan="9" style="text-align:center;">

                                    No classes have been created yet.

                                </td>

                            </tr>


                        <?php endif; ?>


                    </tbody>

                </table>

            </div>

        </section>


    </div>

</body>

</html>