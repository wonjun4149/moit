<?php
require_once 'config.php';

// 로그인하지 않은 사용자는 접근 차단
if (!isLoggedIn()) {
    redirect('login.php');
}

$site_title = "MOIT - 새 모임 만들기";

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
            <form id="create-meeting-form" action="create_meeting.php" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="create-title">제목</label>
                    <input type="text" id="create-title" name="title" placeholder="예: 주말 아침 함께 테니스 칠 분!" required>
                </div>

                <div class="form-group">
                    <label for="create-image">대표 사진</label>
                    <div class="file-upload-wrapper">
                        <input type="file" id="create-image" name="meeting_image" accept="image/*" class="file-upload-hidden">
                        <label for="create-image" class="file-upload-button">파일 선택</label>
                        <span class="file-upload-name">선택된 파일 없음</span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="create-category">카테고리</label>
                    <select id="create-category" name="category" required>
                        <option value="취미 및 여가">취미 및 여가</option>
                        <option value="운동">운동</option>
                        <option value="스터디">스터디</option>
                        <option value="문화">문화</option>
                        <option value="봉사활동">봉사활동</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="create-description">상세 설명</label>
                    <textarea id="create-description" name="description" rows="4" placeholder="모임에 대한 상세한 설명을 적어주세요." required></textarea>
                </div>

                <div class="form-group">
                     <label>날짜 및 시간</label>
                     <div class="datetime-group">
                        <input type="date" id="create-date" name="meeting_date" required>
                        <input type="time" id="create-time" name="meeting_time" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="create-location">장소</label>
                    <input type="text" id="create-location" name="location" placeholder="예: 아산시 방축동 실내테니스장" required>
                </div>

                <div class="form-group">
                    <label for="create-max-members">최대 인원</label>
                    <input type="number" id="create-max-members" name="max_members" min="2" value="2" required>
                </div>
                <div class="form-footer">
                    <button type="submit" class="btn-primary">생성하기</button>
                </div>
            </form>
        </div>
    </main>

    <div id="similar-meetings-modal" class="modal-backdrop" style="display: none;">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h2>비슷한 모임이 이미 있어요!</h2>
            <p>새로운 모임을 만들기 전에, 혹시 이런 모임은 어떠세요?</p>
            <div id="similar-meetings-list"></div>
            <div class="modal-footer">
                <button id="force-create-meeting-btn" class="btn-primary">그래도 새로 만들기</button>
            </div>
        </div>
    </div>

    <script src="/js/navbar.js"></script>
    <script>
        const fileInput = document.getElementById('create-image');
        const fileNameSpan = document.querySelector('.file-upload-name');
        if (fileInput && fileNameSpan) {
            fileInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    fileNameSpan.textContent = this.files[0].name;
                } else {
                    fileNameSpan.textContent = '선택된 파일 없음';
                }
            });
        }

        const createMeetingForm = document.getElementById('create-meeting-form');
        const similarMeetingsModal = document.getElementById('similar-meetings-modal');
        const closeModalBtn = similarMeetingsModal.querySelector('.modal-close-btn');
        const forceCreateBtn = document.getElementById('force-create-meeting-btn');

        let isSubmitting = false; // 중복 제출 방지 플래그

        if (createMeetingForm) {
            // '생성하기' 버튼을 미리 찾아둡니다.
            const submitButton = createMeetingForm.querySelector('button[type="submit"]');

            createMeetingForm.addEventListener('submit', function(event) {
                // AI 분석이 시작되면 버튼 상태를 변경하고, 기본 제출을 막습니다.
                event.preventDefault();

                if (isSubmitting) return; // 이미 제출 중이면 중단

                const dateInput = document.getElementById('create-date').value;
                const timeInput = document.getElementById('create-time').value;

                if (dateInput && timeInput) {
                    const selectedDateTime = new Date(dateInput + 'T' + timeInput);
                    const now = new Date();
                    if (selectedDateTime < now) {
                        alert('지난 시간으로는 모임을 생성할 수 없습니다. 현재 시간 이후로 설정해주세요.');
                        return;
                    }
                }

                if (!this.checkValidity()) {
                    this.reportValidity();
                    return;
                }

                isSubmitting = true;
                if (submitButton) {
                    submitButton.textContent = '생성 중...';
                    submitButton.disabled = true;
                }

                // 모든 검사를 통과하면 폼을 제출합니다.
                // 이제 페이지가 create_meeting.php로 이동하며 AI 분석이 시작됩니다.
                this.submit();
            });
        }

        function displaySimilarMeetings(meetings) {
            const listElement = document.getElementById('similar-meetings-list');
            listElement.innerHTML = ''; // 기존 목록 초기화

            meetings.forEach(meeting => {
                const meetingItem = document.createElement('div');
                meetingItem.classList.add('similar-meeting-item');
                meetingItem.innerHTML = `
                    <div class="similar-meeting-info">
                        <strong>${meeting.title}</strong>
                        <span>${meeting.category} / ${meeting.location}</span>
                    </div>
                    <a href="meeting_detail.php?id=${meeting.id}&from=recommend" class="btn-secondary" target="_blank">자세히 보기</a>
                `;
                listElement.appendChild(meetingItem);
            });
        }

    </script>
</body>
</html>
