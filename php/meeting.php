<?php
// MOIT 모임 페이지
require_once 'config.php';

// 로그인 확인
if (!isLoggedIn()) {
    redirect('login.php');
}

$site_title = "MOIT - 모임";

// 가상 모임 데이터를 빈 배열로 초기화 (기존 모임 삭제)
$meetings = [];

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
                <a href="mypage.php" class="nav-btn">마이페이지</a> <a href="logout.php" class="nav-btn logout-btn">로그아웃</a>
                <button class="profile-btn"></button>
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
                    <?php endif; ?>

                    <?php foreach ($meetings as $meeting): ?>
                        <?php endforeach; ?>
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
            <form id="create-meeting-form">
                <div class="form-group">
                    <label for="create-title">제목</label>
                    <input type="text" id="create-title" placeholder="예: 주말 아침 함께 테니스 칠 분!" required>
                </div>
                <div class="form-group">
                    <label for="create-category">카테고리</label>
                    <select id="create-category" required>
                        <option value="운동">운동</option>
                        <option value="스터디">스터디</option>
                        <option value="문화">문화</option>
                        <option value="봉사활동">봉사활동</option>
                    </select>
                </div>
                 <div class="form-group">
                    <label for="create-description">상세 설명</label>
                    <textarea id="create-description" rows="4" placeholder="모임에 대한 상세한 설명을 적어주세요. (시간, 준비물 등)" required></textarea>
                </div>
                <div class="form-group">
                    <label for="create-location">장소</label>
                    <input type="text" id="create-location" placeholder="예: 아산시 방축동 실내테니스장" required>
                </div>
                <div class="form-group">
                    <label for="create-max-members">최대 인원</label>
                    <input type="number" id="create-max-members" min="2" placeholder="2명 이상" required>
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
        const detailsModal = document.getElementById('details-modal'); // 상세 모달 참조 추가
        const openCreateModalBtn = document.getElementById('open-create-modal-btn');
        const meetingCardsContainer = document.getElementById('meeting-cards-container');
        const emptyMessage = document.getElementById('empty-meetings-message');

        // --- 모임 목록 상태 관리 ---
        function checkEmptyState() {
            if (meetingCardsContainer.querySelector('.meeting-card')) {
                emptyMessage.style.display = 'none';
            } else {
                emptyMessage.style.display = 'block';
            }
        }

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

        // --- 상세 보기 및 삭제 기능 (이벤트 위임) ---
        meetingCardsContainer.addEventListener('click', (e) => {
            const card = e.target.closest('.meeting-card');
            if (!card) return;

            // 상세 보기 버튼 클릭 시
            if (e.target.classList.contains('btn-details')) {
                // 상세 보기 로직은 이전과 동일 (생략)
                openModal(detailsModal);
            }

            // 삭제 버튼 클릭 시
            if (e.target.classList.contains('btn-delete')) {
                if (confirm('정말로 이 모임을 삭제하시겠습니까?')) {
                    card.remove();
                    checkEmptyState(); // 삭제 후 목록 상태 체크
                }
            }
        });

        // --- 새 모임 생성 기능 ---
        document.getElementById('create-meeting-form').addEventListener('submit', (e) => {
            e.preventDefault();
            
            const title = document.getElementById('create-title').value;
            const category = document.getElementById('create-category').value;
            const description = document.getElementById('create-description').value;
            const location = document.getElementById('create-location').value;
            const maxMembers = document.getElementById('create-max-members').value;
            
            // 새 카드 HTML (삭제 버튼 추가)
            const newCardHTML = `
                <div class="meeting-card" data-category="${category}" data-location="${location}">
                    <div class="card-image"><img src="https://images.unsplash.com/photo-1522202176988-66273c2fd55f?q=80&w=2071&auto=format&fit=crop" alt="${title}"></div>
                    <div class="card-content">
                        <div class="card-header">
                            <span class="card-category">${category}</span>
                            <span class="card-status recruiting">모집중</span>
                        </div>
                        <h3 class="card-title">${title}</h3>
                        <p class="card-description" style="display:none;">${description}</p>
                        <div class="card-details">
                            <span class="detail-item">📍 ${location}</span>
                            <span class="detail-item">👥 <span class="member-count">1 / ${maxMembers}</span>명</span>
                        </div>
                        <div class="card-footer">
                            <button class="btn-details">상세보기</button>
                            <button class="btn-delete">삭제하기</button>
                        </div>
                    </div>
                </div>
            `;
            
            meetingCardsContainer.insertAdjacentHTML('afterbegin', newCardHTML);
            checkEmptyState(); // 생성 후 목록 상태 체크
            
            e.target.reset();
            closeModal(createModal);
        });

        // --- 검색 및 필터 기능 ---
        const searchInput = document.getElementById('search-input');
        const categoryFilter = document.getElementById('filter-category');
        const locationFilter = document.getElementById('filter-location');

        function applyFilters() {
            // 필터 로직은 이전과 동일 (생략)
        }

        searchInput.addEventListener('keyup', applyFilters);
        categoryFilter.addEventListener('change', applyFilters);
        locationFilter.addEventListener('change', applyFilters);

        // 페이지 로드 시 초기 상태 체크
        checkEmptyState();

    </script>
</body>
</html>