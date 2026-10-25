<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Allow logged-in clients and freelancers only.
if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['client', 'freelancer'], true)
) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];

$message = '';
$error = '';

// CSRF protection.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_token'];

$uploadDir = __DIR__ . '/uploads/project_files/';
$maxFileSize = 5 * 1024 * 1024;

// Create upload directory if necessary.
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        $error = 'Could not create the upload folder. Check permissions.';
    }
}

$allowedExtensions = [
    'pdf', 'doc', 'docx', 'txt', 'jpg',
    'jpeg', 'png', 'zip', 'xlsx', 'pptx'
];

$allowedMimeTypes = [
    'pdf' => ['application/pdf'],
    'doc' => [
        'application/msword',
        'application/x-ole-storage',
        'application/octet-stream'
    ],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/x-zip',
        'application/octet-stream'
    ],
    'txt' => ['text/plain', 'application/octet-stream'],
    'jpg' => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png' => ['image/png'],
    'zip' => [
        'application/zip',
        'application/x-zip-compressed',
        'application/x-zip',
        'application/octet-stream'
    ],
    'xlsx' => [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/x-zip',
        'application/octet-stream'
    ],
    'pptx' => [
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/zip',
        'application/x-zip',
        'application/octet-stream'
    ]
];

/*
 * Return true only when the current user is the project owner
 * or the freelancer on an active contract for that project.
 */
function userCanAccessProject(
    mysqli $conn,
    int $projectId,
    int $userId,
    string $role
): bool {
    $sql = "
        SELECT p.id
        FROM projects p
        JOIN proposals pr ON pr.project_id = p.id
        JOIN contracts c ON c.proposal_id = pr.id
        WHERE p.id = ?
          AND c.status = 'active'
          AND (
              (p.client_id = ? AND ? = 'client')
              OR (pr.freelancer_id = ? AND ? = 'freelancer')
          )
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'iisis',
        $projectId,
        $userId,
        $role,
        $userId,
        $role
    );

    $stmt->execute();
    $allowed = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $allowed;
}

// Load projects available to the logged-in user.
$projects = [];

$projectSql = "
    SELECT DISTINCT p.id, p.title
    FROM projects p
    JOIN proposals pr ON pr.project_id = p.id
    JOIN contracts c ON c.proposal_id = pr.id
    WHERE c.status = 'active'
      AND (
          (p.client_id = ? AND ? = 'client')
          OR (pr.freelancer_id = ? AND ? = 'freelancer')
      )
    ORDER BY p.title
";

$stmt = $conn->prepare($projectSql);

if ($stmt) {
    $stmt->bind_param('isis', $userId, $role, $userId, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $projects[] = $row;
    }

    $stmt->close();
}

// Process file upload.
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['upload_file'])
) {
    $projectId = filter_input(
        INPUT_POST,
        'project_id',
        FILTER_VALIDATE_INT
    );

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($csrfToken, (string) $_POST['csrf_token'])
    ) {
        $error = 'Invalid request. Refresh the page and try again.';
    } elseif (
        !$projectId ||
        !isset($_FILES['project_file'])
    ) {
        $error = 'Please select a project and a file.';
    } elseif (!userCanAccessProject(
        $conn,
        (int) $projectId,
        $userId,
        $role
    )) {
        $error = 'You do not have permission to upload to this project.';
    } else {
        $file = $_FILES['project_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error = match ($file['error']) {
                UPLOAD_ERR_INI_SIZE,
                UPLOAD_ERR_FORM_SIZE => 'The file exceeds the server upload limit.',
                UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'Please choose a file.',
                UPLOAD_ERR_NO_TMP_DIR => 'The PHP temporary upload folder is missing.',
                UPLOAD_ERR_CANT_WRITE => 'PHP could not write the temporary upload.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload.',
                default => 'Upload failed. Error code: ' . (int) $file['error']
            };
        } elseif ($file['size'] <= 0) {
            $error = 'The selected file is empty.';
        } elseif ($file['size'] > $maxFileSize) {
            $error = 'File size must not exceed 5 MB.';
        } elseif (!is_uploaded_file($file['tmp_name'])) {
            $error = 'The uploaded file could not be verified.';
        } else {
            $originalName = basename($file['name']);
            $extension = strtolower(
                pathinfo($originalName, PATHINFO_EXTENSION)
            );

            if (!in_array($extension, $allowedExtensions, true)) {
                $error = 'This file type is not allowed.';
            } else {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $mime = $finfo->file($file['tmp_name']);

                if (
                    $mime === false ||
                    !isset($allowedMimeTypes[$extension]) ||
                    !in_array(
                        $mime,
                        $allowedMimeTypes[$extension],
                        true
                    )
                ) {
                    $error = 'The file content does not match an allowed type.';
                } elseif (!is_dir($uploadDir) || !is_writable($uploadDir)) {
                    $error = 'The upload folder is not writable by PHP.';
                } else {
                    $storedName = bin2hex(random_bytes(16))
                        . '.' . $extension;

                    $destination = $uploadDir . $storedName;

                    if (move_uploaded_file(
                        $file['tmp_name'],
                        $destination
                    )) {
                        $insert = $conn->prepare("
                            INSERT INTO files
                                (project_id, uploader_id, original_name, stored_name)
                            VALUES (?, ?, ?, ?)
                        ");

                        if (!$insert) {
                            @unlink($destination);
                            $error = 'Could not prepare the database insert.';
                        } else {
                            $insert->bind_param(
                                'iiss',
                                $projectId,
                                $userId,
                                $originalName,
                                $storedName
                            );

                            if ($insert->execute()) {
                                $message = 'File uploaded successfully!';
                            } else {
                                @unlink($destination);
                                $error = 'The file details could not be saved to the database.';
                            }

                            $insert->close();
                        }
                    } else {
                        $error = 'PHP could not move the uploaded file. Check folder permissions.';
                    }
                }
            }
        }
    }
}

