<?php
require_once 'config.php';

header('Content-Type: application/json');

// 로그인 확인
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => '로그인이 필요합니다.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

    // 2. 이미지 파일 처리
    $image_paths = [];
    if (isset($_FILES['hobby_photos'])) {
        // [수정] 상대 경로 대신 __DIR__을 사용한 절대 경로로 변경하여 안정성 확보
        $upload_dir = realpath(__DIR__ . '/../uploads') . '/hobby_photos/';
        error_log("[AI_HOBBY_DEBUG] 1. Target upload directory: " . $upload_dir);

        if (!is_dir($upload_dir)) {
            error_log("[AI_HOBBY_DEBUG] 2. Directory does not exist. Attempting to create...");
            // mkdir의 세 번째 파라미터 'true'는 재귀적으로 폴더를 생성합니다.
            if (!mkdir($upload_dir, 0775, true)) {
                // 폴더 생성 실패 시 즉시 에러를 던집니다.
                throw new Exception("Failed to create upload directory. Check permissions for parent directory: " . dirname($upload_dir));
            }
            error_log("[AI_HOBBY_DEBUG] 3. Upload directory created: " . $upload_dir);
        }

        // [추가] 폴더 쓰기 권한 확인
        if (!is_writable($upload_dir)) {
            $error_message = "Upload directory is not writable. Please check permissions for: " . $upload_dir;
            error_log("[AI_HOBBY_DEBUG] 4. " . $error_message);
            throw new Exception($error_message);
        }
        error_log("[AI_HOBBY_DEBUG] 4. Upload directory is writable.");

        foreach ($_FILES['hobby_photos']['tmp_name'] as $key => $tmp_name) {
            $upload_error = $_FILES['hobby_photos']['error'][$key];
            error_log("[AI_HOBBY_DEBUG] 5. Processing file key {$key}. Upload status: {$upload_error}");

            if ($upload_error === UPLOAD_ERR_OK) {
                $file_name = uniqid() . '-' . basename($_FILES['hobby_photos']['name'][$key]);
                $target_file = $upload_dir . $file_name;
                error_log("[AI_HOBBY_DEBUG] 6. Attempting to move '{$tmp_name}' to '{$target_file}'");

                if (@move_uploaded_file($tmp_name, $target_file)) { // @ 연산자로 기본 경고를 숨기고 직접 에러를 처리합니다.
                    $image_paths[] = realpath($target_file);
                    error_log("[AI_HOBBY_DEBUG] 7. SUCCESS: File moved to " . $target_file);
                } else {
                    error_log("[AI_HOBBY_DEBUG] 7. FAILURE: Could not move uploaded file to " . $target_file . ". Check file upload settings or directory permissions.");
                }
            } else {
                // [수정] 업로드 에러 코드를 분석하여 더 상세한 로그를 남깁니다.
                $error_message = "Unknown upload error.";
                switch ($upload_error) {
                    case UPLOAD_ERR_INI_SIZE:
                        $error_message = "The uploaded file exceeds the upload_max_filesize directive in php.ini.";
                        break;
                    case UPLOAD_ERR_FORM_SIZE:
                        $error_message = "The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form.";
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $error_message = "The uploaded file was only partially uploaded.";
                        break;
                    case UPLOAD_ERR_NO_FILE:
                        $error_message = "No file was uploaded.";
                        break;
                    case UPLOAD_ERR_NO_TMP_DIR:
                        $error_message = "Missing a temporary folder.";
                        break;
                }
                error_log("[AI_HOBBY_DEBUG] File upload error for key {$key}. Code: {$upload_error} - {$error_message}");
            }
        }
    }

    // 3. AI 에이전트에 보낼 데이터 구조 생성
    $request_payload = [
        'user_input' => [
            'survey' => $survey_data,
            'image_paths' => $image_paths
        ]
    ];

    // 4. cURL을 사용해 AI 에이전트 API 호출
    $ch = curl_init('http://127.0.0.1:8000/agent/invoke');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // AI 처리 시간을 고려해 넉넉하게 2분으로 설정

    $response_body = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch) || $http_code !== 200) {
        throw new Exception("AI 추천 서버와의 통신에 실패했습니다. (HTTP: {$http_code})");
    }
    curl_close($ch);

    // 5. AI 추천 결과 파싱 및 반환
    $response_data = json_decode($response_body, true);
    if (isset($response_data['final_answer']) && !empty($response_data['final_answer'])) {
        $recommendation_text = $response_data['final_answer'];

        // DB에 결과 저장
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("INSERT INTO ai_hobby_recommendations (user_id, recommendation_text) VALUES (?, ?)");
        $stmt->execute([$_SESSION['user_id'], $recommendation_text]);

        echo json_encode(['success' => true, 'recommendation' => htmlspecialchars($recommendation_text)]);
    } else {
        throw new Exception('AI가 유효한 추천을 생성하지 못했습니다.');
    }

} catch (Exception $e) {
    error_log("AI Recommendation Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

?>