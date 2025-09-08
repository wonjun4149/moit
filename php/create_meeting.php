<?php
// 디버깅을 위해 에러 메시지를 화면에 표시합니다.
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'config.php';

// 로그인하지 않은 사용자는 접근 차단
if (!isLoggedIn()) {
    redirect('login.php');
}

// 폼이 POST 방식으로 제출되었는지 확인
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    
    // 폼 데이터 가져오기
    $organizer_id = $_SESSION['user_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $category = $_POST['category'];
    $location = trim($_POST['location']);
    $max_members = (int)$_POST['max_members'];
    $image_path = null; // 기본값은 null

    // --- 1. 파일 업로드 처리 ---
    $upload_dir = '../uploads/'; // 이미지를 저장할 폴더

    // uploads 폴더가 있는지 확인
    if (!is_dir($upload_dir)) {
        die("오류: 'uploads' 폴더가 존재하지 않습니다. 프로젝트 최상위 폴더에 생성해주세요.");
    }
    // uploads 폴더에 쓰기 권한이 있는지 확인
    if (!is_writable($upload_dir)) {
        die("오류: 'uploads' 폴더에 쓰기 권한이 없습니다. 터미널에서 폴더 권한을 확인해주세요. (예: sudo chmod -R 775 uploads)");
    }

    // 파일이 첨부되었는지, 업로드 에러는 없는지 확인
    if (isset($_FILES['meeting_image']) && $_FILES['meeting_image']['error'] == UPLOAD_ERR_OK) {
        
        $file_name = uniqid() . '-' . basename($_FILES['meeting_image']['name']);
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['meeting_image']['tmp_name'], $target_file)) {
            // 성공 시 DB에 저장할 경로를 변수에 할당
            $image_path = 'uploads/' . $file_name;
        } else {
            die("파일 업로드 실패: 파일을 'uploads' 폴더로 옮기는 데 실패했습니다. 폴더 권한을 다시 확인하세요.");
        }
    } elseif (isset($_FILES['meeting_image']) && $_FILES['meeting_image']['error'] != UPLOAD_ERR_NO_FILE) {
        // 파일이 첨부되었으나 다른 에러가 발생한 경우
        die("파일 업로드 오류 발생. 에러 코드: " . $_FILES['meeting_image']['error']);
    }

    // --- 2. 데이터베이스에 저장 ---
    try {
        $pdo = getDBConnection();
        $sql = "INSERT INTO meetings (organizer_id, title, description, category, location, max_members, image_path) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute([$organizer_id, $title, $description, $category, $location, $max_members, $image_path])) {
            // 성공적으로 저장 후 모임 목록 페이지로 이동
            redirect('meeting.php');
        } else {
            die("데이터베이스 실행 오류: 저장이 실패했습니다.");
        }

    } catch (PDOException $e) {
        die("데이터베이스 오류: " . $e->getMessage());
    }
} else {
    // POST 방식이 아니면 모임 목록 페이지로 이동
    redirect('meeting.php');
}
?>