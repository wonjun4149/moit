<?php $currentPage = basename($_SERVER['PHP_SELF']); ?>
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
                <li><a href="introduction.php" class="<?php echo ($currentPage == 'introduction.php') ? 'active' : ''; ?>">소개</a></li>
                <li><a href="hobby_recommendation.php" class="<?php echo ($currentPage == 'hobby_recommendation.php') ? 'active' : ''; ?>">취미 추천</a></li>
                <li><a href="meeting.php" class="<?php echo ($currentPage == 'meeting.php') ? 'active' : ''; ?>">모임</a></li>
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
