<?php
session_start();

require_once "../config/db.php";

// Admin access only
if (!isset($_SESSION["user_id"]) || ($_SESSION["user_role"] ?? "") !== "admin") {
    header("Location: ../auth/login.php");
    exit;
}

// Total members
$total_members = $conn->query("
    SELECT COUNT(*)
    FROM users
    WHERE role = 'member'
")->fetchColumn();

// Active memberships
$active_memberships = $conn->query("
    SELECT COUNT(*)
    FROM memberships
    WHERE status = 'active'
    AND start_date <= CURDATE()
    AND end_date >= CURDATE()
")->fetchColumn();

// Today's scheduled classes
$today_classes = $conn->query("
    SELECT COUNT(*)
    FROM classes
    WHERE class_date = CURDATE()
    AND status = 'scheduled'
")->fetchColumn();

// Bookings this week (Monday to Sunday)
$weekly_bookings = $conn->query("
    SELECT COUNT(*)
    FROM bookings
    WHERE YEARWEEK(booking_date, 1) = YEARWEEK(CURDATE(), 1)
    AND status IN ('booked', 'attended')
")->fetchColumn();

// Pending membership applications
$pending_memberships = $conn->query("
    SELECT COUNT(*)
    FROM memberships
    WHERE status = 'pending'
")->fetchColumn();

// Recent members
$recent_members_stmt = $conn->query("
    SELECT id, name, email, created_at
    FROM users
    WHERE role = 'member'
    ORDER BY created_at DESC
    LIMIT 5
");

$recent_members = $recent_members_stmt->fetchAll(PDO::FETCH_ASSOC);

// Upcoming classes
$upcoming_classes_stmt = $conn->query("
    SELECT
        c.class_name,
        c.class_date,
        c.start_time,
        c.room,
        c.status,
        t.name AS trainer_name
    FROM classes c
    LEFT JOIN trainers t ON c.trainer_id = t.id
    WHERE c.class_date >= CURDATE()
    AND c.status = 'scheduled'
    ORDER BY c.class_date ASC, c.start_time ASC
    LIMIT 5
");

$upcoming_classes = $upcoming_classes_stmt->fetchAll(PDO::FETCH_ASSOC);

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Admin Dashboard | True Fit Gym</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f7f5ff;
    color: #333;
}

.container {
    max-width: 1200px;
    margin: auto;
    padding: 40px 25px;
}

.header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
    margin-bottom: 30px;
}

.header h1 {
    color: #702dcc;
    margin: 0 0 8px;
}

.header p {
    color: #777;
    margin: 0;
}

.logout {
    background: #dc3545;
    color: white;
    padding: 12px 18px;
    border-radius: 8px;
    text-decoration: none;
    font-weight: bold;
}

.section-title {
    margin: 35px 0 18px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 20px;
}

.stat-card {
    background: white;
    padding: 25px;
    border-radius: 13px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.04);
}

.stat-card p {
    color: #777;
    margin: 0 0 12px;
}

.stat-card h2 {
    font-size: 32px;
    color: #702dcc;
    margin: 0;
}

.menu-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
}

.menu-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    text-decoration: none;
    color: #333;
    font-weight: bold;
    border: 1px solid #eee;
    transition: 0.2s;
}

.menu-card:hover {
    border-color: #702dcc;
    color: #702dcc;
    transform: translateY(-3px);
}

.content-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.panel {
    background: white;
    padding: 22px;
    border-radius: 13px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.04);
    overflow-x: auto;
}

.panel h3 {
    margin-top: 0;
    color: #702dcc;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 400px;
}

th, td {
    text-align: left;
    padding: 13px 10px;
    border-bottom: 1px solid #eee;
    font-size: 14px;
}

th {
    background: #f7f3ff;
    color: #702dcc;
}

.empty {
    color: #888;
    padding: 15px 0;
}

@media (max-width: 800px) {

    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .menu-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .content-grid {
        grid-template-columns: 1fr;
    }

}

@media (max-width: 500px) {

    .stats-grid,
    .menu-grid {
        grid-template-columns: 1fr;
    }

    .container {
        padding: 25px 15px;
    }

}

</style>

</head>

<body>

<div class="container">

    <!-- Header -->

    <div class="header">

        <div>
            <h1>Admin Dashboard</h1>

            <p>
                Welcome back,
                <?= htmlspecialchars($_SESSION["user_name"] ?? "Admin") ?>!
                Manage your gym activities here.
            </p>
        </div>

        <a href="../auth/logout.php" class="logout">
            Logout
        </a>

    </div>


    <!-- Statistics -->

    <h2 class="section-title">Dashboard Overview</h2>

    <div class="stats-grid">

        <div class="stat-card">
            <p>Total Members</p>
            <h2><?= number_format($total_members) ?></h2>
        </div>

        <div class="stat-card">
            <p>Active Memberships</p>
            <h2><?= number_format($active_memberships) ?></h2>
        </div>

        <div class="stat-card">
            <p>Today's Classes</p>
            <h2><?= number_format($today_classes) ?></h2>
        </div>

        <div class="stat-card">
            <p>Bookings This Week</p>
            <h2><?= number_format($weekly_bookings) ?></h2>
        </div>

        <div class="stat-card">
            <p>Pending Applications</p>
            <h2><?= number_format($pending_memberships) ?></h2>
        </div>

    </div>


    <!-- Management Menu -->

    <h2 class="section-title">Management</h2>

    <div class="menu-grid">

        <a href="members.php" class="menu-card">
            Manage Members →
        </a>

        <a href="manage-plans.php" class="menu-card">
            Manage Plans →
        </a>

        <a href="manage-trainers.php" class="menu-card">
            Manage Trainers →
        </a>

        <a href="manage-classes.php" class="menu-card">
            Manage Classes →
        </a>

        <a href="attendance.php" class="menu-card">
            Class Attendance →
        </a>

        <a href="members.php" class="menu-card">
            Membership Applications →
        </a>
        <a href="verify-payments.php" class="menu-card">
            Verify Payments →
        </a>

    </div>


    <!-- Recent Members and Upcoming Classes -->

    <h2 class="section-title">Recent Activities</h2>

    <div class="content-grid">

        <div class="panel">

            <h3>Recent Members</h3>

            <table>

                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Joined</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($recent_members) > 0): ?>

                    <?php foreach ($recent_members as $member): ?>

                        <tr>
                            <td><?= htmlspecialchars($member["name"]) ?></td>

                            <td><?= htmlspecialchars($member["email"]) ?></td>

                            <td>
                                <?= date("d M Y", strtotime($member["created_at"])) ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="3">No members found.</td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>


        <div class="panel">

            <h3>Upcoming Classes</h3>

            <table>

                <thead>
                    <tr>
                        <th>Class</th>
                        <th>Trainer</th>
                        <th>Date</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($upcoming_classes) > 0): ?>

                    <?php foreach ($upcoming_classes as $class): ?>

                        <tr>
                            <td><?= htmlspecialchars($class["class_name"]) ?></td>

                            <td><?= htmlspecialchars($class["trainer_name"] ?? "Not assigned") ?></td>

                            <td>
                                <?= date("d M Y", strtotime($class["class_date"])) ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="3">No upcoming classes.</td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

</body>
</html>