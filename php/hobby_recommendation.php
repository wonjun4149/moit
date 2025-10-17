<?php
// MVP 취미 추천 페이지 - 상세 설문 기반
require_once 'config.php';

// 디버그 모드 확인
$debug_mode = isset($_GET['debug']) || isset($_POST['debug']);

// 디버그 출력 함수
function debug_output($message, $data = null) {
    global $debug_mode;
    if ($debug_mode) {
        echo "<div style='background: #f0f0f0; padding: 10px; margin: 5px; border-left: 4px solid #007cba;'>";
        echo "<strong>DEBUG:</strong> " . htmlspecialchars($message);
        if ($data !== null) {
            echo "<pre>" . htmlspecialchars(print_r($data, true)) . "</pre>";
        }
        echo "</div>";
    }
}

debug_output("페이지 로드 시작");
debug_output("REQUEST_METHOD", $_SERVER['REQUEST_METHOD']);
debug_output("POST 데이터", $_POST);

// 로그인 확인
if (!isLoggedIn()) {
    debug_output("로그인되지 않음");
    redirect('login.php');
}

debug_output("로그인 확인됨", $_SESSION['user_id']);

$site_title = "MOIT - 취미 추천";
$error_message = '';
$recommendations = [];
$popular_hobbies = [];
$meetup_posts = [];

// 데이터베이스 연결
try {
    debug_output("데이터베이스 연결 시도");
    $pdo = getDBConnection();
    debug_output("데이터베이스 연결 성공");
    
    // 인기 취미 가져오기
    $stmt = $pdo->query("
        SELECT h.*, COUNT(hr.hobby_id) as recommendation_count
        FROM hobbies h
        LEFT JOIN hobby_recommendations hr ON h.id = hr.hobby_id
        GROUP BY h.id
        ORDER BY recommendation_count DESC, h.name ASC
        LIMIT 10
    ");
    $popular_hobbies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    debug_output("인기 취미 로드됨", count($popular_hobbies) . "개");
    
} catch (PDOException $e) {
    debug_output("데이터베이스 에러", $e->getMessage());
    $error_message = '데이터를 불러오는 중 오류가 발생했습니다.';
}

// 설문 제출 처리 - AI 에이전트 연동
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_survey'])) {
    debug_output("=== AI 에이전트 기반 추천 시작 ===");
    
    try {
        // 1. 설문 데이터 수집
        $survey_data = [];
        foreach ($_POST as $key => $value) {
            if (strpos($key, 'Q') === 0) {
                // Q10과 같은 체크박스는 배열로 들어오므로 그대로 유지
                $q_num = intval(substr($key, 1));
                if (is_array($value)) {
                    $survey_data[$q_num] = $value;
                } else {
                    // 라디오/likert 값은 숫자 값으로 변환
                    $survey_data[$q_num] = intval($value);
                }
            }
        }
        debug_output("정리된 설문 데이터", $survey_data);

        // 2. 이미지 파일 처리
        $image_urls = [];
        if (isset($_FILES['hobby_photos'])) {
            $upload_dir = '../uploads/hobby_photos/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0775, true);
            }

            // 웹 서버의 기본 URL을 설정합니다. (예: http://localhost:8080)
            $base_url = "http://" . $_SERVER['HTTP_HOST'];

            foreach ($_FILES['hobby_photos']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['hobby_photos']['error'][$key] === UPLOAD_ERR_OK) {
                    $file_name = uniqid() . '-' . basename($_FILES['hobby_photos']['name'][$key]);
                    $target_file = $upload_dir . $file_name;
                    if (move_uploaded_file($tmp_name, $target_file)) {
                        // AI 서버가 웹을 통해 접근할 수 있는 전체 URL을 생성합니다.
                        // '/moit' 부분은 웹 서버 설정에 따라 필요 없을 수 있습니다.
                        $image_urls[] = $base_url . '/uploads/hobby_photos/' . $file_name;
                    }
                }
            }
        }
        debug_output("AI 서버로 전송할 이미지 URL", $image_urls);

        // 3. AI 에이전트에 보낼 데이터 구조 생성
        $request_payload = [
            'user_input' => [
                'survey' => $survey_data, 
                'image_urls' => $image_urls
            ]
        ];
        debug_output("AI 서버 요청 데이터", $request_payload);

        // 4. cURL을 사용해 AI 에이전트 API 호출
        $ch = curl_init('http://127.0.0.1:8000/agent/invoke');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen(json_encode($request_payload))
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 연결 타임아웃 5초
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);      // 이미지 분석을 위해 타임아웃 60초로 연장

        $response_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch) || $http_code !== 200) {
            $curl_error = curl_error($ch);
            debug_output("cURL Error", ['message' => $curl_error, 'http_code' => $http_code, 'body' => $response_body]);
            throw new Exception("AI 추천 서버와의 통신에 실패했습니다. (HTTP: {$http_code})");
        }
        curl_close($ch);

        debug_output("AI 서버 응답", json_decode($response_body, true));

        // 5. AI 추천 결과 파싱 및 변환
        $response_data = json_decode($response_body, true);
        if (isset($response_data['final_answer']) && !empty($response_data['final_answer'])) {
            // AI 응답이 추천 메시지 전체이므로 그대로 사용
            $recommendations = $response_data['final_answer'];

            // AI 추천 결과를 데이터베이스에 저장
            try {
                debug_output("AI 추천 결과 DB 저장 시도");
                $stmt = $pdo->prepare("
                    INSERT INTO ai_hobby_recommendations (user_id, recommendation_text) 
                    VALUES (?, ?)
                ");
                $stmt->execute([$_SESSION['user_id'], $recommendations]);
                debug_output("AI 추천 결과 DB 저장 성공");
            } catch (PDOException $e) {
                debug_output("AI 추천 결과 DB 저장 실패", $e->getMessage());
            }
        }

        if (empty($recommendations)) {
             $error_message = "AI가 추천을 생성하지 못했거나, 응답을 처리하는 데 실패했습니다. AI 서버 로그를 확인해주세요.";
             debug_output("추천 결과 파싱 실패 또는 빈 결과", $response_data);
        }

    } catch (Exception $e) {
        debug_output("예외 발생", ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        $error_message = '추천을 생성하는 중 오류가 발생했습니다: ' . $e->getMessage();
    }
    debug_output("=== AI 추천 처리 완료 ===");
}

