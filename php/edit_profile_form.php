<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

$site_title = "MOIT - 프로필 수정";
$user_id = $_SESSION['user_id'];
$user = null;

try {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT nickname, email, profile_image_path FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Profile data fetch error: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $site_title; ?></title>
    <link rel="stylesheet" href="../css/navbar-style.css">
    <link rel="stylesheet" href="../css/auth-style.css">
</head>
<body>
    <?php require_once 'navbar.php'; ?>

    <main class="form-container">
        <div class="form-box">
            <h2>프로필 수정</h2>
            <?php if ($user): ?>
            <form action="update_profile.php" method="post" enctype="multipart/form-data">
                <div class="input-group">
                    <label for="nickname">닉네임</label>
                    <input type="text" id="nickname" name="nickname" value="<?php echo htmlspecialchars($user['nickname']); ?>" required>
                </div>
                <div class="input-group">
                    <label for="profile_image">프로필 사진</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/*">
                    <?php if ($user['profile_image_path']): ?>
                        <img src="../<?php echo htmlspecialchars($user['profile_image_path']); ?>" alt="Current profile image" style="max-width: 100px; margin-top: 10px;">
                    <?php endif; ?>
                </div>
                <button type="submit" class="btn-submit">수정하기</button>
            </form>
            <?php else: ?>
                <p>사용자 정보를 불러올 수 없습니다.</p>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
