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

    // --- 4. Pinecone DB에서도 해당 벡터 삭제 요청 ---
    // AI 서버의 삭제 엔드포인트 호출
    $ch = curl_init("http://127.0.0.1:8000/meetings/delete/" . urlencode($meeting_id));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // AI 서버 응답 확인
    if ($http_code !== 200) {
        // 실패하더라도 사용자에게는 성공으로 보이게 하되, 서버 로그에는 기록을 남김
        error_log("Pinecone delete failed for meeting ID {$meeting_id}. AI server responded with code: {$http_code}. Response: {$response}");
    }
    // ---------------------------------------------

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