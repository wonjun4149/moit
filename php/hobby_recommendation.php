<?php
// 취미 추천 페이지
require_once 'config.php';

// 로그인 확인
if (!isLoggedIn()) {
    redirect('login.php');
}

$site_title = "MOIT - 취미 추천";
$error_message = '';
$recommendations = [];
$popular_hobbies = [];
$meetup_posts = [];

// 데이터베이스 연결
try {
    $pdo = getDBConnection();
    
    // 인기 취미 가져오기 (추천 횟수 기준)
    $stmt = $pdo->query("
        SELECT h.*, COUNT(hr.hobby_id) as recommendation_count
        FROM hobbies h
        LEFT JOIN hobby_recommendations hr ON h.id = hr.hobby_id
        GROUP BY h.id
        ORDER BY recommendation_count DESC, h.name ASC
        LIMIT 10
    ");
    $popular_hobbies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = '데이터를 불러오는 중 오류가 발생했습니다.';
}

// 설문 제출 처리
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_survey'])) {
    try {
        // 디버깅을 위한 로그
        error_log("Survey submission started");
        
        $activity_preference = $_POST['activity_preference'] ?? '';
        $physical_preference = $_POST['physical_preference'] ?? '';
        $group_preference = $_POST['group_preference'] ?? '';
        $cost_preference = $_POST['cost_preference'] ?? '';
        $time_preference = $_POST['time_preference'] ?? '';
        
        // 모든 값이 입력되었는지 확인
        if (empty($activity_preference) || empty($physical_preference) || empty($group_preference) || 
            empty($cost_preference) || empty($time_preference)) {
            $error_message = '모든 질문에 답변해주세요.';
        } else {
            // 설문 응답 저장
            $stmt = $pdo->prepare("
                INSERT INTO hobby_surveys (user_id, activity_preference, physical_preference, group_preference, cost_preference, time_preference) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$_SESSION['user_id'], $activity_preference, $physical_preference, $group_preference, $cost_preference, $time_preference]);
            $survey_id = $pdo->lastInsertId();
            
            error_log("Survey saved with ID: " . $survey_id);
            
            // 취미 추천 알고리즘
            $where_conditions = [];
            $params = [];
            
            if ($activity_preference !== '상관없음') {
                $where_conditions[] = "(activity_type = ? OR activity_type = '혼합')";
                $params[] = $activity_preference;
            }
            
            if ($physical_preference !== '상관없음') {
                $where_conditions[] = "physical_level = ?";
                $params[] = $physical_preference;
            }
            
            if ($group_preference !== '상관없음') {
                $where_conditions[] = "(group_size = ? OR group_size = '상관없음')";
                $params[] = $group_preference;
            }
            
            if ($cost_preference !== '상관없음') {
                $where_conditions[] = "cost_level = ?";
                $params[] = $cost_preference;
            }
            
            $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
            
            $query = "
                SELECT *, 
                (CASE 
                    WHEN activity_type = ? OR activity_type = '혼합' THEN 0.3 ELSE 0
                END +
                CASE 
                    WHEN physical_level = ? THEN 0.3 ELSE 0
                END +
                CASE 
                    WHEN group_size = ? OR group_size = '상관없음' THEN 0.2 ELSE 0
                END +
                CASE 
                    WHEN cost_level = ? THEN 0.2 ELSE 0
                END) as score
                FROM hobbies $where_clause
                ORDER BY score DESC, name ASC
                LIMIT 6
            ";
            
            $stmt = $pdo->prepare($query);
            $search_params = [$activity_preference, $physical_preference, $group_preference, $cost_preference];
            $stmt->execute(array_merge($search_params, $params));
            $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            error_log("Found " . count($recommendations) . " recommendations");
            
            // 추천 기록 저장
            foreach ($recommendations as $hobby) {
                $stmt = $pdo->prepare("
                    INSERT INTO hobby_recommendations (user_id, hobby_id, survey_id, recommendation_score) 
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$_SESSION['user_id'], $hobby['id'], $survey_id, $hobby['score']]);
            }
            
            error_log("Survey processing completed successfully");
        }
        
    } catch (PDOException $e) {
        error_log("Survey processing error: " . $e->getMessage());
        $error_message = '설문 처리 중 오류가 발생했습니다: ' . $e->getMessage();
    }
}

