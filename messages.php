
<?php
session_start();
require_once __DIR__ . '/config/db.php';

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = (string) $_SESSION['role'];

if (!in_array($role, ['client', 'freelancer'], true)) {
    http_response_code(403);
    exit('Access denied.');
}

if (empty($_SESSION['messages_csrf'])) {
    $_SESSION['messages_csrf'] = bin2hex(random_bytes(32));
}
$csrf = $_SESSION['messages_csrf'];

function h($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function getRows(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Could not prepare database query.');
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Could not execute database query.');
    }

    $result = $stmt->get_result();
    $rows = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows;
}

function getOne(mysqli $conn, string $sql, string $types, array $params): ?array {
    $rows = getRows($conn, $sql, $types, $params);
    return $rows[0] ?? null;
}

$error = '';
$success = '';
$projectId = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT);
$projectId = $projectId ? (int) $projectId : 0;
$withId = filter_input(INPUT_GET, 'with', FILTER_VALIDATE_INT);
$withId = $withId ? (int) $withId : 0;
$projects = [];
$participants = [];
$conversation = [];
$selectedProject = null;
$otherUser = null;

try {
    // Projects and proposals the logged-in user is allowed to access.
    if ($role === 'client') {
        $projects = getRows(
            $conn,
            "SELECT DISTINCT p.id, p.title
             FROM projects p
             WHERE p.client_id = ?
             ORDER BY p.id DESC",
            'i',
            [$userId]
        );
    } else {
        $projects = getRows(
            $conn,
            "SELECT DISTINCT p.id, p.title
             FROM projects p
             INNER JOIN proposals pr ON pr.project_id = p.id
             WHERE pr.freelancer_id = ?
             ORDER BY p.id DESC",
            'i',
            [$userId]
        );
    }

    if ($projectId > 0) {
        if ($role === 'client') {
            $selectedProject = getOne(
                $conn,
                "SELECT id, title, client_id
                 FROM projects
                 WHERE id = ? AND client_id = ?",
                'ii',
                [$projectId, $userId]
            );

            if ($selectedProject) {
                $participants = getRows(
                    $conn,
                    "SELECT DISTINCT u.id, u.name, u.email
                     FROM proposals pr
                     INNER JOIN users u ON u.id = pr.freelancer_id
                     WHERE pr.project_id = ?
                     ORDER BY u.name",
                    'i',
                    [$projectId]
                );
            }
        } else {
            $selectedProject = getOne(
                $conn,
                "SELECT p.id, p.title, p.client_id
                 FROM projects p
                 INNER JOIN proposals pr ON pr.project_id = p.id
                 WHERE p.id = ? AND pr.freelancer_id = ?
                 LIMIT 1",
                'ii',
                [$projectId, $userId]
            );

            if ($selectedProject) {
                $participants = getRows(
                    $conn,
                    "SELECT id, name, email
                     FROM users
                     WHERE id = ?",
                    'i',
                    [(int) $selectedProject['client_id']]
                );
                $withId = (int) $selectedProject['client_id'];
            }
        }

        if (!$selectedProject) {
            http_response_code(403);
            $error = 'You do not have access to this project conversation.';
        } else {
            if ($withId > 0) {
                foreach ($participants as $participant) {
                    if ((int) $participant['id'] === $withId) {
                        $otherUser = $participant;
                        break;
                    }
                }
            }

            // A client must select a freelancer who submitted a proposal.
            // A freelancer can only message the owning client.
            if ($otherUser) {
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    $postedToken = (string) ($_POST['csrf_token'] ?? '');
                    $postedMessage = trim((string) ($_POST['message'] ?? ''));
                    $postedReceiver = (int) ($_POST['receiver_id'] ?? 0);

                    if (!hash_equals($csrf, $postedToken)) {
                        $error = 'Security check failed. Refresh the page and try again.';
                    } elseif ($postedReceiver !== (int) $otherUser['id']) {
                        $error = 'Invalid conversation recipient.';
                    } elseif ($postedMessage === '') {
                        $error = 'Please type a message before sending.';
                    } elseif (mb_strlen($postedMessage) > 5000) {
                        $error = 'Messages must be 5000 characters or fewer.';
                    } else {
                        $stmt = $conn->prepare(
                            "INSERT INTO messages
                                (sender_id, receiver_id, project_id, message)
                             VALUES (?, ?, ?, ?)"
                        );

                        if (!$stmt) {
                            throw new RuntimeException('Could not prepare message.');
                        }

                        $stmt->bind_param(
                            'iiis',
                            $userId,
                            $postedReceiver,
                            $projectId,
                            $postedMessage
                        );

                        if (!$stmt->execute()) {
                            $stmt->close();
                            throw new RuntimeException('Could not send message.');
                        }
                        $stmt->close();

                        // Redirect prevents duplicate messages on refresh.
                        header(
                            'Location: messages.php?project_id=' . $projectId .
                            '&with=' . (int) $otherUser['id'] . '&sent=1'
                        );
                        exit;
                    }
                }

                if (isset($_GET['sent'])) {
                    $success = 'Message sent successfully.';
                }

                $conversation = getRows(
                    $conn,
                    "SELECT m.id, m.sender_id, m.receiver_id,
                            m.message, m.sent_at, u.name AS sender_name
                     FROM messages m
                     INNER JOIN users u ON u.id = m.sender_id
                     WHERE m.project_id = ?
                       AND (
                            (m.sender_id = ? AND m.receiver_id = ?)
                            OR
                            (m.sender_id = ? AND m.receiver_id = ?)
                       )
                     ORDER BY m.sent_at ASC, m.id ASC",
                    'iiiii',
                    [
                        $projectId,
                        $userId,
                        (int) $otherUser['id'],
                        (int) $otherUser['id'],
                        $userId
                    ]
                );
            }
        }
    }
} catch (Throwable $e) {
    error_log('FreelancerHub messages error: ' . $e->getMessage());
    $error = 'Something went wrong while loading messages. Please try again.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Messages | FreelancerHub</title>
    <style>
        * { box-sizing: border-box; }

        body {
            margin: 0;
            padding: 28px 16px;
            font-family: Arial, sans-serif;
            background: #f5f0e6;
            color: #26372d;
        }

        .container { max-width: 1000px; margin: auto; }

        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        h1 { margin: 0; color: #244735; }
        h2 { color: #244735; }

        .button, button {
            display: inline-block;
            border: 0;
            padding: 11px 16px;
            border-radius: 8px;
            background: #244735;
            color: white;
            text-decoration: none;
            font: inherit;
            cursor: pointer;
        }

        .panel {
            background: white;
            border-radius: 14px;
            padding: 22px;
            margin-bottom: 18px;
            box-shadow: 0 3px 12px rgba(0,0,0,.05);
        }

        .project-list {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .project-card {
            border: 1px solid #e6ddcc;
            border-radius: 10px;
            padding: 16px;
        }

        .project-card h3 { margin-top: 0; }

        .muted { color: #777; font-size: 14px; }
        .error, .success { padding: 13px; border-radius: 8px; }
        .error { background: #fff0f0; color: #a12626; }
        .success { background: #edf8ef; color: #24633a; }

        label { display: block; font-weight: bold; margin-bottom: 8px; }

        select, textarea {
            width: 100%;
            border: 1px solid #d9d0c0;
            border-radius: 8px;
            padding: 12px;
            font: inherit;
            background: #fff;
            color: #26372d;
        }

        textarea { resize: vertical; min-height: 90px; }

        .field { margin-bottom: 16px; }

        .chat {
            display: flex;
            flex-direction: column;
            gap: 12px;
            max-height: 430px;
            overflow-y: auto;
            padding: 8px 2px 18px;
        }

        .bubble {
            max-width: 78%;
            padding: 12px 15px;
            border-radius: 14px;
            background: #f1eee6;
            overflow-wrap: anywhere;
            white-space: pre-wrap;
        }

        .bubble.mine {
            align-self: flex-end;
            background: #244735;
            color: white;
        }

        .bubble .meta {
            margin-top: 8px;
            font-size: 12px;
            opacity: .75;
        }

        .empty { text-align: center; padding: 28px 12px; }

        @media (max-width: 600px) {
            body { padding: 18px 12px; }
            .panel { padding: 16px; }
            .bubble { max-width: 92%; }
        }
    </style>
</head>
<body>
<main class="container">
    <header class="topbar">
        <div>
            <h1>Messages</h1>
            <p class="muted">Private project conversations</p>
        </div>
        <a class="button"
           href="<?= $role === 'client' ? 'client_dashboard.php' : 'index.php' ?>">
            Go Back
        </a>
    </header>

    <?php if ($error !== ''): ?>
        <div class="error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if (!$selectedProject): ?>
        <section class="panel">
            <h2>Your projects</h2>
            <p>Select a project to open its conversation.</p>

            <?php if (!$projects): ?>
                <div class="empty">
                    <h3>No projects available yet</h3>
                    <p class="muted">
                        Projects you own or have submitted proposals for will appear here.
                    </p>
                </div>
            <?php else: ?>
                <div class="project-list">
                    <?php foreach ($projects as $project): ?>
                        <article class="project-card">
                            <h3><?= h($project['title']) ?></h3>
                            <a class="button"
                               href="messages.php?project_id=<?= (int) $project['id'] ?>">
                                Open project
                            </a>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    <?php elseif ($selectedProject): ?>
        <section class="panel">
            <h2><?= h($selectedProject['title']) ?></h2>

            <?php if (!$participants): ?>
                <p class="muted">
                    No other participant with a proposal was found for this project yet.
                </p>
            <?php elseif ($role === 'client' && !$otherUser): ?>
                <form method="get" action="messages.php">
                    <input type="hidden" name="project_id"
                           value="<?= (int) $projectId ?>">
                    <div class="field">
                        <label for="with">Choose a freelancer</label>
                        <select name="with" id="with" required>
                            <option value="">Select a freelancer</option>
                            <?php foreach ($participants as $participant): ?>
                                <option value="<?= (int) $participant['id'] ?>">
                                    <?= h($participant['name']) ?>
                                    (<?= h($participant['email']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit">Open conversation</button>
                </form>
            <?php elseif ($otherUser): ?>
                <p class="muted">
                    Conversation with <strong><?= h($otherUser['name']) ?></strong>
                    · <?= h($otherUser['email']) ?>
                </p>

                <?php if ($success !== ''): ?>
                    <p class="success"><?= h($success) ?></p>
                <?php endif; ?>

                <div class="chat" id="chat">
                    <?php if (!$conversation): ?>
                        <div class="empty">
                            <h3>Start the conversation</h3>
                            <p class="muted">Send your first message below.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($conversation as $item): ?>
                            <article class="bubble <?= (int) $item['sender_id'] === $userId ? 'mine' : '' ?>">
                                <strong><?= h($item['sender_name']) ?></strong>
                                <div><?= h($item['message']) ?></div>
                                <div class="meta"><?= h($item['sent_at']) ?></div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <form method="post"
                      action="messages.php?project_id=<?= (int) $projectId ?>&with=<?= (int) $otherUser['id'] ?>">
                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                    <input type="hidden" name="receiver_id"
                           value="<?= (int) $otherUser['id'] ?>">

                    <div class="field">
                        <label for="message">Your message</label>
                        <textarea id="message" name="message" maxlength="5000"
                                  required placeholder="Write a message..."></textarea>
                    </div>
                    <button type="submit">Send message</button>
                </form>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</main>

<script>
    const chat = document.getElementById('chat');
    if (chat) {
        chat.scrollTop = chat.scrollHeight;
    }
</script>
</body>
</html>
