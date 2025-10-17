<?php
require_once 'config.php';

// 로그인 확인
if (!isLoggedIn()) {
    redirect('login.php');
}

// POST 요청 확인
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("잘못된 요청입니다.");
}

// 폼 데이터 가져오기
$meeting_id = $_POST['meeting_id'] ?? null;
$user_id = $_SESSION['user_id'];
$title = trim($_POST['title']);
$description = trim($_POST['description']);
$category = $_POST['category'];
$location = trim($_POST['location']);
$max_members = (int)$_POST['max_members'];
$meeting_date = $_POST['meeting_date'];
$meeting_time = $_POST['meeting_time'];

if (!$meeting_id) {
    die("모임 ID가 없습니다.");
}

// 시간 유효성 검사 (서버 측)
try {
    $selected_datetime = new DateTime($meeting_date . ' ' . $meeting_time);
    $now = new DateTime();
    if ($selected_datetime < $now) {
        die('<script>alert("지난 시간으로는 모임을 수정할 수 없습니다."); window.history.back();</script>');
    }
} catch (Exception $e) {
    die('<script>alert("잘못된 날짜 또는 시간 형식입니다."); window.history.back();</script>');
}

try {
    $pdo = getDBConnection();

    // 권한 확인 (사용자가 모임 개설자인지)
    $stmt = $pdo->prepare("SELECT organizer_id, image_path FROM meetings WHERE id = ?");
    $stmt->execute([$meeting_id]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$meeting || $meeting['organizer_id'] != $user_id) {
        die("수정 권한이 없습니다.");
    }

    $image_path = $meeting['image_path']; // 기본값은 기존 이미지 경로

    // 새 이미지 파일이 업로드되었는지 확인
    if (isset($_FILES['meeting_image']) && $_FILES['meeting_image']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0775, true); }

        // 기존 이미지 파일 삭제 (기본 이미지가 아닐 경우)
        if ($image_path != 'assets/default_image.png' && file_exists('../' . $image_path)) {
            unlink('../' . $image_path);
        }

        $file_name = uniqid() . '-' . basename($_FILES['meeting_image']['name']);
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['meeting_image']['tmp_name'], $target_file)) {
            $image_path = 'uploads/' . $file_name; // 새 이미지 경로로 업데이트
        } else {
            // 파일 업로드 실패 시 오류 처리
            die("파일 업로드에 실패했습니다.");
        }
    }

    // 데이터베이스 업데이트
    $sql = "UPDATE meetings SET 
                title = ?,
                description = ?,
                category = ?,
                location = ?,
                max_members = ?,
                meeting_date = ?,
                meeting_time = ?,
                image_path = ?
            WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $success = $stmt->execute([
        $title, 
        $description, 
        $category, 
        $location, 
        $max_members, 
        $meeting_date, 
        $meeting_time, 
        $image_path, 
        $meeting_id
    ]);

    if ($success) {
        // 수정 후 상세 페이지로 리디렉션
        redirect('meeting_detail.php?id=' . $meeting_id);
    } else {
        die("모임 정보 업데이트에 실패했습니다.");
    }

} catch (PDOException $e) {
    die("데이터베이스 오류: " . $e->getMessage());
}

?>