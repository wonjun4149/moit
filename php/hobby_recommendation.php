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
        $survey_data = $_POST; // POST 데이터를 그대로 사용
        unset($survey_data['submit_survey']); // 불필요한 데이터 제거
        if(isset($survey_data['debug'])) unset($survey_data['debug']);

        // 2. AI 에이전트에 보낼 데이터 구조 생성
        $request_payload = [
            'user_input' => [
                'survey' => $survey_data,
                'user_context' => [
                    'user_id' => $_SESSION['user_id']
                ]
            ]
        ];
        debug_output("AI 서버 요청 데이터", $request_payload);

        // 3. cURL을 사용해 AI 에이전트 API 호출
        $ch = curl_init('http://127.0.0.1:8000/agent/invoke');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($request_payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen(json_encode($request_payload))
        ]);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5); // 연결 타임아웃 5초
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);      // 전체 실행 타임아웃 30초

        $response_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch) || $http_code !== 200) {
            $curl_error = curl_error($ch);
            debug_output("cURL Error", ['message' => $curl_error, 'http_code' => $http_code, 'body' => $response_body]);
            throw new Exception("AI 추천 서버와의 통신에 실패했습니다. (HTTP: {$http_code})");
        }
        curl_close($ch);

        debug_output("AI 서버 응답", json_decode($response_body, true));

        // 4. AI 추천 결과 파싱 및 변환
        $response_data = json_decode($response_body, true);
        if (isset($response_data['final_answer'])) {
            // AI의 답변이 hobby_recommendation_api/app.py에서 온 JSON 형식이라고 가정
            // main.py에서 받은 텍스트 답변을 다시 JSON으로 파싱 시도
            $json_part = substr($response_data['final_answer'], strpos($response_data['final_answer'], '['));
            if ($json_part) {
                $parsed_recos = json_decode($json_part, true);
                if (is_array($parsed_recos)) {
                    $recommendations = array_map(function($reco) {
                        return [
                            'name' => $reco['name_ko'] ?? '이름 없음',
                            'description' => $reco['short_desc'] ?? '설명 없음',
                            'score' => $reco['score_total'] ?? 0.5,
                            'id' => $reco['hobby_id'] ?? 0,
                            'reason' => $reco['reason'] ?? '' // 추천 이유(reason) 필드 추가
                        ];
                    }, $parsed_recos);
                }
            }
        }

        if (empty($recommendations)) {
             $error_message = "AI가 추천을 생성하지 못했거나, 응답을 처리하는 데 실패했습니다.";
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
    <!-- 디버그 정보 표시 -->
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

    <!-- 메인 컨테이너 -->
    <main class="main-container">
        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="content-wrapper">
            <!-- 왼쪽: 설문조사 또는 추천 결과 -->
            <div class="left-section">
                <?php if (empty($recommendations)): ?>
                    <!-- 설문조사 폼 -->
                    <div class="survey-container">
                        <div class="survey-progress">
                            <div class="progress-bar">
                                <div class="progress-fill" id="progressFill"></div>
                            </div>
                            <span class="progress-text" id="progressText">1 / 15</span>
                        </div>

                        <h2>당신의 취향을 알려주세요</h2>
                        <p class="survey-subtitle">15개 질문으로 딱 맞는 취미를 찾아드릴게요!</p>

                        <form method="POST" class="survey-form" id="surveyForm">
                            <?php if ($debug_mode): ?>
                                <input type="hidden" name="debug" value="1">
                            <?php endif; ?>

                            <?php
                                $part1_questions = [
                                    ['name' => 'age_group', 'label' => '1. 연령대를 선택해 주세요.', 'options' => ['10대', '20대', '30대', '40대', '50대 이상']],
                                    ['name' => 'gender', 'label' => '2. 성별을 선택해 주세요.', 'options' => ['남성', '여성', '선택 안 함']],
                                    ['name' => 'occupation', 'label' => '3. 현재 어떤 일을 하고 계신가요?', 'options' => ['학생', '직장인', '프리랜서', '주부', '구직자', '기타']],
                                    ['name' => 'weekly_time', 'label' => '4. 일주일에 온전히 나를 위해 사용할 수 있는 시간은 어느 정도인가요?', 'options' => ['3시간 미만', '3~5시간', '5~10시간', '10시간 이상']],
                                    ['name' => 'monthly_budget', 'label' => '5. 한 달에 취미 활동을 위해 얼마까지 지출할 수 있나요?', 'options' => ['5만원 미만', '5~10만원', '10~20만원', '20만원 이상']],
                                ];
                                ?>

                                <?php
                                                                $part2_questions = [
                                    ['name' => 'Q6', 'label' => '6. 새로운 사람들과 어울리기보다, 혼자 또는 가까운 친구와 깊이 있는 시간을 보내는 것을 선호합니다.'],
                                    ['name' => 'Q7', 'label' => '7. 반복적인 일상에 안정감을 느끼기보다, 예측 불가능한 새로운 경험을 통해 영감을 얻는 편입니다.'],
                                    ['name' => 'Q8', 'label' => '8. 즉흥적으로 행동하기보다, 명확한 목표를 세우고 계획에 따라 꾸준히 실행하는 것에서 성취감을 느낍니다.'],
                                    ['name' => 'Q9', 'label' => '9. 정해진 규칙을 따르기보다, 나만의 방식과 스타일을 더해 독창적인 결과물을 만드는 것을 즐깁니다.'],
                                    ['name' => 'Q10', 'label' => '10. 과정 자체를 즐기는 것도 좋지만, 꾸준한 연습을 통해 실력이 향상되는 것을 눈으로 확인할 때 가장 큰 보람을 느낍니다.'],
                                    ['name' => 'Q11', 'label' => '11. 하루의 스트레스를 조용히 생각하며 풀기보다, 몸을 움직여 땀을 흘리며 해소하는 것을 선호합니다.'],
                                    ['name' => 'Q12', 'label' => '12. 취미 활동을 통해 새로운 수익을 창출하거나, SNS에서 영향력을 키우는 것에 관심이 많습니다.'],
                                    ['name' => 'Q13', 'label' => '13. 오프라인에서 직접 만나 교류하는 것만큼, 온라인 커뮤니티에서 소통하는 것에서도 강한 소속감을 느낍니다.'],
                                    ['name' => 'Q14', 'label' => '14. 하나의 취미를 깊게 파고드는 전문가가 되기보다, 다양한 분야를 경험해보는 제너럴리스트가 되고 싶습니다.'],
                                    ['name' => 'Q15', 'label' => '15. 이 취미를 통해 \'무엇을 얻을 수 있는가\'보다 \'그 순간이 얼마나 즐거운가\'가 더 중요합니다.'],
                                ];


                                $all_questions = array_merge(
                                    array_map(fn($q) => array_merge($q, ['type' => 'radio']), $part1_questions),
                                    array_map(fn($q) => array_merge($q, ['type' => 'likert']), $part2_questions)
                                );
                            ?>

                            <!-- Part 1 Header -->
                            <div id="part1-header" class="survey-part-header" style="display: none;">
                                <h3>Part 1. 기본 정보 설정하기</h3>
                                <p class="part-subtitle">추천의 정확도를 높이기 위한 기본적인 정보예요.</p>
                            </div>
                            <!-- Part 2 Header -->
                            <div id="part2-header" class="survey-part-header" style="display: none;">
                                <h3>Part 2. 당신의 스타일 알아보기</h3>
                                <p class="part-subtitle">정답은 없으니, 가장 가깝다고 생각하는 곳에 편하게 체크해 주세요.</p>
                            </div>

                            <?php foreach ($all_questions as $index => $q): ?>
                                <div class="question-step <?php echo $index === 0 ? 'active' : ''; ?>" data-step="<?php echo $index + 1; ?>">
                                    <?php if ($q['type'] === 'radio'): ?>
                                        <div class="question-group">
                                            <label class="question-label"><?php echo $q['label']; ?></label>
                                            <div class="option-group-inline">
                                                <?php foreach ($q['options'] as $opt): ?>
                                                <label class="option-label-inline">
                                                    <input type="radio" name="<?php echo $q['name']; ?>" value="<?php echo $opt; ?>" required>
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
                                                        <span class="likert-radio-button"><?php echo $i; ?></span>
                                                    </label>
                                                    <?php endfor; ?>
                                                </div>
                                                <div class="likert-labels">
                                                    <span>전혀 그렇지 않다</span>
                                                    <span>매우 그렇다</span>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>

                            <!-- 버튼 영역 -->
                            <div class="survey-buttons">
                                <button type="button" class="btn-prev" id="prevBtn" style="display: none;">이전</button>
                                <button type="button" class="btn-next" id="nextBtn">다음</button>
                                <button type="submit" name="submit_survey" class="submit-btn" id="submitBtn" style="display: none;">취미 추천받기</button>
                            </div>
                        </form>
                    </div>
                <?php else: ?>
                    <!-- 추천 결과 -->
                    <div class="recommendations-container">
                        <h2>🎉 맞춤 취미 추천</h2>
                        <p class="recommendations-subtitle">설문 결과를 바탕으로 <?php echo count($recommendations); ?>개의 취미를 추천해드려요!</p>
                        
                        <div class="hobby-cards">
                            <?php foreach ($recommendations as $hobby): ?>
                                <div class="hobby-card" onclick="loadMeetups(<?php echo $hobby['id']; ?>)">
                                    <div class="hobby-card-header">
                                        <h3 class="hobby-name"><?php echo htmlspecialchars($hobby['name']); ?></h3>
                                        
                                    </div>
                                    <p class="hobby-description"><?php echo htmlspecialchars($hobby['description']); ?></p>
                                    <div class="hobby-tags">
                                        <?php 
                                            // 추천 이유(reason)를 분리하여 태그로 표시합니다.
                                            $reasons = explode(' · ', $hobby['reason']);
                                            foreach (array_filter($reasons) as $reason_tag): 
                                        ?>
                                            <span class="tag"><?php echo htmlspecialchars($reason_tag); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="hobby-score">
                                        <span>추천도: <?php echo round($hobby['score']); ?>%</span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="survey-actions">
                            <a href="hobby_recommendation.php" class="btn-secondary">다시 설문하기</a>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- 오른쪽: 인기 취미 -->
            <div class="right-section">
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
            </div>
        </div>
    </main>

    <script src="/js/navbar.js"></script>
    <script>
        const surveyForm = document.getElementById('surveyForm');
        if (surveyForm) {
            let currentStep = 1;
            const totalSteps = 15;

            const questionSteps = document.querySelectorAll('.question-step');
            const prevBtn = document.getElementById('prevBtn');
            const nextBtn = document.getElementById('nextBtn');
            const submitBtn = document.getElementById('submitBtn');
            const progressFill = document.getElementById('progressFill');
            const progressText = document.getElementById('progressText');

            const part1Header = document.getElementById('part1-header');
            const part2Header = document.getElementById('part2-header');

            // --- 자동 다음 질문으로 넘기기 기능 추가 ---
            const allRadioButtons = surveyForm.querySelectorAll('input[type="radio"]');
            allRadioButtons.forEach(radio => {
                radio.addEventListener('change', function() {
                    // 마지막 질문이 아닐 경우에만 자동 진행
                    if (currentStep < totalSteps) {
                        // 사용자가 선택을 인지할 수 있도록 약간의 딜레이 후 다음으로 이동
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
                e.preventDefault();
                if (validateCurrentStep()) {
                    submitBtn.textContent = '분석 중...';
                    submitBtn.disabled = true;

                    // submit() 함수가 버튼의 name을 포함하지 않으므로, hidden input을 추가해줍니다.
                    const hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'submit_survey';
                    hiddenInput.value = 'true';
                    surveyForm.appendChild(hiddenInput);

                    surveyForm.submit();
                } else {
                    alert('마지막 질문에 답변해주세요.');
                }
            });

            function updateStepDisplay() {
                questionSteps.forEach(step => step.classList.remove('active'));
                const currentQuestionStep = document.querySelector(`.question-step[data-step="${currentStep}"]`);
                if (currentQuestionStep) currentQuestionStep.classList.add('active');

                // 파트 헤더 표시 로직
                if (currentStep >= 1 && currentStep <= 5) {
                    part1Header.style.display = 'block';
                    part2Header.style.display = 'none';
                } else if (currentStep >= 6) {
                    part1Header.style.display = 'none';
                    part2Header.style.display = 'block';
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

                const radioInput = currentQuestionStep.querySelector('input[type="radio"]');
                if (!radioInput) return false;

                const radioName = radioInput.name;
                const checkedRadio = currentQuestionStep.querySelector(`input[name="${radioName}"]:checked`);
                return checkedRadio !== null;
            }
        }


        function loadMeetups(hobbyId) {
            window.location.href = `hobby_recommendation.php?hobby_id=${hobbyId}`;
        }
    </script>
</body>
</html>