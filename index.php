<?php
// MOIT 홈페이지
require_once 'php/config.php';

$site_title = "MOIT";
$main_title = "취미를 찾고, 사람을 만나고, 함께 즐기세요";
$sub_title = "AI 기반 취미 추천 서비스와 모임을 만들고 함께 즐겨보세요.";

// 로그아웃 메시지 처리
$logout_message = '';
if (isset($_GET['logout']) && $_GET['logout'] == '1') {
    $logout_message = '성공적으로 로그아웃되었습니다.';
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $site_title; ?></title>
    <link rel="stylesheet" href="css/style.css">
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
                <div class="logo"><?php echo $site_title; ?></div>
                <ul class="nav-menu">
                    <li><a href="php/introduction.php">소개</a></li>
                    <li><a href="php/hobby_recommendation.php">취미 추천</a></li>
                    <li><a href="php/meeting.php">모임</a></li>
                </ul>
            </div>
            <div class="nav-right">
                <?php if (isLoggedIn()): ?>
                    <span class="welcome-msg">환영합니다, <?php echo htmlspecialchars($_SESSION['user_nickname']); ?>님!</span>
                    <a href="php/mypage.php" class="nav-btn">마이페이지</a> <a href="php/logout.php" class="nav-btn logout-btn">로그아웃</a>
                    <button class="profile-btn"></button>
                <?php else: ?>
                    <a href="php/login.php" class="nav-btn">로그인</a>
                    <a href="php/register.php" class="nav-btn">회원가입</a>
                    <button class="profile-btn"></button>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- 메인 컨테이너 -->
    <main class="main-container">
        <!-- 왼쪽 컨텐츠 -->
        <div class="left-content">
            <?php if ($logout_message): ?>
                <div class="logout-message">
                    <?php echo htmlspecialchars($logout_message); ?>
                </div>
            <?php endif; ?>
            
            <h1 class="main-title">
                <?php echo $main_title; ?> - <span class="highlight">MOIT</span>
            </h1>
            <p class="sub-title">
                <?php echo $sub_title; ?>
            </p>
            <a href="#start" class="cta-button">
                자세히 보기
            </a>
        </div>

        <!-- 오른쪽 이미지 -->
        <div class="right-image">
            <div class="image-overlay"></div>
            <div class="motion-blur"></div>
        </div>
    </main>



    <script>
        // 간단한 인터랙션
        document.querySelector('.hamburger').addEventListener('click', function() {
            document.querySelector('.nav-menu').classList.toggle('active');
        });

        // 스크롤 효과
        window.addEventListener('scroll', function() {
            const navbar = document.querySelector('.navbar');
            if (window.scrollY > 50) {
                navbar.style.background = 'rgba(0, 0, 0, 0.95)';
            } else {
                navbar.style.background = 'rgba(0, 0, 0, 0.9)';
            }
        });
    </script>
</body>
</html>