
<?php
session_start();

require_once 'config/db.php';

// Generate a CSRF token for the proposal form.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Validate the project ID.
if (!isset($_GET['id']) || !ctype_digit((string) $_GET['id']) || (int) $_GET['id'] < 1) {
    header('Location: index.php');
    exit;
}

$id = (int) $_GET['id'];

$notice = '';
$noticeType = 'error';
$coverLetter = '';
$bidAmount = '';
$deliveryDays = '';

// Load the selected project.
$stmt = $conn->prepare(
    "SELECT * FROM projects WHERE id = ?"
);
$stmt->bind_param("i", $id);
$stmt->execute();

$result = $stmt->get_result();
$project = $result->fetch_assoc();

$stmt->close();

// Show a success message after redirecting from a successful submission.
if (isset($_GET['submitted']) && $_GET['submitted'] === '1') {
    $notice = 'Your proposal has been submitted successfully!';
    $noticeType = 'success';
}

// Process the proposal form.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['submit_proposal'])) {

    $coverLetter = trim($_POST['cover_letter'] ?? '');
    $bidAmount = trim($_POST['bid_amount'] ?? '');
    $deliveryDays = trim($_POST['delivery_days'] ?? '');

    // Require a logged-in freelancer.
    $freelancerId = filter_var(
        $_SESSION['user_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $role = strtolower((string) ($_SESSION['role'] ?? ''));

    if (!$freelancerId || $role !== 'freelancer') {
        $notice = 'Please log in with a freelancer account before submitting a proposal.';

    } elseif (
        !isset($_POST['csrf_token']) ||
        !is_string($_POST['csrf_token']) ||
        !hash_equals(
            $_SESSION['csrf_token'],
            $_POST['csrf_token']
        )
    ) {
        $notice = 'Your form session has expired. Please refresh the page and try again.';

    } elseif (!$project) {
        $notice = 'The selected project could not be found.';

    } elseif (strtolower((string) ($project['status'] ?? '')) !== 'open') {
        $notice = 'This project is not open for proposals.';

    } elseif ($coverLetter === '') {
        $notice = 'Please enter your cover letter.';

    } elseif (
        !is_numeric($bidAmount) ||
        !is_finite((float) $bidAmount) ||
        (float) $bidAmount <= 0 ||
        (float) $bidAmount > 99999999.99
    ) {
        $notice = 'Please enter a valid bid amount.';

    } elseif (
        !ctype_digit($deliveryDays) ||
        (int) $deliveryDays < 1 ||
        (int) $deliveryDays > 2147483647
    ) {
        $notice = 'Delivery days must be a positive whole number.';

    } else {
        try {
            // Verify that the user exists and is a freelancer.
            $userCheck = $conn->prepare(
                "SELECT id FROM users WHERE id = ? AND role = 'freelancer'"
            );
            $userCheck->bind_param("i", $freelancerId);
            $userCheck->execute();

            $validFreelancer = $userCheck->get_result()->num_rows > 0;
            $userCheck->close();

            if (!$validFreelancer) {
                $notice = 'Your freelancer account could not be verified. Please log in again.';

            } else {
                // Prevent duplicate proposals for the same project.
                $duplicateCheck = $conn->prepare(
                    "SELECT id FROM proposals
                     WHERE project_id = ? AND freelancer_id = ?
                     LIMIT 1"
                );
                $duplicateCheck->bind_param("ii", $id, $freelancerId);
                $duplicateCheck->execute();

                $alreadyApplied = $duplicateCheck->get_result()->num_rows > 0;
                $duplicateCheck->close();

                if ($alreadyApplied) {
                    $notice = 'You have already submitted a proposal for this project.';

                } else {
                    // Insert into the existing proposals table.
                    // status and created_at use their database defaults.
                    $insert = $conn->prepare(
                        "INSERT INTO proposals
                         (project_id, freelancer_id, cover_letter,
                          bid_amount, delivery_days)
                         VALUES (?, ?, ?, ?, ?)"
                    );

                    $amount = (float) $bidAmount;
                    $days = (int) $deliveryDays;

                    $insert->bind_param(
                        "iisdi",
                        $id,
                        $freelancerId,
                        $coverLetter,
                        $amount,
                        $days
                    );

                    $insert->execute();
                    $insert->close();

                    // Prevent resubmission when the page is refreshed.
                    header(
                        'Location: project_details.php?id=' . $id . '&submitted=1'
                    );
                    exit;
                }
            }

        } catch (Throwable $e) {
            error_log('Proposal submission error: ' . $e->getMessage());
            $notice = 'Something went wrong while submitting your proposal. Please try again.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Project Details | FreelanceHub</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 35px 18px;
            background: #f8f6f2;
            color: #292820;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 850px;
            margin: auto;
        }

        .back {
            display: inline-block;
            margin-bottom: 25px;
            color: #806334;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
        }

        .card {
            background: #fff;
            padding: 38px;
            border: 1px solid #eeece5;
            border-radius: 18px;
            box-shadow: 0 6px 25px #29282008;
        }

        .brand {
            color: #98753b;
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
            margin-bottom: 24px;
        }

        h1 {
            font-size: clamp(28px, 5vw, 38px);
            letter-spacing: -1px;
            overflow-wrap: anywhere;
            margin: 0 0 18px;
        }

        h2 {
            font-size: 22px;
            margin: 35px 0 18px;
        }

        h3 {
            margin-top: 28px;
        }

        .category {
            display: inline-block;
            background: #f5f0e5;
            color: #806334;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 13px;
            font-weight: bold;
        }

        .description {
            color: #69685f;
            font-size: 15px;
            line-height: 1.9;
            margin: 28px 0;
            overflow-wrap: anywhere;
        }

        .info {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 15px;
            margin: 28px 0;
        }

        .info-box {
            background: #f8f6f2;
            border-radius: 12px;
            padding: 18px;
            min-width: 0;
        }

        .label {
            color: #858478;
            font-size: 12px;
            margin-bottom: 9px;
        }

        .value {
            font-size: 16px;
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        .status {
            color: #327451;
        }

        .apply-button,
        .submit-button {
            display: inline-block;
            background: #292b24;
            color: white;
            text-align: center;
            padding: 14px 22px;
            border: none;
            border-radius: 10px;
            text-decoration: none;
            font-weight: bold;
            font-size: 14px;
            cursor: pointer;
        }

        .apply-button:hover,
        .submit-button:hover {
            background: #48483a;
        }

        .note {
            color: #858478;
            font-size: 12px;
            line-height: 1.7;
            margin-top: 18px;
        }

        .proposal-form {
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid #eeece5;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 14px;
            font-weight: bold;
        }

        .form-group input,
        .form-group textarea {
            width: 100%;
            padding: 13px;
            border: 1px solid #dedbd2;
            border-radius: 9px;
            background: #fff;
            color: #292820;
            font: inherit;
        }

        .form-group textarea {
            min-height: 150px;
            resize: vertical;
            line-height: 1.6;
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: 2px solid #98753b;
            outline-offset: 1px;
        }

        .message {
            padding: 14px 16px;
            margin: 20px 0;
            border-radius: 9px;
            font-size: 14px;
            line-height: 1.6;
        }

        .message.error {
            background: #fff0ee;
            color: #9b3027;
            border: 1px solid #f2d0cb;
        }

        .message.success {
            background: #edf8f0;
            color: #24643a;
            border: 1px solid #cce8d3;
        }

        .not-found {
            text-align: center;
            padding: 45px 20px;
        }

        @media (max-width: 600px) {
            .card {
                padding: 24px 20px;
            }

            .info {
                grid-template-columns: 1fr;
            }

            .apply-button,
            .submit-button {
                display: block;
                width: 100%;
            }
        }
    </style>
</head>

<body>
<div class="container">

    <a class="back" href="index.php">← Back to all projects</a>

    <?php if ($project): ?>

        <div class="card">

            <div class="brand">FreelanceHub · Project Overview</div>

            <h1>
                <?= htmlspecialchars($project['title'] ?? 'Untitled Project') ?>
            </h1>

            <span class="category">
                <?= htmlspecialchars($project['category'] ?? 'General') ?>
            </span>

            <h3>About this project</h3>

            <div class="description">
                <?= nl2br(htmlspecialchars($project['description'] ?? 'No description provided.')) ?>
            </div>

            <div class="info">

                <div class="info-box">
                    <div class="label">Project Budget</div>
                    <div class="value">
                        ₹<?= number_format((float) ($project['budget'] ?? 0), 2) ?>
                    </div>
                </div>

                <div class="info-box">
                    <div class="label">Deadline</div>
                    <div class="value">
                        <?php
                        echo !empty($project['deadline'])
                            ? htmlspecialchars(date('d M Y', strtotime($project['deadline'])))
                            : 'Not specified';
                        ?>
                    </div>
                </div>

                <div class="info-box">
                    <div class="label">Project Status</div>
                    <div class="value status">
                        <?= htmlspecialchars(ucfirst($project['status'] ?? 'open')) ?>
                    </div>
                </div>

            </div>

            <?php if ($notice !== ''): ?>
                <div class="message <?= $noticeType === 'success' ? 'success' : 'error' ?>">
                    <?= htmlspecialchars($notice) ?>
                </div>
            <?php endif; ?>

            <?php if (strtolower((string) ($project['status'] ?? '')) === 'open'): ?>

                <section class="proposal-form" id="proposal-form">
                    <h2>Submit Your Proposal</h2>

                    <p class="note">
                        Tell the client why you're a good fit for this project.
                        Enter your proposed budget and estimated delivery time.
                    </p>

                    <form method="POST"
                          action="project_details.php?id=<?= (int) $id ?>#proposal-form">

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>"
                        >

                        <div class="form-group">
                            <label for="cover_letter">Cover Letter</label>
                            <textarea
                                id="cover_letter"
                                name="cover_letter"
                                placeholder="Introduce yourself and explain how you would complete this project..."
                                required
                            ><?= htmlspecialchars($coverLetter) ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="bid_amount">Your Bid Amount (₹)</label>
                            <input
                                type="number"
                                id="bid_amount"
                                name="bid_amount"
                                min="0.01"
                                max="99999999.99"
                                step="0.01"
                                value="<?= htmlspecialchars($bidAmount) ?>"
                                placeholder="e.g. 5000"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label for="delivery_days">Estimated Delivery (Days)</label>
                            <input
                                type="number"
                                id="delivery_days"
                                name="delivery_days"
                                min="1"
                                step="1"
                                value="<?= htmlspecialchars($deliveryDays) ?>"
                                placeholder="e.g. 7"
                                required
                            >
                        </div>

                        <button
                            type="submit"
                            name="submit_proposal"
                            value="1"
                            class="submit-button"
                        >
                            Submit Proposal →
                        </button>

                    </form>
                </section>

            <?php else: ?>

                <p class="note">
                    This project is not currently accepting proposals.
                </p>

            <?php endif; ?>

        </div>

    <?php else: ?>

        <div class="card not-found">
            <h1>Project not found</h1>
            <p>This project may have been removed or the link may be invalid.</p>
            <a href="index.php" class="apply-button">Browse Projects</a>
        </div>

    <?php endif; ?>

</div>
</body>
</html>
