<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Only logged-in administrators can access this dashboard.
if (
    !isset($_SESSION['user_id'], $_SESSION['role']) ||
    strtolower(trim($_SESSION['role'])) !== 'admin'
) {
    header('Location: login.php');
    exit;
}

$userName = $_SESSION['user_name'] ?? 'Administrator';

$totalUsers = 0;
$totalClients = 0;
$totalFreelancers = 0;
$totalProjects = 0;
$recentUsers = [];
$error = '';

try {
    // Count all registered users.
    $result = $conn->query("SELECT COUNT(*) AS total FROM users");
    if ($result) {
        $totalUsers = (int) $result->fetch_assoc()['total'];
    }

    // Count clients.
    $result = $conn->query(
        "SELECT COUNT(*) AS total FROM users WHERE role = 'client'"
    );
    if ($result) {
        $totalClients = (int) $result->fetch_assoc()['total'];
    }

    // Count freelancers.
    $result = $conn->query(
        "SELECT COUNT(*) AS total FROM users WHERE role = 'freelancer'"
    );
    if ($result) {
        $totalFreelancers = (int) $result->fetch_assoc()['total'];
    }

    // Count projects.
    $result = $conn->query("SELECT COUNT(*) AS total FROM projects");
    if ($result) {
        $totalProjects = (int) $result->fetch_assoc()['total'];
    }

    // Show the five most recently registered users.
    $result = $conn->query(
        "SELECT id, name, email, role, created_at
         FROM users
         ORDER BY id DESC
         LIMIT 5"
    );

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $recentUsers[] = $row;
        }
    }
} catch (Throwable $e) {
    // Keep database details out of the page.
    $error = 'Unable to load some dashboard information. Please check your database tables.';
}

function escapeHtml($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | FreelanceHub</title>

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
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
            padding: 20px 6%;
            background: #292b24;
            color: white;
        }

        header h2 {
            margin: 0;
        }

        .logout {
            padding: 10px 16px;
            background: #f8f6f2;
            color: #292b24;
            text-decoration: none;
            border-radius: 8px;
            font-weight: bold;
        }

        main {
            max-width: 1150px;
            margin: 35px auto;
            padding: 0 20px;
        }

        .welcome {
            margin-bottom: 28px;
        }

        .welcome h1 {
            margin-bottom: 8px;
        }

        .welcome p {
            color: #77766c;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 30px;
        }

        .card {
            padding: 24px;
            background: white;
            border: 1px solid #eeece5;
            border-radius: 14px;
        }

        .card h3 {
            margin: 0 0 14px;
            color: #77766c;
            font-size: 15px;
        }

        .number {
            font-size: 32px;
            font-weight: bold;
            color: #40533b;
        }

        .table-card {
            padding: 24px;
            background: white;
            border: 1px solid #eeece5;
            border-radius: 14px;
            overflow-x: auto;
        }

        .table-card h2 {
            margin-top: 0;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
        }

        th, td {
            padding: 14px 12px;
            border-bottom: 1px solid #eeece5;
            white-space: nowrap;
        }

        th {
            color: #55564b;
            background: #f8f6f2;
        }

        .role {
            display: inline-block;
            padding: 5px 9px;
            background: #edf1e8;
            color: #40533b;
            border-radius: 6px;
            text-transform: capitalize;
        }

        .error {
            margin-bottom: 20px;
            padding: 14px;
            background: #fff0ed;
            color: #a52a20;
            border-radius: 8px;
        }

        footer {
            padding: 25px;
            text-align: center;
            color: #77766c;
        }

        @media (max-width: 800px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 450px) {
            .cards {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<header>
    <h2>FreelanceHub Admin</h2>
    <a class="logout" href="logout.php">Log Out</a>
</header>

<main>
    <section class="welcome">
        <h1>Welcome, <?= escapeHtml($userName) ?>!</h1>
        <p>Manage and monitor your FreelanceHub platform.</p>
    </section>

    <?php if ($error !== ''): ?>
        <div class="error"><?= escapeHtml($error) ?></div>
    <?php endif; ?>

    <section class="cards">
        <div class="card">
            <h3>Total Users</h3>
            <div class="number"><?= $totalUsers ?></div>
        </div>

        <div class="card">
            <h3>Total Clients</h3>
            <div class="number"><?= $totalClients ?></div>
        </div>

        <div class="card">
            <h3>Total Freelancers</h3>
            <div class="number"><?= $totalFreelancers ?></div>
        </div>

        <div class="card">
            <h3>Total Projects</h3>
            <div class="number"><?= $totalProjects ?></div>
        </div>
    </section>

    <section class="table-card">
        <h2>Recently Registered Users</h2>

        <?php if (count($recentUsers) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Registered</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($recentUsers as $user): ?>
                        <tr>
                            <td><?= (int) $user['id'] ?></td>
                            <td><?= escapeHtml($user['name']) ?></td>
                            <td><?= escapeHtml($user['email']) ?></td>
                            <td>
                                <span class="role">
                                    <?= escapeHtml($user['role']) ?>
                                </span>
                            </td>
                            <td><?= escapeHtml($user['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p>No registered users were found.</p>
        <?php endif; ?>
    </section>
</main>

<footer>
    FreelanceHub Admin Dashboard
</footer>

</body>
</html>