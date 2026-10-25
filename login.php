
<?php
session_start();

require_once __DIR__ . '/config/db.php';

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = $conn->prepare(
            "SELECT id, name, email, password, role
             FROM users
             WHERE email = ?
             LIMIT 1"
        );

        if (!$stmt) {
            $error = 'Unable to process login. Please try again.';
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();

            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            $stmt->close();

            $validPassword = false;

            if ($user) {
                $storedPassword = $user['password'];

                // Verify hashed passwords.
                if (password_verify($password, $storedPassword)) {
                    $validPassword = true;
                } elseif (hash_equals($storedPassword, $password)) {
                    // Support older plaintext passwords and upgrade them.
                    $validPassword = true;

                    $newHash = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    $update = $conn->prepare(
                        "UPDATE users SET password = ? WHERE id = ?"
                    );

                    if ($update) {
                        $userId = (int) $user['id'];

                        $update->bind_param(
                            "si",
                            $newHash,
                            $userId
                        );

                        $update->execute();
                        $update->close();
                    }
                }
            }

            if (!$user || !$validPassword) {
                $error = 'Invalid email or password.';
            } else {
                // Normalize the role before using it.
                $role = strtolower(trim($user['role']));

                // Allow only recognized account roles.
                if (!in_array(
                    $role,
                    ['client', 'freelancer', 'admin'],
                    true
                )) {
                    $error = 'Your account role is not recognized. Please contact support.';
                } else {
                    // Create a fresh session after successful login.
                    session_regenerate_id(true);

                    $_SESSION['user_id'] = (int) $user['id'];
                    $_SESSION['role'] = $role;
                    $_SESSION['user_name'] = $user['name'];

                    // Redirect users according to their roles.
                    if ($role === 'client') {
                        header('Location: client_dashboard.php');
                        exit;
                    } elseif ($role === 'freelancer') {
                        header('Location: index.php');
                        exit;
                    } elseif ($role === 'admin') {
                        header('Location: admin_dashboard.php');
                        exit;
                    }
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

    <title>Login | FreelanceHub</title>

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
            max-width: 430px;
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
            margin: 18px 0 8px;
            font-weight: bold;
        }

        input {
            width: 100%;
            padding: 13px;
            border: 1px solid #dcd7ca;
            border-radius: 8px;
            font-size: 16px;
        }

        button {
            width: 100%;
            margin-top: 24px;
            padding: 14px;
            border: 0;
            border-radius: 8px;
            background: #292b24;
            color: white;
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

        .back {
            display: inline-block;
            margin-top: 20px;
            color: #806334;
            text-decoration: none;
        }

        .back:hover {
            text-decoration: underline;
        }
    </style>
</head>

<body>
    <main class="card">
        <h1>Welcome back</h1>
        <p>Log in to your FreelanceHub account.</p>

        <?php if ($error !== ''): ?>
            <div class="error">
                <?= htmlspecialchars(
                    $error,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <label for="email">Email address</label>

            <input
                type="email"
                id="email"
                name="email"
                value="<?= htmlspecialchars(
                    $email,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>"
                required
                autocomplete="email"
            >

            <label for="password">Password</label>

            <input
                type="password"
                id="password"
                name="password"
                required
                autocomplete="current-password"
            >

            <button type="submit">Log In</button>
        </form>

        <a class="back" href="index.php">
            &larr; Back to projects
        </a>
    </main>
</body>
</html>
