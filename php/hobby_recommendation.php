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

// 데이터베이스 연결
try {
    debug_output("데이터베이스 연결 시도");
    $pdo = getDBConnection();
    debug_output("데이터베이스 연결 성공");

    // MOIT 통계 데이터 가져오기 (오른쪽 섹션의 기본 표시용)
    $stmt_total_meetings = $pdo->query("SELECT COUNT(*) as total_meetings FROM meetings");
    $total_meetings = $stmt_total_meetings->fetchColumn();

    $stmt_popular_category = $pdo->query("SELECT category FROM meetings GROUP BY category ORDER BY COUNT(*) DESC LIMIT 1");
    $popular_category = $stmt_popular_category->fetchColumn() ?: '아직 없음';

    $stmt_new_members = $pdo->query("SELECT COUNT(*) FROM users WHERE YEARWEEK(created_at, 1) = YEARWEEK(NOW(), 1)");
    $new_members_this_week = $stmt_new_members->fetchColumn();
    
} catch (PDOException $e) {
    debug_output("데이터베이스 에러", $e->getMessage());
    $error_message = '데이터를 불러오는 중 오류가 발생했습니다.';
}

debug_output("최종 상태", [
    'recommendations_count' => count($recommendations), // 이 페이지는 이제 AJAX로 결과를 받으므로 항상 0
    'error_message' => $error_message
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
                            // ### 설문 문항 정의 (Q1 ~ Q48) ###
                            // (기존 코드와 동일하게 유지)
                            $stage1_questions = [
                                ['name' => 'Q1', 'label' => '1. 일주일에 새로운 활동을 위해 온전히 사용할 수 있는 시간은 어느 정도인가요?', 'type' => 'radio', 'options' => ['1시간 미만', '1시간 ~ 3시간', '3시간 ~ 5시간', '5시간 이상']],
                                // ... (Q2 ~ Q12)
                                ['name' => 'Q12', 'label' => '12. 당신의 주거 환경은 새로운 활동을 하기에 어떻다고 생각하시나요?', 'type' => 'radio', 'options' => ['활동에 집중할 수 있는 독립된 공간이 있다.', '공용 공간을 사용해야 해서 제약이 있다.', '층간 소음 등 주변 환경이 신경 쓰인다.', '공간이 협소하여 활동에 제약이 있다.']],
                            ];
                            $stage2_questions = [
                                ['name' => 'Q13', 'label' => '13. "나는 어떤 일에 실패하거나 실수를 했을 때, 나 자신을 심하게 비난하고 자책하는 편이다."', 'type' => 'likert', 'options_text' => ['전혀 그렇지 않다', '그렇지 않다', '보통이다', '그렇다', '매우 그렇다']],
                                // ... (Q14 ~ Q30)
                                ['name' => 'Q30', 'label' => '30. "나는 다른 사람들이 나를 있는 그대로 이해해주지 못한다고 느낄 때가 많다."', 'type' => 'likert', 'options_text' => ['매우 그렇다', '그렇다', '보통이다', '그렇지 않다', '전혀 그렇지 않다']],
                            ];
                            $stage3_questions = [
                                ['name' => 'Q31', 'label' => '31. 새로운 활동을 통해 당신이 가장 얻고 싶은 것은 무엇인가요? (가장 중요한 것 1개 선택)', 'type' => 'radio', 'options' => ['성취: 새로운 기술을 배우고 실력이 느는 것을 확인하는 것', '회복: 복잡한 생각에서 벗어나 편안하게 재충전하는 것', '연결: 좋은 사람들과 교류하며 소속감을 느끼는 것', '활력: 몸을 움직여 건강해지고 에너지를 얻는 것']],
                                // ... (Q32 ~ Q48)
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
                                <?php elseif ($q['type'] === 'checkbox'): ?>
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
                            <button type="submit" class="submit-btn" id="submitBtn" style="display: none;">취미 추천받기</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="right-section">
                <h3>MOIT 통계</h3>
                <div class="moit-stats">
                    <div class="stat-item">
                        <strong>총 모임수</strong>
                        <span><?php echo $total_meetings; ?></span>
                    </div>
                    <div class="stat-item">
                        <strong>가장 인기있는 카테고리</strong>
                        <span><?php echo htmlspecialchars($popular_category); ?></span>
                    </div>
                    <div class="stat-item">
                        <strong>이번 주 새 멤버</strong>
                        <span><?php echo $new_members_this_week; ?></span>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div id="recommendation-modal-overlay" class="modal-overlay">
        <div class="modal-content">
            <h2>🎉 맞춤 취미 추천 결과</h2>
            <div id="recommendation-content" class="ai-recommendation-box">
                </div>
            <button id="close-modal-btn" class="close-button">닫기</button>
        </div>
    </div>


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

            // 사진 미리보기 기능 (기존과 동일)
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

            // 라디오 버튼 자동 다음 (기존과 동일)
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

            // 이전 버튼 (기존과 동일)
            prevBtn.addEventListener('click', function() {
                if (currentStep > 1) {
                    currentStep--;
                    updateStepDisplay();
                    updateProgress();
                }
            });

            // 다음 버튼 (기존과 동일)
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

            // 제출 버튼 (fetch 로직 수정됨)
            submitBtn.addEventListener('click', function(e) {
                e.preventDefault(); 
                if (!validateCurrentStep()) {
                    alert('마지막 질문에 답변하거나 사진을 추가해주세요.');
                    return;
                }

                submitBtn.textContent = '분석 중...';
                submitBtn.disabled = true;

                const formData = new FormData(surveyForm);
                
                fetch('get_ai_recommendation.php', { 
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
                    // ===============================================
                    // [수정됨] 오른쪽 섹션 대신 모달 창에 결과 표시
                    // ===============================================
                    if (data.success && data.recommendation) {
                        const modalOverlay = document.getElementById('recommendation-modal-overlay');
                        const recommendationContent = document.getElementById('recommendation-content');
                        
                        if (modalOverlay && recommendationContent) {
                            // \n (줄바꿈)을 <br> 태그로 변경하여 HTML에 삽입
                            recommendationContent.innerHTML = data.recommendation.replace(/\n/g, '<br>');
                            // 모달 창을 띄웁니다.
                            modalOverlay.style.display = 'flex'; 
                        } else {
                            console.error('모달 요소를 찾을 수 없습니다.');
                            alert('결과를 표시하는 데 오류가 발생했습니다.');
                        }
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

            // updateStepDisplay 함수 (기존과 동일)
            function updateStepDisplay() {
                questionSteps.forEach(step => step.classList.remove('active'));
                const currentQuestionStep = document.querySelector(`.question-step[data-step="${currentStep}"]`);
                if (currentQuestionStep) currentQuestionStep.classList.add('active');

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

            // updateProgress 함수 (기존과 동일)
            function updateProgress() {
                const progress = (currentStep / totalSteps) * 100;
                if (progressFill) progressFill.style.width = progress + '%';
                if (progressText) progressText.textContent = `${currentStep} / ${totalSteps}`;
            }

            // validateCurrentStep 함수 (기존과 동일 - Q10 필수 선택 검사 포함)
            function validateCurrentStep() {
                const currentQuestionStep = document.querySelector(`.question-step[data-step="${currentStep}"]`);
                if (!currentQuestionStep) return false;

                if (currentStep === totalSteps) {
                    return true;
                }

                const checkboxInputs = currentQuestionStep.querySelectorAll('input[type="checkbox"]');
                if (checkboxInputs.length > 0) {
                    const checkedCheckbox = currentQuestionStep.querySelector('input[type="checkbox"]:checked');
                    // Q10(data-step="10")은 하나 이상 선택해야 함
                    if (currentQuestionStep.dataset.step === "10") {
                        return checkedCheckbox !== null; 
                    }
                    return true; // 다른 체크박스는 선택 안해도 통과 (필수가 아님)
                }

                const radioInput = currentQuestionStep.querySelector('input[type="radio"]');
                if (!radioInput) return false;

                const radioName = radioInput.name;
                const checkedRadio = currentQuestionStep.querySelector(`input[name="${radioName}"]:checked`);
                return checkedRadio !== null;
            }
        } // 'if (surveyForm)' 끝

        
        // [새로 추가] 모달 닫기 이벤트 리스너
        const modalOverlay = document.getElementById('recommendation-modal-overlay');
        const closeModalBtn = document.getElementById('close-modal-btn');

        if (closeModalBtn && modalOverlay) {
            // 닫기 버튼 클릭 시
            closeModalBtn.addEventListener('click', function() {
                modalOverlay.style.display = 'none';
            });

            // 모달 바깥의 어두운 영역(오버레이) 클릭 시
            modalOverlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    modalOverlay.style.display = 'none';
                }
            });
        }

        // loadMeetups 함수 (기존과 동일)
        function loadMeetups(hobbyId) {
            window.location.href = `hobby_list.php?hobby_id=${hobbyId}`; 
        }
    </script>
</body>
</html>