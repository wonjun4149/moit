<?php
// 세션 시작
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// 데이터베이스 연결 설정
const DB_HOST = 'localhost';
const DB_NAME = 'moit_db'; // 이전에 확인한 데이터베이스 이름
const DB_USER = 'moit_user'; 
const DB_PASS = 'password'; 

// 데이터베이스 연결 함수
function getDBConnection() {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        throw new PDOException($e->getMessage(), (int)$e->getCode());
    }
}

// 리다이렉트 함수
function redirect($url) {
    header("Location: $url");
    exit();
}

// 로그인 상태 확인 함수
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// 유효성 검사 함수들
function validateId($id) {
    return strlen($id) >= 4 && preg_match('/^[a-zA-Z0-9_]+$/', $id);
}

function validatePassword($password) {
    return strlen($password) >= 6;
}

function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validateName($name) {
    return strlen($name) >= 2 && strlen($name) <= 20;
}

function validateNickname($nickname) {
    return strlen($nickname) >= 2 && strlen($nickname) <= 15;
}

// 사용자 정의 예외 처리
set_exception_handler(function($e) {
    error_log("Uncaught exception: " . $e->getMessage());
    echo "<h1>죄송합니다, 오류가 발생했습니다. 잠시 후 다시 시도해주세요.</h1>";
    exit();
});
?>