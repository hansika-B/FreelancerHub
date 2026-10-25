<?php
session_start();
require_once __DIR__ . '/config/db.php';

// Allow only logged-in clients and freelancers.
if (
    !isset($_SESSION['user_id']) ||
    !in_array($_SESSION['role'] ?? '', ['client', 'freelancer'], true)
) {
    header('Location: login.php');
    exit;
}

$userId = (int) $_SESSION['user_id'];
$role = $_SESSION['role'];

$fileId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$fileId || $fileId < 1) {
    http_response_code(400);
    exit('Invalid file ID.');
}

// Verify that the logged-in user is authorized to access this file.
$stmt = $conn->prepare("
    SELECT f.original_name, f.stored_name
    FROM files f
    JOIN projects p ON p.id = f.project_id
    WHERE f.id = ?
      AND EXISTS (
          SELECT 1
          FROM proposals pr
          JOIN contracts c ON c.proposal_id = pr.id
          WHERE pr.project_id = p.id
            AND c.status = 'active'
            AND (
                (p.client_id = ? AND ? = 'client')
                OR
                (pr.freelancer_id = ? AND ? = 'freelancer')
            )
      )
    LIMIT 1
");

if (!$stmt) {
    http_response_code(500);
    exit('Unable to prepare the download request.');
}

$stmt->bind_param(
    'iisis',
    $fileId,
    $userId,
    $role,
    $userId,
    $role
);

if (!$stmt->execute()) {
    $stmt->close();
    http_response_code(500);
    exit('Unable to retrieve the file.');
}

$file = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$file) {
    http_response_code(404);
    exit('File not found or you do not have permission to access it.');
}

// Locate the stored file.
$uploadDir = __DIR__ . '/uploads/project_files/';
$storedName = basename($file['stored_name']);
$path = $uploadDir . $storedName;

if (
    $storedName === '' ||
    !is_file($path) ||
    !is_readable($path)
) {
    http_response_code(404);
    exit('The stored file could not be found or read.');
}

// Get the actual file size.
$fileSize = filesize($path);

if ($fileSize === false || $fileSize === 0) {
    http_response_code(500);
    exit('The stored file is empty or its size could not be determined.');
}

// Detect the file's actual MIME type.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($path);

if (!$mimeType) {
    $mimeType = 'application/octet-stream';
}

// Prepare a safe download filename.
$downloadName = basename($file['original_name']);
$downloadName = preg_replace('/[\x00-\x1F\x7F]/', '', $downloadName);

if ($downloadName === '') {
    $downloadName = 'downloaded_file';
}

// Clear buffered output so it cannot corrupt the downloaded file.
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Send download headers.
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . $fileSize);
header('Content-Disposition: attachment; filename="' .
    addcslashes($downloadName, "\\\"") . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (readfile($path) === false) {
    // Avoid printing error text into a file download.
    exit;
}

exit;