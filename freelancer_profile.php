
<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Only logged-in freelancers can access this page.
if (!isset($_SESSION['user_id']) || !isset($_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

if (strtolower(trim($_SESSION['role'])) !== 'freelancer') {
    header('Location: index.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$userName = $_SESSION['user_name'] ?? 'Freelancer';

$headline = '';
$bio = '';
$skills = '';
$hourlyRate = '0.00';
$success = '';
$error = '';

// Load the existing profile, if one has been saved.
$stmt = $conn->prepare(
    "SELECT headline, bio, skills, hourly_rate
     FROM freelancer_profiles
     WHERE user_id = ?
     LIMIT 1"
);

if (!$stmt) {
    die('Unable to load profile. Please check the database connection.');
}

$stmt->bind_param("i", $userId);
$stmt->execute();
$stmt->bind_result($dbHeadline, $dbBio, $dbSkills, $dbRate);

if ($stmt->fetch()) {
    $headline = $dbHeadline ?? '';
    $bio = $dbBio ?? '';
    $skills = $dbSkills ?? '';
    $hourlyRate = (string) $dbRate;
}

$stmt->close();

// Save or update the profile.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $headline = trim($_POST['headline'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    $skills = trim($_POST['skills'] ?? '');
    $hourlyRateInput = trim($_POST['hourly_rate'] ?? '');

    if ($headline === '' || $bio === '' || $skills === '') {
        $error = 'Please fill in your headline, bio, and skills.';
    } elseif (
        $hourlyRateInput === '' ||
        !is_numeric($hourlyRateInput) ||
        (float) $hourlyRateInput < 0 ||
        (float) $hourlyRateInput > 99999999
    ) {
        $error = 'Please enter a valid non-negative hourly rate.';
    } else {
        $hourlyRate = number_format((float) $hourlyRateInput, 2, '.', '');

        // user_id is unique, so save a new profile or update
        // the existing profile for this user.
        $save = $conn->prepare(
            "INSERT INTO freelancer_profiles
                (user_id, headline, bio, skills, hourly_rate)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                headline = VALUES(headline),
                bio = VALUES(bio),
                skills = VALUES(skills),
                hourly_rate = VALUES(hourly_rate)"
        );

        if (!$save) {
            $error = 'Unable to prepare the profile save operation.';
        } else {
            $save->bind_param(
                "issss",
                $userId,
                $headline,
                $bio,
                $skills,
                $hourlyRate
            );

            if ($save->execute()) {
                $success = 'Your freelancer profile has been saved successfully!';
            } else {
                $error = 'Could not save your profile. Please try again.';
            }

            $save->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Freelancer Profile | FreelanceHub</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 16px;
            background: #f5f0e6;
            color: #292b24;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 800px;
            margin: auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 25px;
        }

        h1, h2 {
            color: #244735;
        }

        h1 {
            margin-bottom: 8px;
        }

        .subtitle {
            color: #77766c;
            line-height: 1.6;
        }

        .back {
            display: inline-block;
            padding: 12px 16px;
            background: #244735;
            color: white;
            border-radius: 8px;
            text-decoration: none;
        }

        .card {
            padding: 28px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 16px rgba(0, 0, 0, 0.04);
        }

        label {
            display: block;
            margin: 20px 0 8px;
            font-weight: bold;
        }

        input, textarea {
            display: block;
            width: 100%;
            padding: 13px;
            border: 1px solid #ded9cd;
            border-radius: 8px;
            background: #fff;
            color: #292b24;
            font: inherit;
        }

        textarea {
            min-height: 110px;
            resize: vertical;
            line-height: 1.5;
        }

        input:focus, textarea:focus {
            outline: 2px solid #c5a45b;
            outline-offset: 1px;
        }

        .hint {
            margin-top: 6px;
            color: #77766c;
            font-size: 13px;
        }

        .save {
            width: 100%;
            margin-top: 25px;
            padding: 14px;
            border: none;
            border-radius: 8px;
            background: #244735;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        .save:hover {
            background: #193728;
        }

        .message {
            padding: 13px 15px;
            margin-bottom: 18px;
            border-radius: 8px;
            line-height: 1.5;
        }

        .success {
            color: #205b38;
            background: #e8f5eb;
        }

        .error {
            color: #9d2828;
            background: #fff0ed;
        }

        @media (max-width: 500px) {
            body {
                padding: 20px 12px;
            }

            .card {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
<main class="container">
    <div class="topbar">
        <div>
            <h1>My Freelancer Profile</h1>
            <div class="subtitle">
                Welcome, <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8') ?>.
                Tell clients about your skills and experience.
            </div>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
    <a class="back" href="files.php">Project Files</a>
    <a class="back" href="index.php">Back to Dashboard</a>
</div>
    </div>

    <section class="card">
        <h2>Professional Details</h2>
        <p class="subtitle">
            Complete your profile so clients can understand what you offer.
        </p>

        <?php if ($success !== ''): ?>
            <div class="message success">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="message error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="freelancer_profile.php">
            <label for="headline">Professional Headline *</label>
            <input
                type="text"
                id="headline"
                name="headline"
                maxlength="150"
                value="<?= htmlspecialchars($headline, ENT_QUOTES, 'UTF-8') ?>"
                placeholder="e.g. Web Developer | UI/UX Designer"
                required
            >

            <label for="bio">About You *</label>
            <textarea
                id="bio"
                name="bio"
                maxlength="10000"
                placeholder="Describe your experience and the services you offer..."
                required
            ><?= htmlspecialchars($bio, ENT_QUOTES, 'UTF-8') ?></textarea>

            <label for="skills">Skills *</label>
            <textarea
                id="skills"
                name="skills"
                maxlength="10000"
                placeholder="e.g. PHP, MySQL, HTML, CSS, JavaScript"
                required
            ><?= htmlspecialchars($skills, ENT_QUOTES, 'UTF-8') ?></textarea>

            <label for="hourly_rate">Hourly Rate (₹)</label>
            <input
                type="number"
                id="hourly_rate"
                name="hourly_rate"
                min="0"
                max="99999999"
                step="0.01"
                value="<?= htmlspecialchars($hourlyRate, ENT_QUOTES, 'UTF-8') ?>"
                required
            >
            <p class="hint">
                Enter your rate in Indian rupees per hour.
            </p>

            <button class="save" type="submit">Save Profile</button>
        </form>
    </section>
</main>
</body>
</html>
