<?php
session_start();

require_once __DIR__ . '/../config/db.php';

$message = "";
$message_type = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $name = trim($_POST["name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";

    if (empty($name) || empty($email) || empty($password)) {

        $message = "Please fill in all required fields.";
        $message_type = "error";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {

        $message = "Please enter a valid email address.";
        $message_type = "error";

    } elseif (strlen($password) < 8) {

        $message = "Password must contain at least 8 characters.";
        $message_type = "error";

    } else {

        try {

            $check = $conn->prepare(
                "SELECT id FROM users WHERE email = ?"
            );

            $check->execute([$email]);

            if ($check->fetch()) {

                $message = "This email is already registered.";
                $message_type = "error";

            } else {

                $hashedPassword = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $sql = "
                    INSERT INTO users
                    (name, email, phone, password, role)
                    VALUES (?, ?, ?, ?, 'member')
                ";

                $stmt = $conn->prepare($sql);

                $stmt->execute([
                    $name,
                    $email,
                    $phone,
                    $hashedPassword
                ]);

                // Get the newly registered member ID
                $userId = $conn->lastInsertId();

                // Create login session automatically
                session_regenerate_id(true);

                $_SESSION['user_id'] = $userId;
                $_SESSION['user_name'] = $name;
                $_SESSION['user_email'] = $email;
                $_SESSION['user_role'] = 'member';

                // Return to homepage as a logged-in member
                header("Location: ../index.php");
                exit;

            }

        } catch (PDOException $e) {

            $message = "Something went wrong. Please try again.";
            $message_type = "error";

        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Register | True Fit Gym</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            background: #f8f5ff;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }

        .register-container {
            background: white;
            width: 100%;
            max-width: 430px;
            padding: 35px;
            border-radius: 15px;
            box-shadow: 0 5px 25px rgba(0,0,0,0.08);
        }

        h1 {
            color: #702bd1;
            text-align: center;
            margin-bottom: 10px;
        }

        .subtitle {
            text-align: center;
            color: #777;
            margin-bottom: 25px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
            color: #333;
        }

        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 18px;
            border: 1px solid #ddd;
            border-radius: 7px;
            font-size: 15px;
        }

        input:focus {
            outline: none;
            border-color: #702bd1;
        }

        button {
            width: 100%;
            padding: 13px;
            background: #702bd1;
            color: white;
            border: none;
            border-radius: 7px;
            font-size: 16px;
            cursor: pointer;
        }

        button:hover {
            background: #5720a8;
        }

        .message {
            padding: 12px;
            margin-bottom: 18px;
            border-radius: 7px;
            text-align: center;
        }

        .success {
            background: #e5f8e9;
            color: #187532;
        }

        .error {
            background: #ffe8e8;
            color: #b42318;
        }

        .login-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }

        a {
            color: #702bd1;
            text-decoration: none;
            font-weight: bold;
        }

    </style>

</head>

<body>

    <div class="register-container">

        <h1>Join True Fit Gym</h1>

        <p class="subtitle">
            Create your free member account
        </p>

        <?php if (!empty($message)): ?>

            <div class="message <?= htmlspecialchars($message_type) ?>">
                <?= htmlspecialchars($message) ?>
            </div>

        <?php endif; ?>

        <form method="POST" action="">

            <label>Full Name *</label>

            <input
                type="text"
                name="name"
                placeholder="Enter your full name"
                value="<?= htmlspecialchars($_POST['name'] ?? '') ?>"
                required
            >

            <label>Email Address *</label>

            <input
                type="email"
                name="email"
                placeholder="Enter your email"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                required
            >

            <label>Phone Number</label>

            <input
                type="text"
                name="phone"
                placeholder="Enter your phone number"
                value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>"
            >

            <label>Password *</label>

            <input
                type="password"
                name="password"
                placeholder="Minimum 8 characters"
                minlength="8"
                required
            >

            <button type="submit">
                Create Account
            </button>

        </form>

        <p class="login-link">
            Already have an account?
            <a href="login.php">Login</a>
        </p>

    </div>

</body>
</html>