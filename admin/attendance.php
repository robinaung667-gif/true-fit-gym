<?php
session_start();

require_once "../config/db.php";

// Admin access only
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["user_role"] ?? "") !== "admin"
) {
    header("Location: ../auth/login.php");
    exit;
}

$message = "";
$error = "";

// Create CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}


// Save attendance
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_attendance"])) {

    if (!hash_equals(
        $_SESSION["csrf_token"],
        $_POST["csrf_token"] ?? ""
    )) {
        die("Invalid request. Please refresh the page.");
    }

    $booking_id = (int)($_POST["booking_id"] ?? 0);
    $class_id = (int)($_POST["class_id"] ?? 0);
    $attendance_status = $_POST["attendance_status"] ?? "";

    if (
        $booking_id <= 0 ||
        $class_id <= 0 ||
        !in_array($attendance_status, ["present", "absent"], true)
    ) {
        $error = "Invalid attendance information.";
    } else {

        try {

            // Check that the booking belongs to this class
            $check = $conn->prepare("
                SELECT id
                FROM bookings
                WHERE id = ?
                AND class_id = ?
                AND status IN ('booked', 'attended')
            ");

            $check->execute([$booking_id, $class_id]);

            if (!$check->fetch()) {

                $error = "Booking not found or already cancelled.";

            } else {

                // Insert or update attendance
                $stmt = $conn->prepare("
                    INSERT INTO attendance
                    (booking_id, attendance_status, marked_by, marked_at)
                    VALUES (?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE
                        attendance_status = VALUES(attendance_status),
                        marked_by = VALUES(marked_by),
                        marked_at = NOW()
                ");

                $stmt->execute([
                    $booking_id,
                    $attendance_status,
                    $_SESSION["user_id"]
                ]);

                // Update booking status when member is present
                if ($attendance_status === "present") {

                    $update = $conn->prepare("
                        UPDATE bookings
                        SET status = 'attended'
                        WHERE id = ?
                    ");

                    $update->execute([$booking_id]);

                } else {

                    // Keep absent bookings as booked
                    $update = $conn->prepare("
                        UPDATE bookings
                        SET status = 'booked'
                        WHERE id = ?
                    ");

                    $update->execute([$booking_id]);
                }

                $message = "Attendance saved successfully!";
            }

        } catch (PDOException $e) {

            $error = "Database error: " . $e->getMessage();

        }
    }
}


// Get all classes
$class_stmt = $conn->query("
    SELECT
        id,
        class_name,
        class_date,
        start_time,
        status
    FROM classes
    ORDER BY class_date DESC, start_time DESC
");

$classes = $class_stmt->fetchAll(PDO::FETCH_ASSOC);


// Selected class
$selected_class_id = (int)($_GET["class_id"] ?? $_POST["class_id"] ?? 0);

$selected_class = null;
$attendees = [];

if ($selected_class_id > 0) {

    // Get selected class information
    $class_check = $conn->prepare("
        SELECT *
        FROM classes
        WHERE id = ?
    ");

    $class_check->execute([$selected_class_id]);

    $selected_class = $class_check->fetch(PDO::FETCH_ASSOC);


    if ($selected_class) {

        // Get members who booked this class
        $attendee_stmt = $conn->prepare("
            SELECT
                b.id AS booking_id,
                b.booking_date,
                b.status AS booking_status,
                u.name,
                u.email,
                a.attendance_status,
                a.marked_at
            FROM bookings b
            INNER JOIN users u ON b.user_id = u.id
            LEFT JOIN attendance a ON b.id = a.booking_id
            WHERE b.class_id = ?
            AND b.status IN ('booked', 'attended')
            ORDER BY u.name ASC
        ");

        $attendee_stmt->execute([$selected_class_id]);

        $attendees = $attendee_stmt->fetchAll(PDO::FETCH_ASSOC);

    } else {

        $error = "Selected class was not found.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Class Attendance | True Fit Gym</title>

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
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
}

label {
    display: block;
    font-weight: bold;
    margin-bottom: 10px;
}

select {
    width: 100%;
    padding: 13px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 15px;
    background: white;
}

button {
    padding: 11px 17px;
    border: none;
    border-radius: 8px;
    background: #702dcc;
    color: white;
    font-weight: bold;
    cursor: pointer;
}

button:hover {
    background: #5720a5;
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
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}

.present {
    background: #d1e7dd;
    color: #155724;
}

.absent {
    background: #f8d7da;
    color: #842029;
}

.not-marked {
    background: #fff3cd;
    color: #856404;
}

.attendance-form {
    display: flex;
    gap: 8px;
    align-items: center;
}

.attendance-form select {
    min-width: 120px;
    padding: 9px;
}

.empty {
    color: #888;
    padding: 20px 0;
}

@media (max-width: 650px) {

    body {
        padding: 20px 12px;
    }

    .card {
        padding: 17px;
    }

    .attendance-form {
        flex-direction: column;
        align-items: stretch;
    }

}

</style>

</head>

<body>

<div class="container">

    <a href="dashboard.php" class="back-link">
        ← Back to Admin Dashboard
    </a>

    <h1>Class Attendance</h1>

    <p class="subtitle">
        View class attendees and record their attendance.
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


    <!-- Select Class -->

    <div class="card">

        <h2>Select Gym Class</h2>

        <form method="GET">

            <label for="class_id">Choose a class</label>

            <select name="class_id" id="class_id" required>

                <option value="">-- Select a Class --</option>

                <?php foreach ($classes as $class): ?>

                    <option
                        value="<?= (int)$class["id"] ?>"
                        <?= $selected_class_id === (int)$class["id"] ? "selected" : "" ?>
                    >

                        <?= htmlspecialchars($class["class_name"]) ?>
                        -
                        <?= date("d M Y", strtotime($class["class_date"])) ?>
                        (<?= ucfirst($class["status"]) ?>)

                    </option>

                <?php endforeach; ?>

            </select>

            <br><br>

            <button type="submit">View Attendees</button>

        </form>

    </div>


    <!-- Attendee List -->

    <?php if ($selected_class): ?>

        <div class="card">

            <h2>
                <?= htmlspecialchars($selected_class["class_name"]) ?>
            </h2>

            <p>
                <strong>Date:</strong>
                <?= date("d M Y", strtotime($selected_class["class_date"])) ?>
            </p>

            <p>
                <strong>Start Time:</strong>
                <?= date("h:i A", strtotime($selected_class["start_time"])) ?>
            </p>

            <h3>Booked Members (<?= count($attendees) ?>)</h3>

            <div class="table-wrapper">

                <table>

                    <thead>

                        <tr>
                            <th>Member</th>
                            <th>Email</th>
                            <th>Booking Status</th>
                            <th>Attendance</th>
                            <th>Action</th>
                        </tr>

                    </thead>

                    <tbody>

                    <?php if (count($attendees) > 0): ?>

                        <?php foreach ($attendees as $attendee): ?>

                            <tr>

                                <td>
                                    <?= htmlspecialchars($attendee["name"]) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($attendee["email"]) ?>
                                </td>

                                <td>
                                    <?= ucfirst(htmlspecialchars($attendee["booking_status"])) ?>
                                </td>

                                <td>

                                    <?php if ($attendee["attendance_status"] === "present"): ?>

                                        <span class="status present">Present</span>

                                    <?php elseif ($attendee["attendance_status"] === "absent"): ?>

                                        <span class="status absent">Absent</span>

                                    <?php else: ?>

                                        <span class="status not-marked">Not Marked</span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <form method="POST" class="attendance-form">

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= htmlspecialchars($_SESSION["csrf_token"]) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="booking_id"
                                            value="<?= (int)$attendee["booking_id"] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="class_id"
                                            value="<?= $selected_class_id ?>"
                                        >

                                        <select name="attendance_status" required>

                                            <option value="">Select</option>

                                            <option value="present">Present</option>

                                            <option value="absent">Absent</option>

                                        </select>

                                        <button type="submit" name="save_attendance">
                                            Save
                                        </button>

                                    </form>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <tr>
                            <td colspan="5" class="empty">
                                No members have booked this class yet.
                            </td>
                        </tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    <?php endif; ?>

</div>

</body>
</html>