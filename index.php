<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Allow only logged-in freelancers to access this workspace.
if (
    !isset($_SESSION['user_id']) ||
    ($_SESSION['role'] ?? '') !== 'freelancer'
) {
    header('Location: login.php');
    exit;
}

// Load projects after checking the user's session.
$sql = "SELECT * FROM projects ORDER BY created_at DESC";
$result = $conn->query($sql);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreelanceHub | Find Your Next Collaboration</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI",
                         Arial, sans-serif;
            background: #f8f6f2;
            color: #292820;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */
        .sidebar {
            width: 245px;
            background: #252720;
            color: #f8f6f2;
            padding: 30px 20px;
            display: flex;
            flex-direction: column;
            flex-shrink: 0;
        }

        .brand {
            font-size: 23px;
            font-weight: 750;
            letter-spacing: -0.8px;
            margin: 5px 10px 45px;
        }

        .brand span {
            color: #d5b477;
        }

        .nav-label {
            color: #92938a;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.5px;
            margin: 0 10px 15px;
        }

        .nav-links {
            display: flex;
            flex-direction: column;
        }

        .nav-link {
            display: block;
            padding: 13px 14px;
            margin-bottom: 7px;
            border-radius: 10px;
            color: #c9c9c1;
            font-size: 14px;
            transition: 0.2s;
        }

        .nav-link:hover,
        .nav-link.active {
            background: #3a3b32;
            color: #ffffff;
        }

        .nav-link.active {
            border-left: 3px solid #d5b477;
        }

        .logout-link {
            margin-top: 14px;
            border-top: 1px solid #414239;
            padding-top: 18px;
        }

        .sidebar-bottom {
            margin-top: auto;
            padding: 20px 10px 0;
            border-top: 1px solid #414239;
        }

        .sidebar-bottom p {
            color: #b7b7ad;
            font-size: 12px;
            line-height: 1.7;
        }

        /* MAIN CONTENT */
        .main {
            flex: 1;
            min-width: 0;
            padding: 32px 5%;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 40px;
        }

        .breadcrumb {
            font-size: 13px;
            color: #858478;
        }

        .post-button {
            display: inline-block;
            background: #292b24;
            color: white;
            border-radius: 10px;
            padding: 12px 18px;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s;
        }

        .post-button:hover {
            background: #48483a;
            transform: translateY(-1px);
        }

        /* HERO */
        .hero {
            background: #eeeadf;
            border-radius: 20px;
            padding: 38px;
            margin-bottom: 38px;
            position: relative;
            overflow: hidden;
        }

        .eyebrow {
            color: #8a6c37;
            text-transform: uppercase;
            font-size: 11px;
            font-weight: 750;
            letter-spacing: 2px;
            margin-bottom: 16px;
        }

        .hero h1 {
            font-size: clamp(30px, 4vw, 46px);
            letter-spacing: -1.8px;
            line-height: 1.12;
            max-width: 560px;
            margin: 0 0 16px;
        }

        .hero p {
            color: #777468;
            font-size: 15px;
            line-height: 1.8;
            max-width: 500px;
            margin: 0;
        }

        .hero-note {
            display: inline-block;
            margin-top: 24px;
            color: #5e4b2d;
            font-size: 13px;
            font-weight: 650;
        }

        /* SECTION HEADER */
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: end;
            gap: 18px;
            margin-bottom: 22px;
        }

        .section-header h2 {
            font-size: 25px;
            letter-spacing: -0.7px;
            margin: 0 0 7px;
        }

        .section-header p {
            font-size: 13px;
            color: #858478;
            margin: 0;
        }

        .search {
            width: 240px;
            max-width: 100%;
            padding: 12px 15px;
            border: 1px solid #e5e1d8;
            border-radius: 10px;
            background: #fff;
            font: inherit;
            font-size: 13px;
            outline: none;
        }

        .search:focus {
            border-color: #b99b60;
            box-shadow: 0 0 0 3px #b99b601c;
        }

        /* PROJECT CARDS */
        .projects {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }

        .project {
            background: #ffffff;
            padding: 25px;
            border: 1px solid #eeece5;
            border-radius: 16px;
            box-shadow: 0 5px 20px #29282006;
            display: flex;
            flex-direction: column;
            min-width: 0;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .project:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 28px #2928200d;
        }

        .project-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 19px;
        }

        .category {
            color: #8a6c37;
            background: #f5f0e5;
            padding: 7px 10px;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
        }

        .status {
            font-size: 11px;
            color: #327451;
            background: #eaf5ed;
            padding: 6px 9px;
            border-radius: 20px;
            white-space: nowrap;
        }

        .project h3 {
            font-size: 19px;
            letter-spacing: -0.4px;
            margin: 0 0 12px;
            overflow-wrap: anywhere;
        }

        .description {
            color: #77766c;
            font-size: 13px;
            line-height: 1.8;
            margin: 0 0 24px;
            overflow-wrap: anywhere;
        }

        .project-footer {
            border-top: 1px solid #f0eee8;
            padding-top: 18px;
            margin-top: auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .budget-label {
            font-size: 11px;
            color: #8b897e;
            margin-bottom: 5px;
        }

        .budget {
            font-size: 19px;
            font-weight: 750;
            letter-spacing: -0.5px;
        }

        .details-link {
            font-size: 12px;
            color: #85672f;
            font-weight: 700;
        }

        .empty-state {
            background: white;
            border: 1px dashed #dcd7ca;
            border-radius: 16px;
            padding: 35px;
            text-align: center;
            color: #77766c;
            grid-column: 1 / -1;
        }

        .empty-state h3 {
            color: #292820;
        }

        .footer {
            padding: 35px 0 10px;
            color: #99968a;
            font-size: 12px;
            text-align: center;
        }

        /* RESPONSIVE */
        @media (max-width: 950px) {
            .sidebar {
                width: 205px;
            }

            .main {
                padding: 28px;
            }

            .projects {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 650px) {
            .layout {
                display: block;
            }

            .sidebar {
                width: 100%;
                padding: 18px;
            }

            .brand {
                margin: 0 5px 18px;
            }

            .nav-label,
            .sidebar-bottom {
                display: none;
            }

            .nav-links {
                display: flex;
                flex-direction: row;
                gap: 7px;
                flex-wrap: wrap;
            }

            .nav-link {
                padding: 10px 12px;
                margin: 0;
                font-size: 12px;
            }

            .logout-link {
                margin-top: 0;
                border-top: 0;
                padding-top: 10px;
            }

            .main {
                padding: 22px 17px;
            }

            .topbar {
                margin-bottom: 25px;
            }

            .hero {
                padding: 27px 22px;
                margin-bottom: 30px;
            }

            .section-header {
                align-items: stretch;
                flex-direction: column;
            }

            .search {
                width: 100%;
            }

            .project {
                padding: 20px;
            }
        }
    </style>
</head>

<body>
<div class="layout">

    <aside class="sidebar">
        <a href="index.php" class="brand">
            freelance<span>hub.</span>
        </a>

        <p class="nav-label">WORKSPACE</p>

        <nav class="nav-links">
            <a href="index.php" class="nav-link active">
                ⌂ &nbsp; Dashboard
            </a>

            <a href="#projects" class="nav-link">
                ▦ &nbsp; Browse Projects
            </a>

            <a href="freelancer_profile.php" class="nav-link">
                👤 &nbsp; My Profile
            </a>

            <a href="milestones.php" class="nav-link">
                ▤ &nbsp; My Milestones
            </a>

            <a href="tasks.php" class="nav-link">
                ✓ &nbsp; My Tasks
            </a>

            <a href="messages.php" class="nav-link">
                ✉ &nbsp; Messages
            </a>

            <a href="notifications.php" class="nav-link">
                ♧ &nbsp; Notifications
            </a>

            <a href="my_reviews.php" class="nav-link">
                ⭐ &nbsp; Reviews &amp; Ratings
            </a>

            <a href="files.php" class="nav-link">
                📁 &nbsp; Project Files
            </a>

            <a href="add_project.php" class="nav-link">
                ＋ &nbsp; Post a Project
            </a>

            <a href="payments.php" class="nav-link">
                💳 &nbsp; Payments
            </a>

            <a href="logout.php" class="nav-link logout-link">
                🚪 &nbsp; Logout
            </a>
        </nav>

        <div class="sidebar-bottom">
            <p>
                Good work starts with a good connection.<br>
                Find your people. Build something great.
            </p>
        </div>
    </aside>

    <main class="main">

        <header class="topbar">
            <div class="breadcrumb">Workspace / Dashboard</div>

            <a href="add_project.php" class="post-button">
                ＋ Post a Project
            </a>
        </header>

        <section class="hero">
            <div class="eyebrow">Your next big thing starts here</div>

            <h1>Find your next collaboration.</h1>

            <p>
                Discover exciting projects, connect with creative professionals,
                and turn great ideas into meaningful work.
            </p>

            <a href="#projects" class="hero-note">
                Explore opportunities &nbsp; →
            </a>
        </section>

        <section id="projects">

            <div class="section-header">
                <div>
                    <h2>Latest Projects</h2>
                    <p>Fresh opportunities from the community.</p>
                </div>

                <input
                    type="search"
                    id="projectSearch"
                    class="search"
                    placeholder="⌕  Search projects..."
                    aria-label="Search projects"
                >
            </div>

            <div class="projects" id="projectList">

                <?php if ($result && $result->num_rows > 0): ?>

                    <?php while ($project = $result->fetch_assoc()): ?>

                        <article class="project">
                            <div class="project-top">
                                <span class="category">
                                    <?= htmlspecialchars(
                                        $project['category'] ?? 'General',
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>

                                <span class="status">
                                    <?= htmlspecialchars(
                                        ucfirst($project['status'] ?? 'open'),
                                        ENT_QUOTES,
                                        'UTF-8'
                                    ) ?>
                                </span>
                            </div>

                            <h3 class="project-title">
                                <?= htmlspecialchars(
                                    $project['title'],
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </h3>

                            <p class="description">
                                <?= nl2br(htmlspecialchars(
                                    $project['description'] ?? 'No description provided.',
                                    ENT_QUOTES,
                                    'UTF-8'
                                )) ?>
                            </p>

                            <div class="project-footer">
                                <div>
                                    <div class="budget-label">PROJECT BUDGET</div>

                                    <div class="budget">
                                        ₹<?= number_format(
                                            (float) $project['budget'],
                                            2
                                        ) ?>
                                    </div>
                                </div>

                                <a
                                    href="project_details.php?id=<?= (int) $project['id'] ?>"
                                    class="details-link"
                                >
                                    View details ↗
                                </a>
                            </div>
                        </article>

                    <?php endwhile; ?>

                <?php else: ?>

                    <div class="empty-state">
                        <h3>No projects yet</h3>
                        <p>Be the first to share an idea with the community.</p>

                        <a href="add_project.php" class="post-button">
                            Post a Project
                        </a>
                    </div>

                <?php endif; ?>

            </div>

            <p
                id="noResults"
                class="empty-state"
                style="display:none;"
            >
                No projects match your search. Try another keyword.
            </p>

        </section>

        <footer class="footer">
            © 2026 FreelanceHub · Built for people who create together.
        </footer>

    </main>
</div>

<script>
    const searchInput = document.getElementById('projectSearch');
    const projectCards = document.querySelectorAll('.project');
    const noResults = document.getElementById('noResults');
    const projectList = document.getElementById('projectList');

    searchInput.addEventListener('input', function () {
        const searchText = this.value.toLowerCase().trim();
        let visibleCount = 0;

        projectCards.forEach(function (card) {
            const cardText = card.textContent.toLowerCase();
            const matches = cardText.includes(searchText);

            card.style.display = matches ? 'flex' : 'none';

            if (matches) {
                visibleCount++;
            }
        });

        const hasCards = projectCards.length > 0;

        noResults.style.display =
            hasCards && visibleCount === 0 ? 'block' : 'none';
    });
</script>

</body>
</html>
