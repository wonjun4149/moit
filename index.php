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
    <link rel="stylesheet" href="css/navbar-style.css">
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php require_once 'php/navbar.php'; ?>

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

    <script src="/js/navbar.js"></script>
</body>
</html>