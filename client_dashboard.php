<?php
session_start();

require_once __DIR__ . '/config/db.php';

/* Authentication */
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

if (($_SESSION['role'] ?? '') !== 'client') {
    http_response_code(403);
    exit('Access denied. Please log in with a client account.');
}

$client_id = (int) $_SESSION['user_id'];
$client_name = $_SESSION['user_name'] ?? 'Client';

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/* CSRF protection */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$actionMessage = '';
$actionType = 'success';

/* Accept or reject a proposal */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['proposal_action'])
) {
    $proposalId = filter_var(
        $_POST['proposal_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $action = $_POST['proposal_action'];
    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $actionMessage = 'Your session token is invalid. Refresh the page and try again.';
        $actionType = 'error';
    } elseif (
        !$proposalId ||
        !in_array($action, ['accept', 'reject'], true)
    ) {
        $actionMessage = 'Invalid proposal action.';
        $actionType = 'error';
    } else {
        $transactionStarted = false;

        try {
            $conn->begin_transaction();
            $transactionStarted = true;

            /* Verify proposal ownership and statuses */
            $check = $conn->prepare("
                SELECT
                    p.id AS project_id,
                    p.status AS project_status,
                    pr.status AS proposal_status,
                    pr.freelancer_id AS freelancer_id
                FROM proposals pr
                INNER JOIN projects p ON p.id = pr.project_id
                WHERE pr.id = ? AND p.client_id = ?
                FOR UPDATE
            ");

            if (!$check) {
                throw new Exception('Could not prepare proposal verification.');
            }

            $check->bind_param("ii", $proposalId, $client_id);

            if (!$check->execute()) {
                $check->close();
                throw new Exception('Could not verify the proposal.');
            }

            $proposal = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$proposal) {
                throw new Exception(
                    'Proposal not found in your projects.'
                );
            }

            if (
                $proposal['project_status'] !== 'open' ||
                $proposal['proposal_status'] !== 'pending'
            ) {
                throw new Exception(
                    'This proposal is no longer available for changes.'
                );
            }

            $projectId = (int) $proposal['project_id'];
            $freelancerId = (int) $proposal['freelancer_id'];

            if ($action === 'accept') {

                /* Accept the selected proposal */
                $update = $conn->prepare("
                    UPDATE proposals
                    SET status = 'accepted'
                    WHERE id = ? AND status = 'pending'
                ");

                if (!$update) {
                    throw new Exception('Could not prepare proposal update.');
                }

                $update->bind_param("i", $proposalId);

                if (!$update->execute()) {
                    $update->close();
                    throw new Exception('Could not accept the proposal.');
                }

                if ($update->affected_rows !== 1) {
                    $update->close();
                    throw new Exception(
                        'Could not accept this proposal. Please refresh and retry.'
                    );
                }

                $update->close();

                /* Reject other pending proposals for this project */
                $rejectOthers = $conn->prepare("
                    UPDATE proposals
                    SET status = 'rejected'
                    WHERE project_id = ?
                      AND id <> ?
                      AND status = 'pending'
                ");

                if (!$rejectOthers) {
                    throw new Exception('Could not prepare other proposal updates.');
                }

                $rejectOthers->bind_param(
                    "ii",
                    $projectId,
                    $proposalId
                );

                if (!$rejectOthers->execute()) {
                    $rejectOthers->close();
                    throw new Exception('Could not update other proposals.');
                }

                $rejectOthers->close();

                /* Update the project status */
                $updateProject = $conn->prepare("
                    UPDATE projects
                    SET status = 'in_progress'
                    WHERE id = ?
                      AND client_id = ?
                      AND status = 'open'
                ");

                if (!$updateProject) {
                    throw new Exception('Could not prepare project update.');
                }

                $updateProject->bind_param(
                    "ii",
                    $projectId,
                    $client_id
                );

                if (!$updateProject->execute()) {
                    $updateProject->close();
                    throw new Exception('Could not update project status.');
                }

                if ($updateProject->affected_rows !== 1) {
                    $updateProject->close();
                    throw new Exception(
                        'Could not update the project status.'
                    );
                }

                $updateProject->close();

                /*
                 * AUTOMATIC CONTRACT CREATION
                 * Check for an existing contract first.
                 */
                $contractCheck = $conn->prepare("
                    SELECT id
                    FROM contracts
                    WHERE proposal_id = ?
                    LIMIT 1
                ");

                if (!$contractCheck) {
                    throw new Exception('Could not check the existing contract.');
                }

                $contractCheck->bind_param("i", $proposalId);

                if (!$contractCheck->execute()) {
                    $contractCheck->close();
                    throw new Exception('Could not check the contract.');
                }

                $existingContract = $contractCheck
                    ->get_result()
                    ->fetch_assoc();

                $contractCheck->close();

                if (!$existingContract) {
                    $createContract = $conn->prepare("
                        INSERT INTO contracts
                            (proposal_id, started_at, status)
                        VALUES
                            (?, NOW(), 'active')
                    ");

                    if (!$createContract) {
                        throw new Exception('Could not prepare contract creation.');
                    }

                    $createContract->bind_param("i", $proposalId);

                    if (!$createContract->execute()) {
                        $createContract->close();
                        throw new Exception('Could not create the contract.');
                    }

                    $createContract->close();
                }

/*
 * CREATE NOTIFICATIONS FOR BOTH CLIENT AND FREELANCER
 * Both notifications are saved in the same transaction.
 */
$freelancerMessage =
    'Your proposal has been accepted! The project is now in progress, and your contract is active.';

$clientMessage =
    'You accepted a freelancer proposal successfully. Your project is now in progress, and the contract is active.';

$notify = $conn->prepare("
    INSERT INTO notifications
        (user_id, message, is_read, created_at)
    VALUES (?, ?, 0, NOW())
");

if (!$notify) {
    throw new Exception('Could not prepare notifications.');
}

/* Notify the freelancer */
$notify->bind_param(
    "is",
    $freelancerId,
    $freelancerMessage
);

if (!$notify->execute()) {
    $notify->close();
    throw new Exception('Could not save freelancer notification.');
}

/* Notify the client */
$notify->bind_param(
    "is",
    $client_id,
    $clientMessage
);

if (!$notify->execute()) {
    $notify->close();
    throw new Exception('Could not save client notification.');
}

$notify->close();

                /*
                 * Commit acceptance, project, contract and notification.
                 */
                if (!$conn->commit()) {
                    throw new Exception('Could not commit the transaction.');
                }

                $transactionStarted = false;

                $actionMessage =
                    'Proposal accepted successfully! Other pending proposals were rejected, the contract is active, and the freelancer was notified.';

            } else {

                /* Reject only the selected proposal */
                $update = $conn->prepare("
                    UPDATE proposals
                    SET status = 'rejected'
                    WHERE id = ? AND status = 'pending'
                ");

                if (!$update) {
                    throw new Exception('Could not prepare proposal rejection.');
                }

                $update->bind_param("i", $proposalId);

                if (!$update->execute()) {
                    $update->close();
                    throw new Exception('Could not reject this proposal.');
                }

                if ($update->affected_rows !== 1) {
                    $update->close();
                    throw new Exception(
                        'Could not reject this proposal.'
                    );
                }

                $update->close();

                if (!$conn->commit()) {
                    throw new Exception('Could not commit the transaction.');
                }

                $transactionStarted = false;

                $actionMessage = 'Proposal rejected successfully.';
            }

        } catch (Throwable $error) {
            if ($transactionStarted) {
                $conn->rollback();
            }

            error_log(
                'Proposal action error: ' . $error->getMessage()
            );

            $actionMessage =
                'The proposal could not be updated. Please check the project and proposal status, then try again.';

            $actionType = 'error';
        }
    }
}


/* Complete a project */
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['complete_project'])
) {
    $projectId = filter_var(
        $_POST['project_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $token = $_POST['csrf_token'] ?? '';

    if (
        !is_string($token) ||
        !hash_equals($_SESSION['csrf_token'], $token)
    ) {
        $actionMessage = 'Your session token is invalid. Refresh and try again.';
        $actionType = 'error';
    } elseif (!$projectId || $projectId < 1) {
        $actionMessage = 'Invalid project.';
        $actionType = 'error';
    } else {
        $transactionStarted = false;

        try {
            $conn->begin_transaction();
            $transactionStarted = true;

            // Verify this project belongs to this client and is in progress.
            $checkProject = $conn->prepare("
                SELECT id, status
                FROM projects
                WHERE id = ? AND client_id = ?
                FOR UPDATE
            ");

            if (!$checkProject) {
                throw new Exception('Could not prepare project verification.');
            }

            $checkProject->bind_param("ii", $projectId, $client_id);

            if (!$checkProject->execute()) {
                $checkProject->close();
                throw new Exception('Could not verify the project.');
            }

            $projectRow = $checkProject->get_result()->fetch_assoc();
            $checkProject->close();

            if (!$projectRow) {
                throw new Exception('Project not found in your account.');
            }

            if ($projectRow['status'] !== 'in_progress') {
                throw new Exception(
                    'Only projects currently in progress can be completed.'
                );
            }

            // A project must have an accepted proposal and an active contract.
            $checkContract = $conn->prepare("
                SELECT c.id
                FROM proposals pr
                INNER JOIN contracts c ON c.proposal_id = pr.id
                WHERE pr.project_id = ?
                  AND pr.status = 'accepted'
                  AND c.status = 'active'
                LIMIT 1
                FOR UPDATE
            ");

            if (!$checkContract) {
                throw new Exception('Could not prepare contract verification.');
            }

            $checkContract->bind_param("i", $projectId);

            if (!$checkContract->execute()) {
                $checkContract->close();
                throw new Exception('Could not verify the active contract.');
            }

            $activeContract = $checkContract->get_result()->fetch_assoc();
            $checkContract->close();

            if (!$activeContract) {
                throw new Exception(
                    'No active contract was found for this project.'
                );
            }

            // Mark the project as completed.
            $updateProject = $conn->prepare("
                UPDATE projects
                SET status = 'completed'
                WHERE id = ?
                  AND client_id = ?
                  AND status = 'in_progress'
            ");

            if (!$updateProject) {
                throw new Exception('Could not prepare project completion.');
            }

            $updateProject->bind_param("ii", $projectId, $client_id);

            if (!$updateProject->execute()) {
                $updateProject->close();
                throw new Exception('Could not complete the project.');
            }

            if ($updateProject->affected_rows !== 1) {
                $updateProject->close();
                throw new Exception('The project status was not changed.');
            }

            $updateProject->close();

            // Mark the active contract(s) for this project's accepted proposal(s)
            // as completed, so the review page can recognize eligibility.
            $updateContract = $conn->prepare("
                UPDATE contracts c
                INNER JOIN proposals pr ON pr.id = c.proposal_id
                SET c.status = 'completed'
                WHERE pr.project_id = ?
                  AND pr.status = 'accepted'
                  AND c.status = 'active'
            ");

            if (!$updateContract) {
                throw new Exception('Could not prepare contract completion.');
            }

            $updateContract->bind_param("i", $projectId);

            if (!$updateContract->execute()) {
                $updateContract->close();
                throw new Exception('Could not complete the contract.');
            }

            $updateContract->close();

            if (!$conn->commit()) {
                throw new Exception('Could not save project completion.');
            }

            $transactionStarted = false;

            $actionMessage =
                'Project and contract marked as completed. The client can now submit a review.';
            $actionType = 'success';

        } catch (Throwable $error) {
            if ($transactionStarted) {
                $conn->rollback();
            }

            error_log('Project completion error: ' . $error->getMessage());

            $actionMessage =
                'The project could not be completed. Please refresh and try again.';
            $actionType = 'error';
        }
    }
}


/* Fetch this client's projects */
$projects = [];

$stmt = $conn->prepare("
    SELECT
        id,
        title,
        description,
        category,
        budget,
        deadline,
        status,
        created_at
    FROM projects
    WHERE client_id = ?
    ORDER BY created_at DESC
");

if (!$stmt) {
    exit('Unable to load projects. Please check the database configuration.');
}

$stmt->bind_param("i", $client_id);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $projects[] = $row;
}

$stmt->close();

/* Fetch proposals for each project */
$totalProposals = 0;

$proposalQuery = $conn->prepare("
    SELECT
        pr.id AS proposal_id,
        pr.freelancer_id,
        pr.cover_letter,
        pr.bid_amount,
        pr.delivery_days,
        pr.status AS proposal_status,
        pr.created_at AS proposal_date,
        u.name AS freelancer_name,
        u.email AS freelancer_email,
        c.id AS contract_id,
        c.status AS contract_status
    FROM proposals pr
    INNER JOIN users u ON u.id = pr.freelancer_id
    LEFT JOIN contracts c ON c.proposal_id = pr.id
    WHERE pr.project_id = ?
    ORDER BY pr.created_at DESC
");

if (!$proposalQuery) {
    exit('Unable to load proposals. Please check the database configuration.');
}

foreach ($projects as &$project) {
    $project['proposals'] = [];
    $projectId = (int) $project['id'];

    $proposalQuery->bind_param("i", $projectId);
    $proposalQuery->execute();

    $proposalResult = $proposalQuery->get_result();

    while ($proposalRow = $proposalResult->fetch_assoc()) {
        $project['proposals'][] = $proposalRow;
        $totalProposals++;
    }
}

unset($project);
$proposalQuery->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Client Dashboard | FreelanceHub</title>

<style>
:root {
    --green-dark: #252720;
    --green-sidebar-active: #3b3c32;
    --cream: #f8f6f2;
    --beige: #eee9db;
    --gold: #a07832;
    --text: #292a23;
    --muted: #858477;
    --border: #eae5dc;
    --white: #ffffff;
    --success: #2f8058;
    --success-bg: #eaf5ee;
    --danger: #963f30;
    --danger-bg: #f8ece7;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: -apple-system, BlinkMacSystemFont,
        "Segoe UI", sans-serif;
    background: var(--cream);
    color: var(--text);
}

a {
    color: inherit;
    text-decoration: none;
}

button,
input {
    font: inherit;
}

.layout {
    min-height: 100vh;
}

.sidebar {
    width: 255px;
    background: var(--green-dark);
    color: #f8f6f2;
    position: fixed;
    inset: 0 auto 0 0;
    padding: 36px 20px;
    display: flex;
    flex-direction: column;
}

.brand {
    font-size: 25px;
    font-weight: 800;
    letter-spacing: -1.2px;
    margin: 0 10px 48px;
}

.brand span {
    color: #d8b96c;
}

.sidebar-label {
    margin: 0 11px 15px;
    color: #a2a296;
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 2px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 15px 14px;
    margin-bottom: 8px;
    border-radius: 10px;
    color: #d0d0c8;
    font-size: 14px;
    font-weight: 600;
    transition: background .2s;
}

.nav-link:hover,
.nav-link.active {
    background: var(--green-sidebar-active);
    color: white;
}

.nav-link.active {
    border-left: 4px solid #d9b96d;
    padding-left: 10px;
}

.sidebar-note {
    margin: auto 12px 0;
    color: #a2a296;
    font-size: 14px;
    line-height: 1.9;
}

.main {
    margin-left: 255px;
    padding: 34px clamp(24px, 5vw, 76px) 60px;
    max-width: 1800px;
}

.topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    margin-bottom: 40px;
}

.breadcrumb {
    color: var(--muted);
    font-size: 14px;
}

.breadcrumb strong {
    color: var(--text);
}

.primary-button {
    display: inline-flex;
    justify-content: center;
    align-items: center;
    padding: 14px 20px;
    border: 0;
    border-radius: 10px;
    background: var(--green-dark);
    color: white;
    font-weight: 700;
    font-size: 13px;
    cursor: pointer;
}

.primary-button:hover {
    background: #414338;
}

.welcome {
    background: var(--beige);
    border-radius: 22px;
    padding: 38px 40px;
    margin-bottom: 32px;
}

.eyebrow {
    color: var(--gold);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 2px;
    margin: 0 0 18px;
}

.welcome h1 {
    font-size: clamp(27px, 3vw, 39px);
    line-height: 1.2;
    letter-spacing: -1.5px;
    margin: 0 0 17px;
}

.welcome p:last-child {
    color: #77776b;
    line-height: 1.8;
    margin: 0;
    max-width: 720px;
}

.action-message {
    padding: 15px 18px;
    margin-bottom: 22px;
    border-radius: 10px;
    font-size: 14px;
    line-height: 1.6;
}

.action-message.success {
    background: var(--success-bg);
    color: #276749;
    border: 1px solid #cfe7d6;
}

.action-message.error {
    background: var(--danger-bg);
    color: var(--danger);
    border: 1px solid #eed0c7;
}

.stats {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 22px;
    margin-bottom: 44px;
}

.stat-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 22px;
    padding: 28px 32px;
}

.stat-card p {
    color: var(--muted);
    margin: 0 0 12px;
    font-size: 14px;
}

.stat-card strong {
    display: block;
    font-size: 35px;
    letter-spacing: -1px;
}

.section-heading {
    margin-bottom: 24px;
}

.section-heading h2 {
    font-size: 28px;
    letter-spacing: -1px;
    margin: 0 0 8px;
}

.section-heading p {
    color: var(--muted);
    font-size: 14px;
    margin: 0;
}

.project-card {
    background: white;
    border: 1px solid var(--border);
    border-radius: 22px;
    padding: 30px;
    margin-bottom: 24px;
    box-shadow: 0 8px 30px rgba(35, 36, 30, .025);
}

.project-top {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
}

.category {
    display: inline-block;
    padding: 9px 13px;
    border-radius: 9px;
    background: #f5f0e3;
    color: #936c2b;
    font-size: 12px;
    font-weight: 800;
}

.status {
    display: inline-block;
    border-radius: 30px;
    padding: 8px 13px;
    background: var(--success-bg);
    color: var(--success);
    font-size: 12px;
    font-weight: 800;
    text-transform: capitalize;
}

.status.pending {
    background: #f5f0e3;
    color: #936c2b;
}

.status.accepted {
    background: var(--success-bg);
    color: var(--success);
}

.status.rejected,
.status.cancelled {
    background: var(--danger-bg);
    color: var(--danger);
}

.status.in-progress {
    background: #e9eee8;
    color: #355b42;
}

.project-card h3 {
    font-size: 25px;
    letter-spacing: -.7px;
    margin: 0 0 16px;
}

.project-description {
    color: #77776b;
    line-height: 1.8;
    white-space: pre-line;
    overflow-wrap: anywhere;
    margin: 0 0 20px;
}

.project-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 22px;
    color: var(--muted);
    font-size: 14px;
}

.project-meta strong {
    color: var(--text);
}

.section-divider {
    border: 0;
    border-top: 1px solid #eeeae3;
    margin: 28px 0;
}

.proposal-heading {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: center;
    margin-bottom: 18px;
}

.proposal-heading h4 {
    font-size: 18px;
    margin: 0;
}

.proposal-count {
    font-size: 13px;
    color: var(--muted);
}

.proposal-card {
    border: 1px solid var(--border);
    background: #fdfcf9;
    border-radius: 15px;
    padding: 22px;
    margin-top: 14px;
}

.freelancer-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
}

.freelancer-name {
    font-size: 16px;
    font-weight: 800;
    margin: 0 0 5px;
}

.freelancer-email {
    color: var(--muted);
    font-size: 13px;
    overflow-wrap: anywhere;
}

.proposal-details {
    display: flex;
    flex-wrap: wrap;
    gap: 22px;
    margin: 20px 0;
    font-size: 14px;
}

.detail-label {
    display: block;
    color: var(--muted);
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .7px;
    margin-bottom: 6px;
}

.detail-value {
    font-weight: 800;
}

.cover-letter {
    color: #66665d;
    font-size: 14px;
    line-height: 1.8;
    white-space: pre-line;
    overflow-wrap: anywhere;
}

.submitted {
    color: var(--muted);
    font-size: 12px;
    margin-top: 16px;
}

.proposal-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 20px;
}

.proposal-actions form {
    margin: 0;
}

.action-button {
    padding: 11px 16px;
    border: 0;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
    transition: opacity .2s;
}

.action-button:hover {
    opacity: .85;
}

.accept-button {
    background: #254c3a;
    color: white;
}

.reject-button {
    background: #f4e9e4;
    color: #963f30;
}

.empty-state {
    text-align: center;
    padding: 30px 18px;
    color: var(--muted);
    line-height: 1.8;
    background: #fdfcf9;
    border-radius: 12px;
}

.empty-projects {
    background: white;
    border: 1px solid var(--border);
    border-radius: 18px;
    padding: 45px 20px;
    text-align: center;
    color: var(--muted);
}

@media (max-width: 900px) {
    .sidebar {
        width: 210px;
    }

    .main {
        margin-left: 210px;
        padding: 28px 24px 45px;
    }

    .welcome {
        padding: 30px;
    }

    .project-card {
        padding: 23px;
    }
}

@media (max-width: 650px) {
    .sidebar {
        position: static;
        width: 100%;
        padding: 20px;
    }

    .brand {
        margin: 0 0 20px;
    }

    .sidebar-label,
    .sidebar-note {
        display: none;
    }

    .nav-link {
        display: inline-flex;
        padding: 11px 12px;
        margin: 0 4px 4px 0;
    }

    .main {
        margin-left: 0;
        padding: 22px 16px 40px;
    }

    .topbar {
        margin-bottom: 24px;
    }

    .welcome {
        padding: 25px 22px;
        border-radius: 17px;
    }

    .stats {
        gap: 12px;
        margin-bottom: 34px;
    }

    .stat-card {
        padding: 20px 17px;
        border-radius: 16px;
    }

    .stat-card strong {
        font-size: 29px;
    }

    .project-card {
        padding: 20px 17px;
        border-radius: 17px;
    }

    .project-card h3 {
        font-size: 21px;
    }

    .project-meta {
        gap: 13px;
    }

    .primary-button {
        padding: 12px;
        font-size: 12px;
    }
}
</style>
</head>

<body>
<div class="layout">

    <aside class="sidebar">
        <div class="brand">freelancehub<span>.</span></div>

        <p class="sidebar-label">WORKSPACE</p>

        <a class="nav-link active" href="client_dashboard.php">
            <span>⌂</span> Dashboard
        </a>

        <a class="nav-link" href="index.php">
            <span>▦</span> Browse Projects
        </a>

        <a class="nav-link" href="milestones.php">
            <span>◷</span> Milestones
        </a>
        
        <a class="nav-link" href="payments.php">
            <span>💳</span> Payments
        </a>

        <a class="nav-link" href="messages.php">
            <span>✉</span> Messages
        </a>

        <a class="nav-link" href="notifications.php">
            <span>♧</span> Notifications
        </a>
        
        <a class="nav-link" href="reviews.php">
            <span>⭐</span> Reviews & Ratings
        </a>
        
        <a class="nav-link" href="tasks.php">
            <span>✓</span> Tasks
        </a>

        <a class="nav-link" href="files.php">
            <span>✓</span> Files
        </a>

        <a class="nav-link" href="add_project.php">
            <span>＋</span> Post a Project
        </a>
        
        <a class="nav-link" href="logout.php">
            <span>＋</span> Logout
        </a>

        <p class="sidebar-note">
            Your workspace for discovering talent and managing project proposals.
        </p>
    </aside>

    <main class="main">

        <header class="topbar">
            <div class="breadcrumb">
                Workspace / <strong>Client Dashboard</strong>
            </div>

            <a class="primary-button" href="add_project.php">
                + Post a Project
            </a>
        </header>

        <?php if ($actionMessage !== ''): ?>
            <div
                class="action-message <?= e($actionType) ?>"
                role="status"
            >
                <?= e($actionMessage) ?>
            </div>
        <?php endif; ?>

        <section class="welcome">
            <p class="eyebrow">YOUR WORKSPACE AT A GLANCE</p>

            <h1>Welcome back, <?= e($client_name) ?>.</h1>

            <p>
                Keep track of your projects, review freelancer proposals,
                and find the right people to bring your ideas to life.
            </p>
        </section>

        <section class="stats">
            <div class="stat-card">
                <p>Your Projects</p>
                <strong><?= count($projects) ?></strong>
            </div>

            <div class="stat-card">
                <p>Proposals Received</p>
                <strong><?= $totalProposals ?></strong>
            </div>
        </section>

        <section class="section-heading">
            <h2>Your Projects</h2>
            <p>Manage your projects and review incoming proposals.</p>
        </section>

        <?php if (empty($projects)): ?>

            <div class="empty-projects">
                <h3>No projects yet</h3>
                <p>Post your first project to start receiving proposals.</p>
                <a class="primary-button" href="add_project.php">                   + Post a Project
                </a>
            </div>

        <?php else: ?>

            <?php foreach ($projects as $project): ?>

                <?php
                $projectStatusClass = strtolower(
                    str_replace(
                        ' ',
                        '-',
                        (string) $project['status']
                    )
                );
                ?>

                <article class="project-card">

                    <div class="project-top">
                        <span class="category">
                            <?= e($project['category'] ?: 'General') ?>
                        </span>

                        <span class="status <?= e($projectStatusClass) ?>">
                            <?= e($project['status']) ?>
                        </span>
                    </div>

                    <h3><?= e($project['title']) ?></h3>

                    <?php if (!empty($project['description'])): ?>
                        <p class="project-description"><?= e($project['description']) ?></p>
                    <?php endif; ?>

                    <div class="project-meta">
                        <span>
                            Budget:
                            <strong>
                                ₹<?= number_format((float) $project['budget'], 2) ?>
                            </strong>
                        </span>

                        <?php if (!empty($project['deadline'])): ?>
                            <span>
                                Deadline:
                                <strong><?= e($project['deadline']) ?></strong>
                            </span>
                        <?php endif; ?>
                    </div>


<?php
$hasActiveContract = false;

foreach ($project['proposals'] as $projectProposal) {
    if (
        $projectProposal['proposal_status'] === 'accepted' &&
        $projectProposal['contract_status'] === 'active'
    ) {
        $hasActiveContract = true;
        break;
    }
}
?>

<?php if (
    $project['status'] === 'in_progress' &&
    $hasActiveContract
): ?>
    <form
        method="POST"
        action="client_dashboard.php"
        onsubmit="return confirm('Confirm that this project is finished? This will mark the project and its active contract as completed, allowing the client to submit a review.');"
        style="margin: 22px 0;"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION['csrf_token']) ?>"
        >

        <input
            type="hidden"
            name="project_id"
            value="<?= (int) $project['id'] ?>"
        >

        <button
            type="submit"
            name="complete_project"
            value="1"
            class="primary-button"
        >
            ✓ Complete Project
        </button>
    </form>
<?php endif; ?>

                    <hr class="section-divider">

                    <div class="proposal-heading">
                        <h4>Freelancer Proposals</h4>

                        <span class="proposal-count">
                            <?= count($project['proposals']) ?> received
                        </span>
                    </div>

                    <?php if (empty($project['proposals'])): ?>

                        <div class="empty-state">
                            No proposals have been submitted for this project yet.
                        </div>

                    <?php else: ?>

                        <?php foreach ($project['proposals'] as $proposal): ?>

                            <?php
                            $proposalStatusClass = strtolower(
                                (string) $proposal['proposal_status']
                            );
                            ?>

                            <div class="proposal-card">

                                <div class="freelancer-row">
                                    <div>
                                        <p class="freelancer-name">
                                            <?= e($proposal['freelancer_name'] ?: 'Freelancer') ?>
                                        </p>

                                        <div class="freelancer-email">
                                            <?= e($proposal['freelancer_email']) ?>
                                        </div>
                                    </div>

                                    <span class="status <?= e($proposalStatusClass) ?>">
                                        <?= e($proposal['proposal_status']) ?>
                                    </span>
                                </div>

                                <div class="proposal-details">
                                    <div>
                                        <span class="detail-label">Bid Amount</span>

                                        <span class="detail-value">
                                            ₹<?= number_format((float) $proposal['bid_amount'], 2) ?>
                                        </span>
                                    </div>

                                    <div>
                                        <span class="detail-label">Delivery Time</span>

                                        <span class="detail-value">
                                            <?= (int) $proposal['delivery_days'] ?> days
                                        </span>
                                    </div>
                                </div>

                                <span class="detail-label">Cover Letter</span>

                                <div class="cover-letter">
                                    <?= e($proposal['cover_letter']) ?>
                                </div>

                                <div class="submitted">
                                    Submitted on <?= e($proposal['proposal_date']) ?>
                                </div>

                                <?php
                                if (
                                    $project['status'] === 'open' &&
                                    $proposal['proposal_status'] === 'pending'
                                ):
                                ?>

                                    <div class="proposal-actions">

                                        <form
                                            method="POST"
                                            action="client_dashboard.php"
                                            onsubmit="return confirm('Accept this proposal? All other pending proposals for this project will be rejected.');"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($_SESSION['csrf_token']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="proposal_id"
                                                value="<?= (int) $proposal['proposal_id'] ?>"
                                            >

                                            <button
                                                class="action-button accept-button"
                                                type="submit"
                                                name="proposal_action"
                                                value="accept"
                                            >
                                                ✓ Accept Proposal
                                            </button>
                                        </form>

                                        <form
                                            method="POST"
                                            action="client_dashboard.php"
                                            onsubmit="return confirm('Are you sure you want to reject this proposal?');"
                                        >
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($_SESSION['csrf_token']) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="proposal_id"
                                                value="<?= (int) $proposal['proposal_id'] ?>"
                                            >

                                            <button
                                                class="action-button reject-button"
                                                type="submit"
                                                name="proposal_action"
                                                value="reject"
                                            >
                                                ✕ Reject Proposal
                                            </button>
                                        </form>

                                    </div>

                                <?php endif; ?>

                            </div>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </article>

            <?php endforeach; ?>

        <?php endif; ?>

    </main>
</div>
</body>
</html>
