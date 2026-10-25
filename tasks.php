<?php
session_start();

require_once 'config/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];

if (!in_array($role, ['client', 'freelancer'], true)) {
    exit('Access denied.');
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$message = '';
$error = '';

/*
 * Get tasks only for contracts belonging to
 * the logged-in client or assigned freelancer.
 */
$sql = "
    SELECT
        t.id,
        t.title,
        t.description,
        t.status,
        t.created_at,
        c.id AS contract_id,
        p.id AS project_id,
        p.title AS project_title,
        p.client_id,
        pr.freelancer_id,
        u.name AS freelancer_name
    FROM tasks t
    INNER JOIN contracts c ON t.contract_id = c.id
    INNER JOIN proposals pr ON c.proposal_id = pr.id
    INNER JOIN projects p ON pr.project_id = p.id
    INNER JOIN users u ON pr.freelancer_id = u.id
    WHERE
        (p.client_id = ? AND ? = 'client')
        OR
        (pr.freelancer_id = ? AND ? = 'freelancer')
    ORDER BY t.created_at DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    exit('Could not load tasks. Please check the database query.');
}

$stmt->bind_param('isis', $userId, $role, $userId, $role);
$stmt->execute();
$result = $stmt->get_result();

$tasks = [];

while ($row = $result->fetch_assoc()) {
    $tasks[] = $row;
}

$stmt->close();

/*
 * Handle task creation and status updates.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Invalid request. Please refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create' && $role === 'client') {
            $contractId = filter_input(
                INPUT_POST,
                'contract_id',
                FILTER_VALIDATE_INT
            );

            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if (!$contractId || $title === '' || strlen($title) > 200) {
                $error = 'Enter a valid contract and task title (maximum 200 characters).';
            } else {
                /*
                 * Verify the contract belongs to this client
                 * and is active.
                 */
                $check = $conn->prepare("
                    SELECT c.id
                    FROM contracts c
                    INNER JOIN proposals pr ON c.proposal_id = pr.id
                    INNER JOIN projects p ON pr.project_id = p.id
                    WHERE c.id = ?
                      AND p.client_id = ?
                      AND c.status = 'active'
                      AND p.status = 'in_progress'
                    LIMIT 1
                ");

                $check->bind_param('ii', $contractId, $userId);
                $check->execute();
                $allowed = $check->get_result()->num_rows > 0;
                $check->close();

                if (!$allowed) {
                    $error = 'You can create tasks only for your active contracts.';
                } else {
                    $insert = $conn->prepare("
                        INSERT INTO tasks
                            (contract_id, title, description, status)
                        VALUES (?, ?, ?, 'todo')
                    ");

                    $insert->bind_param(
                        'iss',
                        $contractId,
                        $title,
                        $description
                    );

                    if ($insert->execute()) {
                        $insert->close();
                        header('Location: tasks.php?created=1');
                        exit;
                    }

                    $insert->close();
                    $error = 'Could not create the task. Please try again.';
                }
            }
        } elseif ($action === 'update_status') {
            $taskId = filter_input(
                INPUT_POST,
                'task_id',
                FILTER_VALIDATE_INT
            );

            $newStatus = $_POST['status'] ?? '';
            $validStatuses = ['todo', 'in_progress', 'done'];

            if (!$taskId || !in_array($newStatus, $validStatuses, true)) {
                $error = 'Invalid task or status.';
            } else {
                /*
                 * Update only if the logged-in user belongs
                 * to the task's client/freelancer pair.
                 */
                $update = $conn->prepare("
                    UPDATE tasks t
                    INNER JOIN contracts c ON t.contract_id = c.id
                    INNER JOIN proposals pr ON c.proposal_id = pr.id
                    INNER JOIN projects p ON pr.project_id = p.id
                    SET t.status = ?
                    WHERE t.id = ?
                      AND (
                          (p.client_id = ? AND ? = 'client')
                          OR
                          (pr.freelancer_id = ? AND ? = 'freelancer')
                      )
                ");

                $update->bind_param(
                    'siisis',
                    $newStatus,
                    $taskId,
                    $userId,
                    $role,
                    $userId,
                    $role
                );

                if ($update->execute() && $update->affected_rows >= 0) {
                    $update->close();
                    header('Location: tasks.php?updated=1');
                    exit;
                }

                $update->close();
                $error = 'Could not update the task status.';
            }
        } else {
            $error = 'Invalid action.';
        }
    }

    /*
     * Refresh the task list after a failed POST,
     * so the page can still display existing tasks.
     */
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isis', $userId, $role, $userId, $role);
    $stmt->execute();
    $result = $stmt->get_result();
    $tasks = [];

    while ($row = $result->fetch_assoc()) {
        $tasks[] = $row;
    }

    $stmt->close();
}

if (isset($_GET['created'])) {
    $message = 'Task created successfully.';
} elseif (isset($_GET['updated'])) {
    $message = 'Task status updated successfully.';
}

/*
 * Get active contracts available to this client
 * for the task creation dropdown.
 */
$activeContracts = [];

