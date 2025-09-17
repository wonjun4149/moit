<?php
require_once 'config.php';

// 로그인 및 세션 데이터 확인
if (!isLoggedIn() || !isset($_SESSION['pending_meeting'])) {
    redirect('meeting.php');
}

// 세션에서 보류 중이던 모임 데이터 가져오기
$post_data = $_SESSION['pending_meeting'];

$organizer_id = $_SESSION['user_id'];
$title = $post_data['title'];
$description = $post_data['description'];
$category = $post_data['category'];
$location = $post_data['location'];
$max_members = (int)$post_data['max_members'];
$meeting_date = $post_data['meeting_date'];
$meeting_time = $post_data['meeting_time'];
$image_path = null;

// --- 1. 파일 업로드 처리 ---
if (isset($_SESSION['pending_meeting_image'])) {
    $image_info = $_SESSION['pending_meeting_image'];
    $upload_dir = '../uploads/';
    
    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0775, true); }
    if (!is_writable($upload_dir)) { die("오류: 'uploads' 폴더에 쓰기 권한이 없습니다."); }

    $file_name = uniqid() . '-' . basename($image_info['original_name']);
    $target_file = $upload_dir . $file_name;

    // 세션에 저장된 임시 파일을 최종 목적지로 이동
    if (rename($image_info['tmp_path'], $target_file)) {
        $image_path = 'uploads/' . $file_name;
    } else {
        // 임시 파일이 없는 경우 등 예외 처리
        if (file_exists($image_info['tmp_path'])) {
            unlink($image_info['tmp_path']); // 실패 시 임시 파일 삭제
        }
        die("파일 업로드 실패. 임시 파일을 찾을 수 없거나 옮길 수 없습니다.");
    }
}

// --- 2. 데이터베이스에 저장 ---
try {
    $pdo = getDBConnection();
    $sql = "INSERT INTO meetings (organizer_id, title, description, category, location, max_members, meeting_date, meeting_time, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);
    
    if ($stmt->execute([$organizer_id, $title, $description, $category, $location, $max_members, $meeting_date, $meeting_time, $image_path])) {
        $new_meeting_id = $pdo->lastInsertId();
        
        // --- 3. Pinecone 업데이트 ---
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

        // --- 4. 세션 데이터 정리 ---
        unset($_SESSION['pending_meeting']);
        unset($_SESSION['pending_meeting_image']);
        unset($_SESSION['recommendations']);

        // --- 5. 모임 목록으로 리다이렉트 ---
        redirect('meeting.php');

    } else {
        die("데이터베이스 실행 오류: 저장이 실패했습니다.");
    }

} catch (PDOException $e) {
    die("데이터베이스 오류: " . $e->getMessage());
}
?>