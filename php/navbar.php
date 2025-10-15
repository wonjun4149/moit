<?php 
$currentPage = basename($_SERVER['PHP_SELF']); 

// [수정] 현재 페이지가 index.php이면 'navbar-dark' 클래스를 추가
$navClass = 'navbar';
if ($currentPage == 'index.php') {
    $navClass .= ' navbar-dark';
}
?>
<nav class="<?php echo $navClass; ?>">
    <div class="nav-container">
        <div class="nav-left">
            <div class="hamburger">
                <span></span>
                <span></span>
                <span></span>
            </div>
            <div class="logo"><a href="/index.php">MOIT</a></div>
            <ul class="nav-menu">
                <li><a href="/php/introduction.php" class="<?php echo ($currentPage == 'introduction.php') ? 'active' : ''; ?>">소개</a></li>
                <li><a href="/php/hobby_recommendation.php" class="<?php echo ($currentPage == 'hobby_recommendation.php') ? 'active' : ''; ?>">취미 추천</a></li>
                <li><a href="/php/meeting.php" class="<?php echo ($currentPage == 'meeting.php') ? 'active' : ''; ?>">모임</a></li>
            </ul>
        </div>
        <div class="nav-right">
            <?php if (isLoggedIn()): ?>
                <span class="welcome-msg">환영합니다, <?php echo htmlspecialchars($_SESSION['user_nickname']); ?>님!</span>
                <a href="/php/mypage.php" class="nav-btn">마이페이지</a> <a href="/php/logout.php" class="nav-btn logout-btn">로그아웃</a>
            <?php else: ?>
                <a href="/php/login.php" class="nav-btn">로그인</a>
                <a href="/php/register.php" class="nav-btn">회원가입</a>
            <?php endif; ?>
        </div>
    </div>
</nav>