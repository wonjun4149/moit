<?php
// 새 모임 만들기 폼
require_once 'config.php';

// 로그인 확인
if (!isLoggedIn()) {
    redirect('login.php');
}

$site_title = "MOIT - 새 모임 만들기";
$error_message = $_SESSION['error_message'] ?? '';
unset($_SESSION['error_message']);

$form_data = $_SESSION['form_data'] ?? [];
unset($_SESSION['form_data']);

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $site_title; ?></title>
    <link rel="stylesheet" href="../css/navbar-style.css">
    <link rel="stylesheet" href="../css/meeting-style.css"> 
</head>
<body>
    <?php require_once 'navbar.php'; ?>

    <main class="main-container">
        <div class="form-container">
            <h2>새 모임 만들기</h2>

            <?php if ($error_message): ?>
                <div class="alert alert-error"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <form id="meetingForm" action="create_meeting.php" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="title">모임 제목</label>
                    <input type="text" id="title" name="title" value="<?php echo htmlspecialchars($form_data['title'] ?? ''); ?>" required placeholder="예: 함께 주말마다 코딩 공부하실 분!">
                </div>

                <div class="form-group">
                    <label for="description">모임 소개</label>
                    <textarea id="description" name="description" rows="6" required placeholder="모임의 목적, 활동 내용, 참여 대상 등을 자세히 적어주세요."><?php echo htmlspecialchars($form_data['description'] ?? ''); ?></textarea>
                </div>

                <div class="form-group">
                    <label for="category">카테고리</label>
                    <select id="category" name="category" required>
                        <option value="" disabled <?php echo empty($form_data['category']) ? 'selected' : ''; ?>>카테고리를 선택하세요</option>
                        <option value="취미 및 여가" <?php echo ($form_data['category'] ?? '') === '취미 및 여가' ? 'selected' : ''; ?>>취미 및 여가 (예: 독서, 공예)</option>
                        <option value="운동 및 액티비티" <?php echo ($form_data['category'] ?? '') === '운동 및 액티비티' ? 'selected' : ''; ?>>운동 및 액티비티 (예: 등산, 축구)</option>
                        <option value="성장 및 배움" <?php echo ($form_data['category'] ?? '') === '성장 및 배움' ? 'selected' : ''; ?>>성장 및 배움 (예: 스터디, 강연)</option>
                        <option value="문화 및 예술" <?php echo ($form_data['category'] ?? '') === '문화 및 예술' ? 'selected' : ''; ?>>문화 및 예술 (예: 전시, 영화)</option>
                        <option value="푸드 및 드링크" <?php echo ($form_data['category'] ?? '') === '푸드 및 드링크' ? 'selected' : ''; ?>>푸드 및 드링크 (예: 맛집 탐방, 카페)</option>
                        <option value="여행 및 탐방" <?php echo ($form_data['category'] ?? '') === '여행 및 탐방' ? 'selected' : ''; ?>>여행 및 탐방 (예: 국내여행, 당일치기)</option>
                        <option value="봉사 및 참여" <?php echo ($form_data['category'] ?? '') === '봉사 및 참여' ? 'selected' : ''; ?>>봉사 및 참여 (예: 유기견 봉사)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="location">주요 활동 지역</label>
                    <input type="text" id="location" name="location" value="<?php echo htmlspecialchars($form_data['location'] ?? ''); ?>" required placeholder="예: 서울 강남구">
                </div>

                <div class="form-group">
                    <label>모임 날짜 및 시간</label>
                    <div class="datetime-group">
                        <input type="date" id="meeting_date" name="meeting_date" value="<?php echo htmlspecialchars($form_data['meeting_date'] ?? ''); ?>" required>
                        <input type="time" id="meeting_time" name="meeting_time" value="<?php echo htmlspecialchars($form_data['meeting_time'] ?? ''); ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="max_members">최대 모집 인원</label>
                    <input type="number" id="max_members" name="max_members" min="2" max="100" value="<?php echo htmlspecialchars($form_data['max_members'] ?? '10'); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="image">대표 이미지</label>
                    <div class="file-upload-wrapper">
                        <input type="file" id="image" name="image" class="file-upload-hidden" accept="image/*">
                        <button type="button" class="file-upload-button">파일 선택</button>
                        <span class="file-upload-name">선택된 파일 없음</span>
                    </div>
                </div>
                
                <div class="form-footer">
                    <a href="meeting.php" class="btn-cancel">취소</a>
                    <button type="submit" id="submitBtn" class="btn-primary">생성하기</button>
                </div>
            </form>
        </div>

        <div id="similarMeetingsModal" class="modal-backdrop" style="display: none;">
            <div class="modal-content">
                <span class="modal-close-btn">&times;</span>
                <h2>잠깐! ✋</h2>
                <p>입력하신 내용과 유사한 모임이 이미 있어요. <br>먼저 참여해보는 건 어떠세요?</p>
                <div id="similar-meetings-list">
                    </div>
                <div class="modal-footer">
                    <button type="button" id="forceCreateBtn" class="btn-secondary">무시하고 계속 만들기</button>
                </div>
            </div>
        </div>
    </main>

    <script src="/js/navbar.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 파일 업로드 UI 스크립트
            const fileInput = document.getElementById('image');
            const fileButton = document.querySelector('.file-upload-button');
            const fileNameSpan = document.querySelector('.file-upload-name');

            if (fileButton) {
                fileButton.addEventListener('click', () => {
                    fileInput.click();
                });
            }

            if (fileInput) {
                fileInput.addEventListener('change', () => {
                    if (fileInput.files.length > 0) {
                        fileNameSpan.textContent = fileInput.files[0].name;
                    } else {
                        fileNameSpan.textContent = '선택된 파일 없음';
                    }
                });
            }

            // 모임 생성 폼 제출 핸들링 (AI 유사 모임 체크)
            const meetingForm = document.getElementById('meetingForm');
            const submitBtn = document.getElementById('submitBtn'); // [수정됨] 버튼 ID로 가져오기
            const modal = document.getElementById('similarMeetingsModal');
            const closeBtn = document.querySelector('.modal-close-btn');
            const forceCreateBtn = document.getElementById('forceCreateBtn');
            const similarList = document.getElementById('similar-meetings-list');

            if (meetingForm && modal) {
                meetingForm.addEventListener('submit', function(e) {
                    e.preventDefault(); // 기본 폼 제출(새로고침)을 막습니다.

                    const formData = new FormData(meetingForm);
                    
                    // [수정됨] 버튼 텍스트 변경 및 비활성화
                    submitBtn.disabled = true;
                    submitBtn.textContent = '분석중...';

                    // AI 서버에 유사 모임 체크 요청
                    fetch('check_similar_meetings.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        // [수정됨] 버튼 텍스트 복원
                        submitBtn.disabled = false;
                        submitBtn.textContent = '생성하기';

                        if (data.success && data.similar_meetings && data.similar_meetings.length > 0) {
                            // 유사 모임이 있으면 모달을 띄웁니다.
                            similarList.innerHTML = ''; // 목록 초기화
                            data.similar_meetings.forEach(meeting => {
                                const item = document.createElement('div');
                                item.className = 'similar-meeting-item';
                                item.innerHTML = `
                                    <div class="similar-meeting-info">
                                        <strong>${meeting.title}</strong>
                                        <span>${meeting.location} / ${meeting.current_members_count + 1}명 / ${meeting.similarity}% 유사</span>
                                    </div>
                                    <a href="meeting_detail.php?id=${meeting.id}" class="btn-secondary btn-sm" target="_blank">보러가기</a>
                                `;
                                similarList.appendChild(item);
                            });
                            modal.style.display = 'flex';
                        } else {
                            // 유사 모임이 없거나 AI 체크 실패 시, 폼을 즉시 제출합니다.
                            meetingForm.submit();
                        }
                    })
                    .catch(error => {
                        console.error('Error checking similar meetings:', error);
                        // [수정됨] 버튼 텍스트 복원
                        submitBtn.disabled = false;
                        submitBtn.textContent = '생성하기';
                        
                        // 에러 발생 시에도 그냥 폼을 제출합니다 (안전 장치).
                        meetingForm.submit();
                    });
                });
            }

            // 모달 닫기 버튼
            if (closeBtn) {
                closeBtn.addEventListener('click', () => {
                    modal.style.display = 'none';
                });
            }

            // '무시하고 계속 만들기' 버튼
            if (forceCreateBtn) {
                forceCreateBtn.addEventListener('click', () => {
                    // 모달을 숨기고 폼을 제출합니다.
                    modal.style.display = 'none';
                    meetingForm.submit(); 
                });
            }
            
            // 모달 바깥 영역 클릭 시 닫기
            if (modal) {
                modal.addEventListener('click', (e) => {
                    if (e.target === modal) {
                        modal.style.display = 'none';
                    }
                });
            }
        });
    </script>
</body>
</html>