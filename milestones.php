<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Allow only logged-in clients and freelancers.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    !in_array($_SESSION['role'], ['client', 'freelancer'], true)
) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$isClient = $_SESSION['role'] === 'client';
$error = '';
$success = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

// Handle milestone actions. Only clients may modify milestones.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isClient) {
        http_response_code(403);
        exit('Access denied. Freelancers can only view their milestones.');
    }

    $postedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($postedToken) ||
        !hash_equals($csrfToken, $postedToken)) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'add') {
            $contractId = filter_input(
                INPUT_POST,
                'contract_id',
                FILTER_VALIDATE_INT
            );

            $title = trim($_POST['title'] ?? '');
            $amount = filter_input(
                INPUT_POST,
                'amount',
                FILTER_VALIDATE_FLOAT
            );
            $dueDate = trim($_POST['due_date'] ?? '');

            if (
                !$contractId ||
                $title === '' ||
                strlen($title) > 255 ||
                $amount === false ||
                $amount === null ||
                !is_numeric($amount) ||
                $amount <= 0 ||
                $dueDate === ''
            ) {
                $error = 'Please enter a valid contract, title, amount, and due date.';
            } else {
                $date = DateTime::createFromFormat('!Y-m-d', $dueDate);
                $validDate = $date && $date->format('Y-m-d') === $dueDate;

                if (!$validDate) {
                    $error = 'Please select a valid due date.';
                } elseif ($dueDate < date('Y-m-d')) {
                    $error = 'The due date cannot be in the past.';
                } else {
                    // Verify the contract belongs to this client.
                    $check = $conn->prepare(
                        "SELECT c.id, p.bid_amount
                         FROM contracts c
                         JOIN proposals p ON p.id = c.proposal_id
                         JOIN projects pr ON pr.id = p.project_id
                         WHERE c.id = ?
                           AND pr.client_id = ?
                           AND c.status = 'active'
                           AND p.status = 'accepted'
                           AND pr.status = 'in_progress'
                         LIMIT 1"
                    );

                    if (!$check) {
                        $error = 'Unable to verify the contract.';
                    } else {
                        $check->bind_param("ii", $contractId, $userId);
                        $check->execute();
                        $contract = $check->get_result()->fetch_assoc();
                        $check->close();

                        if (!$contract) {
                            $error = 'Contract not found or unavailable for milestone creation.';
                        } elseif ($amount > (float) $contract['bid_amount']) {
                            $error = 'The milestone amount cannot exceed the accepted proposal amount.';
                        } else {
                            // Ensure total milestone amounts do not exceed the bid.
                            $sumStmt = $conn->prepare(
                                "SELECT COALESCE(SUM(amount), 0) AS total
                                 FROM milestones
                                 WHERE contract_id = ?"
                            );

                            if (!$sumStmt) {
                                $error = 'Unable to validate milestone amounts.';
                            } else {
                                $sumStmt->bind_param("i", $contractId);
                                $sumStmt->execute();
                                $sumRow = $sumStmt->get_result()->fetch_assoc();
                                $sumStmt->close();

                                $remaining = (float) $contract['bid_amount']
                                    - (float) $sumRow['total'];

                                if ($amount > $remaining) {
                                    $error = 'The amount exceeds the remaining contract budget.';
                                } else {
                                    $insert = $conn->prepare(
                                        "INSERT INTO milestones
                                         (contract_id, title, amount, due_date, status)
                                         VALUES (?, ?, ?, ?, 'pending')"
                                    );

                                    if (!$insert) {
                                        $error = 'Unable to prepare the milestone.';
                                    } else {
                                        $insert->bind_param(
                                            "isds",
                                            $contractId,
                                            $title,
                                            $amount,
                                            $dueDate
                                        );

                                        if ($insert->execute()) {
                                            $insert->close();
                                            header('Location: milestones.php?added=1');
                                            exit;
                                        } else {
                                            $error = 'Could not create the milestone.';
                                            $insert->close();
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

        } elseif ($action === 'update_status') {
            $milestoneId = filter_input(
                INPUT_POST,
                'milestone_id',
                FILTER_VALIDATE_INT
            );

            $newStatus = $_POST['status'] ?? '';
            $allowedStatuses = ['pending', 'completed'];

            if (
                !$milestoneId ||
                !in_array($newStatus, $allowedStatuses, true)
            ) {
                $error = 'Invalid milestone update.';
            } else {
                $update = $conn->prepare(
                    "UPDATE milestones m
                     JOIN contracts c ON c.id = m.contract_id
                     JOIN proposals p ON p.id = c.proposal_id
                     JOIN projects pr ON pr.id = p.project_id
                     SET m.status = ?
                     WHERE m.id = ?
                       AND pr.client_id = ?
                       AND c.status = 'active'
                       AND p.status = 'accepted'
                       AND pr.status = 'in_progress'"
                );

                if (!$update) {
                    $error = 'Unable to prepare the status update.';
                } else {
                    $update->bind_param(
                        "sii",
                        $newStatus,
                        $milestoneId,
                        $userId
                    );

                    if (!$update->execute()) {
                        $error = 'Unable to update milestone status.';
                        $update->close();
                    } else {
                        $changedRows = $update->affected_rows;
                        $update->close();

                        if ($changedRows > 0) {
                            header('Location: milestones.php?updated=1');
                            exit;
                        }

                        // An unchanged status can also be a valid request.
                        $check = $conn->prepare(
                            "SELECT m.status
                             FROM milestones m
                             JOIN contracts c ON c.id = m.contract_id
                             JOIN proposals p ON p.id = c.proposal_id
                             JOIN projects pr ON pr.id = p.project_id
                             WHERE m.id = ?
                               AND pr.client_id = ?
                               AND c.status = 'active'
                               AND p.status = 'accepted'
                               AND pr.status = 'in_progress'
                             LIMIT 1"
                        );

                        if (!$check) {
                            $error = 'Unable to verify the milestone.';
                        } else {
                            $check->bind_param("ii", $milestoneId, $userId);
                            $check->execute();
                            $check->bind_result($currentStatus);
                            $found = $check->fetch();
                            $check->close();

                            if ($found && $currentStatus === $newStatus) {
                                header('Location: milestones.php?updated=1');
                                exit;
                            } elseif (!$found) {
                                $error = 'Milestone not found or unavailable for this project.';
                            } else {
                                $error = 'Milestone status could not be changed.';
                            }
                        }
                    }
                }
            }

        } else {
            $error = 'Unknown action.';
        }
    }
}

// Success messages after redirect.
if (isset($_GET['added'])) {
    $success = 'Milestone created successfully.';
} elseif (isset($_GET['updated'])) {
    $success = 'Milestone status updated successfully.';
}

// Fetch active contracts for clients to create milestones.
// Freelancers do not receive the creation form or this contract list.
$contracts = [];

if ($isClient) {
    $contractQuery = $conn->prepare(
        "SELECT c.id AS contract_id,
                p.bid_amount,
                pr.title AS project_title,
                u.name AS freelancer_name,
                COALESCE(
                    (SELECT SUM(m.amount)
                     FROM milestones m
                     WHERE m.contract_id = c.id),
                    0
                ) AS allocated_amount
         FROM contracts c
         JOIN proposals p ON p.id = c.proposal_id
         JOIN projects pr ON pr.id = p.project_id
         JOIN users u ON u.id = p.freelancer_id
         WHERE pr.client_id = ?
           AND c.status = 'active'
           AND p.status = 'accepted'
           AND pr.status = 'in_progress'
         ORDER BY c.started_at DESC"
    );

    if ($contractQuery) {
        $contractQuery->bind_param("i", $userId);
        $contractQuery->execute();
        $contractResult = $contractQuery->get_result();

        while ($row = $contractResult->fetch_assoc()) {
            $contracts[] = $row;
        }

        $contractQuery->close();
    } else {
        $error = 'Unable to load contracts.';
    }
}

// Fetch milestones for this user's own active contracts.
// Client: only contracts for projects they own.
// Freelancer: only contracts attached to their accepted proposals.
$milestones = [];

if ($isClient) {
    $milestoneQuery = $conn->prepare(
        "SELECT m.id,
                m.contract_id,
                m.title,
                m.amount,
                m.due_date,
                m.status,
                pr.title AS project_title,
                u.name AS freelancer_name
         FROM milestones m
         JOIN contracts c ON c.id = m.contract_id
         JOIN proposals p ON p.id = c.proposal_id
         JOIN projects pr ON pr.id = p.project_id
         JOIN users u ON u.id = p.freelancer_id
         WHERE pr.client_id = ?
           AND c.status = 'active'
         ORDER BY m.due_date ASC, m.id DESC"
    );
} else {
    $milestoneQuery = $conn->prepare(
        "SELECT m.id,
                m.contract_id,
                m.title,
                m.amount,
                m.due_date,
                m.status,
                pr.title AS project_title,
                u.name AS freelancer_name
         FROM milestones m
         JOIN contracts c ON c.id = m.contract_id
         JOIN proposals p ON p.id = c.proposal_id
         JOIN projects pr ON pr.id = p.project_id
         JOIN users u ON u.id = pr.client_id
         WHERE p.freelancer_id = ?
           AND p.status = 'accepted'
           AND c.status = 'active'
         ORDER BY m.due_date ASC, m.id DESC"
    );
}

if ($milestoneQuery) {
    $milestoneQuery->bind_param("i", $userId);
    $milestoneQuery->execute();
    $milestoneResult = $milestoneQuery->get_result();

    while ($row = $milestoneResult->fetch_assoc()) {
        $milestones[] = $row;
    }

    $milestoneQuery->close();
} else {
    $error = 'Unable to load milestones.';
}

function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Milestones | FreelanceHub</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f8f6f2;
            color: #292820;
            font-family: Arial, sans-serif;
        }

        header {
            background: #292b24;
            color: #fff;
            padding: 20px 5%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }

        header h1 {
            margin: 0;
            font-size: 23px;
        }

        header a {
            color: #fff;
            text-decoration: none;
        }

        main {
            max-width: 1100px;
            margin: 32px auto;
            padding: 0 20px 40px;
        }

        .intro {
            margin-bottom: 24px;
        }

        .intro p {
            color: #77766c;
            line-height: 1.6;
        }

        .panel {
            background: #fff;
            border: 1px solid #eeece5;
            border-radius: 14px;
            padding: 24px;
            margin-bottom: 24px;
        }

        h2 {
            margin-top: 0;
            font-size: 21px;
        }

        label {
            display: block;
            margin: 14px 0 7px;
            font-weight: bold;
        }

        input, select {
            width: 100%;
            padding: 12px;
            border: 1px solid #dcd7ca;
            border-radius: 8px;
            background: #fff;
            font-size: 15px;
        }

        button, .button {
            display: inline-block;
            margin-top: 16px;
            padding: 12px 17px;
            border: 0;
            border-radius: 8px;
            background: #344536;
            color: #fff;
            font-size: 14px;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
        }

        button:hover, .button:hover {
            background: #263528;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .message {
            padding: 13px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .error {
            background: #fff0ed;
            color: #a52a20;
        }

        .success {
            background: #eaf5e9;
            color: #24552b;
        }

        .milestone {
            padding: 18px 0;
            border-bottom: 1px solid #eeece5;
        }

        .milestone:last-child {
            border-bottom: 0;
        }

        .milestone h3 {
            margin: 0 0 8px;
        }

        .muted {
            color: #77766c;
            line-height: 1.6;
        }

        .status {
            display: inline-block;
            background: #f0eee7;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            text-transform: capitalize;
        }

        .empty {
            padding: 20px;
            background: #f8f6f2;
            border-radius: 8px;
            color: #77766c;
            line-height: 1.6;
        }

        @media (max-width: 650px) {
            .grid {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 18px;
            }
        }
    </style>
</head>
<body>

<header>
    <h1>FreelanceHub · Milestones</h1>

    <?php if ($isClient): ?>
        <a href="client_dashboard.php">← Back to Client Dashboard</a>
    <?php else: ?>
        <a href="index.php">← Back to Freelancer Dashboard</a>
    <?php endif; ?>
</header>

<main>
    <section class="intro">
        <h2>Contract Milestones</h2>

        <?php if ($isClient): ?>
            <p>Create payment milestones for your active contracts and track their status.</p>
        <?php else: ?>
            <p>View payment milestones and track the status of your active contracts.</p>
        <?php endif; ?>
    </section>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($success !== '' && $isClient): ?>
        <div class="message success"><?= h($success) ?></div>
    <?php endif; ?>

    <?php if ($isClient): ?>
        <section class="panel">
            <h2>Add a Milestone</h2>

            <?php if (count($contracts) === 0): ?>
                <div class="empty">
                    No eligible active contracts were found for your client account.
                    Accept a proposal first, then return here to add milestones.
                </div>
            <?php else: ?>
                <form method="POST" action="milestones.php">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="add">

                    <label for="contract_id">Contract / Project</label>
                    <select id="contract_id" name="contract_id" required>
                        <option value="">Select a contract</option>

                        <?php foreach ($contracts as $contract): ?>
                            <option
                                value="<?= (int) $contract['contract_id'] ?>"
                                <?= (
                                    isset($_POST['contract_id']) &&
                                    (int) $_POST['contract_id'] === (int) $contract['contract_id']
                                ) ? 'selected' : '' ?>
                            >
                                <?= h($contract['project_title']) ?>
                                — Freelancer: <?= h($contract['freelancer_name']) ?>
                                — Remaining: ₹<?= number_format(
                                    max(
                                        0,
                                        (float) $contract['bid_amount'] -
                                        (float) $contract['allocated_amount']
                                    ),
                                    2
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="grid">
                        <div>
                            <label for="title">Milestone Title</label>
                            <input
                                type="text"
                                id="title"
                                name="title"
                                maxlength="255"
                                placeholder="e.g. Design approval"
                                required
                            >
                        </div>

                        <div>
                            <label for="amount">Amount (₹)</label>
                            <input
                                type="number"
                                id="amount"
                                name="amount"
                                min="0.01"
                                step="0.01"
                                placeholder="e.g. 1500"
                                required
                            >
                        </div>
                    </div>

                    <label for="due_date">Due Date</label>
                    <input
                        type="date"
                        id="due_date"
                        name="due_date"
                        min="<?= date('Y-m-d') ?>"
                        required
                    >

                    <button type="submit">Create Milestone</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section class="panel">
        <h2>Existing Milestones</h2>

        <?php if (count($milestones) === 0): ?>
            <div class="empty">
                No milestones have been created yet.
                <?php if ($isClient): ?>
                    Use the form above to create the first one.
                <?php else: ?>
                    Your client can create milestones for your active contract.
                <?php endif; ?>
            </div>
        <?php else: ?>
            <?php foreach ($milestones as $milestone): ?>
                <article class="milestone">
                    <h3><?= h($milestone['title']) ?></h3>

                    <p class="muted">
                        Project: <?= h($milestone['project_title']) ?><br>

                        <?php if ($isClient): ?>
                            Freelancer: <?= h($milestone['freelancer_name']) ?><br>
                        <?php else: ?>
                            Client: <?= h($milestone['freelancer_name']) ?><br>
                        <?php endif; ?>

                        Contract ID: <?= (int) $milestone['contract_id'] ?><br>
                        Amount: ₹<?= number_format((float) $milestone['amount'], 2) ?><br>
                        Due date: <?= h($milestone['due_date']) ?>
                    </p>

                    <span class="status"><?= h($milestone['status']) ?></span>

                    <?php if (
                        $isClient &&
                        in_array($milestone['status'], ['pending', 'completed'], true)
                    ): ?>
                        <form method="POST" action="milestones.php">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= h($csrfToken) ?>"
                            >
                            <input type="hidden" name="action" value="update_status">
                            <input
                                type="hidden"
                                name="milestone_id"
                                value="<?= (int) $milestone['id'] ?>"
                            >

                            <label for="status-<?= (int) $milestone['id'] ?>">
                                Update Status
                            </label>

                            <select
                                id="status-<?= (int) $milestone['id'] ?>"
                                name="status"
                            >
                                <option
                                    value="pending"
                                    <?= $milestone['status'] === 'pending' ? 'selected' : '' ?>
                                >
                                    Pending
                                </option>
                                <option
                                    value="completed"
                                    <?= $milestone['status'] === 'completed' ? 'selected' : '' ?>
                                >
                                    Completed
                                </option>
                            </select>

                            <button type="submit">Save Status</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>

</body>
</html>