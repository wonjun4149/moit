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

    // 1. 모임 개설자가 현재 사용자인지 확인
    $stmt = $pdo->prepare("SELECT organizer_id FROM meetings WHERE id = ?");
    $stmt->execute([$meeting_id]);
    $meeting = $stmt->fetch();

    if (!$meeting || $meeting['organizer_id'] != $user_id) {
        echo json_encode(['success' => false, 'message' => '모임을 삭제할 권한이 없습니다.']);
        exit;
    }

    // 트랜잭션 시작
    $pdo->beginTransaction();

    // 2. 해당 모임의 참여자 정보 모두 삭제
    $stmt_delete_participants = $pdo->prepare("DELETE FROM meeting_participants WHERE meeting_id = ?");
    $stmt_delete_participants->execute([$meeting_id]);

    // 3. 모임 삭제
    $stmt_delete_meeting = $pdo->prepare("DELETE FROM meetings WHERE id = ?");
    $stmt_delete_meeting->execute([$meeting_id]);

    // 트랜잭션 커밋
    $pdo->commit();

    echo json_encode(['success' => true, 'message' => '모임이 성공적으로 삭제되었습니다.']);

} catch (PDOException $e) {
    // 오류 발생 시 롤백
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Meeting deletion error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '데이터베이스 오류로 인해 모임 삭제에 실패했습니다.']);
}
?>