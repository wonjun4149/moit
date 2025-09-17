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
                            <?php
                                // 설명을 80자로 자르는 로직
                                $description_full = htmlspecialchars($meeting['description']);
                                $description_short = $description_full;
                                if (mb_strlen($description_short) > 80) {
                                    $description_short = mb_substr($description_short, 0, 80) . '...';
                                }
                                $current_members = $meeting['current_members_count'] + 1; // 개설자 포함
                                $isRecruiting = $current_members < $meeting['max_members'];
                                $status_text = $isRecruiting ? '모집중' : '모집완료';
                                $status_class = $isRecruiting ? 'recruiting' : 'completed';
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
                                    <div class="card-header">
                                        <span class="card-category"><?php echo htmlspecialchars($meeting['category']); ?></span>
                                        <span class="card-status <?php echo $status_class; ?>">
                                            <?php echo $status_text; ?>
                                        </span>
                                    </div>
                                    <h3 class="card-title"><?php echo htmlspecialchars($meeting['title']); ?></h3>
                                    <p class="card-description-short"><?php echo $description_short; ?></p>
                                    <p class="card-description-full" style="display:none;"><?php echo $description_full; ?></p>
                                    
                                    <span class="organizer-nickname-hidden" style="display:none;"><?php echo htmlspecialchars($meeting['organizer_nickname']); ?></span>

                                    <div class="card-details">
                                        <span class="detail-item">🗓️ <?php echo htmlspecialchars($meeting['meeting_date']); ?> <?php echo htmlspecialchars(substr($meeting['meeting_time'], 0, 5)); ?></span>
                                        <span class="detail-item">📍 <?php echo htmlspecialchars($meeting['location']); ?></span>
                                        <span class="detail-item">👥 <span class="member-count"><?php echo $current_members; ?> / <?php echo $meeting['max_members']; ?></span>명</span>
                                        </div>
                                    <div class="card-footer">
                                        <button class="btn-details">상세보기</button>
                                        <?php if ($current_user_id == $meeting['organizer_id']): ?>
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
                    <div class="search-input-wrapper">
                        <input type="text" id="search-input" placeholder="제목, 카테고리로 검색">
                        <button id="search-button">🔍</button>
                    </div>
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
                    <input type="text" id="filter-location" placeholder="지역으로 검색">
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
                    <span>🗓️ 날짜: <strong id="modal-details-datetime"></strong></span>
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
                <div class="form-group form-row">
                    <div class="form-group-half">
                        <label for="create-date">모임 날짜</label>
                        <input type="date" id="create-date" name="meeting_date" required>
                    </div>
                    <div class="form-group-half">
                        <label for="create-time">모임 시간</label>
                        <input type="time" id="create-time" name="meeting_time" required>
                    </div>
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

    <div id="recommendation-modal" class="modal-backdrop" style="display: none;">
        <div class="modal-content">
            <button class="modal-close-btn">&times;</button>
            <h2>이런 모임은 어떠세요?</h2>
            <p>입력하신 내용과 비슷한 모임이 이미 있어요.</p>
            <div id="recommendation-list" class="recommendation-list">
                <!-- 추천 모임이 여기에 동적으로 추가됩니다. -->
            </div>
            <div class="modal-footer recommendation-footer">
                <button id="force-create-meeting-btn" class="btn-primary">그냥 새로 만들게요</button>
            </div>
        </div>
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

        // --- 추천 모달 기능 ---
        const recommendationModal = document.getElementById('recommendation-modal');
        const createMeetingForm = document.getElementById('create-meeting-form');
        const forceCreateBtn = document.getElementById('force-create-meeting-btn');

        createMeetingForm.addEventListener('submit', function(e) {
            e.preventDefault(); // 기본 폼 제출 방지

            const title = document.getElementById('create-title').value;
            const description = document.getElementById('create-description').value;

            // AI 에이전트 서버에 보낼 데이터
            const requestData = {
                user_input: {
                    title: title,
                    description: description
                }
            };

            // AI 에이전트 API 호출
            fetch('http://127.0.0.1:8000/agent/invoke', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(requestData)
            })
            .then(response => response.json())
            .then(data => {
                if (data.final_answer && !data.final_answer.includes("찾지 못했습니다")) {
                    // AI가 유사한 모임을 찾은 경우, 추천 모달을 띄웁니다.
                    const recommendationList = document.getElementById('recommendation-list');
                    recommendationList.innerHTML = ''; // 기존 목록 초기화

                    const item = document.createElement('div');
                    item.className = 'recommendation-item-ai';
                    // AI의 답변을 마크다운처럼 간단히 파싱하여 표시합니다.
                    const formattedAnswer = data.final_answer.replace(/\n/g, '<br>').replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
                    item.innerHTML = `<p>${formattedAnswer}</p>`;
                    recommendationList.appendChild(item);
                    
                    document.querySelector('#recommendation-modal h2').textContent = "이런 모임은 어떠세요?";
                    document.querySelector('#recommendation-modal p').textContent = "AI가 회원님의 입력과 유사한 모임을 찾았어요.";

                    closeModal(createModal);
                    openModal(recommendationModal);
                } else {
                    // AI가 유사 모임을 찾지 못했거나 오류가 발생하면 바로 폼을 제출하여 모임을 생성합니다.
                    this.submit();
                }
            })
            .catch(error => {
                console.error('AI Agent API Error:', error);
                // API 서버가 꺼져있는 등 네트워크 오류 발생 시, 그냥 모임을 생성하도록 바로 제출합니다.
                this.submit();
            });
        });

        // "그냥 새로 만들게요" 버튼 클릭 시
        forceCreateBtn.addEventListener('click', () => {
            createMeetingForm.submit();
        });

        // --- 상세보기 기능 ---
        meetingCardsContainer.addEventListener('click', (e) => {
            if (!e.target.classList.contains('btn-details')) {
                return;
            }

            const card = e.target.closest('.meeting-card');
            
            // 카드에서 정보 추출
            const id = card.dataset.id;
            const title = card.querySelector('.card-title').textContent;
            const description = card.querySelector('.card-description-full').textContent;
            const category = card.querySelector('.card-category').textContent;
            const status = card.querySelector('.card-status').textContent.trim();
            const statusClass = card.querySelector('.card-status').className;
            const location = card.dataset.location;
            const members = card.querySelector('.member-count').textContent.trim();
            const organizer = card.querySelector('.organizer-nickname-hidden')?.textContent || '정보 없음';
            const imgSrc = card.querySelector('.card-image img').src;
            const meetingDate = card.querySelector('.detail-item:first-child').textContent.replace('🗓️','').trim();
            
            const isJoined = card.dataset.isJoined === 'true';
            const organizerId = card.dataset.organizerId;
            const isFull = card.dataset.isFull === 'true';

            // 모달에 정보 채우기
            document.getElementById('modal-details-title').textContent = title;
            document.getElementById('modal-details-description').textContent = description;
            document.getElementById('modal-details-category').textContent = category;
            document.getElementById('modal-details-status').textContent = status;
            document.getElementById('modal-details-status').className = 'card-status ' + statusClass.split(' ')[1];
            document.getElementById('modal-details-datetime').textContent = meetingDate;
            document.getElementById('modal-details-location').textContent = location;
            document.getElementById('modal-details-members').textContent = members;
            document.getElementById('modal-details-organizer').textContent = organizer;
            document.getElementById('modal-details-img').src = imgSrc;
            
            // 모달 푸터 버튼 업데이트
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

            // 참여자 목록 가져오기
            const participantsList = document.getElementById('modal-details-participants-list');
            participantsList.innerHTML = '<li>목록을 불러오는 중...</li>'; // 로딩 표시

            fetch(`get_participants.php?meeting_id=${id}`)
                .then(response => response.json())
                .then(data => {
                    participantsList.innerHTML = ''; // 기존 목록 초기화
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


        // --- 검색 및 필터 기능 ---
        const searchInput = document.getElementById('search-input');
        const categoryFilter = document.getElementById('filter-category');
        const locationFilter = document.getElementById('filter-location');
        const searchButton = document.getElementById('search-button');

        function applyFilters() {
            const searchTerm = searchInput.value.toLowerCase();
            const selectedCategory = categoryFilter.value;
            const selectedLocation = locationFilter.value.toLowerCase();

            document.querySelectorAll('.meeting-card').forEach(card => {
                const title = card.querySelector('.card-title').textContent.toLowerCase();
                const cardCategory = card.dataset.category;
                const cardLocation = card.dataset.location.toLowerCase();

                const searchMatch = title.includes(searchTerm) || cardCategory.toLowerCase().includes(searchTerm);
                const categoryMatch = !selectedCategory || cardCategory === selectedCategory;
                const locationMatch = !selectedLocation || cardLocation.includes(selectedLocation);

                if (searchMatch && categoryMatch && locationMatch) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        searchButton.addEventListener('click', applyFilters);
        categoryFilter.addEventListener('change', applyFilters);
        locationFilter.addEventListener('keyup', applyFilters);

    </script>
</body>
</html>
