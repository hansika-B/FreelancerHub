
<?php
session_start();
require_once 'config/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

$user_id = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];

if (!in_array($role, ['client', 'freelancer'], true)) {
    exit('Access denied.');
}

$message = '';
$error = '';

// Create a CSRF token for secure form submissions.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// Verify that a contract belongs to the logged-in user.
$ownershipSql = "
    SELECT c.id AS contract_id,
           c.status AS contract_status,
           p.freelancer_id,
           p.bid_amount
    FROM contracts c
    JOIN proposals p ON p.id = c.proposal_id
    JOIN projects pr ON pr.id = p.project_id
    WHERE c.id = ?
      AND " . ($role === 'client'
          ? 'pr.client_id = ?'
          : 'p.freelancer_id = ?');

// Handle all payment form submissions.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';

    if (!is_string($submittedToken) ||
        !hash_equals($csrf_token, $submittedToken)) {
        $error = 'Security check failed. Please refresh the page and try again.';
    }

    // CLIENT: Create a demo payment record.
    elseif (isset($_POST['create_demo_payment']) && $role === 'client') {
        $contract_id = filter_input(
            INPUT_POST,
            'contract_id',
            FILTER_VALIDATE_INT
        );

        if (!$contract_id || $contract_id < 1) {
            $error = 'Please select a valid contract.';
        } else {
            $stmt = $conn->prepare($ownershipSql);

            if (!$stmt) {
                $error = 'Could not prepare the contract query.';
            } else {
                $stmt->bind_param('ii', $contract_id, $user_id);
                $stmt->execute();
                $contract = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$contract) {
                    $error = 'That contract does not belong to your account.';
                } elseif ($contract['contract_status'] !== 'active') {
                    $error = 'Demo payments can only be created for active contracts.';
                } else {
                    $check = $conn->prepare(
                        "SELECT id FROM payments
                         WHERE contract_id = ?
                           AND status IN ('pending', 'paid')
                         LIMIT 1"
                    );

                    if (!$check) {
                        $error = 'Could not check existing payments.';
                    } else {
                        $check->bind_param('i', $contract_id);
                        $check->execute();
                        $existing = $check->get_result()->fetch_assoc();
                        $check->close();

                        if ($existing) {
                            $error = 'A pending or paid payment already exists for this contract.';
                        } else {
                            $amount = (float) $contract['bid_amount'];
                            $method = 'demo';
                            $status = 'pending';

                            $insert = $conn->prepare(
                                "INSERT INTO payments
                                 (contract_id, amount, payment_method, status)
                                 VALUES (?, ?, ?, ?)"
                            );

                            if (!$insert) {
                                $error = 'Could not prepare the payment record.';
                            } else {
                                $insert->bind_param(
                                    'idss',
                                    $contract_id,
                                    $amount,
                                    $method,
                                    $status
                                );

                                if ($insert->execute()) {
                                    $message = 'Demo payment record created successfully.';
                                } else {
                                    $error = 'Could not create the payment record.';
                                }

                                $insert->close();
                            }
                        }
                    }
                }
            }
        }
    }

    // CLIENT: Mark their own pending payment as paid (demo only).
    elseif (isset($_POST['mark_paid']) && $role === 'client') {
        $payment_id = filter_input(
            INPUT_POST,
            'payment_id',
            FILTER_VALIDATE_INT
        );

        if (!$payment_id || $payment_id < 1) {
            $error = 'Please select a valid payment.';
        } else {
            $stmt = $conn->prepare(
                "UPDATE payments pay
                 JOIN contracts c ON c.id = pay.contract_id
                 JOIN proposals p ON p.id = c.proposal_id
                 JOIN projects pr ON pr.id = p.project_id
                 SET pay.status = 'paid',
                     pay.paid_at = CURRENT_TIMESTAMP
                 WHERE pay.id = ?
                   AND pay.status = 'pending'
                   AND pr.client_id = ?"
            );

            if (!$stmt) {
                $error = 'Could not prepare the payment update.';
            } else {
                $stmt->bind_param('ii', $payment_id, $user_id);

                if ($stmt->execute() && $stmt->affected_rows === 1) {
                    $message = 'Demo payment marked as paid successfully.';
                } elseif ($stmt->errno) {
                    $error = 'Could not update the payment.';
                } else {
                    $error = 'Payment not found, already paid, or not owned by your account.';
                }

                $stmt->close();
            }
        }
    }

    // Reject unknown actions and attempts by freelancers to change status.
    else {
        $error = 'This action is not allowed for your account.';
    }
}

