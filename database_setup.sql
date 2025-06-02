-- MOIT 데이터베이스 설정
-- 이 파일을 MySQL에서 실행하여 데이터베이스와 테이블을 생성하세요.

-- 데이터베이스 생성
CREATE DATABASE IF NOT EXISTS moit_db DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- 데이터베이스 사용
USE moit_db;

-- 사용자 테이블 생성
CREATE TABLE IF NOT EXISTS users (
    id VARCHAR(50) PRIMARY KEY COMMENT '사용자 아이디 (4글자 이상)',
    password VARCHAR(255) NOT NULL COMMENT '해시화된 비밀번호',
    name VARCHAR(20) NOT NULL COMMENT '실명',
    nickname VARCHAR(15) NOT NULL UNIQUE COMMENT '닉네임',
    email VARCHAR(100) NOT NULL UNIQUE COMMENT '이메일 주소',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '가입일시',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '최종 수정일시',
    INDEX idx_email (email),
    INDEX idx_nickname (nickname),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='사용자 정보 테이블';

-- 취미 마스터 테이블
CREATE TABLE IF NOT EXISTS hobbies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL COMMENT '취미명',
    category VARCHAR(30) NOT NULL COMMENT '카테고리 (운동, 예술, 학습 등)',
    description TEXT COMMENT '취미 설명',
    difficulty_level ENUM('초급', '중급', '고급') DEFAULT '초급' COMMENT '난이도',
    activity_type ENUM('실내', '실외', '혼합') DEFAULT '혼합' COMMENT '활동 장소',
    group_size ENUM('개인', '소그룹', '대그룹', '상관없음') DEFAULT '상관없음' COMMENT '참여 인원',
    cost_level ENUM('무료', '저비용', '중비용', '고비용') DEFAULT '저비용' COMMENT '비용 수준',
    physical_level ENUM('낮음', '보통', '높음') DEFAULT '보통' COMMENT '체력 요구도',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_category (category),
    INDEX idx_activity_type (activity_type),
    INDEX idx_physical_level (physical_level)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='취미 마스터 테이블';

-- 설문 응답 테이블
CREATE TABLE IF NOT EXISTS hobby_surveys (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    activity_preference ENUM('실내', '실외', '상관없음') COMMENT '활동 선호도',
    physical_preference ENUM('낮음', '보통', '높음') COMMENT '체력 활동 선호도',
    group_preference ENUM('개인', '소그룹', '대그룹', '상관없음') COMMENT '그룹 규모 선호도',
    cost_preference ENUM('무료', '저비용', '중비용', '고비용') COMMENT '비용 선호도',
    time_preference ENUM('주중', '주말', '상관없음') COMMENT '시간 선호도',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='설문 응답 테이블';

-- 취미 추천 기록 테이블
CREATE TABLE IF NOT EXISTS hobby_recommendations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id VARCHAR(50) NOT NULL,
    hobby_id INT NOT NULL,
    survey_id INT NOT NULL,
    recommendation_score DECIMAL(3,2) DEFAULT 0.00 COMMENT '추천 점수 (0~1)',
    is_accepted BOOLEAN DEFAULT FALSE COMMENT '사용자가 수락했는지',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (hobby_id) REFERENCES hobbies(id) ON DELETE CASCADE,
    FOREIGN KEY (survey_id) REFERENCES hobby_surveys(id) ON DELETE CASCADE,
    INDEX idx_user_id (user_id),
    INDEX idx_hobby_id (hobby_id),
    INDEX idx_created_at (created_at),
    INDEX idx_score (recommendation_score)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='취미 추천 기록 테이블';

-- 모임 모집 공고 테이블
CREATE TABLE IF NOT EXISTS meetup_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL COMMENT '모집 제목',
    description TEXT NOT NULL COMMENT '모집 내용',
    hobby_id INT NOT NULL,
    organizer_id VARCHAR(50) NOT NULL,
    location VARCHAR(100) COMMENT '모임 장소',
    meeting_date DATETIME COMMENT '모임 일시',
    max_participants INT DEFAULT 10 COMMENT '최대 참여 인원',
    current_participants INT DEFAULT 1 COMMENT '현재 참여 인원',
    status ENUM('모집중', '모집완료', '진행중', '완료', '취소') DEFAULT '모집중',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (hobby_id) REFERENCES hobbies(id) ON DELETE CASCADE,
    FOREIGN KEY (organizer_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_hobby_id (hobby_id),
    INDEX idx_organizer_id (organizer_id),
    INDEX idx_status (status),
    INDEX idx_meeting_date (meeting_date),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='모임 모집 공고 테이블';

-- 모임 참여자 테이블
CREATE TABLE IF NOT EXISTS meetup_participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    meetup_id INT NOT NULL,
    user_id VARCHAR(50) NOT NULL,
    joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('신청', '승인', '거절', '취소') DEFAULT '신청',
    FOREIGN KEY (meetup_id) REFERENCES meetup_posts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_participation (meetup_id, user_id),
    INDEX idx_meetup_id (meetup_id),
    INDEX idx_user_id (user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='모임 참여자 테이블';

-- 샘플 취미 데이터 삽입
INSERT IGNORE INTO hobbies (name, category, description, difficulty_level, activity_type, group_size, cost_level, physical_level) VALUES 
('축구', '운동', '팀워크와 체력을 기를 수 있는 대표적인 스포츠', '초급', '실외', '대그룹', '저비용', '높음'),
('농구', '운동', '실내외에서 즐길 수 있는 인기 스포츠', '초급', '혼합', '소그룹', '저비용', '높음'),
('요가', '운동', '몸과 마음의 균형을 찾는 운동', '초급', '실내', '소그룹', '중비용', '보통'),
('그림 그리기', '예술', '창의성과 표현력을 기를 수 있는 예술 활동', '초급', '실내', '개인', '중비용', '낮음'),
('기타 연주', '예술', '음악을 통해 감정을 표현하는 악기 연주', '중급', '실내', '개인', '중비용', '낮음'),
('독서', '학습', '지식과 교양을 쌓을 수 있는 활동', '초급', '실내', '개인', '저비용', '낮음'),
('요리', '생활', '맛있는 음식을 만들며 창의성을 발휘하는 활동', '초급', '실내', '소그룹', '중비용', '보통'),
('등산', '운동', '자연과 함께하며 체력을 기르는 활동', '중급', '실외', '소그룹', '저비용', '높음'),
('사진 촬영', '예술', '순간을 포착하고 아름다움을 기록하는 활동', '초급', '실외', '개인', '고비용', '보통'),
('게임', '취미', '다양한 장르의 게임을 즐기는 활동', '초급', '실내', '개인', '중비용', '낮음');

-- 샘플 모집 공고 데이터 (테스트용)
-- 실제 사용 시에는 user_id가 존재하는 사용자로 수정해야 합니다
-- INSERT IGNORE INTO meetup_posts (title, description, hobby_id, organizer_id, location, meeting_date, max_participants) VALUES 
-- ('주말 축구 모임 모집', '매주 토요일 오전 축구 모임에 참여하실 분들을 모집합니다.', 1, 'testuser', '서울 월드컵공원', '2024-12-15 10:00:00', 20);

-- 테스트 데이터 삽입 (선택사항)
-- 비밀번호는 'test123'으로 해시화된 값입니다.
INSERT IGNORE INTO users (id, password, name, nickname, email) VALUES 
('testuser', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', '테스트', '테스터', 'test@example.com');

-- 생성된 테이블 목록 확인
SHOW TABLES;

-- 데이터베이스 설정 완료 메시지
SELECT 'MOIT 데이터베이스 설정이 완료되었습니다!' as message;