<?php
require_once 'config.php';

// 로그인 확인
if (!isLoggedIn()) {
    redirect('login.php');
}

$site_title = "MOIT - 모임 수정";
$meeting_id = $_GET['id'] ?? null;
$user_id = $_SESSION['user_id'];

if (!$meeting_id) {
    die("잘못된 접근입니다.");
}

// 데이터베이스에서 모임 정보 가져오기
try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT * FROM meetings WHERE id = ?");
    $stmt->execute([$meeting_id]);
    $meeting = $stmt->fetch(PDO::FETCH_ASSOC);

    // 보안 검사
    if (!$meeting) {
        die("존재하지 않는 모임입니다.");
    }
    if ($meeting['organizer_id'] != $user_id) {
        die("수정 권한이 없습니다.");
    }

    $is_past = strtotime($meeting['meeting_date'] . ' ' . $meeting['meeting_time']) < time();
    if ($is_past) {
        die("이미 지난 모임은 수정할 수 없습니다.");
    }

} catch (PDOException $e) {
    die("데이터베이스 오류: " . $e->getMessage());
}

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
            <h2>모임 수정</h2>
            <form id="edit-meeting-form" action="update_meeting.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                
                <div class="form-group">
                    <label for="edit-title">제목</label>
                    <input type="text" id="edit-title" name="title" value="<?php echo htmlspecialchars($meeting['title']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="edit-image">대표 사진 (변경 시 선택)</label>
                    <div class="file-upload-wrapper">
                        <input type="file" id="edit-image" name="meeting_image" accept="image/*" class="file-upload-hidden">
                        <label for="edit-image" class="file-upload-button">파일 선택</label>
                        <span class="file-upload-name">현재 이미지: <?php echo basename($meeting['image_path']); ?></span>
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit-category">카테고리</label>
                    <select id="edit-category" name="category" required>
                        <option value="취미 및 여가" <?php echo ($meeting['category'] == '취미 및 여가') ? 'selected' : ''; ?>>취미 및 여가</option>
                        <option value="운동" <?php echo ($meeting['category'] == '운동') ? 'selected' : ''; ?>>운동</option>
                        <option value="스터디" <?php echo ($meeting['category'] == '스터디') ? 'selected' : ''; ?>>스터디</option>
                        <option value="문화" <?php echo ($meeting['category'] == '문화') ? 'selected' : ''; ?>>문화</option>
                        <option value="봉사활동" <?php echo ($meeting['category'] == '봉사활동') ? 'selected' : ''; ?>>봉사활동</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="edit-description">상세 설명</label>
                    <textarea id="edit-description" name="description" rows="4" required><?php echo htmlspecialchars($meeting['description']); ?></textarea>
                </div>

                <div class="form-group">
                     <label>날짜 및 시간</label>
                     <div class="datetime-group">
                        <input type="date" id="edit-date" name="meeting_date" value="<?php echo $meeting['meeting_date']; ?>" required>
                        <input type="time" id="edit-time" name="meeting_time" value="<?php echo $meeting['meeting_time']; ?>" required>
                    </div>
                </div>

                <div class="form-group">
                    <label for="edit-location">장소</label>
                    <input type="text" id="edit-location" name="location" value="<?php echo htmlspecialchars($meeting['location']); ?>" required>
                </div>

                <div class="form-group">
                    <label for="edit-max-members">최대 인원</label>
                    <input type="number" id="edit-max-members" name="max_members" min="2" value="<?php echo $meeting['max_members']; ?>" required>
                </div>
                <div class="form-footer">
                    <button type="submit" class="btn-primary">수정하기</button>
                </div>
            </form>
        </div>
    </main>

    <script src="/js/navbar.js"></script>
    <script>
        // 파일 이름 표시 로직
        const fileInput = document.getElementById('edit-image');
        const fileNameSpan = document.querySelector('.file-upload-name');
        if (fileInput && fileNameSpan) {
            fileInput.addEventListener('change', function() {
                if (this.files && this.files.length > 0) {
                    fileNameSpan.textContent = this.files[0].name;
                } else {
                    // 파일 선택 안했을 때 기본 텍스트 유지
                }
            });
        }

        // 시간 유효성 검사 로직
        const editMeetingForm = document.getElementById('edit-meeting-form');
        if (editMeetingForm) {
            editMeetingForm.addEventListener('submit', function(event) {
                const dateInput = document.getElementById('edit-date').value;
                const timeInput = document.getElementById('edit-time').value;

                if (dateInput && timeInput) {
                    const selectedDateTime = new Date(dateInput + 'T' + timeInput);
                    const now = new Date();

                    if (selectedDateTime < now) {
                        event.preventDefault();
                        alert('지난 시간으로는 모임을 수정할 수 없습니다. 현재 시간 이후로 설정해주세요.');
                        return;
                    }
                }

                if (!this.checkValidity()) {
                    event.preventDefault();
                    this.reportValidity();
                }
            });
        }
    </script>
</body>
</html>