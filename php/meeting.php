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
        WHERE CONCAT(m.meeting_date, ' ', m.meeting_time) >= NOW()
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

                <div id="ai-search-result-container" style="display: none;">
                    <div class="ai-search-header">
                        <h3>AI 검색 결과</h3>
                        <button id="back-to-list-btn">목록으로 돌아가기</button>
                    </div>
                    <div id="ai-search-result-content" class="ai-result-box">
                        <p>AI가 답변을 생성하고 있습니다. 잠시만 기다려주세요...</p>
                    </div>
                </div>

                <div id="meeting-list-container">
                    
                   <div class="category-filters">
                    <button class="filter-btn active" data-category="전체">
                        <span role="img" aria-label="전체">🌐</span> # 전체
                    </button>
                    <button class="filter-btn" data-category="취미 및 여가">
                        <span role="img" aria-label="취미">🎨</span> # 취미 및 여가
                    </button>
                    <button class="filter-btn" data-category="운동">
                        <span role="img" aria-label="운동">⚽</span> # 운동
                    </button>
                    <button class="filter-btn" data-category="스터디">
                        <span role="img" aria-label="스터디">📚</span> # 스터디
                    </button>
                    <button class="filter-btn" data-category="문화">
                        <span role="img" aria-label="문화">🎭</span> # 문화
                    </button>
                    <button class="filter-btn" data-category="봉사활동">
                        <span role="img" aria-label="봉사">🤝</span> # 봉사활동
                    </button>
                    </div>
                        <button id="show-more-btn">v 더보기</button>
                    </div>
                    <div class="sorting-options">
                        <a href="#" class="sort-link active" data-sort="latest">최신순</a>
                        <a href="#" class="sort-link" data-sort="deadline">마감 임박순</a>
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
            </div>

            <div class="right-section">
                <a href="create_meeting_form.php" class="btn-create-meeting">+ 새 모임 만들기</a>
                
                <div class="search-box">
                    <h3>AI 스마트 검색</h3>
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
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search-input');
            const searchButton = document.getElementById('search-button');
            const categoryFilterContainer = document.querySelector('.category-filters');
            const sortingOptionsContainer = document.querySelector('.sorting-options');
            const meetingCardsContainer = document.getElementById('meeting-cards-container');
            
            // AI 검색 관련 요소
            const meetingListContainer = document.getElementById('meeting-list-container');
            const aiSearchResultContainer = document.getElementById('ai-search-result-container');
            const aiSearchResultContent = document.getElementById('ai-search-result-content');
            const backToListBtn = document.getElementById('back-to-list-btn');

            // AI 스마트 검색 실행 함수
            function performAiSearch() {
                const query = searchInput.value.trim();
                if (query.length < 5) { // 너무 짧은 질문은 기존 필터링으로 처리
                    applyFilters();
                    return;
                }

                // UI 전환: 목록 숨기고 AI 결과창 표시
                meetingListContainer.style.display = 'none';
                aiSearchResultContainer.style.display = 'block';
                aiSearchResultContent.innerHTML = '<p class="loading-text">AI가 답변을 생성하고 있습니다. 잠시만 기다려주세요...</p>';
                searchButton.disabled = true;
                searchButton.textContent = '🧠';

                fetch('ai_search.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ query: query })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.answer) {
                        // [개선 제안] AI 응답의 마크다운을 HTML로 변환
                        let formattedAnswer = data.answer;
                        // 줄바꿈 -> <br>
                        formattedAnswer = formattedAnswer.replace(/\n/g, '<br>');
                        // **굵은 글씨** -> <strong>
                        formattedAnswer = formattedAnswer.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                        // * 목록 or - 목록 -> <ul><li>
                        formattedAnswer = formattedAnswer.replace(/<br>\s*[\*-]\s(.*?)(?=<br>|$)/g, '<ul><li>$1</li></ul>').replace(/<\/ul><br><ul>/g, '');
                        aiSearchResultContent.innerHTML = formattedAnswer;
                    } else {
                        aiSearchResultContent.innerHTML = `<p class="error-text">오류: ${data.error || 'AI 검색 중 문제가 발생했습니다.'}</p>`;
                    }
                })
                .catch(error => {
                    console.error('AI Search Error:', error);
                    aiSearchResultContent.innerHTML = '<p class="error-text">AI 서버와 통신하는 데 실패했습니다. 잠시 후 다시 시도해주세요.</p>';
                })
                .finally(() => {
                    searchButton.disabled = false;
                    searchButton.textContent = '🔍';
                });
            }

            // 검색 버튼 클릭 이벤트
            searchButton.addEventListener('click', performAiSearch);

            // 엔터 키 입력 이벤트
            searchInput.addEventListener('keyup', function(event) {
                if (event.key === 'Enter') {
                    performAiSearch();
                }
            });

            // '목록으로 돌아가기' 버튼 이벤트
            backToListBtn.addEventListener('click', function() {
                aiSearchResultContainer.style.display = 'none';
                meetingListContainer.style.display = 'block';
                searchInput.value = ''; // 검색창 초기화
                applyFilters(); // 필터 초기화
            });

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

            // --- 이하 기존 코드 유지 ---

            function renderMeetingCards(meetings) {
                meetingCardsContainer.innerHTML = ''; // 기존 카드 삭제
                if (meetings.length === 0) {
                    meetingCardsContainer.innerHTML = `
                        <div id="empty-meetings-message" class="empty-message">
                            <p>😲 현재 생성된 모임이 없습니다.</p>
                            <span>오른쪽 '새 모임 만들기' 버튼으로 첫 모임을 만들어보세요!</span>
                        </div>`;
                    return;
                }

                meetings.forEach(meeting => {
                    const description_full = meeting.description;
                    let description_short = description_full;
                    if (description_full.length > 50) {
                        description_short = description_full.substring(0, 50) + '...';
                    }
                    const current_members = parseInt(meeting.current_members_count) + 1;
                    const isRecruiting = current_members < meeting.max_members;
                    const status_text = isRecruiting ? '모집중' : '모집완료';
                    const status_class = isRecruiting ? 'recruiting' : 'completed';
                    
                    // 날짜 포맷팅
                    const date = new Date(meeting.meeting_date);
                    const formatted_date = `${date.getFullYear()}. ${date.getMonth() + 1}. ${date.getDate()}.`;

                    const cardHtml = `
                        <a href="meeting_detail.php?id=${meeting.id}" class="meeting-card-link">
                            <div class="meeting-card" data-category="${meeting.category}">
                                <div class="card-image">
                                    <img src="../${meeting.image_path || 'assets/default_image.png'}" alt="${meeting.title}">
                                </div>
                                <div class="card-content">
                                    <h3 class="card-title">${meeting.title}</h3>
                                    <p class="card-description-short">${description_short}</p>
                                    <div class="card-details">
                                        <span class="detail-item">${meeting.location}</span>
                                        <span class="detail-item">${formatted_date}</span>
                                        <span class="detail-item member-count">${current_members} / ${meeting.max_members}명</span>
                                    </div>
                                </div>
                            </div>
                        </a>`;
                    meetingCardsContainer.innerHTML += cardHtml;
                });
            }

            sortingOptionsContainer.addEventListener('click', function(e) {
                e.preventDefault();
                if (e.target.classList.contains('sort-link')) {
                    const sortType = e.target.dataset.sort;

                    // 모든 정렬 링크에서 'active' 클래스 제거
                    sortingOptionsContainer.querySelectorAll('.sort-link').forEach(link => {
                        link.classList.remove('active');
                    });
                    // 클릭된 링크에 'active' 클래스 추가
                    e.target.classList.add('active');

                    fetch(`get_meetings.php?sort=${sortType}`)
                        .then(response => response.json())
                        .then(data => {
                            renderMeetingCards(data);
                            applyFilters(); // 정렬 후 필터 다시 적용
                        })
                        .catch(error => console.error('Error fetching meetings:', error));
                }
            });

            categoryFilterContainer.addEventListener('click', (e) => {
                // 클릭된 요소가 버튼 자체이거나 버튼의 자식 요소(img, text)일 수 있으므로 closest를 사용
                const button = e.target.closest('.filter-btn');
                if (button) {
                    const currentActive = categoryFilterContainer.querySelector('.filter-btn.active');
                    if (currentActive) {
                        currentActive.classList.remove('active');
                    }
                    button.classList.add('active');
                    applyFilters();
                }
            });

            const showMoreBtn = document.getElementById('show-more-btn');
            if (showMoreBtn) {
                showMoreBtn.addEventListener('click', () => {
                    const hiddenCategory = document.querySelector('[data-category="봉사 및 참여"]');
                    if (hiddenCategory) {
                        hiddenCategory.style.display = 'inline-flex'; // flex로 변경
                    }
                    showMoreBtn.style.display = 'none';
                });
            }

            // 마감 임박 모임 불러오기
            function renderDeadlineMeetings(meetings) {
                const deadlineBox = document.querySelector('.deadline-box');
                deadlineBox.innerHTML = '<h4>🔥 마감 임박!</h4>'; // 로딩 메시지 제거

                if (meetings.length === 0) {
                    deadlineBox.innerHTML += '<p>마감 임박 모임이 없습니다.</p>';
                    return;
                }

                const list = document.createElement('ul');
                list.className = 'deadline-list';

                meetings.slice(0, 3).forEach(meeting => { // 상위 3개 표시
                    const item = document.createElement('li');
                    const link = document.createElement('a');
                    link.href = `meeting_detail.php?id=${meeting.id}`;

                    const title = document.createElement('span');
                    title.className = 'deadline-title';
                    title.textContent = meeting.title;

                    const details = document.createElement('div');
                    details.className = 'deadline-details';

                    const location = document.createElement('span');
                    location.textContent = meeting.location;

                    const timeRemaining = document.createElement('span');
                    const meetingTime = new Date(`${meeting.meeting_date} ${meeting.meeting_time}`);
                    const now = new Date();
                    const diffMs = meetingTime - now;
                    const diffHours = Math.floor(diffMs / (1000 * 60 * 60));

                    if (diffHours >= 24) {
                        const diffDays = Math.floor(diffHours / 24);
                        const remainingHours = diffHours % 24;
                        timeRemaining.textContent = `${diffDays}일 ${remainingHours}시간 후`;
                    } else {
                        timeRemaining.textContent = `${diffHours}시간 후`;
                    }

                    details.appendChild(location);
                    details.appendChild(timeRemaining);

                    link.appendChild(title);
                    link.appendChild(details);

                    item.appendChild(link);
                    list.appendChild(item);
                });

                deadlineBox.appendChild(list);
            }

            fetch('get_meetings.php?sort=deadline')
                .then(response => response.json())
                .then(data => {
                    renderDeadlineMeetings(data);
                })
                .catch(error => {
                    console.error('Error fetching deadline meetings:', error);
                    const deadlineBox = document.querySelector('.deadline-box');
                    deadlineBox.innerHTML = '<h4>🔥 마감 임박!</h4><p>모임 정보를 불러오는데 실패했습니다.</p>';
                });
        });
    </script>
</body>
</html>