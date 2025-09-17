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
    <link rel="stylesheet" href="../css/navbar-style.css">
    <link rel="stylesheet" href="../css/introduction-style.css">
</head>
<body>
    <?php require_once 'navbar.php'; ?>

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

    <script src="/js/navbar.js"></script>
    <script>
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