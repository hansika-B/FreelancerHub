<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Only logged-in clients can submit reviews.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    $_SESSION['role'] !== 'client'
) {
    header('Location: login.php');
    exit;
}

$clientId = (int) $_SESSION['user_id'];
$error = '';
$success = '';

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

// Escape output safely.
function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Submit a review.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';

    if (!hash_equals($csrfToken, $postedToken)) {
        $error = 'Invalid request. Please refresh the page.';
    } else {
        $contractId = filter_input(
            INPUT_POST,
            'contract_id',
            FILTER_VALIDATE_INT
        );

        $rating = filter_input(
            INPUT_POST,
            'rating',
            FILTER_VALIDATE_INT
        );

        $comment = trim($_POST['comment'] ?? '');

        if (
            !$contractId ||
            !$rating ||
            $rating < 1 ||
            $rating > 5 ||
            strlen($comment) > 5000
        ) {
            $error = 'Please select a valid contract and rating from 1 to 5. Comments must be 5000 characters or fewer.';
        } else {
            // Verify that the contract belongs to this client,
            // the proposal was accepted, and the contract is completed.
            $check = $conn->prepare(
                "SELECT c.id, p.freelancer_id
                 FROM contracts c
                 JOIN proposals p ON p.id = c.proposal_id
                 JOIN projects pr ON pr.id = p.project_id
                 WHERE c.id = ?
                   AND pr.client_id = ?
                   AND p.status = 'accepted'
                   AND c.status = 'completed'
                   AND pr.status = 'completed'
                 LIMIT 1"
            );

            if (!$check) {
                $error = 'Unable to verify the contract.';
            } else {
                $check->bind_param('ii', $contractId, $clientId);
                $check->execute();
                $contract = $check->get_result()->fetch_assoc();
                $check->close();

                if (!$contract) {
                    $error = 'You can review only your own completed contracts.';
                } else {
                    $freelancerId = (int) $contract['freelancer_id'];

                    // Insert the review. The database's unique
                    // contract_id + reviewer_id index prevents duplicates.
                    $insert = $conn->prepare(
                        "INSERT INTO reviews
                         (contract_id, reviewer_id, reviewee_id, rating, comment)
                         VALUES (?, ?, ?, ?, ?)"
                    );

                    if (!$insert) {
                        $error = 'Unable to prepare the review.';
                    } else {
                        $insert->bind_param(
                            'iiiis',
                            $contractId,
                            $clientId,
                            $freelancerId,
                            $rating,
                            $comment
                        );

                        if ($insert->execute()) {
                            $insert->close();

                            header('Location: reviews.php?submitted=1');
                            exit;
                        } else {
                            if ($insert->errno === 1062) {
                                $error = 'You have already reviewed this contract.';
                            } else {
                                $error = 'Could not submit the review. Please try again.';
                            }

                            $insert->close();
                        }
                    }
                }
            }
        }
    }
}

if (isset($_GET['submitted'])) {
    $success = 'Your review was submitted successfully.';
}

// Load completed contracts and check which have already been reviewed.
$contracts = [];

$query = $conn->prepare(
    "SELECT c.id AS contract_id,
            pr.title AS project_title,
            p.freelancer_id,
            u.name AS freelancer_name,
            p.bid_amount,
            r.id AS review_id
     FROM contracts c
     JOIN proposals p ON p.id = c.proposal_id
     JOIN projects pr ON pr.id = p.project_id
     JOIN users u ON u.id = p.freelancer_id
     LEFT JOIN reviews r
       ON r.contract_id = c.id
      AND r.reviewer_id = ?
     WHERE pr.client_id = ?
       AND p.status = 'accepted'
       AND c.status = 'completed'
       AND pr.status = 'completed'
     ORDER BY c.started_at DESC"
);

if ($query) {
    $query->bind_param('ii', $clientId, $clientId);
    $query->execute();
    $result = $query->get_result();

    while ($row = $result->fetch_assoc()) {
        $contracts[] = $row;
    }

    $query->close();
} else {
    $error = 'Unable to load completed contracts.';
}

// Load reviews submitted by this client.
$reviews = [];

$reviewQuery = $conn->prepare(
    "SELECT r.rating,
            r.comment,
            r.created_at,
            pr.title AS project_title,
            u.name AS freelancer_name
     FROM reviews r
     JOIN contracts c ON c.id = r.contract_id
     JOIN proposals p ON p.id = c.proposal_id
     JOIN projects pr ON pr.id = p.project_id
     JOIN users u ON u.id = r.reviewee_id
     WHERE r.reviewer_id = ?
     ORDER BY r.created_at DESC"
);

