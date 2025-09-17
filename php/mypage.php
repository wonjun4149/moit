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
            m.max_members, m.image_path, m.created_at, m.organizer_id,
            (SELECT COUNT(*) FROM meeting_participants WHERE meeting_id = m.id) + 1 AS current_members,
            '" . htmlspecialchars($_SESSION['user_nickname']) . "' AS organizer_nickname
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
            m.max_members, m.image_path, m.created_at, m.organizer_id,
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
                                    $isRecruiting = $meeting['current_members'] < $meeting['max_members'];
                                    $status_text = $isRecruiting ? '모집중' : '모집완료';
                                    $status_class = $isRecruiting ? 'recruiting' : 'completed';
                                ?>
                                <li>
                                    <div class="meeting-info">
                                        <span class="category-tag"><?php echo htmlspecialchars($meeting['category']); ?></span>
                                        <strong class="meeting-title"><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                    </div>
                                    <div class="meeting-status">
                                        <span><?php echo $meeting['current_members']; ?> / <?php echo $meeting['max_members']; ?>명</span>
                                        <span class="status-tag <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                        <button class="btn-details" data-meeting='<?php echo json_encode($meeting); ?>'>상세보기</button>
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
                                    $isRecruiting = $meeting['current_members'] < $meeting['max_members'];
                                    $status_text = $isRecruiting ? '모집중' : '모집완료';
                                    $status_class = $isRecruiting ? 'recruiting' : 'completed';
                                ?>
                                <li>
                                    <div class="meeting-info">
                                        <span class="category-tag"><?php echo htmlspecialchars($meeting['category']); ?></span>
                                        <strong class="meeting-title"><?php echo htmlspecialchars($meeting['title']); ?></strong>
                                        <span class="organizer"> (개설자: <?php echo htmlspecialchars($meeting['organizer_nickname']); ?>)</span>
                                    </div>
                                    <div class="meeting-status">
                                        <span><?php echo $meeting['current_members']; ?> / <?php echo $meeting['max_members']; ?>명</span>
                                        <span class="status-tag <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                        <button class="btn-details" data-meeting='<?php echo json_encode($meeting); ?>'>상세보기</button>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <div id="details-modal" class="modal-backdrop" style="display: none;">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <img id="modal-details-img" src="" alt="모임 이미지" class="modal-img">
            <div class="modal-header">
                <h2 id="modal-details-title"></h2>
                <div>
                    <span id="modal-details-category" class="card-category"></span>
                    <span id="modal-details-status" class="card-status"></span>
                </div>
            </div>
            <div class="modal-body">
                <p id="modal-details-description"></p>
                <div class="modal-details-info">
                    <span>📍 장소: <strong id="modal-details-location"></strong></span>
                    <span>👥 인원: <strong id="modal-details-members"></strong></span>
                    <span>👤 개설자: <strong id="modal-details-organizer"></strong></span>
                </div>
                <div class="modal-details-participants">
                    <h4>참여자 목록</h4>
                    <ul id="modal-details-participants-list">
                        <!-- 참여자 닉네임이 여기에 동적으로 추가됩니다. -->
                    </ul>
                </div>
            </div>
            <div class="modal-footer" id="modal-details-footer">
                <!-- 버튼이 동적으로 여기에 추가됩니다. -->
            </div>
        </div>
    </div>

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

        // --- 상세보기 모달 기능 ---
        const detailsModal = document.getElementById('details-modal');
        const meetingLists = document.querySelectorAll('.meeting-list');

        const openModal = (modal) => modal.style.display = 'flex';
        const closeModal = (modal) => modal.style.display = 'none';

        detailsModal.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal-backdrop') || e.target.classList.contains('modal-close-btn')) {
                closeModal(detailsModal);
            }
        });

        meetingLists.forEach(list => {
            list.addEventListener('click', e => {
                if (!e.target.classList.contains('btn-details')) return;

                const meetingData = JSON.parse(e.target.dataset.meeting);
                
                const isRecruiting = meetingData.current_members < meetingData.max_members;
                const status_text = isRecruiting ? '모집중' : '모집완료';
                const status_class = isRecruiting ? 'recruiting' : 'completed';

                document.getElementById('modal-details-title').textContent = meetingData.title;
                document.getElementById('modal-details-description').textContent = meetingData.description;
                document.getElementById('modal-details-category').textContent = meetingData.category;
                document.getElementById('modal-details-status').textContent = status_text;
                document.getElementById('modal-details-status').className = 'card-status ' + status_class;
                document.getElementById('modal-details-location').textContent = meetingData.location;
                document.getElementById('modal-details-members').textContent = `${meetingData.current_members} / ${meetingData.max_members}`;
                document.getElementById('modal-details-organizer').textContent = meetingData.organizer_nickname;
                document.getElementById('modal-details-img').src = `../${meetingData.image_path || 'assets/default_image.png'}`;

                const modalFooter = document.getElementById('modal-details-footer');
                modalFooter.innerHTML = ''; // 기존 버튼 삭제

                openModal(detailsModal);

                // 참여자 목록 가져오기
                const participantsList = document.getElementById('modal-details-participants-list');
                participantsList.innerHTML = '<li>목록을 불러오는 중...</li>';

                fetch(`get_participants.php?meeting_id=${meetingData.id}`)
                    .then(response => response.json())
                    .then(data => {
                        participantsList.innerHTML = '';
                        if (data.error) {
                            participantsList.innerHTML = '<li>참여자 정보를 가져오는데 실패했습니다.</li>';
                            console.error(data.error);
                        } else if (data.length > 0) {
                            data.forEach(participant => {
                                const li = document.createElement('li');
                                li.textContent = participant;
                                participantsList.appendChild(li);
                            });
                        } else {
                            participantsList.innerHTML = '<li>아직 참여자가 없습니다.</li>';
                        }
                    })
                    .catch(error => {
                        participantsList.innerHTML = '<li>참여자 정보를 가져오는데 실패했습니다.</li>';
                        console.error('Error fetching participants:', error);
                    });
            });
        });
    </script>
</body>
</html>