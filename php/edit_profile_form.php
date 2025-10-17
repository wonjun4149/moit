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
            <form action="update_profile.php" method="post" enctype="multipart/form-data" class="auth-form">
                <div class="profile-pic-edit-area">
                    <div class="profile-pic-preview" style="background-image: url('../<?php echo htmlspecialchars($user['profile_image_path'] ?? 'assets/default_profile.png'); ?>');"></div>
                    <label for="profile_image" class="btn-upload">사진 변경</label>
                    <input type="file" id="profile_image" name="profile_image" accept="image/*" style="display: none;">
                </div>

                <div class="form-group">
                    <label for="nickname">닉네임</label>
                    <input type="text" id="nickname" name="nickname" value="<?php echo htmlspecialchars($user['nickname']); ?>" required>
                </div>

                <button type="submit" class="submit-btn">수정하기</button>
            </form>
            <?php else: ?>
                <p>사용자 정보를 불러올 수 없습니다.</p>
            <?php endif; ?>
        </div>
    </main>

    <script>
        document.getElementById('profile_image').addEventListener('change', function(event) {
            const preview = document.querySelector('.profile-pic-preview');
            const file = event.target.files[0];
            if (file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    preview.style.backgroundImage = `url('${e.target.result}')`;
                }
                reader.readAsDataURL(file);
            }
        });
    </script>
</body>
</html>