// Load files for projects this user is allowed to access.
// The uploader's role is derived from the users table.
$files = [];

$fileSql = "
    SELECT
        f.id,
        f.original_name,
        f.uploaded_at,
        p.title,
        u.name AS uploader_name,
        u.role AS uploader_role
    FROM files f
    JOIN projects p ON p.id = f.project_id
    JOIN users u ON u.id = f.uploader_id
    WHERE EXISTS (
        SELECT 1
        FROM proposals pr
        JOIN contracts c ON c.proposal_id = pr.id
        WHERE pr.project_id = p.id
          AND c.status = 'active'
          AND (
              (p.client_id = ? AND ? = 'client')
              OR (pr.freelancer_id = ? AND ? = 'freelancer')
          )
    )
    ORDER BY f.uploaded_at DESC
";

$stmt = $conn->prepare($fileSql);

if ($stmt) {
    $stmt->bind_param('isis', $userId, $role, $userId, $role);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $files[] = $row;
    }

    $stmt->close();
}

$dashboardLink = $role === 'client'
    ? 'client_dashboard.php'
    : 'index.php';

function h(?string $value): string {
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Project Files | FreelanceHub</title>

<style>
* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #f7f4ec;
    color: #26382e;
}

header {
    background: #183d2b;
    color: white;
    padding: 20px 6%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

.brand {
    font-size: 23px;
    font-weight: bold;
}

.brand span { color: #d5b878; }

header a {
    color: white;
    text-decoration: none;
}

main {
    max-width: 1050px;
    margin: 35px auto;
    padding: 0 20px;
}

h1 { margin-bottom: 8px; }

.subtitle {
    color: #68756c;
    line-height: 1.6;
}

.card {
    background: white;
    border: 1px solid #e5e1d6;
    border-radius: 14px;
    padding: 25px;
    margin: 22px 0;
    box-shadow: 0 5px 18px rgba(28, 52, 37, .05);
}

label {
    display: block;
    margin: 16px 0 8px;
    font-weight: bold;
}

select, input[type=file] {
    width: 100%;
    padding: 12px;
    border: 1px solid #d8d8ce;
    border-radius: 8px;
    background: #fff;
}

button, .download {
    display: inline-block;
    margin-top: 18px;
    border: 0;
    border-radius: 8px;
    padding: 12px 18px;
    background: #214b34;
    color: white;
    font-weight: bold;
    text-decoration: none;
    cursor: pointer;
}

button:hover, .download:hover { background: #163724; }

.notice {
    padding: 13px;
    border-radius: 8px;
    margin: 15px 0;
    overflow-wrap: anywhere;
}

.success {
    background: #e5f4e8;
    color: #20572c;
}

.error {
    background: #fbe8e5;
    color: #8b2f27;
}

.file-row {
    border-bottom: 1px solid #eeeae1;
    padding: 18px 0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
}

.file-row:last-child { border-bottom: 0; }

.file-name {
    font-weight: bold;
    overflow-wrap: anywhere;
}

.meta {
    color: #6d786f;
    font-size: 13px;
    margin-top: 6px;
    line-height: 1.6;
}

.role-label {
    display: inline-block;
    margin-top: 8px;
    padding: 5px 9px;
    border-radius: 6px;
    background: #e7f1e8;
    color: #214b34;
    font-size: 11px;
    font-weight: bold;
}

.role-label.freelancer {
    background: #f4ead8;
    color: #765629;
}

.download {
    margin: 0;
    white-space: nowrap;
}

.empty {
    color: #68756c;
    padding: 18px 0;
}

.footer {
    color: #879087;
    text-align: center;
    font-size: 12px;
    padding: 20px 0 35px;
}

@media (max-width: 600px) {
    header { padding: 18px; }

    main {
        margin-top: 22px;
        padding: 0 14px;
    }

    .card { padding: 18px; }

    .file-row {
        align-items: flex-start;
        flex-direction: column;
    }
}
</style>
</head>

<body>
<header>
    <div class="brand">freelancehub<span>.</span></div>
    <a href="<?= h($dashboardLink) ?>">&larr; Back to Workspace</a>
</header>

<main>
    <h1>Project Files</h1>

    <p class="subtitle">
        Share requirements, work-in-progress files, feedback, and deliverables.
        Both clients and assigned freelancers can upload and download project files.
    </p>

    <?php if ($message !== ''): ?>
        <div class="notice success"><?= h($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="card">
        <h2>Upload a File</h2>

        <?php if (count($projects) > 0): ?>
            <form method="POST" enctype="multipart/form-data">
                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= h($csrfToken) ?>"
                >

                <label for="project_id">Choose Active Project</label>
                <select id="project_id" name="project_id" required>
                    <option value="">-- Select a project --</option>
                    <?php foreach ($projects as $project): ?>
                        <option
                            value="<?= (int) $project['id'] ?>"
                            <?= (
                                isset($_POST['project_id']) &&
                                (int) $_POST['project_id'] === (int) $project['id']
                            ) ? 'selected' : '' ?>
                        >
                            <?= h($project['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <label for="project_file">Choose File (maximum 5 MB)</label>
                <input
                    id="project_file"
                    type="file"
                    name="project_file"
                    accept=".pdf,.doc,.docx,.txt,.jpg,.jpeg,.png,.zip,.xlsx,.pptx"
                    required
                >

                <p class="meta">
                    Allowed: PDF, Word, TXT, JPG, PNG, ZIP, XLSX and PPTX.
                </p>

                <button type="submit" name="upload_file" value="1">
                    Upload File
                </button>
            </form>
        <?php else: ?>
            <p class="empty">
                No active projects are available for file sharing.
                An active contract is required.
            </p>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Shared Project Files</h2>
        <p class="subtitle">
            Files shared by you and the other person working on each project.
        </p>

        <?php if (count($files) > 0): ?>
            <?php foreach ($files as $file): ?>
                <?php
                    $uploaderRole = strtolower(
                        (string) ($file['uploader_role'] ?? '')
                    );

                    $roleLabel = $uploaderRole === 'client'
                        ? 'Client upload'
                        : ($uploaderRole === 'freelancer'
                            ? 'Freelancer upload'
                            : 'Project upload');

                    $roleClass = $uploaderRole === 'freelancer'
                        ? 'freelancer'
                        : '';
                ?>

                <div class="file-row">
                    <div>
                        <div class="file-name">
                            <?= h($file['original_name']) ?>
                        </div>

                        <div class="meta">
                            Project: <?= h($file['title']) ?><br>
                            Uploaded by: <?= h($file['uploader_name']) ?><br>
                            Date: <?= h($file['uploaded_at']) ?>
                        </div>

                        <span class="role-label <?= h($roleClass) ?>">
                            <?= h($roleLabel) ?>
                        </span>
                    </div>

                    <a
                        class="download"
                        href="download_file.php?id=<?= (int) $file['id'] ?>"
                    >
                        Download
                    </a>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p class="empty">
                No files have been uploaded for your active projects yet.
            </p>
        <?php endif; ?>
    </section>

    <footer class="footer">
        © 2026 FreelanceHub · Built for people who create together.
    </footer>
</main>
</body>
</html>
```