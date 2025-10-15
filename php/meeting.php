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
                            <div class="meeting-card" 
                                 data-id="<?php echo $meeting['id']; ?>"
                                 data-category="<?php echo htmlspecialchars($meeting['category']); ?>"
                                 data-location="<?php echo htmlspecialchars($meeting['location']); ?>"
                                 data-is-joined="<?php echo $meeting['is_joined'] ? 'true' : 'false'; ?>"
                                 data-organizer-id="<?php echo $meeting['organizer_id']; ?>"
                                 data-is-full="<?php echo !$isRecruiting ? 'true' : 'false'; ?>">
                                <div class="card-image">
                                    <img src="../<?php echo htmlspecialchars($meeting['image_path'] ?? 'assets/default_image.png'); ?>" 
                                         alt="<?php echo htmlspecialchars($meeting['title']); ?>">
                                </div>
                                <div class="card-content">
                                    <h3 class="card-title"><?php echo htmlspecialchars($meeting['title']); ?></h3>
                                    <p class="card-description-short"><?php echo $description_short; ?></p>
                                    <p class="card-description-full" style="display:none;"><?php echo $description_full; ?></p>
                                    
                                    <span class="organizer-nickname-hidden" style="display:none;"><?php echo htmlspecialchars($meeting['organizer_nickname']); ?></span>
                                    <span class="meeting-datetime-hidden" style="display:none;"><?php echo htmlspecialchars($meeting['meeting_date']); ?> <?php echo htmlspecialchars(substr($meeting['meeting_time'], 0, 5)); ?></span>

                                    <div class="card-details">
                                        <span class="detail-item"><?php echo htmlspecialchars($meeting['location']); ?></span>
                                        <span class="detail-item"><?php echo $formatted_date; ?></span>
                                        <span class="detail-item member-count"><?php echo $current_members; ?> / <?php echo $meeting['max_members']; ?>명</span>
                                    </div>
                                    </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="right-section">
                <button class="btn-create-meeting" id="open-create-modal-btn">+ 새 모임 만들기</button>
                
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

    <div id="details-modal" class="modal-backdrop" style="display: none;">
        ... (생략) ...
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
            <div class="modal-footer">
                <button type="submit" class="btn-primary">생성하기</button>
            </div>
        </form>
    </div>
