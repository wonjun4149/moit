<?php
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('mypage.php');
}

$user_id = $_SESSION['user_id'];
$nickname = $_POST['nickname'] ?? '';

if (empty($nickname)) {
    // 닉네임이 비어있을 경우 처리
    redirect('edit_profile_form.php', ['error' => '닉네임을 입력해주세요.']);
}

$profile_image_path = null;

// --- 파일 업로드 처리 ---
if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] === UPLOAD_ERR_OK) {
    $upload_dir = '../uploads/profile_pictures/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file_info = pathinfo($_FILES['profile_image']['name']);
    $file_ext = strtolower($file_info['extension']);
    $new_filename = uniqid('profile_', true) . '.' . $file_ext;
    $target_path = $upload_dir . $new_filename;

    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif'];
    if (in_array($file_ext, $allowed_exts)) {
        if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $target_path)) {
            $profile_image_path = 'uploads/profile_pictures/' . $new_filename;
            error_log('New profile image path: ' . $profile_image_path);
        } else {
            redirect('edit_profile_form.php', ['error' => '파일 업로드에 실패했습니다.']);
        }
    } else {
        redirect('edit_profile_form.php', ['error' => '지원되지 않는 파일 형식입니다.']);
    }
}

try {
    $pdo = getDBConnection();

    if ($profile_image_path) {
        // 이전 프로필 이미지 삭제 로직 (선택적)
        $stmt_old_img = $pdo->prepare("SELECT profile_image_path FROM users WHERE id = ?");
        $stmt_old_img->execute([$user_id]);
        $old_image = $stmt_old_img->fetchColumn();
        if ($old_image && $old_image !== 'assets/default_profile.png' && file_exists('../' . $old_image)) {
            unlink('../' . $old_image);
        }

        $sql = "UPDATE users SET nickname = ?, profile_image_path = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nickname, $profile_image_path, $user_id]);
    } else {
        $sql = "UPDATE users SET nickname = ? WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nickname, $user_id]);
    }

    // 세션 정보 업데이트
    $_SESSION['user_nickname'] = $nickname;

    redirect('mypage.php', ['success' => '프로필이 성공적으로 수정되었습니다.']);

} catch (PDOException $e) {
    // 닉네임 중복 오류 처리
    if ($e->errorInfo[1] == 1062) { // 1062 is the error code for duplicate entry
        redirect('edit_profile_form.php', ['error' => '이미 사용중인 닉네임입니다.']);
    } else {
        error_log("Profile update error: " . $e->getMessage());
        redirect('edit_profile_form.php', ['error' => '데이터베이스 오류로 프로필 수정에 실패했습니다.']);
    }
}
?>