<?php
// MOIT 소개 페이지
require_once 'config.php';

$site_title = "MOIT - 소개";
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $site_title; ?></title>
    <link rel="stylesheet" href="../css/introduction-style.css">
</head>
<body>
    <!-- 상단 네비게이션 -->
    <nav class="navbar">
        <div class="nav-container">
            <div class="nav-left">
                <div class="hamburger">
                    <span></span>
                    <span></span>
                    <span></span>
                </div>
                <div class="logo"><a href="../index.php">MOIT</a></div>
                <ul class="nav-menu">
                    <li><a href="introduction.php" class="active">소개</a></li>
                    <li><a href="hobby_recommendation.php">취미 추천</a></li>
                    <li><a href="meeting.php">모임</a></li>
                </ul>
            </div>
            <div class="nav-right">
                <?php if (isLoggedIn()): ?>
                    <span class="welcome-msg">환영합니다, <?php echo htmlspecialchars($_SESSION['user_nickname']); ?>님!</span>
                    <a href="mypage.php" class="nav-btn">마이페이지</a> <a href="logout.php" class="nav-btn logout-btn">로그아웃</a>
                    <button class="profile-btn"></button>
                <?php else: ?>
                    <a href="login.php" class="nav-btn">로그인</a>
                    <a href="register.php" class="nav-btn">회원가입</a>
                    <button class="profile-btn"></button>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- 메인 컨테이너 -->
    <main class="main-container">
        <!-- 왼쪽 콘텐츠 -->
        <div class="left-content">
            <div class="content-wrapper">
                <h1 class="main-title">
                    취미를 통한 연결,<br>
                    그 시작은 <span class="highlight">MOIT</span>
                </h1>
                
                <div class="slogan-section">
                    <h3 class="slogan-title">[slogan]</h3>
                    <div class="slogan-line"></div>
                </div>

                <div class="description">
                    <p class="desc-text main-desc">
                        누구나 쉽게 취미를 찾고, 사람을 만나고<br>
                        함께 즐길 수 있는 공간을 만듭니다.
                    </p>
                    
                    <p class="desc-text">
                        우리는 모두 새로운 걸 시작하고 싶어 합니다.<br>
                        하지만 막상 취미를 찾으려면 정보는 부족하고, 함께할 사람은 더 없습니다.
                    </p>
                    
                    <p class="desc-text">
                        <strong>MOIT</strong>은 그런 고민에서 시작되었습니다.
                    </p>
                    
                    <p class="desc-text">
                        좋아하는 걸 시작하고,<br>
                        같은 관심사를 가진 사람들과 함께하고,<br>
                        그 속에서 나만의 시간을 만들어가기를 바라는 마음에서요.
                    </p>
                    
                    <p class="desc-text">
                        그렇게 우리는 <strong class="philosophy">'취미로 모이는 사람들'</strong>이라는 철학을 담아,<br>
                        누구나 취미를 찾고, 사람을 만나고, 함께 즐길 수 있는 플랫폼을 만들기로 했습니다.
                    </p>
                </div>
            </div>
        </div>

        <!-- 오른쪽 이미지 -->
        <div class="right-image">
            <div class="image-placeholder">
                <div class="basketball-court">
                    <div class="court-lines">
                        <div class="center-circle"></div>
                        <div class="three-point-line"></div>
                        <div class="free-throw-line"></div>
                        <div class="basket"></div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <script>
        // 네비게이션 메뉴 토글
        document.querySelector('.hamburger').addEventListener('click', function() {
            document.querySelector('.nav-menu').classList.toggle('active');
        });

        // 스크롤 효과
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.style.background = 'rgba(255, 255, 255, 0.95)';
                navbar.style.boxShadow = '0 2px 20px rgba(0, 0, 0, 0.1)';
            } else {
                navbar.style.background = 'rgba(255, 255, 255, 0.9)';
                navbar.style.boxShadow = 'none';
            }
        });

        // 페이지 로드 시 애니메이션
        window.addEventListener('load', function() {
            document.querySelector('.left-content').classList.add('loaded');
            setTimeout(() => {
                document.querySelector('.right-image').classList.add('loaded');
            }, 300);
        });
    </script>
</body>
</html>