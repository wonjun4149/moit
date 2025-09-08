<?php
require_once 'config.php';

$error_message = '';
$success_message = '';

if (isLoggedIn()) {
    redirect('../index.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = trim($_POST['id']);
    $password = $_POST['password'];
    
    if (empty($id) || empty($password)) {
        $error_message = '아이디와 비밀번호를 모두 입력해주세요.';
    } else {
        try {
            $pdo = getDBConnection();
            
            // 사용자 정보 조회: id 열을 기준으로 조회
            $stmt = $pdo->prepare("SELECT id, name, nickname, password_hash FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password_hash'])) {
                // 로그인 성공
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['name'];
                $_SESSION['user_nickname'] = $user['nickname'];

                redirect('../index.php');
            } else {
                $error_message = '아이디 또는 비밀번호가 올바르지 않습니다.';
            }
        } catch (PDOException $e) {
            $error_message = '로그인 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>로그인 - MOIT</title>
    <link rel="stylesheet" href="../css/auth-style.css">
</head>
<body>
    <div class="container">
        <div class="form-container">
            <div class="form-header">
                <h1>MOIT</h1>
                <h2>로그인</h2>
                <p>다시 돌아오신 것을 환영합니다</p>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" class="auth-form">
                <div class="form-group">
                    <label for="id">아이디</label>
                    <input type="text" id="id" name="id" placeholder="아이디를 입력하세요" 
                           value="<?php echo isset($_POST['id']) ? htmlspecialchars($_POST['id']) : ''; ?>" required>
                </div>

                <div class="form-group">
                    <label for="password">비밀번호</label>
                    <input type="password" id="password" name="password" placeholder="비밀번호를 입력하세요" required>
                </div>

                <div class="form-options">
                    <label class="checkbox-container">
                        <input type="checkbox" name="remember_me">
                        <span class="checkmark"></span>
                        로그인 상태 유지
                    </label>
                    <a href="#" class="forgot-password">비밀번호 찾기</a>
                </div>

                <button type="submit" class="submit-btn">로그인</button>
            </form>
            
            <div class="divider">
                <span>또는</span>
            </div>

            <div class="social-login">
                <button class="social-btn google-btn">
                    <span>Google로 로그인</span>
                </button>
                <button class="social-btn kakao-btn">
                    <span>카카오로 로그인</span>
                </button>
            </div>

            <div class="form-footer">
                <p>아직 계정이 없으신가요? <a href="register.php">회원가입</a></p>
                <p><a href="../index.php">홈으로 돌아가기</a></p>
            </div>
        </div>
        <div class="background-decoration">
            <div class="decoration-circle circle-1"></div>
            <div class="decoration-circle circle-2"></div>
            <div class="decoration-circle circle-3"></div>
        </div>
    </div>
    <script>
        // 스크립트는 기존과 동일하게 유지
        document.querySelectorAll('.form-group input').forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.classList.add('focused');
            });
            
            input.addEventListener('blur', function() {
                if (!this.value) {
                    this.parentElement.classList.remove('focused');
                }
            });
        });

        document.querySelector('.auth-form').addEventListener('submit', function() {
            const submitBtn = document.querySelector('.submit-btn');
            submitBtn.textContent = '로그인 중...';
            submitBtn.disabled = true;
        });

        document.addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                document.querySelector('.auth-form').submit();
            }
        });
    </script>
</body>
</html>