debug_output("최종 상태", [
    'recommendations_count' => count($recommendations),
    'error_message' => $error_message,
    'popular_hobbies_count' => count($popular_hobbies)
]);
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $site_title; ?></title>
    <link rel="stylesheet" href="../css/navbar-style.css">
    <link rel="stylesheet" href="../css/hobby_recommendation-style.css">
</head>
<body>
    <?php if ($debug_mode): ?>
        <div style="background: #ffffcc; padding: 15px; margin: 10px; border: 2px solid #ffcc00;">
            <h3>🐛 디버그 모드 활성화</h3>
            <p><strong>현재 상태:</strong></p>
            <ul>
                <li>POST 요청: <?php echo ($_SERVER['REQUEST_METHOD'] == 'POST') ? '✅' : '❌'; ?></li>
                <li>설문 제출: <?php echo isset($_POST['submit_survey']) ? '✅' : '❌'; ?></li>
                <li>추천 결과: <?php echo count($recommendations); ?>개</li>
                <li>에러: <?php echo $error_message ?: '없음'; ?></li>
            </ul>
        </div>
    <?php endif; ?>

    <?php require_once 'navbar.php'; ?>

    <main class="main-container">
        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="content-wrapper">
            <div class="left-section">
                <!-- 왼쪽 섹션: 설문조사 폼 -->
                <div class="survey-container">
                    <div class="survey-progress">
                        <div class="progress-bar">
                            <div class="progress-fill" id="progressFill"></div>
                        </div>
                        <span class="progress-text" id="progressText">1 / 49</span>
                    </div>

                    <h2>당신의 취향을 알려주세요</h2>
                    <p class="survey-subtitle">자신을 위한 딱 맞는 활동을 찾아드릴게요!</p>

                    <form method="POST" class="survey-form" id="surveyForm" enctype="multipart/form-data">
                        <?php if ($debug_mode): ?>
                            <input type="hidden" name="debug" value="1">
                        <?php endif; ?>

                        <?php
                            // ### 변경된 부분: 새로운 설문 문항 ###
                            $stage1_questions = [
                                ['name' => 'Q1', 'label' => '1. 일주일에 새로운 활동을 위해 온전히 사용할 수 있는 시간은 어느 정도인가요?', 'type' => 'radio', 'options' => ['1시간 미만', '1시간 ~ 3시간', '3시간 ~ 5시간', '5시간 이상']],
                                ['name' => 'Q2', 'label' => '2. 한 달에 새로운 활동을 위해 부담 없이 지출할 수 있는 예산은 얼마인가요?', 'type' => 'radio', 'options' => ['거의 없음 또는 3만원 미만', '3만원 ~ 5만원', '5만원 ~ 10만원', '10만원 이상']],
                                ['name' => 'Q3', 'label' => '3. 평소 하루를 보낼 때, 당신의 신체적 에너지 수준은 어느 정도라고 느끼시나요?', 'type' => 'likert', 'labels' => ['거의 방전', '매우 활기참']],
                                ['name' => 'Q4', 'label' => '4. 집 밖의 다른 장소로 혼자 이동하는 것이 얼마나 편리한가요?', 'type' => 'likert', 'options_text' => ['매우 불편하고 거의 불가능하다.', '상당한 노력이 필요하다.', '보통이다.', '쉬운 편이다.', '매우 쉽고 편리하다.']],
                                ['name' => 'Q5', 'label' => '5. 다음 중 당신의 현재 신체 상태를 가장 잘 설명하는 것은 무엇인가요?', 'type' => 'radio', 'options' => ['오랜 시간 앉아 있거나 서 있는 것이 힘들다.', '계단을 오르거나 조금만 걸어도 숨이 차다.', '만성적인 통증이나 피로감이 있다.', '딱히 신체적인 어려움은 없다.']],
                                ['name' => 'Q6', 'label' => '6. 활동 공간에 대한 다음 설명 중 더 끌리는 쪽은 어디인가요?', 'type' => 'radio', 'options' => ['익숙하고 안전한 집 안에서 할 수 있는 활동', '집 근처에서 가볍게 할 수 있는 야외 활동', '새로운 장소를 찾아가는 활동']],
                                ['name' => 'Q7', 'label' => '7. 당신은 어떤 환경에서 더 편안함을 느끼나요?', 'type' => 'radio', 'options' => ['조용하고 자극이 적은 환경', '활기차고 다양한 볼거리가 있는 환경']],
                                ['name' => 'Q8', 'label' => '8. 새로운 것을 배울 때 어떤 방식을 더 선호하시나요?', 'type' => 'radio', 'options' => ['정해진 규칙이나 설명서 없이 자유롭게 탐색하는 방식', '명확한 가이드라인이나 단계별 지침이 있는 방식']],
                                ['name' => 'Q9', 'label' => '9. 다음 중 당신이 더 피하고 싶은 활동은 무엇인가요?', 'type' => 'radio', 'options' => ['세밀한 집중력이나 기억력이 많이 요구되는 활동', '빠르거나 순발력이 요구되는 활동']],
                                ['name' => 'Q10', 'label' => '10. 이전에 무언가를 배우거나 시도하다 그만둔 경험이 있다면, 주된 이유는 무엇이었나요? (중복 선택 가능)', 'type' => 'checkbox', 'options' => ['생각보다 재미가 없어서', '생각보다 너무 어렵고 실력이 늘지 않아서', '시간이나 돈이 부족해서', '함께하는 사람들과 어울리기 힘들어서', '건강상의 문제나 체력이 부족해서']],
                                ['name' => 'Q11', 'label' => '11. "새로운 것을 시작하는 것 자체가 큰 스트레스와 부담으로 느껴진다."', 'type' => 'likert', 'options_text' => ['전혀 그렇지 않다', '그렇지 않다', '보통이다', '그렇다', '매우 그렇다']],
                                ['name' => 'Q12', 'label' => '12. 당신의 주거 환경은 새로운 활동을 하기에 어떻다고 생각하시나요?', 'type' => 'radio', 'options' => ['활동에 집중할 수 있는 독립된 공간이 있다.', '공용 공간을 사용해야 해서 제약이 있다.', '층간 소음 등 주변 환경이 신경 쓰인다.', '공간이 협소하여 활동에 제약이 있다.']],
                            ];
                            $stage2_questions = [
                                ['name' => 'Q13', 'label' => '13. "나는 어떤 일에 실패하거나 실수를 했을 때, 나 자신을 심하게 비난하고 자책하는 편이다."', 'type' => 'likert', 'options_text' => ['전혀 그렇지 않다', '그렇지 않다', '보통이다', '그렇다', '매우 그렇다']],
                                ['name' => 'Q14', 'label' => '14. "나는 나의 단점이나 부족한 부분도 너그럽게 받아들이려고 노력한다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                                ['name' => 'Q15', 'label' => '15. "나는 다른 사람의 평가나 시선에 매우 민감하다."', 'type' => 'likert', 'options_text' => ['전혀 그렇지 않다', '그렇지 않다', '보통이다', '그렇다', '매우 그렇다']],
                                ['name' => 'Q16', 'label' => '16. "나는 무언가를 할 때 \'완벽하게\' 해내야 한다는 압박감을 느낀다."', 'type' => 'likert', 'options_text' => ['전혀 그렇지 않다', '그렇지 않다', '보통이다', '그렇다', '매우 그렇다']],
                                ['name' => 'Q17', 'label' => '17. "괴로운 감정이나 생각이 들 때, 애써 외면하기보다 차분히 바라보려고 하는 편이다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                                ['name' => 'Q18', 'label' => '18. "지금 당장 새로운 사람들을 만나야 한다고 상상하면, 심한 불안감이나 불편함이 느껴진다."', 'type' => 'likert', 'options_text' => ['전혀 그렇지 않다', '그렇지 않다', '보통이다', '그렇다', '매우 그렇다']],
                                ['name' => 'Q19', 'label' => '19. "낯선 사람들과의 대화보다는 친한 사람과의 깊은 대화가 훨씬 편안하다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                                ['name' => 'Q20', 'label' => '20. "나는 다른 사람들에게 도움을 요청하는 것을 어려워한다."', 'type' => 'likert', 'options_text' => ['전혀 그렇지 않다', '그렇지 않다', '보통이다', '그렇다', '매우 그렇다']],
                                ['name' => 'Q21', 'label' => '21. "최근 일주일간 당신의 외출 및 사회적 활동 수준은 어떠했나요?"', 'type' => 'radio', 'options' => ['거의 방에서만 시간을 보냈다.', '집 안에서는 활동하지만 외출은 거의 하지 않았다.', '편의점 방문 등 필수적인 용무로만 잠시 외출했다.', '산책 등 혼자 하는 활동을 위해 외출한 적이 있다.', '다른 사람과 만나는 활동을 위해 외출한 적이 있다.']],
                                ['name' => 'Q22', 'label' => '22. "나는 혼자라는 사실이 외롭게 느껴지기보다, 오히려 편안하고 자유롭게 느껴진다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                                ['name' => 'Q23', 'label' => '23. "활동을 할 때, 다른 사람과 경쟁하는 상황은 가급적 피하고 싶다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                                ['name' => 'Q24', 'label' => '24. "함께 무언가를 할 때, 내가 주도하기보다는 다른 사람의 의견을 따르는 것이 더 편하다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                                ['name' => 'Q25', 'label' => '25. 요즘 당신의 기분 상태를 가장 잘 나타내는 단어는 무엇인가요?', 'type' => 'radio', 'options' => ['무기력함', '불안함', '외로움', '지루함', '평온함']],
                                ['name' => 'Q26', 'label' => '26. "요즘 들어 무언가에 집중하는 것이 어렵게 느껴진다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                                ['name' => 'Q27', 'label' => '27. "나는 예측 불가능한 상황보다, 계획되고 구조화된 상황에서 안정감을 느낀다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                                ['name' => 'Q28', 'label' => '28. "사소한 일에도 쉽게 지치거나 스트레스를 받는다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                                ['name' => 'Q29', 'label' => '29. "나는 힘든 일이 있을 때, 그 문제 자체에 대해 생각하기보다 다른 무언가에 몰두하며 잊으려고 하는 편이다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                                ['name' => 'Q30', 'label' => '30. "나는 다른 사람들이 나를 있는 그대로 이해해주지 못한다고 느낄 때가 많다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                            ];
                            $stage3_questions = [
                                ['name' => 'Q31', 'label' => '31. 새로운 활동을 통해 당신이 가장 얻고 싶은 것은 무엇인가요? (가장 중요한 것 1개 선택)', 'type' => 'radio', 'options' => ['성취: 새로운 기술을 배우고 실력이 느는 것을 확인하는 것', '회복: 복잡한 생각에서 벗어나 편안하게 재충전하는 것', '연결: 좋은 사람들과 교류하며 소속감을 느끼는 것', '활력: 몸을 움직여 건강해지고 에너지를 얻는 것']],
                                ['name' => 'Q32', 'label' => '32. 다음 문장들 중, 현재 당신의 마음에 가장 와닿는 것은 무엇인가요?', 'type' => 'radio', 'options' => ['"무언가에 깊이 몰입해서 시간 가는 줄 모르는 경험을 하고 싶다."', '"결과물에 상관없이 과정 자체를 즐기고 싶다."', '"나도 누군가에게 도움이 되는 가치 있는 일을 하고 싶다."', '"그저 즐겁게 웃을 수 있는 시간이 필요하다."']],
                                ['name' => 'Q33', 'label' => '33. 새로운 지식이나 기술을 배우는 것', 'type' => 'likert', 'labels' => ['전혀 중요하지 않음', '매우 중요함']],
                                ['name' => 'Q34', 'label' => '34. 마음의 평화와 안정을 얻는 것', 'type' => 'likert', 'labels' => ['전혀 중요하지 않음', '매우 중요함']],
                                ['name' => 'Q35', 'label' => '35. 다른 사람들과 유대감을 형성하는 것', 'type' => 'likert', 'labels' => ['전혀 중요하지 않음', '매우 중요함']],
                                ['name' => 'Q36', 'label' => '36. 신체적인 건강과 활력을 증진하는 것', 'type' => 'likert', 'labels' => ['전혀 중요하지 않음', '매우 중요함']],
                                ['name' => 'Q37', 'label' => '37. 나만의 개성과 창의성을 표현하는 것', 'type' => 'likert', 'labels' => ['전혀 중요하지 않음', '매우 중요함']],
                                ['name' => 'Q38', 'label' => '38. 나의 삶을 스스로 통제하고 있다는 느낌을 갖는 것', 'type' => 'likert', 'labels' => ['전혀 중요하지 않음', '매우 중요함']],
                                ['name' => 'Q39', 'label' => '39. 당신에게 가장 이상적인 활동 환경을 상상해보세요. 다음 중 가장 끌리는 것을 하나만 선택해주세요.', 'type' => 'radio', 'options' => ['단독형: 누구에게도 방해받지 않는 나만의 공간에서 혼자 하는 활동', '병렬형: 다른 사람들이 주변에 있지만, 각자 자기 활동에 집중하는 조용한 공간 (예: 도서관, 카페)', '저강도 상호작용형: 선생님이나 안내자가 활동을 이끌어주는 소규모 그룹 (예: 강좌, 워크숍)', '고강도 상호작용형: 공통의 목표를 위해 협력하거나 자유롭게 소통하는 모임 (예: 동호회, 팀 스포츠)']],
                                ['name' => 'Q40', 'label' => '40. 누군가와 함께 활동한다면, 어떤 형태를 가장 선호하시나요?', 'type' => 'radio', 'options' => ['마음이 잘 맞는 단 한 명의 파트너와 함께하는 것', '3~4명 정도의 소규모 그룹', '다양한 사람들을 만날 수 있는 대규모 그룹']],
                                ['name' => 'Q41', 'label' => '41. "나는 명확한 목표나 결과물이 있는 활동을 선호한다." (예: 그림 완성, 요리 완성)', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                                ['name' => 'Q42', 'label' => '42. "나는 활동을 할 때, 정해진 규칙을 따르기보다 나만의 방식으로 자유롭게 하는 것이 좋다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                                ['name' => 'Q43', 'label' => '43. 자연과 함께하는 활동에 얼마나 관심이 있으신가요? (예: 산책, 텃밭 가꾸기)', 'type' => 'likert', 'labels' => ['전혀 관심 없음', '매우 관심 많음']],
                                ['name' => 'Q44', 'label' => '44. 손으로 무언가를 만드는 활동(예: 공예, 요리)에 얼마나 관심이 있으신가요?', 'type' => 'likert', 'labels' => ['전혀 관심 없음', '매우 관심 많음']],
                                ['name' => 'Q45', 'label' => '45. 지적인 탐구 활동(예: 책 읽기, 새로운 분야 공부)에 얼마나 관심이 있으신가요?', 'type' => 'likert', 'labels' => ['전혀 관심 없음', '매우 관심 많음']],
                                ['name' => 'Q46', 'label' => '46. 음악, 미술, 글쓰기 등 창작 및 감상 활동에 얼마나 관심이 있으신가요?', 'type' => 'likert', 'labels' => ['전혀 관심 없음', '매우 관심 많음']],
                                ['name' => 'Q47', 'label' => '47. 몸을 움직이는 신체 활동(예: 운동, 춤)에 얼마나 관심이 있으신가요?', 'type' => 'likert', 'labels' => ['전혀 관심 없음', '매우 관심 많음']],
                                ['name' => 'Q48', 'label' => '48. "만약 새로운 그룹 활동에 참여한다면, 기존 멤버들이 끈끈하게 뭉쳐 있는 곳보다는, 나와 같이 새로 시작하는 사람들이 많은 곳이 더 편할 것 같다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                            ];

                            $all_questions = array_merge($stage1_questions, $stage2_questions, $stage3_questions);
                        ?>

                        <div id="stage1-header" class="survey-part-header" style="display: none;">
                            <h3>1단계: 나의 현실적인 일상 점검하기</h3>
                            <p class="part-subtitle">당신의 현재 생활 환경과 현실적인 제약 요인을 파악합니다.</p>
                        </div>
                        <div id="stage2-header" class="survey-part-header" style="display: none;">
                            <h3>2단계: 나의 마음 상태 들여다보기</h3>
                            <p class="part-subtitle">당신의 현재 심리적 상태와 사회적 관계에 대한 생각을 이해합니다.</p>
                        </div>
                        <div id="stage3-header" class="survey-part-header" style="display: none;">
                            <h3>3단계: 내가 바라는 활동의 모습 그려보기</h3>
                            <p class="part-subtitle">새로운 활동을 통해 무엇을 얻고 싶은지 구체적으로 그려봅니다.</p>
                        </div>

                        <?php foreach ($all_questions as $index => $q): ?>
                            <div class="question-step <?php echo $index === 0 ? 'active' : ''; ?>" data-step="<?php echo $index + 1; ?>">
                                <?php if ($q['type'] === 'radio'): ?>
                                    <div class="question-group">
                                        <label class="question-label"><?php echo $q['label']; ?></label>
                                        <div class="option-group-inline">
                                            <?php foreach ($q['options'] as $i => $opt): ?>
                                            <label class="option-label-inline">
                                                <input type="radio" name="<?php echo $q['name']; ?>" value="<?php echo $i + 1; ?>" required>
                                                <span><?php echo $opt; ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php elseif ($q['type'] === 'likert'): ?>
                                    <div class="question-group-likert">
                                        <label class="question-label-likert"><?php echo $q['label']; ?></label>
                                        <div class="likert-scale">
                                            <div class="likert-options">
                                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <label class="likert-option">
                                                    <input type="radio" name="<?php echo $q['name']; ?>" value="<?php echo $i; ?>" required>
                                                    <span class="likert-radio-button">
                                                        <?php echo isset($q['options_text']) ? '' : $i; ?>
                                                    </span>
                                                </label>
                                                <?php endfor; ?>
                                            </div>
                                            <div class="likert-labels">
                                                <span><?php echo $q['labels'][0] ?? (isset($q['options_text']) ? $q['options_text'][0] : '전혀 그렇지 않다'); ?></span>
                                                <span><?php echo $q['labels'][1] ?? (isset($q['options_text']) ? end($q['options_text']) : '매우 그렇다'); ?></span>
                                            </div>
                                        </div>
                                    </div>
                                <?php elseif ($q['type'] === 'checkbox'): // ### 추가된 부분: 체크박스 유형 ### ?>
                                    <div class="question-group">
                                        <label class="question-label"><?php echo $q['label']; ?></label>
                                        <div class="option-group-inline checkbox-group">
                                            <?php foreach ($q['options'] as $i => $opt): ?>
                                            <label class="option-label-inline">
                                                <input type="checkbox" name="<?php echo $q['name']; ?>[]" value="<?php echo $i + 1; ?>">
                                                <span><?php echo $opt; ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>

                        <!-- 사진 업로드 단계 추가 -->
                        <div class="question-step" data-step="49">
                            <div class="question-group">
                                <label class="question-label">📸 마지막으로, 당신의 일상이 담긴 사진을 올려주세요.</label>
                                <div class="photo-upload-guide">
                                    <p>AI가 사진을 분석하여 당신의 잠재적인 관심사를 파악하는 데 도움을 줍니다.</p>
                                    <ul>
                                        <li><strong>최근 한 달 동안</strong> 찍은 사진 중 마음에 드는 것을 골라주세요.</li>
                                        <li>과거의 사진 중 <strong>돌아가고 싶은 순간</strong>이나 <strong>간직하고 싶은 추억</strong>이 담긴 사진도 좋습니다.</li>
                                        <li>인물, 사물, 풍경, 음식 등 <strong>다양한 사진</strong>을 올릴수록 분석 정확도가 높아집니다.</li>
                                    </ul>
                                </div>
                                <input type="file" name="hobby_photos[]" id="hobby_photos" multiple accept="image/*" style="margin-top: 15px;">
                                <div id="photo-preview" class="photo-preview-container"></div>
                            </div>
                        </div>


                        <div class="survey-buttons">
                            <button type="button" class="btn-prev" id="prevBtn" style="display: none;">이전</button>
                            <button type="button" class="btn-next" id="nextBtn">다음</button>
                            <button type="submit" name="submit_survey" class="submit-btn" id="submitBtn" style="display: none;">취미 추천받기</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="right-section">
                <?php if (!empty($recommendations)): ?>
                    <!-- AI 추천 결과가 있을 경우 -->
                    <div class="recommendations-container">
                        <h3>🎉 맞춤 취미 추천 결과</h3>
                        <div class="ai-recommendation-box" style="margin-top: 20px;">
                            <?php 
                                echo nl2br(htmlspecialchars($recommendations)); 
                            ?>
                        </div>
                        <div class="survey-actions">
                            <a href="hobby_recommendation.php" class="btn-secondary">다시 추천받기</a>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- AI 추천 결과가 없을 경우 (기본 상태) -->
                    <h3>요즘 이런 취미로 많이 모여요</h3>
                    <div class="popular-hobbies">
                        <?php foreach ($popular_hobbies as $index => $hobby): ?>
                            <div class="popular-hobby-item" onclick="loadMeetups(<?php echo $hobby['id']; ?>)">
                                <div class="hobby-rank"><?php echo $index + 1; ?></div>
                                <div class="hobby-info">
                                    <h4 class="hobby-name"><?php echo htmlspecialchars($hobby['name']); ?></h4>
                                    <span class="hobby-category"><?php echo htmlspecialchars($hobby['category']); ?></span>
                                </div>
                                <div class="hobby-count">
                                    <span><?php echo $hobby['recommendation_count']; ?>회 추천</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script src="/js/navbar.js"></script>
    <script>
        const surveyForm = document.getElementById('surveyForm');
        if (surveyForm) {
            // ### 변경된 부분: 전체 문항 수 업데이트 ###
            let currentStep = 1;
            const totalSteps = 49; // 사진 업로드 단계 포함

            const questionSteps = document.querySelectorAll('.question-step');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const submitBtn = document.getElementById('submitBtn');
            const progressFill = document.getElementById('progressFill');
            const progressText = document.getElementById('progressText');

            // ### 변경된 부분: 3단계 헤더 참조 추가 ###
            const stage1Header = document.getElementById('stage1-header');
            const stage2Header = document.getElementById('stage2-header');
            const stage3Header = document.getElementById('stage3-header');

            // 사진 미리보기 기능
            const photoInput = document.getElementById('hobby_photos');
            const photoPreview = document.getElementById('photo-preview');
            if(photoInput) {
                photoInput.addEventListener('change', function() {
                    photoPreview.innerHTML = ''; // 기존 미리보기 초기화
                    Array.from(this.files).forEach(file => {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            const img = document.createElement('img');
                            img.src = e.target.result;
                            photoPreview.appendChild(img);
                        }
                        reader.readAsDataURL(file);
                    });
                });
            }


            const allRadioButtons = surveyForm.querySelectorAll('input[type="radio"]');
            allRadioButtons.forEach(radio => {
                radio.addEventListener('change', function() {
                    if (currentStep < totalSteps) {
                        setTimeout(() => {
                            if (nextBtn.style.display !== 'none') {
                                nextBtn.click();
                            }
                        }, 350); 
                    }
                });
            });

            updateStepDisplay();
            updateProgress();

            prevBtn.addEventListener('click', function() {
                if (currentStep > 1) {
                    currentStep--;
                    updateStepDisplay();
                    updateProgress();
                }
            });

            nextBtn.addEventListener('click', function() {
                if (validateCurrentStep()) {
                    if (currentStep < totalSteps) {
                        currentStep++;
                        updateStepDisplay();
                        updateProgress();
                    }
                } else {
                    alert('답변을 선택해주세요.');
                }
            });

            submitBtn.addEventListener('click', function(e) {
                e.preventDefault(); // 기본 폼 제출(새로고침)을 막습니다.
                if (!validateCurrentStep()) {
                    alert('마지막 질문에 답변하거나 사진을 추가해주세요.');
                    return;
                }

                submitBtn.textContent = '분석 중...';
                submitBtn.disabled = true;

                const formData = new FormData(surveyForm);
                
                // fetch API를 사용하여 비동기적으로 데이터 전송
                fetch('get_ai_recommendation.php', { // 결과를 처리할 새로운 PHP 파일을 호출합니다.
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    if (!response.ok) {
                        throw new Error('네트워크 응답이 올바르지 않습니다.');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success && data.recommendation) {
                        // 성공적으로 결과를 받으면 오른쪽 섹션을 업데이트합니다.
                        const rightSection = document.querySelector('.right-section');
                        rightSection.innerHTML = `
                            <div class="recommendations-container">
                                <h3>🎉 맞춤 취미 추천 결과</h3>
                                <div class="ai-recommendation-box" style="margin-top: 20px;">
                                    ${data.recommendation.replace(/\n/g, '<br>')}
                                </div>
                                <div class="survey-actions">
                                    <a href="hobby_recommendation.php" class="btn-secondary">다시 추천받기</a>
                                </div>
                            </div>`;
                    } else {
                        alert('추천을 생성하는 데 실패했습니다: ' + (data.message || '알 수 없는 오류'));
                    }
                })
                .catch(error => {
                    console.error('Fetch Error:', error);
                    alert('추천 결과를 가져오는 중 오류가 발생했습니다. 잠시 후 다시 시도해주세요.');
                })
                .finally(() => {
                    // 버튼 상태 복원
                    submitBtn.textContent = '취미 추천받기';
                    submitBtn.disabled = false;
                });
            });

            function updateStepDisplay() {
                questionSteps.forEach(step => step.classList.remove('active'));
                const currentQuestionStep = document.querySelector(`.question-step[data-step="${currentStep}"]`);
                if (currentQuestionStep) currentQuestionStep.classList.add('active');

                // ### 변경된 부분: 3단계 헤더 표시 로직 ###
                stage1Header.style.display = 'none';
                stage2Header.style.display = 'none';
                stage3Header.style.display = 'none';

                if (currentStep >= 1 && currentStep <= 12) {
                    stage1Header.style.display = 'block';
                } else if (currentStep >= 13 && currentStep <= 30) {
                    stage2Header.style.display = 'block';
                } else if (currentStep >= 31 && currentStep <= 48) {
                    stage3Header.style.display = 'block';
                }

                prevBtn.style.display = currentStep > 1 ? 'inline-block' : 'none';
                
                if (currentStep === totalSteps) {
                    nextBtn.style.display = 'none';
                    submitBtn.style.display = 'inline-block';
                } else {
                    nextBtn.style.display = 'inline-block';
                    submitBtn.style.display = 'none';
                }
            }

            function updateProgress() {
                const progress = (currentStep / totalSteps) * 100;
                if (progressFill) progressFill.style.width = progress + '%';
                if (progressText) progressText.textContent = `${currentStep} / ${totalSteps}`;
            }

            function validateCurrentStep() {
                const currentQuestionStep = document.querySelector(`.question-step[data-step="${currentStep}"]`);
                if (!currentQuestionStep) return false;

                // 사진 업로드 단계는 유효성 검사 통과
                if (currentStep === totalSteps) {
                    return true;
                }

                // ### 추가된 부분: 체크박스 유효성 검사 ###
                const checkboxInputs = currentQuestionStep.querySelectorAll('input[type="checkbox"]');
                if (checkboxInputs.length > 0) {
                    const checkedCheckbox = currentQuestionStep.querySelector('input[type="checkbox"]:checked');
                    // 체크박스는 하나도 선택 안 해도 넘어갈 수 있도록 true를 반환합니다. (필수가 아님)
                    // 만약 필수로 만들고 싶다면 return checkedCheckbox !== null; 로 변경하세요.
                    // Q10은 하나 이상 선택해야 하므로, 아래와 같이 수정
                    return checkedCheckbox !== null;
                }

                const radioInput = currentQuestionStep.querySelector('input[type="radio"]');
                if (!radioInput) return false;

                const radioName = radioInput.name;
                const checkedRadio = currentQuestionStep.querySelector(`input[name="${radioName}"]:checked`);
                return checkedRadio !== null;
            }
        }

        function loadMeetups(hobbyId) {
            window.location.href = `hobby_list.php?hobby_id=${hobbyId}`; // hobby_recommendation.php -> hobby_list.php or your target page
        }
    </script>
</body>
</html>