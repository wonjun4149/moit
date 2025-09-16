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

// 설문 제출 처리 - 개선된 추천 알고리즘
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_survey'])) {
    debug_output("=== 설문 제출 처리 시작 ===");
    
    try {
        // Part 1
        $age_group = $_POST['age_group'] ?? '';
        $gender = $_POST['gender'] ?? '';
        $occupation = $_POST['occupation'] ?? '';
        $weekly_time = $_POST['weekly_time'] ?? '';
        $monthly_budget = $_POST['monthly_budget'] ?? '';

        // Part 2
        $q6 = $_POST['q6_introversion'] ?? 0;
        $q7 = $_POST['q7_openness'] ?? 0;
        $q8 = $_POST['q8_planning'] ?? 0;
        $q9 = $_POST['q9_creativity'] ?? 0;
        $q10 = $_POST['q10_skill_oriented'] ?? 0;
        $q11 = $_POST['q11_active_stress_relief'] ?? 0;
        $q12 = $_POST['q12_monetization'] ?? 0;
        $q13 = $_POST['q13_online_community'] ?? 0;
        $q14 = $_POST['q14_generalist'] ?? 0;
        $q15 = $_POST['q15_process_oriented'] ?? 0;

        $part1_data = compact('age_group', 'gender', 'occupation', 'weekly_time', 'monthly_budget');
        $part2_data = compact('q6', 'q7', 'q8', 'q9', 'q10', 'q11', 'q12', 'q13', 'q14', 'q15');
        
        debug_output("설문 답변 (Part 1)", $part1_data);
        debug_output("설문 답변 (Part 2)", $part2_data);

        // 필수 값 확인
        $required_fields = array_merge($part1_data, $part2_data);
        if (in_array('', $required_fields, true) || in_array(0, $part2_data, true)) {
            debug_output("일부 답변 누락");
            $error_message = '모든 질문에 답변해주세요.';
        } else {
            debug_output("모든 답변 완료 - 데이터베이스 저장 시작");
            
            // 설문 응답 저장
            $sql = "INSERT INTO hobby_surveys (user_id, age_group, gender, occupation, weekly_time, monthly_budget, 
                        q6_introversion, q7_openness, q8_planning, q9_creativity, q10_skill_oriented, 
                        q11_active_stress_relief, q12_monetization, q13_online_community, q14_generalist, q15_process_oriented)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            
            $params_db = array_merge([$_SESSION['user_id']], array_values($part1_data), array_values($part2_data));
            $result = $stmt->execute($params_db);
            $survey_id = $pdo->lastInsertId();
            
            debug_output("설문 저장 결과", "성공: " . ($result ? 'YES' : 'NO') . ", ID: $survey_id");
            
            if (!$result) {
                throw new Exception("설문 저장에 실패했습니다.");
            }
            
            // MVP 추천 알고리즘
            debug_output("=== MVP 추천 알고리즘 시작 ===");
            
            $query = "
                SELECT *, 
                (
                    -- 1. 활동성 (q11) vs physical_level (가중치: 0.3)
                    (CASE
                        WHEN physical_level = '높음' THEN ? -- q11
                        WHEN physical_level = '보통' THEN 3
                        WHEN physical_level = '낮음' THEN 6 - ? -- q11
                    END) / 5 * 0.3 +

                    -- 2. 그룹 크기 (q6) vs group_size (가중치: 0.25)
                    (CASE
                        WHEN group_size = '개인' THEN ? -- q6
                        WHEN group_size = '소그룹' THEN ? -- q6
                        WHEN group_size = '대그룹' THEN 6 - ? -- q6
                        ELSE 3
                    END) / 5 * 0.25 +

                    -- 3. 비용 (monthly_budget) vs cost_level (가중치: 0.15)
                    CASE 
                        WHEN ? = '5만원 미만' AND cost_level IN ('무료', '저비용') THEN 0.15
                        WHEN ? = '5~10만원' AND cost_level IN ('저비용', '중비용') THEN 0.15
                        WHEN ? = '10~20만원' AND cost_level IN ('중비용', '고비용') THEN 0.15
                        WHEN ? = '20만원 이상' AND cost_level = '고비용' THEN 0.15
                        ELSE 0.05
                    END +

                    -- 4. 실력 향상 동기 (q10) vs difficulty_level (가중치: 0.15)
                    (CASE
                        WHEN difficulty_level = '고급' THEN ? -- q10
                        WHEN difficulty_level = '중급' THEN 3
                        WHEN difficulty_level = '초급' THEN 6 - ? -- q10
                    END) / 5 * 0.15 +

                    -- 5. 독창성 (q9) vs category (가중치: 0.15)
                    (CASE
                        WHEN category IN ('예술', '생활', '취미') THEN ? -- q9
                        WHEN category IN ('운동', '학습') THEN 6 - ? -- q9
                        ELSE 3
                    END) / 5 * 0.15
                ) as score
                FROM hobbies 
                HAVING score > 0
                ORDER BY score DESC, name ASC
                LIMIT 6
            ";

            $params = [
                $q11, $q11, // 활동성
                $q6, $q6, $q6, // 그룹 크기
                $monthly_budget, $monthly_budget, $monthly_budget, $monthly_budget, // 비용
                $q10, $q10, // 실력 향상
                $q9, $q9 // 독창성
            ];
            
            debug_output("개선된 쿼리", $query);
            debug_output("파라미터", $params);
            
            $stmt = $pdo->prepare($query);
            $stmt->execute($params);
            $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            debug_output("=== 추천 결과 ===");
            debug_output("추천 개수", count($recommendations));
            
            if (count($recommendations) > 0) {
                debug_output("추천 취미 목록", array_column($recommendations, 'name'));
                debug_output("추천 점수들", array_column($recommendations, 'score'));
                
                // 각 추천 취미의 상세 점수 분석
                foreach ($recommendations as $i => $hobby) {
                    debug_output("취미 #{$i}: {$hobby['name']}", [
                        'score' => $hobby['score'],
                        'activity_type' => $hobby['activity_type'],
                        'physical_level' => $hobby['physical_level'], 
                        'group_size' => $hobby['group_size'],
                        'cost_level' => $hobby['cost_level']
                    ]);
                }
                
                // 추천 기록 저장
                foreach ($recommendations as $hobby) {
                    $stmt = $pdo->prepare("
                        INSERT INTO hobby_recommendations (user_id, hobby_id, survey_id, recommendation_score) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$_SESSION['user_id'], $hobby['id'], $survey_id, $hobby['score']]);
                }
                debug_output("추천 기록 저장 완료");
            } else {
                debug_output("여전히 추천 결과 없음");
                
                // 최후의 수단: 점수 없이 모든 취미 가져오기
                $stmt = $pdo->query("SELECT * FROM hobbies ORDER BY name LIMIT 3");
                $fallback_hobbies = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($fallback_hobbies) > 0) {
                    debug_output("대체 추천 사용", count($fallback_hobbies) . "개");
                    $recommendations = $fallback_hobbies;
                    
                    // 기본 점수 부여
                    foreach ($recommendations as &$hobby) {
                        $hobby['score'] = 0.5; // 기본 점수
                    }
                    
                    // 대체 추천도 기록 저장
                    foreach ($recommendations as $hobby) {
                        $stmt = $pdo->prepare("
                            INSERT INTO hobby_recommendations (user_id, hobby_id, survey_id, recommendation_score) 
                            VALUES (?, ?, ?, ?)
                        ");
                        $stmt->execute([$_SESSION['user_id'], $hobby['id'], $survey_id, 0.5]);
                    }
                }
            }
        }
        
    } catch (Exception $e) {
        debug_output("예외 발생", $e->getMessage());
        debug_output("스택 트레이스", $e->getTraceAsString());
        $error_message = '설문 처리 중 오류가 발생했습니다: ' . $e->getMessage();
    }
    
    debug_output("=== 설문 처리 완료 ===");
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

                                <?php foreach ($part1_questions as $q): ?>
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
                                <?php endforeach; ?>
                            </div>

                            <!-- Part 2: 스타일 -->
                            <div class="survey-part">
                                <h3>Part 2. 당신의 스타일 알아보기</h3>
                                <p class="part-subtitle">정답은 없으니, 가장 가깝다고 생각하는 곳에 편하게 체크해 주세요.</p>

                                <?php
                                $part2_questions = [
                                    ['name' => 'q6_introversion', 'label' => '6. 새로운 사람들과 어울리기보다, 혼자 또는 가까운 친구와 깊이 있는 시간을 보내는 것을 선호합니다.'],
                                    ['name' => 'q7_openness', 'label' => '7. 반복적인 일상에 안정감을 느끼기보다, 예측 불가능한 새로운 경험을 통해 영감을 얻는 편입니다.'],
                                    ['name' => 'q8_planning', 'label' => '8. 즉흥적으로 행동하기보다, 명확한 목표를 세우고 계획에 따라 꾸준히 실행하는 것에서 성취감을 느낍니다.'],
                                    ['name' => 'q9_creativity', 'label' => '9. 정해진 규칙을 따르기보다, 나만의 방식과 스타일을 더해 독창적인 결과물을 만드는 것을 즐깁니다.'],
                                    ['name' => 'q10_skill_oriented', 'label' => '10. 과정 자체를 즐기는 것도 좋지만, 꾸준한 연습을 통해 실력이 향상되는 것을 눈으로 확인할 때 가장 큰 보람을 느낍니다.'],
                                    ['name' => 'q11_active_stress_relief', 'label' => '11. 하루의 스트레스를 조용히 생각하며 풀기보다, 몸을 움직여 땀을 흘리며 해소하는 것을 선호합니다.'],
                                    ['name' => 'q12_monetization', 'label' => '12. 취미 활동을 통해 새로운 수익을 창출하거나, SNS에서 영향력을 키우는 것에 관심이 많습니다.'],
                                    ['name' => 'q13_online_community', 'label' => '13. 오프라인에서 직접 만나 교류하는 것만큼, 온라인 커뮤니티에서 소통하는 것에서도 강한 소속감을 느낍니다.'],
                                    ['name' => 'q14_generalist', 'label' => '14. 하나의 취미를 깊게 파고드는 전문가가 되기보다, 다양한 분야를 경험해보는 제너럴리스트가 되고 싶습니다.'],
                                    ['name' => 'q15_process_oriented', 'label' => '15. 이 취미를 통해 \'무엇을 얻을 수 있는가\'보다 \'그 순간이 얼마나 즐거운가\'가 더 중요합니다.'],
                                ];

                                $all_questions = array_merge(
                                    array_map(fn($q) => array_merge($q, ['type' => 'radio']), $part1_questions),
                                    array_map(fn($q) => array_merge($q, ['type' => 'likert']), $part2_questions)
                                );
                            ?>

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
                                                <span class="likert-label-left">전혀 그렇지 않다</span>
                                                <div class="likert-options">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                    <label class="likert-option">
                                                        <input type="radio" name="<?php echo $q['name']; ?>" value="<?php echo $i; ?>" required>
                                                        <span class="likert-radio-button"></span>
                                                        <span class="likert-number"><?php echo $i; ?></span>
                                                    </label>
                                                    <?php endfor; ?>
                                                </div>
                                                <span class="likert-label-right">매우 그렇다</span>
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
                                        <span class="hobby-category"><?php echo htmlspecialchars($hobby['category']); ?></span>
                                    </div>
                                    <p class="hobby-description"><?php echo htmlspecialchars($hobby['description']); ?></p>
                                    <div class="hobby-tags">
                                        <span class="tag"><?php echo $hobby['difficulty_level']; ?></span>
                                        <span class="tag"><?php echo $hobby['activity_type']; ?></span>
                                        <span class="tag"><?php echo $hobby['physical_level']; ?> 체력</span>
                                        <span class="tag"><?php echo $hobby['cost_level']; ?></span>
                                    </div>
                                    <div class="hobby-score">
                                        <span>추천도: <?php echo round($hobby['score'] * 100); ?>%</span>
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
                    surveyForm.submit();
                } else {
                    alert('마지막 질문에 답변해주세요.');
                }
            });

            function updateStepDisplay() {
                questionSteps.forEach(step => step.classList.remove('active'));
                const currentQuestionStep = document.querySelector(`.question-step[data-step="${currentStep}"]`);
                if (currentQuestionStep) currentQuestionStep.classList.add('active');

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