// Load contracts belonging to this user.
$contracts = [];

$contractListSql = "
    SELECT c.id AS contract_id,
           c.status AS contract_status,
           pr.title AS project_title,
           p.bid_amount
    FROM contracts c
    JOIN proposals p ON p.id = c.proposal_id
    JOIN projects pr ON pr.id = p.project_id
    WHERE " . ($role === 'client'
        ? 'pr.client_id = ?'
        : 'p.freelancer_id = ?') . "
    ORDER BY c.id DESC";

$stmt = $conn->prepare($contractListSql);

if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $contracts[] = $row;
    }

    $stmt->close();
}

// Load payments belonging to this user's contracts.
$payments = [];

$paymentListSql = "
    SELECT pay.id AS payment_id,
           pay.contract_id,
           pay.amount,
           pay.payment_method,
           pay.status AS payment_status,
           pay.paid_at,
           pay.created_at,
           pr.title AS project_title
    FROM payments pay
    JOIN contracts c ON c.id = pay.contract_id
    JOIN proposals p ON p.id = c.proposal_id
    JOIN projects pr ON pr.id = p.project_id
    WHERE " . ($role === 'client'
        ? 'pr.client_id = ?'
        : 'p.freelancer_id = ?') . "
    ORDER BY pay.id DESC";

$stmt = $conn->prepare($paymentListSql);

