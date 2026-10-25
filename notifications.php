<?php
session_start();

require_once __DIR__ . '/config/db.php';

// Only logged-in users can view notifications.
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$notifications = [];
$error = '';

$stmt = $conn->prepare(
    "SELECT id, message, is_read, created_at
     FROM notifications
     WHERE user_id = ?
     ORDER BY created_at DESC"
);

if ($stmt) {
    $stmt->bind_param("i", $userId);

    if ($stmt->execute()) {
        $stmt->bind_result(
            $id,
            $message,
            $isRead,
            $createdAt
        );

        while ($stmt->fetch()) {
            $notifications[] = [
                'id' => $id,
                'message' => $message,
                'is_read' => $isRead,
                'created_at' => $createdAt
            ];
        }
    } else {
        $error = 'Unable to load notifications.';
    }

    $stmt->close();
} else {
    $error = 'Unable to prepare the notifications query.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notifications | FreelancerHub</title>
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 30px 16px;
            font-family: Arial, sans-serif;
            background: #f5f0e6;
            color: #26372d;
        }

        .container {
            max-width: 850px;
            margin: auto;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 24px;
        }

        h1 {
            margin: 0;
            color: #244735;
        }

        .back {
            display: inline-block;
            padding: 10px 15px;
            background: #244735;
            color: white;
            text-decoration: none;
            border-radius: 8px;
        }

        .notification {
            background: white;
            padding: 20px;
            margin-bottom: 14px;
            border-radius: 12px;
            border-left: 5px solid #c5a45b;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
        }

        .notification.unread {
            background: #fffaf0;
        }

        .message {
            margin: 0 0 10px;
            overflow-wrap: anywhere;
        }

        .date {
            color: #777;
            font-size: 13px;
        }

        .empty {
            background: white;
            padding: 28px;
            text-align: center;
            border-radius: 12px;
        }

        .error {
            color: #a12626;
            background: #fff;
            padding: 15px;
            border-radius: 8px;
        }
    </style>
</head>
<body>
    <main class="container">
        <div class="topbar">
            <h1>Notifications</h1>
            <a class="back" href="javascript:history.back()">Go Back</a>
        </div>

        <?php if ($error !== ''): ?>
            <p class="error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </p>
        <?php elseif (empty($notifications)): ?>
            <div class="empty">
                <h3>No notifications yet</h3>
                <p>Updates about your projects will appear here when generated.</p>
            </div>
        <?php else: ?>
            <?php foreach ($notifications as $notification): ?>
                <article class="notification <?= (int) $notification['is_read'] === 0 ? 'unread' : '' ?>">
                    <p class="message">
                        <?= htmlspecialchars($notification['message'], ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <div class="date">
                        <?= htmlspecialchars($notification['created_at'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ((int) $notification['is_read'] === 0): ?>
                            · Unread
                        <?php else: ?>
                            · Read
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        <?php endif; ?>
    </main>
</body>
</html>
