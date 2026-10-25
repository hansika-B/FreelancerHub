<?php
session_start();
require_once 'config/db.php';

// Only logged-in freelancers can view their received reviews.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit;
}

if ($_SESSION['role'] !== 'freelancer') {
    header("Location: login.php");
    exit;
}

$freelancer_id = (int) $_SESSION['user_id'];

// Fetch reviews received by this freelancer.
$sql = "
    SELECT
        r.rating,
        r.comment,
        r.created_at,
        u.name AS reviewer_name,
        p.title AS project_title
    FROM reviews r
    INNER JOIN users u
        ON u.id = r.reviewer_id
    INNER JOIN contracts c
        ON c.id = r.contract_id
    INNER JOIN proposals pr
        ON pr.id = c.proposal_id
    INNER JOIN projects p
        ON p.id = pr.project_id
    WHERE r.reviewee_id = ?
    ORDER BY r.created_at DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Unable to load reviews. Please check your database query.");
}

$stmt->bind_param("i", $freelancer_id);
$stmt->execute();
$result = $stmt->get_result();

$reviews = [];
$total_rating = 0;

while ($row = $result->fetch_assoc()) {
    $reviews[] = $row;
    $total_rating += (int) $row['rating'];
}

$stmt->close();

$review_count = count($reviews);
$average_rating = $review_count > 0
    ? $total_rating / $review_count
    : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reviews | FreelanceHub</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f7f4ec;
            color: #263b2d;
        }

        header {
            background: #183d2b;
            color: #fff;
            padding: 22px 7%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        header h1 {
            margin: 0;
            font-size: 24px;
        }

        header a {
            color: #fff;
            text-decoration: none;
            border: 1px solid #d9bd78;
            padding: 10px 15px;
            border-radius: 8px;
        }

        main {
            max-width: 1000px;
            margin: 35px auto;
            padding: 0 20px;
        }

        .summary {
            background: #fff;
            padding: 25px;
            border-radius: 14px;
            border: 1px solid #e5dfd0;
            margin-bottom: 25px;
        }

        .summary h2 {
            margin-top: 0;
        }

        .rating-number {
            color: #b08024;
            font-size: 34px;
            font-weight: bold;
        }

        .stars {
            color: #d39b26;
            letter-spacing: 2px;
            font-size: 21px;
        }

        .review-card {
            background: #fff;
            border: 1px solid #e5dfd0;
            border-radius: 14px;
            padding: 23px;
            margin-bottom: 18px;
        }

        .review-card h3 {
            margin: 0 0 8px;
            color: #183d2b;
        }

        .review-meta {
            color: #74776e;
            font-size: 13px;
            margin-bottom: 12px;
        }

        .comment {
            line-height: 1.6;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .empty {
            background: #fff;
            border: 1px dashed #c8c0ad;
            border-radius: 14px;
            padding: 35px 20px;
            text-align: center;
            line-height: 1.7;
        }

        .empty h2 {
            color: #183d2b;
        }

        @media (max-width: 600px) {
            header {
                padding: 18px 5%;
            }

            main {
                margin-top: 22px;
                padding: 0 14px;
            }
        }
    </style>
</head>

<body>
<header>
    <h1>⭐ My Reviews &amp; Ratings</h1>
    <a href="index.php">← Back to Workspace</a>
</header>

<main>
    <section class="summary">
        <h2>Your Reputation</h2>

        <div class="rating-number">
            <?php echo $review_count > 0
                ? number_format($average_rating, 1)
                : '—'; ?>
            <span class="stars">
                <?php
                echo $review_count > 0
                    ? str_repeat('★', (int) round($average_rating))
                        . str_repeat('☆', 5 - (int) round($average_rating))
                    : '☆☆☆☆☆';
                ?>
            </span>
        </div>

        <p>
            <?php echo $review_count; ?>
            review<?php echo $review_count === 1 ? '' : 's'; ?>
            received
        </p>
    </section>

    <h2>Feedback from Clients</h2>

    <?php if ($review_count === 0): ?>
        <section class="empty">
            <h2>No reviews yet</h2>
            <p>
                When clients review you after completing a project,
                their ratings and feedback will appear here.
            </p>
        </section>
    <?php else: ?>

        <?php foreach ($reviews as $review): ?>
            <article class="review-card">
                <h3>
                    <?php
                    echo htmlspecialchars(
                        $review['project_title'] ?? 'Project',
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    ?>
                </h3>

                <div class="stars">
                    <?php
                    $rating = (int) $review['rating'];
                    echo str_repeat('★', $rating)
                        . str_repeat('☆', 5 - $rating);
                    ?>
                    <span style="color:#263b2d;font-size:14px;">
                        (<?php echo $rating; ?>/5)
                    </span>
                </div>

                <div class="review-meta">
                    From:
                    <?php
                    echo htmlspecialchars(
                        $review['reviewer_name'] ?? 'Client',
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    ?>
                    ·
                    <?php
                    echo htmlspecialchars(
                        date('d M Y', strtotime($review['created_at'])),
                        ENT_QUOTES,
                        'UTF-8'
                    );
                    ?>
                </div>

                <div class="comment"><?php
                    echo htmlspecialchars(
                        $review['comment'] ?? '',
                        ENT_QUOTES,
                        'UTF-8'
                    );
                ?></div>
            </article>
        <?php endforeach; ?>

    <?php endif; ?>
</main>
</body>
</html>