</div>

    <div id="recommendation-modal" class="modal-backdrop" style="display: none;">
        ... (생략) ...
    </div>

    <script src="/js/navbar.js"></script>
    <script>
        const currentUserId = '<?php echo $current_user_id; ?>';

        // --- 필요한 DOM 요소들 선택 ---
        const createModal = document.getElementById('create-modal');
        const detailsModal = document.getElementById('details-modal');
        const openCreateModalBtn = document.getElementById('open-create-modal-btn');
        const meetingCardsContainer = document.getElementById('meeting-cards-container');
        
        // --- 모달 관리 함수 ---
        const openModal = (modal) => modal.style.display = 'flex';
        const closeModal = (modal) => modal.style.display = 'none';

        // '새 모임 만들기' 버튼 클릭 시 모달 열기
        openCreateModalBtn.addEventListener('click', () => openModal(createModal));

        // 모달의 닫기 버튼 또는 배경 클릭 시 모달 닫기
        document.querySelectorAll('.modal-backdrop').forEach(modal => {
            modal.addEventListener('click', (e) => {
                if (e.target.classList.contains('modal-backdrop') || e.target.classList.contains('modal-close-btn')) {
                    closeModal(modal);
                }
            });
        });

        // --- 추천 모달 기능 (변경 없음) ---
        // ... (생략) ...

        // --- 상세보기 기능 (이벤트 리스너 변경) ---
        meetingCardsContainer.addEventListener('click', (e) => {
            // 이제 버튼이 아닌 카드 전체에 이벤트를 적용
            const card = e.target.closest('.meeting-card');
            if (!card) {
                return;
            }
            
            // 카드에서 정보 추출
            const id = card.dataset.id;
            const title = card.querySelector('.card-title').textContent;
            const description = card.querySelector('.card-description-full').textContent;
            const category = card.dataset.category;
            const members = card.querySelector('.member-count').textContent.trim();
            const location = card.dataset.location;
            const organizer = card.querySelector('.organizer-nickname-hidden')?.textContent || '정보 없음';
            const imgSrc = card.querySelector('.card-image img').src;
            const meetingDateTime = card.querySelector('.meeting-datetime-hidden').textContent.trim();
            
            const isJoined = card.dataset.isJoined === 'true';
            const organizerId = card.dataset.organizerId;
            const isFull = card.dataset.isFull === 'true';

            // 모집 상태 다시 계산
            const statusText = isFull ? '모집완료' : '모집중';
            const statusClass = isFull ? 'completed' : 'recruiting';


            // 모달에 정보 채우기
            document.getElementById('modal-details-title').textContent = title;
            document.getElementById('modal-details-description').textContent = description;
            document.getElementById('modal-details-category').textContent = category;
            document.getElementById('modal-details-status').textContent = statusText;
            document.getElementById('modal-details-status').className = 'card-status ' + statusClass;
            document.getElementById('modal-details-datetime').textContent = meetingDateTime;
            document.getElementById('modal-details-location').textContent = location;
            document.getElementById('modal-details-members').textContent = members;
            document.getElementById('modal-details-organizer').textContent = organizer;
            document.getElementById('modal-details-img').src = imgSrc;
            
            // 모달 푸터 버튼 업데이트 (기존 로직과 동일)
            const modalFooter = document.getElementById('modal-details-footer');
            modalFooter.innerHTML = ''; // 기존 버튼 삭제

            if (currentUserId === organizerId) {
                // 개설자는 신청/취소 버튼이 보이지 않음
            } else if (isJoined) {
                // 이미 신청한 경우 -> 취소 버튼
                modalFooter.innerHTML = `
                    <form action="cancel_application.php" method="POST" onsubmit="return confirm('정말로 신청을 취소하시겠습니까?');">
                        <input type="hidden" name="meeting_id" value="${id}">
                        <button type="submit" class="btn-cancel">신청 취소</button>
                    </form>
                `;
            } else {
                // 신청하지 않은 경우 -> 신청 버튼
                const joinButton = document.createElement('button');
                joinButton.type = 'submit';
                joinButton.className = 'btn-primary';
                joinButton.textContent = '신청하기';
                if (isFull) {
                    joinButton.disabled = true;
                    joinButton.textContent = '모집완료';
                }

                const form = document.createElement('form');
                form.action = 'join_meeting.php';
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="meeting_id" value="${id}">`;
                form.appendChild(joinButton);
                modalFooter.appendChild(form);
            }
            
            openModal(detailsModal);

            // 참여자 목록 가져오기 (기존 로직과 동일)
            // ... (생략) ...
        });


        // --- 검색 및 필터 기능 (필터 로직 수정) ---
        const searchInput = document.getElementById('search-input');
        const searchButton = document.getElementById('search-button');
        const categoryFilterContainer = document.querySelector('.category-filters');

        function applyFilters() {
            const searchTerm = searchInput.value.toLowerCase();
            const activeCategoryBtn = categoryFilterContainer.querySelector('.filter-btn.active');
            const selectedCategory = activeCategoryBtn ? activeCategoryBtn.dataset.category : '전체';

            document.querySelectorAll('.meeting-card').forEach(card => {
                const title = card.querySelector('.card-title').textContent.toLowerCase();
                const cardCategory = card.dataset.category;

                const searchMatch = title.includes(searchTerm) || cardCategory.toLowerCase().includes(searchTerm);
                const categoryMatch = (selectedCategory === '전체') || (cardCategory === selectedCategory);

                if (searchMatch && categoryMatch) {
                    card.style.display = 'flex'; // display: flex로 변경
                } else {
                    card.style.display = 'none';
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
    </script>
</body>
</html>