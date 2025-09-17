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
    $meeting_date = $_POST['meeting_date'];
    $meeting_time = $_POST['meeting_time'];
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
        $sql = "INSERT INTO meetings (organizer_id, title, description, category, location, max_members, meeting_date, meeting_time, image_path) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute([$organizer_id, $title, $description, $category, $location, $max_members, $meeting_date, $meeting_time, $image_path])) {
            // --- AI 서버에 새 모임 정보 전송 (Pinecone 업데이트) ---
            $new_meeting_id = $pdo->lastInsertId();
            
            // API로 보낼 데이터 준비
            $api_data = [
                'meeting_id' => (string)$new_meeting_id, // ID는 문자열로
                'title' => $title,
                'description' => $description,
                'time' => $meeting_date . ' ' . $meeting_time,
                'location' => $location
            ];
            
            // cURL을 사용해 FastAPI 서버에 POST 요청 (FastAPI 기본 포트 8000)
            $ch = curl_init('http://127.0.0.1:8000/meetings/add');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($api_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen(json_encode($api_data))
            ]);
            
            // API 서버가 꺼져있어도 프론트엔드 동작에 영향을 주지 않도록 타임아웃 설정
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2); // 2초
            curl_setopt($ch, CURLOPT_TIMEOUT, 5); // 5초

            $response = curl_exec($ch);
            // API 호출 실패 시 에러를 로그 파일에 기록할 수 있습니다.
            if(curl_errno($ch)){
                error_log('AI server API call error: ' . curl_error($ch));
            }
            curl_close($ch);
            // --- AI 서버 전송 끝 ---

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