if ($stmt) {
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $payments[] = $row;
    }

    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments | FreelanceHub</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f1e8;
            color: #26372d;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        .sidebar {
            width: 235px;
            flex-shrink: 0;
            background: #173d2b;
            color: #fff;
            padding: 28px 18px;
        }

        .brand {
            font-size: 25px;
            font-weight: bold;
            margin-bottom: 38px;
        }

        .brand span {
            color: #d9b779;
        }

        .sidebar-label {
            color: #c4d0c7;
            font-size: 11px;
            letter-spacing: 1.5px;
            margin-bottom: 14px;
        }

        .nav-link {
            display: block;
            padding: 12px 14px;
            margin: 5px 0;
            border-radius: 8px;
            color: #f5f5f0;
            text-decoration: none;
        }

        .nav-link:hover,
        .nav-link.active {
            background: #315640;
        }

        .main {
            flex: 1;
            min-width: 0;
            padding: 35px;
        }

        h1 {
            margin: 0 0 8px;
            font-size: 30px;
        }

        .subtitle {
            color: #69766c;
            margin-bottom: 28px;
        }

        .notice {
            padding: 13px 16px;
            margin-bottom: 18px;
            border-radius: 8px;
        }

        .success {
            background: #e1efe3;
            color: #20552e;
        }

        .error {
            background: #f8e2df;
            color: #8a2924;
        }

        .demo-note {
            background: #fff4d8;
            color: #72551d;
            padding: 13px 16px;
            border-radius: 8px;
            margin-bottom: 22px;
            line-height: 1.5;
        }

        .panel {
            background: #fffdf8;
            border: 1px solid #e6dfd1;
            border-radius: 13px;
            padding: 24px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(40, 50, 35, 0.04);
        }

        h2 {
            margin: 0 0 18px;
            font-size: 21px;
        }

        .form-row {
            display: flex;
            align-items: end;
            gap: 12px;
            flex-wrap: wrap;
        }

        .field {
            flex: 1;
            min-width: 220px;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d8d1c3;
            border-radius: 7px;
            background: #fff;
            color: #26372d;
        }

        button {
            border: none;
            border-radius: 7px;
            padding: 12px 16px;
            background: #214c35;
            color: #fff;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #163b28;
        }

        .paid-button {
            background: #6d7e39;
            padding: 9px 12px;
            font-size: 12px;
        }

        .paid-button:hover {
            background: #52632a;
        }

        .table-wrap {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        th,
        td {
            text-align: left;
            padding: 14px 12px;
            border-bottom: 1px solid #e9e3d8;
            font-size: 14px;
        }

        th {
            color: #617064;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: .5px;
        }

        .status {
            display: inline-block;
            border-radius: 20px;
            padding: 6px 10px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
            background: #f7ebcb;
            color: #795d1d;
        }

        .status.paid {
            background: #dcefe0;
            color: #27613a;
        }

        .status.failed {
            background: #f6dfdc;
            color: #8a302a;
        }

        .empty {
            padding: 22px 0;
            color: #748075;
        }

        .small-note {
            color: #7a8178;
            font-size: 12px;
            margin-top: 7px;
        }

        @media (max-width: 760px) {
            .layout {
                display: block;
            }

            .sidebar {
                width: 100%;
            }

            .main {
                padding: 20px 14px;
            }

            .panel {
                padding: 17px;
            }
        }
    </style>
</head>

<body>
<div class="layout">

    <aside class="sidebar">
        <div class="brand">freelancehub<span>.</span></div>

        <p class="sidebar-label">WORKSPACE</p>

        <?php if ($role === 'client'): ?>
            <a class="nav-link" href="client_dashboard.php">⌂ Dashboard</a>
            <a class="nav-link" href="index.php">▦ Browse Projects</a>
        <?php else: ?>
            <a class="nav-link" href="freelancer_dashboard.php">⌂ Dashboard</a>
            <a class="nav-link" href="index.php">▦ Browse Projects</a>
        <?php endif; ?>

        <a class="nav-link" href="milestones.php">✓ Milestones</a>
        <a class="nav-link active" href="payments.php">₹ Payments</a>
        <a class="nav-link" href="messages.php">✉ Messages</a>
        <a class="nav-link" href="notifications.php">♧ Notifications</a>
    </aside>

    <main class="main">
        <h1>Payments</h1>
        <p class="subtitle">Track contract payment records in one place.</p>

        <div class="demo-note">
            <strong>Demo mode:</strong> These records are for project testing only.
            Marking a payment as paid does not transfer real money.
        </div>

        <?php if ($message !== ''): ?>
            <div class="notice success">
                <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="notice error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($role === 'client'): ?>
            <section class="panel">
                <h2>Create Demo Payment</h2>

                <form method="POST" action="payments.php">
                    <input type="hidden" name="csrf_token"
                           value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="form-row">
                        <div class="field">
                            <label for="contract_id">Select an active contract</label>

                            <select name="contract_id" id="contract_id" required>
                                <option value="">Choose a contract</option>

                                <?php foreach ($contracts as $contract): ?>
                                    <?php if ($contract['contract_status'] === 'active'): ?>
                                        <option value="<?= (int) $contract['contract_id'] ?>">
                                            Contract #<?= (int) $contract['contract_id'] ?>
                                            — <?= htmlspecialchars($contract['project_title'], ENT_QUOTES, 'UTF-8') ?>
                                            (₹<?= number_format((float) $contract['bid_amount'], 2) ?>)
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>

                            <p class="small-note">
                                Only active contracts are eligible. A second pending or paid
                                payment for the same contract will not be created.
                            </p>
                        </div>

                        <button type="submit" name="create_demo_payment" value="1">
                            Create Demo Payment
                        </button>
                    </div>
                </form>
            </section>
        <?php endif; ?>

        <section class="panel">
            <h2>Payment History</h2>

            <?php if (empty($payments)): ?>
                <p class="empty">No payment records found for your account.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Payment ID</th>
                                <th>Project</th>
                                <th>Contract</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>Created</th>
                                <?php if ($role === 'client'): ?>
                                    <th>Action</th>
                                <?php endif; ?>
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td>#<?= (int) $payment['payment_id'] ?></td>

                                <td>
                                    <?= htmlspecialchars($payment['project_title'], ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>#<?= (int) $payment['contract_id'] ?></td>

                                <td>
                                    ₹<?= number_format((float) $payment['amount'], 2) ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($payment['payment_method'] ?? 'demo', ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <td>
                                    <span class="status <?= htmlspecialchars($payment['payment_status'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($payment['payment_status'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>

                                    <?php if ($payment['payment_status'] === 'paid' && !empty($payment['paid_at'])): ?>
                                        <div class="small-note">
                                            Paid: <?= htmlspecialchars($payment['paid_at'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($payment['created_at'], ENT_QUOTES, 'UTF-8') ?>
                                </td>

                                <?php if ($role === 'client'): ?>
                                    <td>
                                        <?php if ($payment['payment_status'] === 'pending'): ?>
                                            <form method="POST" action="payments.php"
                                                  onsubmit="return confirm('Mark this demo payment as paid? No real money will be transferred.');">
                                                <input type="hidden" name="csrf_token"
                                                       value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>">

                                                <input type="hidden" name="payment_id"
                                                       value="<?= (int) $payment['payment_id'] ?>">

                                                <button class="paid-button" type="submit"
                                                        name="mark_paid" value="1">
                                                    Mark as Paid (Demo)
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="small-note">No action available</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>
</div>
</body>
</html>
