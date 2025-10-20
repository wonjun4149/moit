<?php
require_once 'config.php';

// 로그인 확인
if (!isLoggedIn()) {
    redirect('login.php');
}

// 모임 ID가 없으면 리디렉션
if (!isset($_GET['id']) || empty($_GET['id'])) {
    redirect('meeting.php');
}

$meeting_id = $_GET['id'];
$current_user_id = $_SESSION['user_id'] ?? null;

// DB에서 모임 상세 정보 가져오기
try {
    $pdo = getDBConnection();
    $sql = "
        SELECT 
            m.id, m.title, m.description, m.category, m.location, 
            m.max_members, m.image_path, m.created_at, m.organizer_id,
            m.meeting_date, m.meeting_time,
            u.nickname AS organizer_nickname,
            (SELECT COUNT(*) FROM meeting_participants mp WHERE mp.meeting_id = m.id) AS current_members_count,
            (CASE 
                WHEN EXISTS (
                    SELECT 1 FROM meeting_participants mp 
                    WHERE mp.meeting_id = m.id AND mp.user_id = :current_user_id
                ) THEN 1
                ELSE 0 
            END) AS is_joined
        FROM meetings m
        JOIN users u ON m.organizer_id = u.id
        WHERE m.id = :meeting_id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['current_user_id' => $current_user_id, 'meeting_id' => $meeting_id]);
    $meeting = $stmt->fetch();

    // 모임이 없으면 리디렉션
    if (!$meeting) {
        redirect('meeting.php');
    }

    // 참여자 목록 가져오기
    $sql_participants = "
        SELECT u.nickname 
        FROM users u
        JOIN meeting_participants mp ON u.id = mp.user_id
        WHERE mp.meeting_id = :meeting_id
    ";
    $stmt_participants = $pdo->prepare($sql_participants);
    $stmt_participants->execute(['meeting_id' => $meeting_id]);
    $participants = $stmt_participants->fetchAll(PDO::FETCH_COLUMN);

} catch (PDOException $e) {
    die("데이터베이스 오류: " . $e->getMessage());
}

$site_title = "MOIT - " . htmlspecialchars($meeting['title']);
$current_members = $meeting['current_members_count'] + 1; // 개설자 포함
$is_full = $current_members >= $meeting['max_members'];
$status_text = $is_full ? '모집완료' : '모집중';
$status_class = $is_full ? 'completed' : 'recruiting';

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
        <div class="meeting-detail-container">
            <div class="detail-header">
                <span class="card-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                <h2><?php echo htmlspecialchars($meeting['title']); ?></h2>
            </div>
            <div class="detail-body">
                <div class="detail-image-container">
                    <img src="../<?php echo htmlspecialchars($meeting['image_path'] ?? 'assets/default_image.png'); ?>" alt="모임 대표 이미지">
                </div>
                <div class="detail-grid">
                    <div class="detail-item">
                        <strong>카테고리</strong>
                        <span><?php echo htmlspecialchars($meeting['category']); ?></span>
                    </div>
                    <div class="detail-item">
                        <strong>일시</strong>
                        <span><?php echo htmlspecialchars($meeting['meeting_date']); ?> <?php echo htmlspecialchars(substr($meeting['meeting_time'], 0, 5)); ?></span>
                    </div>
                    <div class="detail-item">
                        <strong>장소</strong>
                        <span><?php echo htmlspecialchars($meeting['location']); ?></span>
                    </div>
                    <div class="detail-item">
                        <strong>인원</strong>
                        <span><?php echo $current_members; ?> / <?php echo $meeting['max_members']; ?>명</span>
                    </div>
                    <div class="detail-item">
                        <strong>개설자</strong>
                        <span><?php echo htmlspecialchars($meeting['organizer_nickname']); ?></span>
                    </div>
                </div>
                <p class="detail-description"><?php echo nl2br(htmlspecialchars($meeting['description'])); ?></p>
                
                <div class="participants-section">
                    <h4>참여 멤버</h4>
                    <ul id="participants-list">
                        <li><?php echo htmlspecialchars($meeting['organizer_nickname']); ?> (개설자)</li>
                        <?php if (empty($participants)): ?>
                            <li>아직 참여 멤버가 없습니다.</li>
                        <?php else: ?>
                            <?php foreach ($participants as $participant): ?>
                                <li><?php echo htmlspecialchars($participant); ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <div class="detail-footer">
                <?php if ($current_user_id == $meeting['organizer_id']): ?>
                    <button type="button" id="delete-meeting-btn" class="btn-danger" data-meeting-id="<?php echo $meeting['id']; ?>">모임 삭제하기</button>
                <?php elseif ($meeting['is_joined']): ?>
                    <form action="cancel_application.php" method="POST" onsubmit="return confirm('정말로 신청을 취소하시겠습니까?');">
                        <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                        <button type="submit" class="btn-cancel">신청 취소</button>
                    </form>
                <?php else: ?>
                    <form action="join_meeting.php" method="POST">
                        <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                        <button type="submit" class="btn-primary" <?php if ($is_full) echo 'disabled'; ?>>
                            <?php echo $is_full ? '모집완료' : '신청하기'; ?>
                        </button>
                    </form>
                <?php endif; ?>
                <?php 
                    $from = $_GET['from'] ?? '';
                    if ($from === 'recommend') {
                        echo '<button type="button" onclick="window.close()" class="btn-secondary">목록으로 돌아가기</button>';
                    } else {
                        echo '<a href="meeting.php" class="btn-secondary">목록으로 돌아가기</a>';
                    }
                ?>
            </div>
        </div>
    </main>

    <script src="/js/navbar.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const deleteButton = document.getElementById('delete-meeting-btn');

        if (deleteButton) {
            deleteButton.addEventListener('click', function() {
                const meetingId = this.dataset.meetingId;

                if (!confirm('정말로 이 모임을 삭제하시겠습니까? \n삭제된 데이터는 복구할 수 없습니다.')) {
                    return;
                }

                const formData = new FormData();
                formData.append('meeting_id', meetingId);

                fetch('delete_meeting.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    alert(data.message);

                    if (data.success) {
                        location.href = 'meeting.php';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('모임 삭제 중 오류가 발생했습니다.');
                });
            });
        }
    });
    </script>
</body>
</html>
