<?php

session_start();

require_once "../config/db.php";

$message = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $email = trim($_POST["email"]);
    $password = $_POST["password"];

    if (empty($email) || empty($password)) {

        $message = "Please enter your email and password.";

    } else {

        $sql = "SELECT * FROM users WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->execute([$email]);

        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user["password"])) {

            session_regenerate_id(true);

            $_SESSION["user_id"] = $user["id"];
            $_SESSION["user_name"] = $user["name"];
            $_SESSION["user_email"] = $user["email"];
            $_SESSION["user_role"] = $user["role"];

            // Redirect everyone to homepage
            header("Location: ../index.php");
            exit;

        } else {

            $message = "Incorrect email or password.";

        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | True Fit Gym</title>

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

        .login-container {
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
        }

        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 18px;
            border: 1px solid #ddd;
            border-radius: 7px;
            font-size: 15px;
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

        .error {
            background: #ffe8e8;
            color: #b42318;
            padding: 12px;
            margin-bottom: 18px;
            border-radius: 7px;
            text-align: center;
        }

        .register-link {
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

    <div class="login-container">

        <h1>Welcome Back!</h1>

        <p class="subtitle">
            Login to your True Fit Gym account
        </p>

        <?php if (!empty($message)): ?>

            <div class="error">
                <?php echo htmlspecialchars($message); ?>
            </div>

        <?php endif; ?>

        <form method="POST" action="">

            <label>Email Address</label>

            <input
                type="email"
                name="email"
                placeholder="Enter your email"
                required
            >

            <label>Password</label>

            <input
                type="password"
                name="password"
                placeholder="Enter your password"
                required
            >

            <button type="submit">
                Login
            </button>

        </form>

        <p class="register-link">
            Don't have an account?
            <a href="register.php">Register</a>
        </p>

    </div>

</body>

</html>