<?php
// MOIT 마이페이지
require_once 'config.php';

// 로그인하지 않은 사용자는 로그인 페이지로 이동
if (!isLoggedIn()) {
    redirect('login.php');
}

$site_title = "MOIT - 마이페이지";

// --- 가상 데이터 (나중에 DB에서 가져올 데이터) ---

// 현재 로그인한 사용자가 만든 모임 목록 (예시)
$created_meetings = [];

// 현재 로그인한 사용자가 참여한 모임 목록 (예시)
$joined_meetings = [];

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
                                <li>
                                    <div class="meeting-info">
                                        <span class="category-tag"><?php echo htmlspecialchars($meeting['category']); ?></span>
                                        <strong class="meeting-title"><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                    </div>
                                    <div class="meeting-status">
                                        <span><?php echo $meeting['current_members']; ?> / <?php echo $meeting['max_members']; ?>명</span>
                                        <span class="status-tag <?php echo ($meeting['status'] === '모집중') ? 'recruiting' : 'completed'; ?>"><?php echo htmlspecialchars($meeting['status']); ?></span>
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
                                <li>
                                    <div class="meeting-info">
                                        <span class="category-tag"><?php echo htmlspecialchars($meeting['category']); ?></span>
                                        <strong class="meeting-title"><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                        <span class="organizer"> (개설자: <?php echo htmlspecialchars($meeting['organizer']); ?>)</span>
                                    </div>
                                    <div class="meeting-status">
                                        <span class="status-tag <?php echo ($meeting['status'] === '모집중') ? 'recruiting' : 'completed'; ?>"><?php echo htmlspecialchars($meeting['status']); ?></span>
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