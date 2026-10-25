<?php
session_start();

require_once __DIR__ . '/config/db.php';

$error = '';
$name = '';
$email = '';
$role = 'freelancer';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $role = $_POST['role'] ?? 'freelancer';

    if ($name === '' || $email === '' ||
        $password === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif (strlen($name) > 100) {
        $error = 'Name must not exceed 100 characters.';
    } elseif (strlen($email) > 150 ||
              !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!in_array($role, ['client', 'freelancer'], true)) {
        $error = 'Please select a valid account type.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must contain at least 8 characters.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } else {
        $check = $conn->prepare(
            "SELECT id FROM users WHERE email = ? LIMIT 1"
        );

        if (!$check) {
            $error = 'Unable to process registration. Please try again.';
        } else {
            $check->bind_param("s", $email);
            $check->execute();
            $existingUser = $check->get_result()->fetch_assoc();
            $check->close();

            if ($existingUser) {
                $error = 'An account with this email already exists.';
            } else {
                $passwordHash = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $conn->prepare(
                    "INSERT INTO users (name, email, password, role)
                     VALUES (?, ?, ?, ?)"
                );

                if (!$stmt) {
                    $error = 'Unable to create your account. Please try again.';
                } else {
                    $stmt->bind_param(
                        "ssss",
                        $name,
                        $email,
                        $passwordHash,
                        $role
                    );

                    if ($stmt->execute()) {
                        $stmt->close();

                        header('Location: login.php?registered=1');
                        exit;
                    }

                    // Handle a duplicate email if the database
                    // has a UNIQUE index on the email column.
                    if ($stmt->errno === 1062) {
                        $error = 'An account with this email already exists.';
                    } else {
                        $error = 'Registration failed. Please try again.';
                    }

                    $stmt->close();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | FreelanceHub</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 20px;
            background: #f8f6f2;
            color: #292820;
            font-family: Arial, sans-serif;
        }

        .card {
            width: 100%;
            max-width: 450px;
            padding: 32px;
            background: #fff;
            border: 1px solid #eeece5;
            border-radius: 16px;
        }

        h1 {
            margin-top: 0;
        }

        p {
            color: #77766c;
            line-height: 1.6;
        }

        label {
            display: block;
            margin: 17px 0 8px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 13px;
            border: 1px solid #dcd7ca;
            border-radius: 8px;
            font-size: 16px;
            background: #fff;
        }

        button {
            width: 100%;
            margin-top: 24px;
            padding: 14px;
            border: 0;
            border-radius: 8px;
            background: #292b24;
            color: #fff;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #414438;
        }

        .error {
            padding: 12px;
            color: #a52a20;
            background: #fff0ed;
            border-radius: 8px;
            line-height: 1.5;
        }

        .link {
            display: inline-block;
            margin-top: 20px;
            color: #806334;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <main class="card">
        <h1>Create an account</h1>
        <p>Join FreelanceHub as a client or freelancer.</p>

        <?php if ($error !== ''): ?>
            <div class="error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <label for="name">Full name</label>
            <input
                type="text"
                id="name"
                name="name"
                maxlength="100"
                value="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="name"
                required
            >

            <label for="email">Email address</label>
            <input
                type="email"
                id="email"
                name="email"
                maxlength="150"
                value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="email"
                required
            >

            <label for="role">Account type</label>
            <select id="role" name="role" required>
                <option value="freelancer"
                    <?= $role === 'freelancer' ? 'selected' : '' ?>>
                    Freelancer
                </option>
                <option value="client"
                    <?= $role === 'client' ? 'selected' : '' ?>>
                    Client
                </option>
            </select>

            <label for="password">Password (minimum 8 characters)</label>
            <input
                type="password"
                id="password"
                name="password"
                minlength="8"
                autocomplete="new-password"
                required
            >

            <label for="confirm_password">Confirm password</label>
            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                minlength="8"
                autocomplete="new-password"
                required
            >

            <button type="submit">Create Account</button>
        </form>

        <a class="link" href="login.php">Already have an account? Log in</a>
    </main>
</body>
</html>
