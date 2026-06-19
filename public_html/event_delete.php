<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int) ($_POST['id'] ?? 0);
$date = trim($_POST['date'] ?? '');

if ($id <= 0) {
    setFlash('error', '削除する予定が指定されていません。');
    header('Location: index.php');
    exit;
}

try {
    $event = getEventById($id);
    if ($event === null) {
        setFlash('error', '予定が見つかりませんでした。');
    } elseif (deleteEvent($id)) {
        setFlash('success', '予定を削除しました。');
        $date = $event['date'];
    } else {
        setFlash('error', '予定の削除に失敗しました。');
    }
} catch (Throwable $e) {
    setFlash('error', 'データベースエラー: ' . $e->getMessage());
}

$redirectDate = DateTime::createFromFormat('Y-m-d', $date);
if ($redirectDate !== false) {
    header('Location: day.php?date=' . urlencode($date));
    exit;
}

header('Location: index.php');
exit;