if ($reviewQuery) {
    $reviewQuery->bind_param('i', $clientId);
    $reviewQuery->execute();
    $result = $reviewQuery->get_result();

    while ($row = $result->fetch_assoc()) {
        $reviews[] = $row;
    }

    $reviewQuery->close();
} else {
    $error = 'Unable to load your reviews.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reviews &amp; Ratings | FreelanceHub</title>

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
            max-width: 1000px;
            margin: 32px auto;
            padding: 0 20px 40px;
        }

        .intro {
            margin-bottom: 24px;
        }

        .intro p,
        .muted {
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

        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #dcd7ca;
            border-radius: 8px;
            background: #fff;
            font: inherit;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
        }

        button,
        .button {
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

        button:hover,
        .button:hover {
            background: #263528;
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

        .review {
            padding: 18px 0;
            border-bottom: 1px solid #eeece5;
        }

        .review:last-child {
            border-bottom: 0;
        }

        .review h3 {
            margin: 0 0 8px;
        }

        .stars {
            color: #b78321;
            font-size: 22px;
            letter-spacing: 2px;
        }

        .empty {
            padding: 18px;
            background: #f8f6f2;
            border-radius: 8px;
            color: #77766c;
            line-height: 1.6;
        }

        @media (max-width: 600px) {
            .panel {
                padding: 18px;
            }
        }
    </style>
</head>

<body>
<header>
    <h1>FreelanceHub · Reviews</h1>
    <a href="client_dashboard.php">← Back to Client Dashboard</a>
</header>

<main>
    <section class="intro">
        <h2>Reviews &amp; Ratings ⭐</h2>
        <p>
            Share feedback about your freelancer after your project
            and contract have been marked as completed.
        </p>
    </section>

    <?php if ($error !== ''): ?>
        <div class="message error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($success !== ''): ?>
        <div class="message success"><?= h($success) ?></div>
    <?php endif; ?>

    <section class="panel">
        <h2>Review a Freelancer</h2>

        <?php if (count($contracts) === 0): ?>
            <div class="empty">
                No eligible completed contracts were found.
                Once a contract and its project are marked completed,
                you can leave a review here.
            </div>
        <?php else: ?>
            <?php foreach ($contracts as $contract): ?>
                <article class="review">
                    <h3><?= h($contract['project_title']) ?></h3>

                    <p class="muted">
                        Freelancer: <?= h($contract['freelancer_name']) ?><br>
                        Contract ID: <?= (int) $contract['contract_id'] ?>
                    </p>

                    <?php if ($contract['review_id'] !== null): ?>
                        <p class="success message">
                            You have already reviewed this contract.
                        </p>
                    <?php else: ?>
                        <form method="POST" action="reviews.php">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?= h($csrfToken) ?>"
                            >

                            <input
                                type="hidden"
                                name="contract_id"
                                value="<?= (int) $contract['contract_id'] ?>"
                            >

                            <label for="rating-<?= (int) $contract['contract_id'] ?>">
                                Your Rating
                            </label>

                            <select
                                id="rating-<?= (int) $contract['contract_id'] ?>"
                                name="rating"
                                required
                            >
                                <option value="">Choose a rating</option>
                                <option value="5">5 - Excellent</option>
                                <option value="4">4 - Very good</option>
                                <option value="3">3 - Good</option>
                                <option value="2">2 - Fair</option>
                                <option value="1">1 - Needs improvement</option>
                            </select>

                            <label for="comment-<?= (int) $contract['contract_id'] ?>">
                                Written Feedback (optional)
                            </label>

                            <textarea
                                id="comment-<?= (int) $contract['contract_id'] ?>"
                                name="comment"
                                maxlength="5000"
                                placeholder="Share your experience working with this freelancer..."
                            ></textarea>

                            <button type="submit">Submit Review</button>
                        </form>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Reviews You Have Submitted</h2>

        <?php if (count($reviews) === 0): ?>
            <div class="empty">
                You haven't submitted any reviews yet.
            </div>
        <?php else: ?>
            <?php foreach ($reviews as $review): ?>
                <article class="review">
                    <h3><?= h($review['project_title']) ?></h3>

                    <p class="muted">
                        Freelancer: <?= h($review['freelancer_name']) ?>
                    </p>

                    <div class="stars" aria-label="<?= (int) $review['rating'] ?> out of 5 stars">
                        <?= str_repeat('★', (int) $review['rating']) ?><?= str_repeat('☆', 5 - (int) $review['rating']) ?>
                    </div>

                    <?php if (trim((string) $review['comment']) !== ''): ?>
                        <p><?= nl2br(h($review['comment'])) ?></p>
                    <?php endif; ?>

                    <p class="muted">
                        Submitted: <?= h($review['created_at']) ?>
                    </p>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
