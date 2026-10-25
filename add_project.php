<?php
session_start();

require_once __DIR__ . '/config/db.php';

$message = '';
$error = '';

/* Allow only logged-in clients */
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'client') {
    http_response_code(403);
    exit('Access denied. Please log in with a client account.');
}

$client_id = (int) $_SESSION['user_id'];

/* CSRF protection */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $budget = $_POST['budget'] ?? '';
    $deadline = $_POST['deadline'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $error = 'Your session has expired. Refresh the page and try again.';
    } elseif (
        $title === '' ||
        $description === '' ||
        $category === '' ||
        $budget === '' ||
        !is_numeric($budget) ||
        !is_finite((float) $budget) ||
        (float) $budget < 0 ||
        (float) $budget > 99999999.99
    ) {
        $error = 'Please fill in all required fields correctly.';
    } else {
        $status = 'open';

        $stmt = $conn->prepare(
            "INSERT INTO projects
            (client_id, title, description, category, budget, deadline, status)
            VALUES (?, ?, ?, ?, ?, NULLIF(?, ''), ?)"
        );

        if (!$stmt) {
            $error = 'Could not prepare the project. Please try again.';
        } else {
            $amount = (float) $budget;

            $stmt->bind_param(
                "isssdss",
                $client_id,
                $title,
                $description,
                $category,
                $amount,
                $deadline,
                $status
            );

            if ($stmt->execute()) {
                $stmt->close();

                header('Location: client_dashboard.php');
                exit;
            } else {
                error_log('Project creation error: ' . $stmt->error);
                $error = 'Could not save the project. Please try again.';
                $stmt->close();
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
    <title>Add a Project</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 35px 18px;
            font-family: Arial, sans-serif;
            background: #f8f6f2;
            color: #292929;
        }

        .container {
            max-width: 650px;
            margin: auto;
            background: white;
            padding: 32px;
            border-radius: 18px;
            box-shadow: 0 5px 25px #0000000c;
        }

        h1 {
            margin-top: 0;
        }

        .subtitle {
            color: #777;
            margin-bottom: 28px;
        }

        label {
            display: block;
            margin: 18px 0 8px;
            font-weight: bold;
        }

        input, textarea, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 9px;
            font: inherit;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        button {
            margin-top: 24px;
            width: 100%;
            padding: 14px;
            background: #292929;
            color: white;
            border: none;
            border-radius: 9px;
            font-size: 16px;
            cursor: pointer;
        }

        .error {
            color: #b42318;
            background: #fff0ee;
            padding: 12px;
            border-radius: 8px;
        }

        .back {
            display: inline-block;
            margin-top: 20px;
            color: #805c30;
            text-decoration: none;
        }
    </style>
</head>

<body>
<div class="container">
    <h1>Post a new project</h1>
    <p class="subtitle">
        Share your idea and find the right people to collaborate with.
    </p>

    <?php if ($error !== ''): ?>
        <p class="error">
            <?= htmlspecialchars($error) ?>
        </p>
    <?php endif; ?>

    <form method="POST">
        <input
        type="hidden"
        name="csrf_token"
        value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
    >
        <label for="title">Project title</label>
        <input id="title" name="title" required
               placeholder="e.g. Modern Portfolio Website">

        <label for="description">Project description</label>
        <textarea id="description" name="description" required
                  placeholder="Describe your project..."></textarea>

        <label for="category">Category</label>
        <select id="category" name="category" required>
            <option value="">Choose a category</option>
            <option value="Web Development">Web Development</option>
            <option value="UI/UX Design">UI/UX Design</option>
            <option value="Mobile App">Mobile App</option>
            <option value="Content Writing">Content Writing</option>
            <option value="Marketing">Marketing</option>
            <option value="Other">Other</option>
        </select>

        <label for="budget">Budget (₹)</label>
        <input id="budget" name="budget" type="number"
               min="0" step="0.01" required placeholder="5000">

        <label for="deadline">Deadline (optional)</label>
        <input id="deadline" name="deadline" type="date">

        <button type="submit">Publish Project</button>
    </form>

    <a class="back" href="index.php">← Back to projects</a>
</div>
</body>
</html>
