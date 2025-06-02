<?php
// 데이터베이스 설정 - 새로 생성한 MySQL 사용자 사용
define('DB_HOST', 'localhost');
define('DB_USER', 'moit_user');          // 새로 생성한 사용자
define('DB_PASS', 'password'); // 새로 생성한 사용자 비밀번호
define('DB_NAME', 'moit_db');

// 데이터베이스 연결 함수
function getDBConnection() {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch(PDOException $e) {
        die("데이터베이스 연결 실패: " . $e->getMessage());
    }
}

// 세션 시작
if (session_status() == PHP_SESSION_NONE) {
    session_start();
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

// 사용자 로그인 상태 확인
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// 리다이렉트 함수
function redirect($url) {
    header("Location: " . $url);
    exit();
}

// SQL: 테이블 생성 쿼리 (처음 실행 시 사용)
/*
CREATE DATABASE IF NOT EXISTS moit_db;
USE moit_db;

CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(50) PRIMARY KEY,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(20) NOT NULL,
    nickname VARCHAR(15) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
*/
?>