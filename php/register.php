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
    $confirm_password = $_POST['confirm_password'];
    $name = trim($_POST['name']);
    $nickname = trim($_POST['nickname']);
    $email = trim($_POST['email']);
    
    // 유효성 검사
    if (!validateId($id)) {
        $error_message = '아이디는 4글자 이상의 영문, 숫자, 언더스코어만 사용 가능합니다.';
    } elseif (!validatePassword($password)) {
        $error_message = '비밀번호는 6글자 이상이어야 합니다.';
    } elseif ($password !== $confirm_password) {
        $error_message = '비밀번호가 일치하지 않습니다.';
    } elseif (!validateName($name)) {
        $error_message = '이름은 2~20글자 사이여야 합니다.';
    } elseif (!validateNickname($nickname)) {
        $error_message = '닉네임은 2~15글자 사이여야 합니다.';
    } elseif (!validateEmail($email)) {
        $error_message = '올바른 이메일 주소를 입력해주세요.';
    } else {
        try {
            $pdo = getDBConnection();
            
            // 중복 검사: id, nickname, email 중복 확인
            $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ? OR nickname = ? OR email = ?");
            $stmt->execute([$id, $nickname, $email]);
            
            if ($stmt->fetch()) {
                $error_message = '이미 존재하는 아이디, 닉네임 또는 이메일입니다.';
            } else {
                // 비밀번호 해시화
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // 사용자 등록: 모든 필드 사용
                $stmt = $pdo->prepare("INSERT INTO users (id, password_hash, name, nickname, email) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$id, $hashed_password, $name, $nickname, $email]);
                
                $success_message = '회원가입이 완료되었습니다. 로그인해주세요.';
            }
        } catch (PDOException $e) {
            $error_message = '회원가입 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>회원가입 - MOIT</title>
    <link rel="stylesheet" href="../css/auth-style.css">
</head>
<body>
    <div class="container">
        <div class="form-container">
            <div class="form-header">
                <h1>MOIT</h1>
                <h2>회원가입</h2>
                <p>새로운 취미와 사람들을 만나보세요</p>
            </div>

            <?php if ($error_message): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error_message); ?>
                </div>
            <?php endif; ?>

            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <?php echo htmlspecialchars($success_message); ?>
                    <a href="login.php" class="success-link">로그인 페이지로 이동</a>
                </div>
            <?php else: ?>
                <form method="POST" class="auth-form">
                    <div class="form-group">
                        <label for="id">아이디</label>
                        <input type="text" id="id" name="id" placeholder="4글자 이상의 아이디" 
                               value="<?php echo isset($_POST['id']) ? htmlspecialchars($_POST['id']) : ''; ?>" required>
                        <small>영문, 숫자, 언더스코어(_)만 사용 가능</small>
                    </div>

                    <div class="form-group">
                        <label for="password">비밀번호</label>
                        <input type="password" id="password" name="password" placeholder="6글자 이상의 비밀번호" required>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">비밀번호 확인</label>
                        <input type="password" id="confirm_password" name="confirm_password" placeholder="비밀번호를 다시 입력하세요" required>
                    </div>

                    <div class="form-group">
                        <label for="name">이름</label>
                        <input type="text" id="name" name="name" placeholder="실명을 입력하세요" 
                               value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="nickname">닉네임</label>
                        <input type="text" id="nickname" name="nickname" placeholder="사용할 닉네임" 
                               value="<?php echo isset($_POST['nickname']) ? htmlspecialchars($_POST['nickname']) : ''; ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="email">이메일</label>
                        <input type="email" id="email" name="email" placeholder="example@email.com" 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" required>
                    </div>

                    <button type="submit" class="submit-btn">회원가입</button>
                </form>

                <div class="form-footer">
                    <p>이미 계정이 있으신가요? <a href="login.php">로그인</a></p>
                    <p><a href="../index.php">홈으로 돌아가기</a></p>
                </div>
            <?php endif; ?>
        </div>
        <div class="background-decoration">
            <div class="decoration-circle circle-1"></div>
            <div class="decoration-circle circle-2"></div>
            <div class="decoration-circle circle-3"></div>
        </div>
    </div>
    <script>
        // 실시간 유효성 검사
        document.getElementById('id').addEventListener('input', function(e) {
            const value = e.target.value;
            const isValid = value.length >= 4 && /^[a-zA-Z0-9_]+$/.test(value);
            e.target.style.borderColor = isValid ? '#4CAF50' : '#f44336';
        });

        document.getElementById('password').addEventListener('input', function(e) {
            const value = e.target.value;
            const isValid = value.length >= 6;
            e.target.style.borderColor = isValid ? '#4CAF50' : '#f44336';
            
            const confirmField = document.getElementById('confirm_password');
            if (confirmField.value) {
                confirmField.style.borderColor = value === confirmField.value ? '#4CAF50' : '#f44336';
            }
        });

        document.getElementById('confirm_password').addEventListener('input', function(e) {
            const password = document.getElementById('password').value;
            const confirmPassword = e.target.value;
            e.target.style.borderColor = password === confirmPassword ? '#4CAF50' : '#f44336';
        });

        document.getElementById('email').addEventListener('input', function(e) {
            const value = e.target.value;
            const isValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
            e.target.style.borderColor = isValid ? '#4CAF50' : '#f44336';
        });
    </script>
</body>
</html>