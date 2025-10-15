<?php
// MOIT 마이페이지
require_once 'config.php';

// 로그인하지 않은 사용자는 로그인 페이지로 이동
if (!isLoggedIn()) {
    redirect('login.php');
}

$site_title = "MOIT - 마이페이지";

// DB에서 사용자의 모임 정보를 가져옵니다.
$created_meetings = [];
$joined_meetings = [];
$user_id = $_SESSION['user_id'];

try {
    $pdo = getDBConnection();

    // 1. 내가 만든 모임 목록 조회
    $stmt_created = $pdo->prepare("
        SELECT 
            m.id, m.title, m.description, m.category, m.location, 
            m.max_members, m.image_path, m.created_at, m.organizer_id, m.meeting_date,
            (SELECT COUNT(*) FROM meeting_participants WHERE meeting_id = m.id) + 1 AS current_members
        FROM meetings m
        WHERE m.organizer_id = ?
        ORDER BY m.created_at DESC
    ");
    $stmt_created->execute([$user_id]);
    $created_meetings = $stmt_created->fetchAll(PDO::FETCH_ASSOC);

    // 2. 내가 참여한 모임 목록 조회
    $stmt_joined = $pdo->prepare("
        SELECT 
            m.id, m.title, m.description, m.category, m.location, 
            m.max_members, m.image_path, m.created_at, m.organizer_id, m.meeting_date,
            u.nickname AS organizer_nickname,
            (SELECT COUNT(*) FROM meeting_participants WHERE meeting_id = m.id) + 1 AS current_members
        FROM meetings m
        JOIN users u ON m.organizer_id = u.id
        JOIN meeting_participants mp ON m.id = mp.meeting_id
        WHERE mp.user_id = ?
        ORDER BY m.created_at DESC
    ");
    $stmt_joined->execute([$user_id]);
    $joined_meetings = $stmt_joined->fetchAll();

} catch (PDOException $e) {
    // 데이터베이스 오류 처리
    error_log("Mypage data fetch error: " . $e->getMessage());
    // 사용자에게는 오류 메시지를 보여주지 않고, 빈 목록으로 표시됩니다.
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $site_title; ?></title>
    <link rel="stylesheet" href="../css/navbar-style.css">
    <link rel="stylesheet" href="../css/mypage-style.css">
</head>
<body>
    <?php require_once 'navbar.php'; ?>

    <main class="main-container">
        <div class="profile-header">
            <div class="profile-pic"></div>
            <div class="profile-info">
                <h2><?php echo htmlspecialchars($_SESSION['user_nickname']); ?> 님</h2>
                <p>오늘도 새로운 취미를 찾아보세요!</p>
            </div>
            <button class="edit-profile-btn">프로필 수정</button>
        </div>

        <div class="meetings-container">
            <div class="tab-nav">
                <button class="tab-btn active" data-target="created-meetings">내가 만든 모임</button>
                <button class="tab-btn" data-target="joined-meetings">내가 참여한 모임</button>
            </div>

            <div class="tab-content">
                <div id="created-meetings" class="tab-pane active">
                    <?php if (empty($created_meetings)): ?>
                        <p class="empty-message">아직 직접 만든 모임이 없어요.</p>
                    <?php else: ?>
                        <ul class="meeting-list">
                            <?php foreach ($created_meetings as $meeting): ?>
                                <?php
                                    $is_past = strtotime($meeting['meeting_date']) < strtotime(date('Y-m-d'));
                                    $status_text = $is_past ? '기간만료' : ($meeting['current_members'] < $meeting['max_members'] ? '모집중' : '모집완료');
                                    $status_class = $is_past ? 'expired' : ($meeting['current_members'] < $meeting['max_members'] ? 'recruiting' : 'completed');
                                ?>
                                <li>
                                    <a href="meeting_detail.php?id=<?php echo $meeting['id']; ?>" class="meeting-info">
                                        <span class="category-tag"><?php echo htmlspecialchars($meeting['category']); ?></span>
                                        <strong class="meeting-title"><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                    </a>
                                    <div class="meeting-status">
                                        <span><?php echo $meeting['current_members']; ?> / <?php echo $meeting['max_members']; ?>명</span>
                                        <span class="status-tag <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </div>
                                    <div class="meeting-actions">
                                        <form action="delete_meeting.php" method="POST" onsubmit="return confirm('정말로 이 모임을 삭제하시겠습니까? 복구할 수 없습니다.');">
                                            <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                                            <input type="hidden" name="source" value="mypage">
                                            <button type="submit" class="btn-danger">삭제</button>
                                        </form>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>

                <div id="joined-meetings" class="tab-pane">
                     <?php if (empty($joined_meetings)): ?>
                        <p class="empty-message">아직 참여한 모임이 없어요.</p>
                    <?php else: ?>
                        <ul class="meeting-list">
                            <?php foreach ($joined_meetings as $meeting): ?>
                                <?php
                                    $is_past = strtotime($meeting['meeting_date']) < strtotime(date('Y-m-d'));
                                    $status_text = $is_past ? '기간만료' : ($meeting['current_members'] < $meeting['max_members'] ? '모집중' : '모집완료');
                                    $status_class = $is_past ? 'expired' : ($meeting['current_members'] < $meeting['max_members'] ? 'recruiting' : 'completed');
                                ?>
                                <li>
                                    <a href="meeting_detail.php?id=<?php echo $meeting['id']; ?>" class="meeting-info">
                                        <span class="category-tag"><?php echo htmlspecialchars($meeting['category']); ?></span>
                                        <strong class="meeting-title"><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                        <span class="organizer"> (개설자: <?php echo htmlspecialchars($meeting['organizer_nickname']); ?>)</span>
                                    </a>
                                    <div class="meeting-status">
                                        <span><?php echo $meeting['current_members']; ?> / <?php echo $meeting['max_members']; ?>명</span>
                                        <span class="status-tag <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                    </div>
                                    <div class="meeting-actions">
                                        <?php if (!$is_past): ?>
                                            <form action="cancel_application.php" method="POST" onsubmit="return confirm('정말로 신청을 취소하시겠습니까?');">
                                                <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                                                <input type="hidden" name="source" value="mypage">
                                                <button type="submit" class="btn-cancel">신청 취소</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script src="/js/navbar.js"></script>
    <script>
        // 탭 기능
        const tabBtns = document.querySelectorAll('.tab-btn');
        const tabPanes = document.querySelectorAll('.tab-pane');

        tabBtns.forEach(btn => {
            btn.addEventListener('click', () => {
                const targetId = btn.getAttribute('data-target');

                tabBtns.forEach(b => b.classList.remove('active'));
                btn.classList.add('active');

                tabPanes.forEach(pane => {
                    if (pane.id === targetId) {
                        pane.classList.add('active');
                    } else {
                        pane.classList.remove('active');
                    }
                });
            });
        });
    </script>
</body>
</html>