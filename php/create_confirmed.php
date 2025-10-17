<?php
require_once 'config.php';

// 로그인 및 세션 데이터 확인
if (!isLoggedIn() || !isset($_SESSION['pending_meeting'])) {
    redirect('meeting.php');
}

// 세션에서 보류 중인 모임 데이터 가져오기
$pending_meeting = $_SESSION['pending_meeting'];

$organizer_id = $_SESSION['user_id'];
$title = $pending_meeting['title'];
$description = $pending_meeting['description'];
$category = $pending_meeting['category'];
$location = $pending_meeting['location'];
$max_members = (int)$pending_meeting['max_members'];
$meeting_date = $pending_meeting['meeting_date'];
$meeting_time = $pending_meeting['meeting_time'];
$image_path = 'assets/default_image.png'; // 기본 이미지

// 파일 업로드 처리
if (isset($_SESSION['pending_meeting_image'])) {
    $pending_image = $_SESSION['pending_meeting_image'];
    $tmp_path = $pending_image['tmp_path'];
    $original_name = $pending_image['original_name'];

    $upload_dir = '../uploads/';
    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0775, true); }

    $file_name = uniqid() . '-' . basename($original_name);
    $target_file = $upload_dir . $file_name;

    // 임시 파일을 최종 위치로 이동
    if (rename($tmp_path, $target_file)) {
        $image_path = 'uploads/' . $file_name;
    } else {
        // 오류 처리: 임시 파일 이동 실패
        // 이 경우 기본 이미지로 진행하거나 오류 메시지를 표시할 수 있습니다.
        error_log("Failed to move pending image from {$tmp_path} to {$target_file}");
    }
}

// 데이터베이스에 저장
try {
    $pdo = getDBConnection();
    $sql = "INSERT INTO meetings (organizer_id, title, description, category, location, max_members, meeting_date, meeting_time, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    
    if ($stmt->execute([$organizer_id, $title, $description, $category, $location, $max_members, $meeting_date, $meeting_time, $image_path])) {
        $new_meeting_id = $pdo->lastInsertId();
        
        // Pinecone 업데이트 (create_meeting.php와 동일한 로직)
        $api_data = [
            'meeting_id' => (string)$new_meeting_id,
            'title' => $title,
            'description' => $description,
            'time' => $meeting_date . ' ' . $meeting_time,
            'location' => $location
        ];
        
        $ch = curl_init('http://127.0.0.1:8000/meetings/add');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($api_data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);

        // 사용된 세션 변수 정리
        unset($_SESSION['pending_meeting']);
        unset($_SESSION['pending_meeting_image']);
        unset($_SESSION['recommendations']);

        // 새로 생성된 모임 상세 페이지로 리디렉션
        redirect('meeting_detail.php?id=' . $new_meeting_id);
    } else {
        die("데이터베이스 실행 오류");
    }
} catch (PDOException $e) {
    die("데이터베이스 오류: " . $e->getMessage());
}

?>