// 선택된 취미의 모집 공고 가져오기
if (isset($_GET['hobby_id'])) {
    try {
        $hobby_id = (int)$_GET['hobby_id'];
        $stmt = $pdo->prepare("
            SELECT mp.*, u.nickname as organizer_nickname, h.name as hobby_name
            FROM meetup_posts mp
            JOIN users u ON mp.organizer_id = u.id
            JOIN hobbies h ON mp.hobby_id = h.id
            WHERE mp.hobby_id = ? AND mp.status = '모집중'
            ORDER BY mp.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$hobby_id]);
        $meetup_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error_message = '모집 공고를 불러오는 중 오류가 발생했습니다.';
    }
}
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
                    <li><a href="#meeting">모임</a></li>
                    <li><a href="#community">커뮤니티</a></li>
                </ul>
            </div>
            <div class="nav-right">
                <span class="welcome-msg">환영합니다, <?php echo htmlspecialchars($_SESSION['user_nickname']); ?>님!</span>
                <a href="logout.php" class="nav-btn logout-btn">로그아웃</a>
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
                            <!-- 질문 1: 활동성 -->
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

                            <!-- 질문 2: 장소 -->
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

                            <!-- 질문 3: 그룹 규모 -->
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

                            <!-- 질문 4: 비용 -->
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

                            <!-- 질문 5: 시간 -->
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
                        <h2>맞춤 취미 추천</h2>
                        <p class="recommendations-subtitle">설문 결과를 바탕으로 추천해드려요!</p>
                        
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

            <!-- 오른쪽: 인기 취미 또는 모집 공고 -->
            <div class="right-section">
                <?php if (!empty($meetup_posts)): ?>
                    <!-- 모집 공고 -->
                    <h3>현재 요집 중인 모임이에요</h3>
                    <div class="meetup-cards">
                        <?php foreach ($meetup_posts as $post): ?>
                            <div class="meetup-card">
                                <div class="meetup-header">
                                    <h4 class="meetup-title"><?php echo htmlspecialchars($post['title']); ?></h4>
                                    <span class="meetup-status"><?php echo $post['status']; ?></span>
                                </div>
                                <p class="meetup-description"><?php echo htmlspecialchars(substr($post['description'], 0, 100)) . '...'; ?></p>
                                <div class="meetup-info">
                                    <div class="meetup-detail">
                                        <span class="detail-label">주최자:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($post['organizer_nickname']); ?></span>
                                    </div>
                                    <div class="meetup-detail">
                                        <span class="detail-label">장소:</span>
                                        <span class="detail-value"><?php echo htmlspecialchars($post['location']); ?></span>
                                    </div>
                                    <div class="meetup-detail">
                                        <span class="detail-label">일시:</span>
                                        <span class="detail-value"><?php echo date('m/d H:i', strtotime($post['meeting_date'])); ?></span>
                                    </div>
                                    <div class="meetup-detail">
                                        <span class="detail-label">인원:</span>
                                        <span class="detail-value"><?php echo $post['current_participants']; ?>/<?php echo $post['max_participants']; ?>명</span>
                                    </div>
                                </div>
                                <button class="join-btn">참여하기</button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <!-- 인기 취미 -->
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

    <script>
        // 설문조사 단계 관리
        let currentStep = 1;
        const totalSteps = 5;

        // DOM 요소들
        const questionSteps = document.querySelectorAll('.question-step');
        const prevBtn = document.getElementById('prevBtn');
        const nextBtn = document.getElementById('nextBtn');
        const submitBtn = document.getElementById('submitBtn');
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');
        const surveyForm = document.getElementById('surveyForm');

        // 초기 설정
        updateStepDisplay();
        updateProgress();

        // 이전 버튼 클릭
        prevBtn?.addEventListener('click', function() {
            if (currentStep > 1) {
                currentStep--;
                updateStepDisplay();
                updateProgress();
            }
        });

        // 다음 버튼 클릭
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

        // 제출 버튼 클릭
        submitBtn?.addEventListener('click', function() {
            if (validateCurrentStep()) {
                submitBtn.textContent = '분석 중...';
                submitBtn.disabled = true;
                surveyForm.submit();
            } else {
                alert('답변을 선택해주세요.');
            }
        });

        // 단계별 화면 업데이트
        function updateStepDisplay() {
            // 모든 단계 숨기기
            questionSteps.forEach(step => {
                step.classList.remove('active');
            });

            // 현재 단계만 보이기
            const currentQuestionStep = document.querySelector(`[data-step="${currentStep}"]`);
            if (currentQuestionStep) {
                currentQuestionStep.classList.add('active');
            }

            // 버튼 상태 업데이트
            if (prevBtn) prevBtn.style.display = currentStep > 1 ? 'block' : 'none';
            
            if (currentStep === totalSteps) {
                if (nextBtn) nextBtn.style.display = 'none';
                if (submitBtn) submitBtn.style.display = 'block';
            } else {
                if (nextBtn) nextBtn.style.display = 'block';
                if (submitBtn) submitBtn.style.display = 'none';
            }
        }

        // 진행률 업데이트
        function updateProgress() {
            const progress = (currentStep / totalSteps) * 100;
            if (progressFill) progressFill.style.width = progress + '%';
            if (progressText) progressText.textContent = `${currentStep} / ${totalSteps}`;
        }

        // 현재 단계 유효성 검사
        function validateCurrentStep() {
            const currentQuestionStep = document.querySelector(`[data-step="${currentStep}"]`);
            if (!currentQuestionStep) return false;

            const radioInputs = currentQuestionStep.querySelectorAll('input[type="radio"]');
            const radioName = radioInputs[0]?.name;
            
            if (!radioName) return false;

            const checkedRadio = currentQuestionStep.querySelector(`input[name="${radioName}"]:checked`);
            return checkedRadio !== null;
        }

        // 네비게이션 메뉴 토글
        document.querySelector('.hamburger')?.addEventListener('click', function() {
            document.querySelector('.nav-menu').classList.toggle('active');
        });

        // 모집 공고 로드
        function loadMeetups(hobbyId) {
            window.location.href = `hobby_recommendation.php?hobby_id=${hobbyId}`;
        }

        // 라디오 버튼 선택 시 자동으로 다음 단계로 이동 (마지막 단계 제외)
        document.querySelectorAll('input[type="radio"]').forEach(radio => {
            radio.addEventListener('change', function() {
                setTimeout(() => {
                    if (currentStep < totalSteps && validateCurrentStep()) {
                        // 약간의 지연 후 자동으로 다음 단계로
                        setTimeout(() => {
                            currentStep++;
                            updateStepDisplay();
                            updateProgress();
                        }, 300);
                    }
                }, 100);
            });
        });

        // 폼 제출 방지 (수동 제출만 허용)
        surveyForm?.addEventListener('submit', function(e) {
            if (currentStep !== totalSteps || !validateCurrentStep()) {
                e.preventDefault();
                return false;
            }
        });
    </script>
</body>
</html>