if ($role === 'client') {
    $contractQuery = $conn->prepare("
        SELECT
            c.id,
            p.title AS project_title,
            u.name AS freelancer_name
        FROM contracts c
        INNER JOIN proposals pr ON c.proposal_id = pr.id
        INNER JOIN projects p ON pr.project_id = p.id
        INNER JOIN users u ON pr.freelancer_id = u.id
        WHERE p.client_id = ?
          AND c.status = 'active'
          AND p.status = 'in_progress'
        ORDER BY c.started_at DESC
    ");

    $contractQuery->bind_param('i', $userId);
    $contractQuery->execute();
    $contractResult = $contractQuery->get_result();

    while ($row = $contractResult->fetch_assoc()) {
        $activeContracts[] = $row;
    }

    $contractQuery->close();
}

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tasks | FreelanceHub</title>

    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f6f3eb;
            color: #24352b;
        }

        header {
            background: #203d30;
            color: white;
            padding: 20px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        header a {
            color: white;
            text-decoration: none;
        }

        main {
            width: min(1000px, 92%);
            margin: 32px auto;
        }

        .panel, .task {
            background: white;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 3px 12px #203d3010;
        }

        h1, h2 { margin-top: 0; }

        label {
            display: block;
            margin: 14px 0 6px;
            font-weight: bold;
        }

        input, textarea, select {
            width: 100%;
            padding: 11px;
            border: 1px solid #d5dcd5;
            border-radius: 7px;
            font: inherit;
            background: white;
        }

        button {
            margin-top: 14px;
            padding: 11px 16px;
            border: 0;
            border-radius: 7px;
            background: #285641;
            color: white;
            font-weight: bold;
            cursor: pointer;
        }

        .status {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #edf3e9;
            font-size: 13px;
        }

        .success {
            background: #e7f5e9;
            color: #205c31;
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 16px;
        }

        .error {
            background: #ffeded;
            color: #8b2525;
            padding: 12px;
            border-radius: 7px;
            margin-bottom: 16px;
        }

        .muted { color: #69766d; font-size: 14px; }

        .task-form {
            display: flex;
            gap: 10px;
            align-items: end;
            flex-wrap: wrap;
        }

        .task-form > div { flex: 1; min-width: 180px; }

        .task-form button { margin: 0; }

        @media (max-width: 600px) {
            .panel, .task { padding: 16px; }
        }
    </style>
</head>

<body>
<header>
    <strong>freelancehub.</strong>
    <a href="<?= $role === 'client'
        ? 'client_dashboard.php'
        : 'freelancer_dashboard.php' ?>">← Back to Dashboard</a>
</header>

<main>
    <h1>Task Management</h1>
    <p class="muted">Organize and track work for your active contracts.</p>

    <?php if ($message !== ''): ?>
        <div class="success"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($role === 'client'): ?>
        <section class="panel">
            <h2>Create a Task</h2>

            <?php if (count($activeContracts) > 0): ?>
                <form method="POST">
                    <input type="hidden" name="csrf_token"
                           value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="create">

                    <label for="contract_id">Active Project / Freelancer</label>
                    <select name="contract_id" id="contract_id" required>
                        <option value="">Choose a contract</option>
                        <?php foreach ($activeContracts as $contract): ?>
                            <option value="<?= (int) $contract['id'] ?>">
                                <?= e($contract['project_title']) ?>
                                — <?= e($contract['freelancer_name']) ?>
                                (Contract #<?= (int) $contract['id'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <label for="title">Task Title</label>
                    <input id="title" name="title" maxlength="200"
                           required placeholder="e.g. Design the homepage">

                    <label for="description">Description</label>
                    <textarea id="description" name="description"
                              rows="4"
                              placeholder="Describe the work to be completed"></textarea>

                    <button type="submit">Create Task</button>
                </form>
            <?php else: ?>
                <p class="muted">
                    No active contracts are available for task creation.
                    Accept a proposal and ensure its project is in progress first.
                </p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section>
        <h2>Your Tasks (<?= count($tasks) ?>)</h2>

        <?php if (count($tasks) === 0): ?>
            <div class="panel">
                <p>No tasks found yet.</p>
                <p class="muted">Tasks associated with your contracts will appear here.</p>
            </div>
        <?php else: ?>
            <?php foreach ($tasks as $task): ?>
                <article class="task">
                    <h3><?= e($task['title']) ?></h3>

                    <p class="muted">
                        Project: <?= e($task['project_title']) ?>
                        · Contract #<?= (int) $task['contract_id'] ?>
                    </p>

                    <?php if ($task['description'] !== null &&
                              $task['description'] !== ''): ?>
                        <p><?= nl2br(e($task['description'])) ?></p>
                    <?php endif; ?>

                    <p>
                        Status:
                        <span class="status">
                            <?php
                            $labels = [
                                'todo' => 'To Do',
                                'in_progress' => 'In Progress',
                                'done' => 'Done'
                            ];
                            echo e($labels[$task['status']] ?? $task['status']);
                            ?>
                        </span>
                    </p>

                    <p class="muted">
                        Created: <?= e($task['created_at']) ?>
                    </p>

                    <form method="POST" class="task-form">
                        <input type="hidden" name="csrf_token"
                               value="<?= e($_SESSION['csrf_token']) ?>">
                        <input type="hidden" name="action"
                               value="update_status">
                        <input type="hidden" name="task_id"
                               value="<?= (int) $task['id'] ?>">

                        <div>
                            <label for="status-<?= (int) $task['id'] ?>">
                                Update Status
                            </label>
                            <select name="status"
                                    id="status-<?= (int) $task['id'] ?>">
                                <option value="todo"
                                    <?= $task['status'] === 'todo' ? 'selected' : '' ?>>
                                    To Do
                                </option>
                                <option value="in_progress"
                                    <?= $task['status'] === 'in_progress' ? 'selected' : '' ?>>
                                    In Progress
                                </option>
                                <option value="done"
                                    <?= $task['status'] === 'done' ? 'selected' : '' ?>>
                                    Done
                                </option>
                            </select>
                        </div>

                        <button type="submit">Save Status</button>
                    </form>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
