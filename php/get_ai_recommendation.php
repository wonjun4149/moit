<?php
require_once 'config.php';

header('Content-Type: application/json');

// =================[ DEBUG START ]=================
// 스크립트 시작과 함께 들어온 요청 데이터를 기록
error_log("--- New AI Recommendation Request ---");
error_log("POST data: " . json_encode($_POST));
error_log("FILES data: " . json_encode($_FILES));
// =================[ DEBUG END ]===================

// 로그인 확인
if (!isLoggedIn()) {
    error_log("Authentication failed: User not logged in.");
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    error_log("Invalid request method: " . $_SERVER['REQUEST_METHOD']);
    echo json_encode(['success' => false, 'message' => '잘못된 요청입니다.']);
    exit;
}

try {
    // 1. 설문 데이터 수집
    $survey_data = [];
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'Q') === 0) {
            $q_num = intval(substr($key, 1));
            if (is_array($value)) {
                $survey_data[$q_num] = array_map('intval', $value);
            } else {
                $survey_data[$q_num] = intval($value);
            }
        }
    }
    error_log("Parsed survey data: " . json_encode($survey_data));

    // 2. 이미지 파일 처리 및 서버 로컬 경로 생성
    $image_paths = [];
    if (isset($_FILES['hobby_photos'])) {
        // =================[ DEBUG START ]=================
        error_log("hobby_photos file upload detected.");
        $upload_dir_fs = __DIR__ . '/../uploads/hobby_photos/';
        error_log("Upload directory (filesystem): " . $upload_dir_fs);

        // 디렉토리 존재 여부 및 생성
        if (!is_dir($upload_dir_fs)) {
            error_log("Upload directory does not exist. Attempting to create it.");
            if (!mkdir($upload_dir_fs, 0775, true)) {
                // mkdir 실패 시 에러를 던져서 아래 catch 블록에서 잡도록 함
                throw new Exception("Failed to create upload directory: " . $upload_dir_fs . ". Check permissions.");
            }
            error_log("Upload directory created successfully.");
        }

        // 디렉토리 쓰기 권한 확인
        if (!is_writable($upload_dir_fs)) {
            throw new Exception("Upload directory is not writable: " . $upload_dir_fs . ". Check permissions.");
        }
        error_log("Upload directory is writable.");
        // =================[ DEBUG END ]===================

        foreach ($_FILES['hobby_photos']['tmp_name'] as $key => $tmp_name) {
            $upload_error = $_FILES['hobby_photos']['error'][$key];
            if ($upload_error === UPLOAD_ERR_OK) {
                $file_name = uniqid() . '-' . basename($_FILES['hobby_photos']['name'][$key]);
                $target_file_fs = $upload_dir_fs . $file_name;
                
                error_log("Attempting to move file '{$tmp_name}' to '{$target_file_fs}'");
                if (move_uploaded_file($tmp_name, $target_file_fs)) {
                    // 웹 URL 대신 서버의 절대 파일 경로를 배열에 추가
                    $image_paths[] = $target_file_fs;
                    error_log("File moved successfully. Path: " . $target_file_fs);
                } else {
                    error_log("move_uploaded_file FAILED for '{$tmp_name}'.");
                }
            } else {
                // =================[ DEBUG START ]=================
                // PHP 업로드 에러 코드에 따른 상세 로깅
                $error_messages = [
                    UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the upload_max_filesize directive in php.ini.',
                    UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.',
                    UPLOAD_ERR_PARTIAL    => 'The uploaded file was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                    UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                    UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                    UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.',
                ];
                $error_message = $error_messages[$upload_error] ?? 'Unknown upload error';
                error_log("File upload error for key {$key}. Code: {$upload_error}. Message: {$error_message}");
                // =================[ DEBUG END ]===================
            }
        }
    } else {
        error_log("No 'hobby_photos' in FILES array.");
    }

    // 3. AI 에이전트에 보낼 데이터 구조 생성
    $request_payload = [
        'user_input' => [
            'survey' => $survey_data,
            'image_paths' => $image_paths // 키를 'image_paths'로 변경하여 전송
        ]
    ];
    error_log("Payload to AI server: " . json_encode($request_payload));

    // 4. cURL을 사용해 AI 에이전트 API 호출
    $ch = curl_init('http://127.0.0.1:8000/agent/invoke');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    if ($curl_error || $http_code !== 200) {
        throw new Exception("AI server communication failed. HTTP: {$http_code}, cURL-Error: {$curl_error}, Response: {$response_body}");
    }
    curl_close($ch);
    error_log("AI server response (HTTP {$http_code}): " . $response_body);

    // 5. AI 추천 결과 파싱 및 반환
    $response_data = json_decode($response_body, true);
    if (isset($response_data['final_answer']) && !empty($response_data['final_answer'])) {
        $recommendation_text = $response_data['final_answer'];

        // DB에 결과 저장
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("INSERT INTO ai_hobby_recommendations (user_id, recommendation_text) VALUES (?, ?)");
        $stmt->execute([$_SESSION['user_id'], $recommendation_text]);
        error_log("Recommendation saved to DB for user_id: " . $_SESSION['user_id']);

        echo json_encode(['success' => true, 'recommendation' => htmlspecialchars($recommendation_text)]);
    } else {
        throw new Exception('AI did not return a valid recommendation. Response: ' . $response_body);
    }

} catch (Exception $e) {
    error_log("AI Recommendation Script CRITICAL ERROR: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>