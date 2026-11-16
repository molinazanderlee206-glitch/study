<?php

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function sendJsonResponse(int $statusCode, array $response): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($response);
    exit;
}

function createHistoryTables(mysqli $connection): void
{
    $connection->query(
        'CREATE TABLE IF NOT EXISTS task_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            task_id VARCHAR(100) NOT NULL,
            task_title VARCHAR(120) NOT NULL,
            task_type VARCHAR(20) NOT NULL,
            completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX task_history_completed_at (completed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
    $connection->query(
        'CREATE TABLE IF NOT EXISTS study_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            subject VARCHAR(120) NOT NULL,
            duration_seconds INT UNSIGNED NOT NULL,
            completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX study_history_completed_at (completed_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

$isApiRequest = isset($_GET['api']) && $_GET['api'] === '1';
$connection = null;

try {
    $connection = new mysqli('sql212.infinityfree.com', 'if0_43399597', 'gunHmtD0u6r59b', 'if0_43399597_zan');
    $connection->set_charset('utf8mb4');
    createHistoryTables($connection);
} catch (mysqli_sql_exception $exception) {
    error_log('StudySync history database error: ' . $exception->getMessage());
    if ($isApiRequest) {
        sendJsonResponse(503, ['ok' => false, 'error' => 'History could not be saved. Check the hosting database settings and confirm the database exists.']);
    }
    $databaseError = 'History could not be loaded. Check the hosting database settings and confirm the database exists.';
}

if ($isApiRequest) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(405, ['ok' => false, 'error' => 'Use POST to save a history entry.']);
    }
    if (!$connection) {
        sendJsonResponse(503, ['ok' => false, 'error' => 'The history database is unavailable.']);
    }

    $entry = json_decode(file_get_contents('php://input'), true);
    if (!is_array($entry)) {
        sendJsonResponse(400, ['ok' => false, 'error' => 'The history entry was not valid JSON.']);
    }

    try {
        if (($entry['kind'] ?? '') === 'task') {
            $taskId = $entry['task_id'] ?? null;
            $taskTitle = $entry['title'] ?? null;
            $taskType = $entry['type'] ?? 'Task';
            if (!is_string($taskId) || !is_string($taskTitle) || !is_string($taskType)) {
                sendJsonResponse(422, ['ok' => false, 'error' => 'The task information is incomplete or invalid.']);
            }
            $taskId = trim($taskId);
            $taskTitle = trim($taskTitle);
            if ($taskId === '' || strlen($taskId) > 100 || $taskTitle === '' || strlen($taskTitle) > 120 || !in_array($taskType, ['Task', 'Event'], true)) {
                sendJsonResponse(422, ['ok' => false, 'error' => 'The task information is incomplete or invalid.']);
            }

            $statement = $connection->prepare('INSERT INTO task_history (task_id, task_title, task_type) VALUES (?, ?, ?)');
            $statement->bind_param('sss', $taskId, $taskTitle, $taskType);
            $statement->execute();
        } elseif (($entry['kind'] ?? '') === 'study_session') {
            $subject = $entry['subject'] ?? null;
            $durationSeconds = $entry['duration_seconds'] ?? null;
            if (!is_string($subject) || !is_int($durationSeconds)) {
                sendJsonResponse(422, ['ok' => false, 'error' => 'The study session information is incomplete or invalid.']);
            }
            $subject = trim($subject);
            if ($subject === '' || strlen($subject) > 120 || $durationSeconds < 1 || $durationSeconds > 10800) {
                sendJsonResponse(422, ['ok' => false, 'error' => 'The study session information is incomplete or invalid.']);
            }

            $statement = $connection->prepare('INSERT INTO study_history (subject, duration_seconds) VALUES (?, ?)');
            $statement->bind_param('si', $subject, $durationSeconds);
            $statement->execute();
        } else {
            sendJsonResponse(422, ['ok' => false, 'error' => 'Unknown history entry type.']);
        }
    } catch (mysqli_sql_exception $exception) {
        error_log('StudySync history save error: ' . $exception->getMessage());
        sendJsonResponse(500, ['ok' => false, 'error' => 'The history entry could not be saved.']);
    }

    sendJsonResponse(201, ['ok' => true]);
}

$taskHistory = [];
$studyHistory = [];
if ($connection) {
    try {
        $taskHistory = $connection->query(
            'SELECT task_title, task_type, completed_at FROM task_history ORDER BY completed_at DESC, id DESC LIMIT 100'
        )->fetch_all(MYSQLI_ASSOC);
        $studyHistory = $connection->query(
            'SELECT subject, duration_seconds, completed_at FROM study_history ORDER BY completed_at DESC, id DESC LIMIT 100'
        )->fetch_all(MYSQLI_ASSOC);
    } catch (mysqli_sql_exception $exception) {
        error_log('StudySync history load error: ' . $exception->getMessage());
        $databaseError = 'History could not be loaded. Check the database tables and try again.';
    }
}

function escapeHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatHistoryDate(string $value): string
{
    $timestamp = strtotime($value);
    return $timestamp === false ? $value : date('M j, Y g:i A', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>History | StudySync</title>
    <link rel="stylesheet" href="theme.css">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: #0f172a; color: #e2e8f0; }
        a { text-decoration: none; }
        .topbar { background: #1f3a5f; color: white; padding: 18px 30px; display: flex; justify-content: space-between; align-items: center; gap: 20px; }
        .brand { font-size: 1.5rem; font-weight: bold; letter-spacing: 0.04em; }
        .nav-buttons { display: flex; gap: 10px; flex-wrap: wrap; }
        .nav-btn { background: #4f9cff; color: white; padding: 10px 18px; border-radius: 8px; font-size: 0.95rem; display: inline-block; }
        .nav-btn.active { background: #2f6ed6; }
        .container { max-width: 1100px; margin: 32px auto; padding: 0 20px 40px; }
        h1 { margin: 0 0 22px; font-size: 2rem; }
        .history-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 22px; }
        .panel { background: #111827; border: 1px solid rgba(148, 163, 184, 0.12); border-radius: 14px; padding: 22px; }
        .panel h2 { margin: 0 0 16px; color: #f8fafc; font-size: 1.3rem; }
        .history-list { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
        .history-item { padding: 14px; border-radius: 9px; background: #1f2937; overflow-wrap: anywhere; }
        .history-item strong { display: block; color: #f8fafc; margin-bottom: 5px; }
        .history-meta, .empty-state { color: #a5b4c9; font-size: 0.9rem; }
        .empty-state { margin: 0; }
        .database-error { padding: 14px 16px; margin: 0 0 20px; border: 1px solid #b91c1c; border-radius: 8px; background: #451a1a; color: #fecaca; }
        @media (max-width: 760px) {
            .history-grid { grid-template-columns: 1fr; }
            .topbar { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="brand">StudySync</div>
        <nav class="nav-buttons" aria-label="Main navigation">
            <a href="home.html" class="nav-btn">Home</a>
            <a href="Tasks.html" class="nav-btn">Tasks &amp; Events</a>
            <a href="Schedule.html" class="nav-btn">Schedule</a>
            <a href="Study.html" class="nav-btn">Study</a>
            <a href="studysync.php" class="nav-btn active" aria-current="page">History</a>
            <a href="about.html" class="nav-btn">About Us</a>
            <a href="pr.html" class="nav-btn">Login</a>
        </nav>
        <button class="theme-toggle" id="theme-toggle" type="button" aria-label="Switch theme">Light theme</button>
    </header>

    <main class="container">
        <h1>History</h1>
        <?php if (isset($databaseError)): ?>
            <p class="database-error" role="alert"><?php echo escapeHtml($databaseError); ?></p>
        <?php endif; ?>

        <div class="history-grid">
            <section class="panel" aria-labelledby="task-history-heading">
                <h2 id="task-history-heading">Completed tasks &amp; events</h2>
                <?php if (!$taskHistory): ?>
                    <p class="empty-state">Completed tasks and events will appear here.</p>
                <?php else: ?>
                    <ul class="history-list">
                        <?php foreach ($taskHistory as $item): ?>
                            <li class="history-item">
                                <strong><?php echo escapeHtml($item['task_title']); ?></strong>
                                <span class="history-meta"><?php echo escapeHtml($item['task_type']); ?> · <?php echo escapeHtml(formatHistoryDate($item['completed_at'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

            <section class="panel" aria-labelledby="study-history-heading">
                <h2 id="study-history-heading">Completed study sessions</h2>
                <?php if (!$studyHistory): ?>
                    <p class="empty-state">Completed study sessions will appear here.</p>
                <?php else: ?>
                    <ul class="history-list">
                        <?php foreach ($studyHistory as $item): ?>
                            <li class="history-item">
                                <strong><?php echo escapeHtml($item['subject']); ?></strong>
                                <span class="history-meta"><?php echo (int) floor(((int) $item['duration_seconds']) / 60); ?> min <?php echo ((int) $item['duration_seconds']) % 60; ?> sec · <?php echo escapeHtml(formatHistoryDate($item['completed_at'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
        </div>
    </main>
    <script src="theme.js"></script>
</body>
</html>
