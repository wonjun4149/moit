<?php
// MOIT 모임 페이지
require_once 'config.php';

// 로그인 확인
if (!isLoggedIn()) {
    redirect('login.php');
}

$site_title = "MOIT - 모임";

// DB에서 실제 모임 목록을 가져옵니다.
try {
    $pdo = getDBConnection();
    // meetings 테이블과 users 테이블을 JOIN하여 개설자 닉네임도 함께 가져옵니다.
    // 최신순으로 정렬합니다.
    $stmt = $pdo->query("
        SELECT 
            m.id, m.title, m.description, m.category, m.location, 
            m.max_members, m.image_path, m.created_at, m.organizer_id,
            u.nickname AS organizer_nickname,
            (SELECT COUNT(*) FROM meeting_participants mp WHERE mp.meeting_id = m.id) + 1 AS current_members
        FROM meetings m
        JOIN users u ON m.organizer_id = u.id
        ORDER BY m.created_at DESC
    ");
    $meetings = $stmt->fetchAll();
} catch (PDOException $e) {
    // 데이터베이스 오류 발생 시, 빈 배열로 초기화하고 에러 로그를 남깁니다.
    $meetings = [];
    error_log("Meeting list fetch error: " . $e->getMessage());
    // 실제 서비스에서는 사용자에게 보여줄 에러 페이지로 이동시키는 것이 좋습니다.
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $site_title; ?></title>
    <link rel="stylesheet" href="../css/meeting-style.css"> 
</head>
<body>
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-left">
                <div class="hamburger"><span></span><span></span><span></span></div>
                <div class="logo"><a href="../index.php">MOIT</a></div>
                <ul class="nav-menu">
                    <li><a href="introduction.php">소개</a></li>
                    <li><a href="hobby_recommendation.php">취미 추천</a></li>
                    <li><a href="meeting.php" class="active">모임</a></li>
                </ul>
            </div>
           <div class="nav-right">
                <span class="welcome-msg">환영합니다, <?php echo htmlspecialchars($_SESSION['user_nickname']); ?>님!</span>
                <a href="mypage.php" class="nav-btn">마이페이지</a>
                <a href="logout.php" class="nav-btn logout-btn">로그아웃</a>
            </div>
        </div>
    </nav>

    <main class="main-container">
        <div class="content-wrapper">
            <div class="left-section">
                <h2>전체 모임</h2>
                <p class="section-subtitle">현재 진행 중인 다양한 모임들을 확인해보세요.</p>

                <div class="meeting-cards" id="meeting-cards-container">
                    <?php if (empty($meetings)): ?>
                        <div id="empty-meetings-message" class="empty-message">
                            <p>😲 현재 생성된 모임이 없습니다.</p>
                            <span>오른쪽 '새 모임 만들기' 버튼으로 첫 모임을 만들어보세요!</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($meetings as $meeting): ?>
                            <div class="meeting-card" 
                                 data-category="<?php echo htmlspecialchars($meeting['category']); ?>"
                                 data-location="<?php echo htmlspecialchars($meeting['location']); ?>">
                                <div class="card-image">
                                    <img src="../<?php echo htmlspecialchars($meeting['image_path'] ?? 'assets/default_image.png'); ?>" 
                                         alt="<?php echo htmlspecialchars($meeting['title']); ?>">
                                </div>
                                <div class="card-content">
                                    <div class="card-header">
                                        <span class="card-category"><?php echo htmlspecialchars($meeting['category']); ?></span>
                                        <?php 
                                            $isRecruiting = $meeting['current_members'] < $meeting['max_members'];
                                            $status_text = $isRecruiting ? '모집중' : '모집완료';
                                            $status_class = $isRecruiting ? 'recruiting' : 'completed';
                                        ?>
                                        <span class="card-status <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </div>
                                    <h3 class="card-title"><?php echo htmlspecialchars($meeting['title']); ?></h3>
                                    <p class="card-description" style="display:none;"><?php echo htmlspecialchars($meeting['description']); ?></p>
                                    <div class="card-details">
                                        <span class="detail-item">📍 <?php echo htmlspecialchars($meeting['location']); ?></span>
                                        <span class="detail-item">👥 <span class="member-count"><?php echo $meeting['current_members']; ?> / <?php echo $meeting['max_members']; ?></span>명</span>
                                    </div>
                                    <div class="card-footer">
                                        <button class="btn-details">상세보기</button>
                                        <?php if (isset($_SESSION['user_id']) && $_SESSION['user_id'] == $meeting['organizer_id']): ?>
                                            <form action="delete_meeting.php" method="POST" onsubmit="return confirm('정말로 이 모임을 삭제하시겠습니까?');">
                                                <input type="hidden" name="meeting_id" value="<?php echo $meeting['id']; ?>">
                                                <button type="submit" class="btn-delete">삭제하기</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="right-section">
                <button class="btn-create-meeting" id="open-create-modal-btn">새 모임 만들기</button>
                
                <div class="search-box">
                    <h3>모임 검색</h3>
                    <input type="text" id="search-input" placeholder="제목, 카테고리, 지역으로 검색">
                </div>

                <div class="filter-box">
                    <h3>필터</h3>
                    <select id="filter-category">
                        <option value="">카테고리 전체</option>
                        <option value="운동">운동</option>
                        <option value="스터디">스터디</option>
                        <option value="문화">문화</option>
                        <option value="봉사활동">봉사활동</option>
                    </select>
                    <select id="filter-location">
                        <option value="">지역 전체</option>
                        <option value="아산">아산</option>
                        <option value="천안">천안</option>
                    </select>
                </div>
            </div>
        </div>
    </main>

    <div id="details-modal" class="modal-backdrop" style="display: none;">
        </div>

    <div id="create-modal" class="modal-backdrop" style="display: none;">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h2>새 모임 만들기</h2>
            <form id="create-meeting-form" action="create_meeting.php" method="POST" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="create-title">제목</label>
                    <input type="text" id="create-title" name="title" placeholder="예: 주말 아침 함께 테니스 칠 분!" required>
                </div>
                <div class="form-group">
                    <label for="create-image">대표 사진</label>
                    <input type="file" id="create-image" name="meeting_image" accept="image/*">
                </div>
                <div class="form-group">
                    <label for="create-category">카테고리</label>
                    <select id="create-category" name="category" required>
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
                    <label for="create-location">장소</label>
                    <input type="text" id="create-location" name="location" placeholder="예: 아산시 방축동 실내테니스장" required>
                </div>
                <div class="form-group">
                    <label for="create-max-members">최대 인원</label>
                    <input type="number" id="create-max-members" name="max_members" min="2" placeholder="2명 이상" required>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn-primary">생성하기</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // --- 네비게이션 메뉴 토글 ---
        document.querySelector('.hamburger').addEventListener('click', () => {
            document.querySelector('.nav-menu').classList.toggle('active');
        });

        const createModal = document.getElementById('create-modal');
        const detailsModal = document.getElementById('details-modal');
        const openCreateModalBtn = document.getElementById('open-create-modal-btn');
        const meetingCardsContainer = document.getElementById('meeting-cards-container');
        const emptyMessage = document.getElementById('empty-meetings-message');

        // --- 모달 관리 ---
        const openModal = (modal) => modal.style.display = 'flex';
        const closeModal = (modal) => modal.style.display = 'none';

        openCreateModalBtn.addEventListener('click', () => openModal(createModal));

        document.querySelectorAll('.modal-backdrop').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target.classList.contains('modal-backdrop') || e.target.classList.contains('modal-close-btn')) {
                    closeModal(modal);
                }
            });
        });

        // --- 검색 및 필터 기능 ---
        const searchInput = document.getElementById('search-input');
        const categoryFilter = document.getElementById('filter-category');
        const locationFilter = document.getElementById('filter-location');

        function applyFilters() {
            const searchTerm = searchInput.value.toLowerCase();
            const selectedCategory = categoryFilter.value;
            const selectedLocation = locationFilter.value;

            document.querySelectorAll('.meeting-card').forEach(card => {
                const title = card.querySelector('.card-title').textContent.toLowerCase();
                const cardCategory = card.dataset.category;
                const cardLocation = card.dataset.location;

                const searchMatch = title.includes(searchTerm) || cardCategory.toLowerCase().includes(searchTerm) || cardLocation.toLowerCase().includes(searchTerm);
                const categoryMatch = !selectedCategory || cardCategory === selectedCategory;
                const locationMatch = !selectedLocation || cardLocation.includes(selectedLocation);

                if (searchMatch && categoryMatch && locationMatch) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        searchInput.addEventListener('keyup', applyFilters);
        categoryFilter.addEventListener('change', applyFilters);
        locationFilter.addEventListener('change', applyFilters);

    </script>
</body>
</html>