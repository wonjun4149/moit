<?php
// 개선된 취미 추천 페이지 - 점수 기반 추천 시스템
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
if ($_SERVER['REQUEST_METHOD'] == 'POST' && (isset($_POST['submit_survey']) || isset($_POST['survey_submitted']))) {
    debug_output("=== 설문 제출 처리 시작 ===");
    
    try {
        $activity_preference = $_POST['activity_preference'] ?? '';
        $physical_preference = $_POST['physical_preference'] ?? '';
        $group_preference = $_POST['group_preference'] ?? '';
        $cost_preference = $_POST['cost_preference'] ?? '';
        $time_preference = $_POST['time_preference'] ?? '';
        
        debug_output("설문 답변들", [
            'activity_preference' => $activity_preference,
            'physical_preference' => $physical_preference,
            'group_preference' => $group_preference,
            'cost_preference' => $cost_preference,
            'time_preference' => $time_preference
        ]);
        
        // 모든 값이 입력되었는지 확인
        if (empty($activity_preference) || empty($physical_preference) || empty($group_preference) || 
            empty($cost_preference) || empty($time_preference)) {
            debug_output("일부 답변 누락");
            $error_message = '모든 질문에 답변해주세요.';
        } else {
            debug_output("모든 답변 완료 - 데이터베이스 저장 시작");
            
            // 설문 응답 저장
            $stmt = $pdo->prepare("
                INSERT INTO hobby_surveys (user_id, activity_preference, physical_preference, group_preference, cost_preference, time_preference) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([$_SESSION['user_id'], $activity_preference, $physical_preference, $group_preference, $cost_preference, $time_preference]);
            $survey_id = $pdo->lastInsertId();
            
            debug_output("설문 저장 결과", "성공: " . ($result ? 'YES' : 'NO') . ", ID: $survey_id");
            
            if (!$result) {
                throw new Exception("설문 저장에 실패했습니다.");
            }
            
            // 개선된 점수 기반 추천 알고리즘
            debug_output("=== 개선된 추천 알고리즘 시작 ===");
            
            // 점수 기반 쿼리 (모든 취미에 대해 점수 계산)
            $query = "
                SELECT *, 
                (
                    -- 활동 장소 점수 (30%)
                    CASE 
                        WHEN ? = '상관없음' THEN 0.3
                        WHEN activity_type = ? THEN 0.3
                        WHEN activity_type = '혼합' THEN 0.2
                        ELSE 0
                    END +
                    
                    -- 체력 요구도 점수 (30%) 
                    CASE 
                        WHEN ? = '상관없음' THEN 0.3
                        WHEN physical_level = ? THEN 0.3
                        WHEN (? = '높음' AND physical_level = '보통') THEN 0.15
                        WHEN (? = '보통' AND physical_level IN ('높음', '낮음')) THEN 0.15
                        WHEN (? = '낮음' AND physical_level = '보통') THEN 0.15
                        ELSE 0
                    END +
                    
                    -- 그룹 규모 점수 (20%)
                    CASE 
                        WHEN ? = '상관없음' THEN 0.2
                        WHEN group_size = ? THEN 0.2
                        WHEN group_size = '상관없음' THEN 0.15
                        ELSE 0
                    END +
                    
                    -- 비용 점수 (20%)
                    CASE 
                        WHEN ? = '상관없음' THEN 0.2
                        WHEN cost_level = ? THEN 0.2
                        WHEN (? = '무료' AND cost_level = '저비용') THEN 0.1
                        WHEN (? = '저비용' AND cost_level IN ('무료', '중비용')) THEN 0.1
                        WHEN (? = '중비용' AND cost_level IN ('저비용', '고비용')) THEN 0.1
                        WHEN (? = '고비용' AND cost_level = '중비용') THEN 0.1
                        ELSE 0
                    END
                ) as score
                FROM hobbies 
                HAVING score > 0
                ORDER BY score DESC, name ASC
                LIMIT 6
            ";
            
            // 파라미터 준비 (각 조건마다 필요한 만큼 반복)
            $params = [
                // 활동 장소 (2개)
                $activity_preference, $activity_preference,
                // 체력 요구도 (5개)  
                $physical_preference, $physical_preference, $physical_preference, $physical_preference, $physical_preference,
                // 그룹 규모 (2개)
                $group_preference, $group_preference,
                // 비용 (6개)
                $cost_preference, $cost_preference, $cost_preference, $cost_preference, $cost_preference, $cost_preference
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
                <li>설문 제출: <?php echo (isset($_POST['submit_survey']) || isset($_POST['survey_submitted'])) ? '✅' : '❌'; ?></li>
                <li>추천 결과: <?php echo count($recommendations); ?>개</li>
                <li>에러: <?php echo $error_message ?: '없음'; ?></li>
            </ul>
        </div>
    <?php endif; ?>

    <!-- 상단 네비게이션 -->
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-left">
                <div class="hamburger">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                <div class="logo"><a href="../index.php">MOIT</a></div>
                <ul class="nav-menu">
                    <li><a href="introduction.php">소개</a></li>
                    <li><a href="hobby_recommendation.php" class="active">취미 추천</a></li>
                    <li><a href="meeting.php">모임</a></li>
                </ul>
            </div>
            <div class="nav-right">
                <span class="welcome-msg">환영합니다, <?php echo htmlspecialchars($_SESSION['user_nickname']); ?>님!</span>
                <a href="mypage.php" class="nav-btn">마이페이지</a> <a href="logout.php" class="nav-btn logout-btn">로그아웃</a>
                <button class="profile-btn"></button>
            </div>
        </div>
    </nav>

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
                            <span class="progress-text" id="progressText">1 / 5</span>
                        </div>

                        <h2>좋아하는 것을 알려주세요.</h2>
                        <p class="survey-subtitle">몇 가지 질문으로 맞춤 취미를 추천해드릴게요!</p>
                        
                        <form method="POST" class="survey-form" id="surveyForm">
                            <!-- 히든 필드 추가 -->
                            <input type="hidden" name="survey_submitted" value="1">
                            
                            <!-- 디버그 모드일 때 히든 필드 추가 -->
                            <?php if ($debug_mode): ?>
                                <input type="hidden" name="debug" value="1">
                            <?php endif; ?>
                            
                            <!-- 질문들 (동일) -->
                            <div class="question-step active" data-step="1">
                                <div class="question-group">
                                    <label class="question-label">활동적인 취미를 선호하시나요?</label>
                                    <div class="option-group">
                                        <label class="option-label">
                                            <input type="radio" name="physical_preference" value="높음" required>
                                            <span class="option-text">네, 활동적인 취미를 좋아해요</span>
                                        </label>
                                        <label class="option-label">
                                            <input type="radio" name="physical_preference" value="낮음" required>
                                            <span class="option-text">아니요, 조용한 취미를 선호해요</span>
                                        </label>
                                        <label class="option-label">
                                            <input type="radio" name="physical_preference" value="보통" required>
                                            <span class="option-text">둘 다 상관없어요</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="question-step" data-step="2">
                                <div class="question-group">
                                    <label class="question-label">어디서 활동하는 것을 선호하시나요?</label>
                                    <div class="option-group">
                                        <label class="option-label">
                                            <input type="radio" name="activity_preference" value="실외" required>
                                            <span class="option-text">실외 활동을 좋아해요</span>
                                        </label>
                                        <label class="option-label">
                                            <input type="radio" name="activity_preference" value="실내" required>
                                            <span class="option-text">실내 활동을 선호해요</span>
                                        </label>
                                        <label class="option-label">
                                            <input type="radio" name="activity_preference" value="상관없음" required>
                                            <span class="option-text">장소는 상관없어요</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="question-step" data-step="3">
                                <div class="question-group">
                                    <label class="question-label">몇 명과 함께 하고 싶으세요?</label>
                                    <div class="option-group">
                                        <label class="option-label">
                                            <input type="radio" name="group_preference" value="개인" required>
                                            <span class="option-text">혼자서 하고 싶어요</span>
                                        </label>
                                        <label class="option-label">
                                            <input type="radio" name="group_preference" value="소그룹" required>
                                            <span class="option-text">소수의 사람들과 함께</span>
                                        </label>
                                        <label class="option-label">
                                            <input type="radio" name="group_preference" value="대그룹" required>
                                            <span class="option-text">많은 사람들과 함께</span>
                                        </label>
                                        <label class="option-label">
                                            <input type="radio" name="group_preference" value="상관없음" required>
                                            <span class="option-text">인원은 상관없어요</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="question-step" data-step="4">
                                <div class="question-group">
                                    <label class="question-label">비용은 어느 정도까지 괜찮으세요?</label>
                                    <div class="option-group">
                                        <label class="option-label">
                                            <input type="radio" name="cost_preference" value="무료" required>
                                            <span class="option-text">무료로 할 수 있는 것</span>
                                        </label>
                                        <label class="option-label">
                                            <input type="radio" name="cost_preference" value="저비용" required>
                                            <span class="option-text">조금의 비용은 괜찮아요</span>
                                        </label>
                                        <label class="option-label">
                                            <input type="radio" name="cost_preference" value="중비용" required>
                                            <span class="option-text">적당한 비용은 지불할 수 있어요</span>
                                        </label>
                                        <label class="option-label">
                                            <input type="radio" name="cost_preference" value="고비용" required>
                                            <span class="option-text">비용은 상관없어요</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

                            <div class="question-step" data-step="5">
                                <div class="question-group">
                                    <label class="question-label">언제 활동하고 싶으세요?</label>
                                    <div class="option-group">
                                        <label class="option-label">
                                            <input type="radio" name="time_preference" value="주중" required>
                                            <span class="option-text">평일에 주로 활동</span>
                                        </label>
                                        <label class="option-label">
                                            <input type="radio" name="time_preference" value="주말" required>
                                            <span class="option-text">주말에 주로 활동</span>
                                        </label>
                                        <label class="option-label">
                                            <input type="radio" name="time_preference" value="상관없음" required>
                                            <span class="option-text">시간은 상관없어요</span>
                                        </label>
                                    </div>
                                </div>
                            </div>

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

    <script>
        // 설문조사 단계 관리 JavaScript (동일)
        let currentStep = 1;
        const totalSteps = 5;

        const questionSteps = document.querySelectorAll('.question-step');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');
        const surveyForm = document.getElementById('surveyForm');

        updateStepDisplay();
        updateProgress();

        prevBtn?.addEventListener('click', function() {
            if (currentStep > 1) {
                currentStep--;
                updateStepDisplay();
                updateProgress();
            }
        });

        nextBtn?.addEventListener('click', function() {
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

        submitBtn?.addEventListener('click', function(e) {
            e.preventDefault();
            
            if (validateCurrentStep()) {
                const allAnswered = ['physical_preference', 'activity_preference', 'group_preference', 'cost_preference', 'time_preference'].every(name => {
                    const checked = document.querySelector(`input[name="${name}"]:checked`);
                    return checked !== null;
                });
                
                if (allAnswered) {
                    submitBtn.textContent = '분석 중...';
                    submitBtn.disabled = true;
                    surveyForm.submit();
                } else {
                    alert('모든 질문에 답변해주세요.');
                }
            } else {
                alert('현재 단계 답변을 선택해주세요.');
            }
        });

        function updateStepDisplay() {
            questionSteps.forEach(step => step.classList.remove('active'));
            const currentQuestionStep = document.querySelector(`[data-step="${currentStep}"]`);
            if (currentQuestionStep) currentQuestionStep.classList.add('active');

            if (prevBtn) prevBtn.style.display = currentStep > 1 ? 'block' : 'none';
            
            if (currentStep === totalSteps) {
                if (nextBtn) nextBtn.style.display = 'none';
                if (submitBtn) submitBtn.style.display = 'block';
            } else {
                if (nextBtn) nextBtn.style.display = 'block';
                if (submitBtn) submitBtn.style.display = 'none';
            }
        }

        function updateProgress() {
            const progress = (currentStep / totalSteps) * 100;
            if (progressFill) progressFill.style.width = progress + '%';
            if (progressText) progressText.textContent = `${currentStep} / ${totalSteps}`;
        }

        function validateCurrentStep() {
            const currentQuestionStep = document.querySelector(`[data-step="${currentStep}"]`);
            if (!currentQuestionStep) return false;

            const radioInputs = currentQuestionStep.querySelectorAll('input[type="radio"]');
            const radioName = radioInputs[0]?.name;
            
            if (!radioName) return false;

            const checkedRadio = currentQuestionStep.querySelector(`input[name="${radioName}"]:checked`);
            return checkedRadio !== null;
        }

        document.querySelector('.hamburger')?.addEventListener('click', function() {
            document.querySelector('.nav-menu').classList.toggle('active');
        });

        function loadMeetups(hobbyId) {
            window.location.href = `hobby_recommendation.php?hobby_id=${hobbyId}`;
        }
    </script>
</body>
</html>