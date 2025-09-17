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
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $location = trim($_POST['location']);
    $meeting_date = $_POST['meeting_date'];
    $meeting_time = $_POST['meeting_time'];

    // --- 1. AI 에이전트를 호출하여 유사 모임 확인 ---
    $agent_input = [
        'user_input' => [
            'title' => $title,
            'description' => $description,
            'location' => $location,
            'time' => $meeting_date . ' ' . $meeting_time
        ]
    ];

    $ch_agent = curl_init('http://127.0.0.1:8000/agent/invoke');
    curl_setopt($ch_agent, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch_agent, CURLOPT_POST, true);
    curl_setopt($ch_agent, CURLOPT_POSTFIELDS, json_encode($agent_input));
    curl_setopt($ch_agent, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch_agent, CURLOPT_CONNECTTIMEOUT, 5); 
    curl_setopt($ch_agent, CURLOPT_TIMEOUT, 30); // 에이전트가 생각할 시간을 넉넉하게 30초

    $agent_response_json = curl_exec($ch_agent);
    curl_close($ch_agent);

    // AI 응답(JSON)을 2단계로 해석
    $response_data = json_decode($agent_response_json, true);
    $final_answer_str = $response_data['final_answer'] ?? '{}';
    $agent_response = json_decode($final_answer_str, true);
    
    // AI가 추천을 반환했는지 확인
    if (isset($agent_response['recommendations']) && !empty($agent_response['recommendations'])) {
        // 추천이 있으면, 사용자가 입력한 데이터를 세션에 저장하고 추천 페이지로 이동
        $_SESSION['pending_meeting'] = $_POST;
        // 파일 업로드 정보도 세션에 저장
        if (isset($_FILES['meeting_image']) && $_FILES['meeting_image']['error'] == UPLOAD_ERR_OK) {
            // 임시 파일을 안전한 곳으로 옮기고 그 경로를 세션에 저장
            $tmp_name = $_FILES['meeting_image']['tmp_name'];
            $new_tmp_path = sys_get_temp_dir() . '/' . basename($tmp_name);
            move_uploaded_file($tmp_name, $new_tmp_path);
            $_SESSION['pending_meeting_image'] = [
                'original_name' => $_FILES['meeting_image']['name'],
                'tmp_path' => $new_tmp_path
            ];
        }
        
        // 해석된 최종 응답(안쪽 JSON)을 세션에 저장
        $_SESSION['recommendations'] = $agent_response;
        redirect('recommend.php');
        exit;
    }

    // --- 2. (추천 없을 시) 즉시 모임 생성 진행 ---
    // 세션에 저장할 필요 없이 바로 변수 사용
    $organizer_id = $_SESSION['user_id'];
    $category = $_POST['category'];
    $max_members = (int)$_POST['max_members'];
    $image_path = null;

    // 파일 업로드 처리
    if (isset($_FILES['meeting_image']) && $_FILES['meeting_image']['error'] == UPLOAD_ERR_OK) {
        $upload_dir = '../uploads/';
        if (!is_dir($upload_dir)) { mkdir($upload_dir, 0775, true); }
        if (!is_writable($upload_dir)) { die("오류: 'uploads' 폴더에 쓰기 권한이 없습니다."); }

        $file_name = uniqid() . '-' . basename($_FILES['meeting_image']['name']);
        $target_file = $upload_dir . $file_name;

        if (move_uploaded_file($_FILES['meeting_image']['tmp_name'], $target_file)) {
            $image_path = 'uploads/' . $file_name;
        } else {
            die("파일 업로드 실패");
        }
    }

    // 데이터베이스에 저장
    try {
        $pdo = getDBConnection();
        $sql = "INSERT INTO meetings (organizer_id, title, description, category, location, max_members, meeting_date, meeting_time, image_path) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        
        if ($stmt->execute([$organizer_id, $title, $description, $category, $location, $max_members, $meeting_date, $meeting_time, $image_path])) {
            $new_meeting_id = $pdo->lastInsertId();
            
            // Pinecone 업데이트
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

            redirect('meeting.php');
        } else {
            die("데이터베이스 실행 오류");
        }
    } catch (PDOException $e) {
        die("데이터베이스 오류: " . $e->getMessage());
    }
} else {
    redirect('meeting.php');
}
?>