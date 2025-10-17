<?php
require_once 'config.php';

header('Content-Type: application/json');

// 로그인 확인
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

// POST 요청인지 확인
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

$meeting_id = $_POST['meeting_id'] ?? null;
$user_id = $_SESSION['user_id'];

if (!$meeting_id) {
    echo json_encode(['success' => false, 'message' => '모임 ID가 없습니다.']);
    exit;
}

try {
    $pdo = getDBConnection();

    // Check if the meeting is in the past
    $stmt_check_date = $pdo->prepare("SELECT meeting_date, meeting_time FROM meetings WHERE id = ?");
    $stmt_check_date->execute([$meeting_id]);
    $meeting = $stmt_check_date->fetch();

    if ($meeting) {
        $is_past = strtotime($meeting['meeting_date'] . ' ' . $meeting['meeting_time']) < time();
        if ($is_past) {
            echo json_encode(['success' => false, 'message' => '이미 종료된 모임은 취소할 수 없습니다.']);
            exit;
        }
    }

    // meeting_participants 테이블에서 해당 사용자의 참여 기록 삭제
    $stmt = $pdo->prepare("DELETE FROM meeting_participants WHERE meeting_id = ? AND user_id = ?");
    $stmt->execute([$meeting_id, $user_id]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => '모임 참여가 성공적으로 취소되었습니다.']);
    } else {
        echo json_encode(['success' => false, 'message' => '이미 참여 중인 모임이 아니거나, 취소할 수 없습니다.']);
    }

} catch (PDOException $e) {
    error_log("Application cancellation error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류로 인해 참여 취소에 실패했습니다.']);
}
?>