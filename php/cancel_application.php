<?php
// 모임 신청 취소 로직
require_once 'config.php';

// 로그인 확인
if (!isLoggedIn()) {
    // 로그인되지 않은 경우, 로그인 페이지로 리디렉션
    header('Location: login.php');
    exit;
}

// POST 요청인지, meeting_id가 있는지 확인
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['meeting_id'])) {
    $meeting_id = $_POST['meeting_id'];
    $user_id = $_SESSION['user_id'];

    try {
        $pdo = getDBConnection();

        // 신청 취소: meeting_participants 테이블에서 해당 레코드 삭제
        $stmt = $pdo->prepare("DELETE FROM meeting_participants WHERE meeting_id = ? AND user_id = ?");
        $stmt->execute([$meeting_id, $user_id]);

        // 성공적으로 취소되었을 경우
        $_SESSION['message'] = "모임 신청이 성공적으로 취소되었습니다.";
        $_SESSION['message_type'] = "success";

    } catch (PDOException $e) {
        error_log("Application cancellation error: " . $e->getMessage());
        $_SESSION['message'] = "신청 취소 중 오류가 발생했습니다. 다시 시도해주세요.";
        $_SESSION['message_type'] = "error";
    }
} else {
    // 잘못된 접근
    $_SESSION['message'] = "잘못된 접근입니다.";
    $_SESSION['message_type'] = "error";
}

// 이전 페이지(모임 페이지)로 리디렉션
header('Location: meeting.php');
exit;
?>
