<?php
// MOIT 모임 페이지
require_once 'config.php';

// 로그인 확인
if (!isLoggedIn()) {
    redirect('login.php');
}

$site_title = "MOIT - 모임";
$current_user_id = $_SESSION['user_id'] ?? null;

// DB에서 실제 모임 목록을 가져옵니다.
try {
    $pdo = getDBConnection();
    
    // 사용자가 로그인했을 경우, 각 모임에 대한 참여 여부를 확인하는 쿼리를 추가합니다.
    $sql = "
        SELECT 
            m.id, m.title, m.description, m.category, m.location, 
            m.max_members, m.image_path, m.created_at, m.organizer_id,
            m.meeting_date, m.meeting_time, -- 추가된 컬럼
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
        ORDER BY m.created_at DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['current_user_id' => $current_user_id]);
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
    <link rel="stylesheet" href="../css/navbar-style.css">
    <link rel="stylesheet" href="../css/meeting-style.css"> 
</head>
<body>
    <?php require_once 'navbar.php'; ?>

    <main class="main-container">
        <div class="content-wrapper">
            <div class="left-section">
                <h2>관심사별 정모 일정</h2>

                <div class="category-filters">
                    <button class="filter-btn active" data-category="전체"># 전체</button>
                    <button class="filter-btn" data-category="취미 및 여가"># 취미 및 여가</button>
                    <button class="filter-btn" data-category="운동 및 액티비티"># 운동 및 액티비티</button>
                    <button class="filter-btn" data-category="성장 및 배움"># 성장 및 배움</button>
                    <button class="filter-btn" data-category="문화 및 예술"># 문화 및 예술</button>
                    <button class="filter-btn" data-category="푸드 및 드링크"># 푸드 및 드링크</button>
                    <button class="filter-btn" data-category="여행 및 탐방"># 여행 및 탐방</button>
                    <button class="filter-btn" data-category="봉사 및 참여" style="display: none;"># 봉사 및 참여</button>
                    <button id="show-more-btn">v 더보기</button>
                </div>

                <div class="sorting-options">
                    <a href="#" class="sort-link active">최신순</a>
                    <a href="#" class="sort-link">마감 임박순</a>
                </div>


                <div class="meeting-cards" id="meeting-cards-container">
                    <?php if (empty($meetings)): ?>
                        <div id="empty-meetings-message" class="empty-message">
                            <p>😲 현재 생성된 모임이 없습니다.</p>
                            <span>오른쪽 '새 모임 만들기' 버튼으로 첫 모임을 만들어보세요!</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($meetings as $meeting): ?>
                            <?php
                                // 설명을 80자로 자르는 로직
                                $description_full = htmlspecialchars($meeting['description']);
                                $description_short = $description_full;
                                if (mb_strlen($description_short) > 50) { // 이미지와 유사하게 글자 수를 줄임
                                    $description_short = mb_substr($description_short, 0, 50) . '...';
                                }
                                $current_members = $meeting['current_members_count'] + 1; // 개설자 포함
                                $isRecruiting = $current_members < $meeting['max_members'];
                                $status_text = $isRecruiting ? '모집중' : '모집완료';
                                $status_class = $isRecruiting ? 'recruiting' : 'completed';
                                $formatted_date = date("Y. n. j.", strtotime($meeting['meeting_date']));
                            ?>
                            <a href="meeting_detail.php?id=<?php echo $meeting['id']; ?>" class="meeting-card-link">
                                <div class="meeting-card" data-category="<?php echo htmlspecialchars($meeting['category']); ?>">
                                    <div class="card-image">
                                        <img src="../<?php echo htmlspecialchars($meeting['image_path'] ?? 'assets/default_image.png'); ?>" 
                                             alt="<?php echo htmlspecialchars($meeting['title']); ?>">
                                    </div>
                                    <div class="card-content">
                                        <h3 class="card-title"><?php echo htmlspecialchars($meeting['title']); ?></h3>
                                        <p class="card-description-short"><?php echo $description_short; ?></p>
                                        
                                        <div class="card-details">
                                            <span class="detail-item"><?php echo htmlspecialchars($meeting['location']); ?></span>
                                            <span class="detail-item"><?php echo $formatted_date; ?></span>
                                            <span class="detail-item member-count"><?php echo $current_members; ?> / <?php echo $meeting['max_members']; ?>명</span>
                                        </div>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="right-section">
                <a href="create_meeting_form.php" class="btn-create-meeting">+ 새 모임 만들기</a>
                
                <div class="search-box">
                    <h3>모임 검색</h3>
                    <div class="search-input-wrapper">
                        <input type="text" id="search-input" placeholder="제목, 카테고리 검색">
                        <button id="search-button">🔍</button>
                    </div>
                </div>

                <div class="deadline-box">
                    <h4>🔥 마감 임박!</h4>
                    <p>모임 정보를 불러오는 중...</p>
                </div>
            </div>
        </div>
    </main>

    <script src="/js/navbar.js"></script>
    <script>
        // --- 검색 및 필터 기능 ---
        const searchInput = document.getElementById('search-input');
        const searchButton = document.getElementById('search-button');
        const categoryFilterContainer = document.querySelector('.category-filters');

        function applyFilters() {
            const searchTerm = searchInput.value.toLowerCase();
            const activeCategoryBtn = categoryFilterContainer.querySelector('.filter-btn.active');
            const selectedCategory = activeCategoryBtn ? activeCategoryBtn.dataset.category : '전체';

            document.querySelectorAll('.meeting-card-link').forEach(link => {
                const card = link.querySelector('.meeting-card');
                const title = card.querySelector('.card-title').textContent.toLowerCase();
                const cardCategory = card.dataset.category;

                const searchMatch = title.includes(searchTerm) || cardCategory.toLowerCase().includes(searchTerm);
                const categoryMatch = (selectedCategory === '전체') || (cardCategory === selectedCategory);

                if (searchMatch && categoryMatch) {
                    link.style.display = 'block';
                } else {
                    link.style.display = 'none';
                }
            });
        }

        // 카테고리 버튼 클릭 이벤트
        categoryFilterContainer.addEventListener('click', (e) => {
            if (e.target.classList.contains('filter-btn')) {
                const currentActive = categoryFilterContainer.querySelector('.filter-btn.active');
                if (currentActive) {
                    currentActive.classList.remove('active');
                }
                e.target.classList.add('active');
                applyFilters();
            }
        });

        // "더보기" 버튼 기능
        const showMoreBtn = document.getElementById('show-more-btn');
        if (showMoreBtn) {
            showMoreBtn.addEventListener('click', () => {
                const hiddenCategory = document.querySelector('[data-category="봉사 및 참여"]');
                if (hiddenCategory) {
                    hiddenCategory.style.display = 'inline-block';
                }
                showMoreBtn.style.display = 'none';
            });
        }

        searchButton.addEventListener('click', applyFilters);
        searchInput.addEventListener('keyup', applyFilters); // 실시간 검색
    </script>
</body>
</html>