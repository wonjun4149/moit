<?php
require_once 'config.php';

// 1. 로그인 상태 확인
if (!isLoggedIn()) {
    redirect('login.php');
}

// 2. POST 방식으로 meeting_id가 전송되었는지 확인
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['meeting_id'])) {
    
    $meeting_id = $_POST['meeting_id'];
    $user_id = $_SESSION['user_id'];

    try {
        $pdo = getDBConnection();

        // 3. 보안 체크: 현재 로그인한 사용자가 정말로 모임의 개설자인지 확인
        $stmt = $pdo->prepare("SELECT organizer_id, image_path FROM meetings WHERE id = ?");
        $stmt->execute([$meeting_id]);
        $meeting = $stmt->fetch();

        if ($meeting && $meeting['organizer_id'] == $user_id) {
            // 4. (성공 시) 데이터베이스에서 모임 삭제
            $stmt = $pdo->prepare("DELETE FROM meetings WHERE id = ?");
            $stmt->execute([$meeting_id]);

            // 5. (성공 시) 서버에 업로드된 관련 이미지 파일도 삭제
            if ($meeting['image_path'] && file_exists('../' . $meeting['image_path'])) {
                unlink('../' . $meeting['image_path']);
            }

            // 6. Pinecone DB에서 해당 모임 정보 삭제
            $ch = curl_init();
            // FastAPI 서버의 주소와 포트에 맞게 URL을 수정해야 합니다.
            curl_setopt($ch, CURLOPT_URL, "http://127.0.0.1:8000/meetings/delete/" . $meeting_id);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $response = curl_exec($ch);
            $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // 7. 모임 목록 페이지로 리다이렉트
            redirect('meeting.php');

        } else {
            // 개설자가 아니거나 모임이 없는 경우
            die("삭제할 권한이 없거나 존재하지 않는 모임입니다.");
        }
    } catch (PDOException $e) {
        die("데이터베이스 오류: " . $e->getMessage());
    }
} else {
    // 비정상적인 접근일 경우
    redirect('meeting.php');
}
?>