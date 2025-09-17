<?php
// php_test.php - php 폴더 안에서 실행하는 버전
echo "<h1>PHP 작동 테스트 (php 폴더 내)</h1>";
echo "<p>현재 시간: " . date('Y-m-d H:i:s') . "</p>";
echo "<p>PHP 버전: " . PHP_VERSION . "</p>";

// 에러 표시 활성화
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>1단계: 기본 PHP 작동 ✅</h2>";

// config.php 파일 존재 확인 (같은 폴더에 있음)
if (file_exists('config.php')) {
    echo "<p>✅ config.php 파일 존재</p>";
    
    try {
        echo "<h2>2단계: config.php 로드 시도</h2>";
        require_once 'config.php';
        echo "<p>✅ config.php 로드 성공</p>";
        
        echo "<h2>3단계: 데이터베이스 연결 시도</h2>";
        $pdo = getDBConnection();
        echo "<p>✅ 데이터베이스 연결 성공</p>";
        
        echo "<h2>4단계: 세션 확인</h2>";
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        
        if (isset($_SESSION['user_id'])) {
            echo "<p>✅ 로그인됨: " . $_SESSION['user_id'] . "</p>";
        } else {
            echo "<p>❌ 로그인되지 않음</p>";
            echo "<p><a href='login.php'>로그인 페이지로 이동</a></p>";
        }
        
        echo "<h2>5단계: 테이블 확인</h2>";
        $tables = ['users', 'hobbies', 'hobby_surveys', 'hobby_recommendations'];
        foreach ($tables as $table) {
            try {
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM $table");
                $result = $stmt->fetch();
                echo "<p>✅ 테이블 '$table': {$result['count']}개 레코드</p>";
            } catch (Exception $e) {
                echo "<p>❌ 테이블 '$table': " . $e->getMessage() . "</p>";
            }
        }
        
    } catch (Exception $e) {
        echo "<p>❌ 에러 발생: " . $e->getMessage() . "</p>";
        echo "<p>파일: " . $e->getFile() . "</p>";
        echo "<p>라인: " . $e->getLine() . "</p>";
        echo "<p>스택 트레이스:</p>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    
} else {
    echo "<p>❌ config.php 파일이 없습니다</p>";
    echo "<p>현재 디렉토리: " . getcwd() . "</p>";
    echo "<p>파일 목록:</p><ul>";
    
    $files = scandir('.');
    foreach ($files as $file) {
        if ($file != '.' && $file != '..') {
            echo "<li>$file</li>";
        }
    }
    echo "</ul>";
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>PHP 테스트 (php 폴더)</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        h1 { color: #333; }
        h2 { color: #4A90E2; border-bottom: 2px solid #4A90E2; padding-bottom: 5px; }
        p { margin: 10px 0; }
        .success { color: green; }
        .error { color: red; }
        pre { background: #f0f0f0; padding: 10px; border-radius: 5px; overflow-x: auto; }
    </style>
</head>
<body></body>
</html>