<?php
// 디버깅 강화된 취미 추천 페이지
require_once 'config.php';

// 디버깅 로그 함수
function debug_log($message) {
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] HOBBY_DEBUG: $message");
    
    // 화면에도 표시 (개발 시에만)
    if (isset($_GET['debug'])) {
        echo "<div style='background: #f0f0f0; padding: 5px; margin: 2px; font-size: 12px;'>DEBUG: $message</div>";
    }
}

debug_log("페이지 로드 시작");

// 로그인 확인
if (!isLoggedIn()) {
    debug_log("로그인되지 않음 - 로그인 페이지로 리다이렉트");
    redirect('login.php');
}

debug_log("로그인 확인됨 - 사용자: " . $_SESSION['user_id']);

$site_title = "MOIT - 취미 추천";
$error_message = '';
$recommendations = [];
$popular_hobbies = [];
$meetup_posts = [];

// 데이터베이스 연결
try {
    debug_log("데이터베이스 연결 시도");
    $pdo = getDBConnection();
    debug_log("데이터베이스 연결 성공");
    
    // 인기 취미 가져오기 (추천 횟수 기준)
    $stmt = $pdo->query("
        SELECT h.*, COUNT(hr.hobby_id) as recommendation_count
        FROM hobbies h
        LEFT JOIN hobby_recommendations hr ON h.id = hr.hobby_id
        GROUP BY h.id
        ORDER BY recommendation_count DESC, h.name ASC
        LIMIT 10
    ");
    $popular_hobbies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    debug_log("인기 취미 " . count($popular_hobbies) . "개 로드됨");
    
} catch (PDOException $e) {
    debug_log("데이터베이스 에러: " . $e->getMessage());
    $error_message = '데이터를 불러오는 중 오류가 발생했습니다.';
}

// POST 데이터 로깅
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    debug_log("POST 요청 받음");
    debug_log("POST 데이터: " . print_r($_POST, true));
}

// 설문 제출 처리
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_survey'])) {
    debug_log("설문 제출 처리 시작");
    
    try {
        $activity_preference = $_POST['activity_preference'] ?? '';
        $physical_preference = $_POST['physical_preference'] ?? '';
        $group_preference = $_POST['group_preference'] ?? '';
        $cost_preference = $_POST['cost_preference'] ?? '';
        $time_preference = $_POST['time_preference'] ?? '';
        
        debug_log("설문 응답 값들:");
        debug_log("- activity_preference: '$activity_preference'");
        debug_log("- physical_preference: '$physical_preference'");
        debug_log("- group_preference: '$group_preference'");
        debug_log("- cost_preference: '$cost_preference'");
        debug_log("- time_preference: '$time_preference'");
        
        // 모든 값이 입력되었는지 확인
        if (empty($activity_preference) || empty($physical_preference) || empty($group_preference) || 
            empty($cost_preference) || empty($time_preference)) {
            debug_log("일부 응답이 누락됨");
            $error_message = '모든 질문에 답변해주세요.';
        } else {
            debug_log("모든 응답 완료 - 데이터베이스 저장 시작");
            
            // 설문 응답 저장
            $stmt = $pdo->prepare("
                INSERT INTO hobby_surveys (user_id, activity_preference, physical_preference, group_preference, cost_preference, time_preference) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([$_SESSION['user_id'], $activity_preference, $physical_preference, $group_preference, $cost_preference, $time_preference]);
            $survey_id = $pdo->lastInsertId();
            
            debug_log("설문 저장 결과: " . ($result ? "성공" : "실패"));
            debug_log("설문 ID: $survey_id");
            
            if (!$result) {
                debug_log("설문 저장 실패");
                throw new Exception("설문 저장에 실패했습니다.");
            }
            
            // 취미 추천 알고리즘
            debug_log("추천 알고리즘 시작");
            
            $where_conditions = [];
            $params = [];
            
            if ($activity_preference !== '상관없음') {
                $where_conditions[] = "(activity_type = ? OR activity_type = '혼합')";
                $params[] = $activity_preference;
            }
            
            if ($physical_preference !== '상관없음') {
                $where_conditions[] = "physical_level = ?";
                $params[] = $physical_preference;
            }
            
            if ($group_preference !== '상관없음') {
                $where_conditions[] = "(group_size = ? OR group_size = '상관없음')";
                $params[] = $group_preference;
            }
            
            if ($cost_preference !== '상관없음') {
                $where_conditions[] = "cost_level = ?";
                $params[] = $cost_preference;
            }
            
            $where_clause = empty($where_conditions) ? "" : "WHERE " . implode(" AND ", $where_conditions);
            debug_log("WHERE 절: $where_clause");
            debug_log("파라미터: " . print_r($params, true));
            
            $query = "
                SELECT *, 
                (CASE 
                    WHEN activity_type = ? OR activity_type = '혼합' THEN 0.3 ELSE 0
                END +
                CASE 
                    WHEN physical_level = ? THEN 0.3 ELSE 0
                END +
                CASE 
                    WHEN group_size = ? OR group_size = '상관없음' THEN 0.2 ELSE 0
                END +
                CASE 
                    WHEN cost_level = ? THEN 0.2 ELSE 0
                END) as score
                FROM hobbies $where_clause
                ORDER BY score DESC, name ASC
                LIMIT 6
            ";
            
            debug_log("실행할 쿼리: $query");
            
            $stmt = $pdo->prepare($query);
            $search_params = [$activity_preference, $physical_preference, $group_preference, $cost_preference];
            $all_params = array_merge($search_params, $params);
            debug_log("모든 파라미터: " . print_r($all_params, true));
            
            $stmt->execute($all_params);
            $recommendations = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            debug_log("추천 결과 개수: " . count($recommendations));
            
            if (count($recommendations) > 0) {
                debug_log("추천 취미들: " . implode(', ', array_column($recommendations, 'name')));
                
                // 추천 기록 저장
                foreach ($recommendations as $hobby) {
                    $stmt = $pdo->prepare("
                        INSERT INTO hobby_recommendations (user_id, hobby_id, survey_id, recommendation_score) 
                        VALUES (?, ?, ?, ?)
                    ");
                    $stmt->execute([$_SESSION['user_id'], $hobby['id'], $survey_id, $hobby['score']]);
                }
                debug_log("추천 기록 저장 완료");
            } else {
                debug_log("추천 결과가 없음");
            }
            
            debug_log("설문 처리 완료");
        }
        
    } catch (Exception $e) {
        debug_log("설문 처리 중 예외 발생: " . $e->getMessage());
        debug_log("스택 트레이스: " . $e->getTraceAsString());
        $error_message = '설문 처리 중 오류가 발생했습니다: ' . $e->getMessage();
    }
}

// 선택된 취미의 모집 공고 가져오기
if (isset($_GET['hobby_id'])) {
    try {
        $hobby_id = (int)$_GET['hobby_id'];
        debug_log("취미 ID $hobby_id 의 모집 공고 검색");
        
        $stmt = $pdo->prepare("
            SELECT mp.*, u.nickname as organizer_nickname, h.name as hobby_name
            FROM meetup_posts mp
            JOIN users u ON mp.organizer_id = u.id
            JOIN hobbies h ON mp.hobby_id = h.id
            WHERE mp.hobby_id = ? AND mp.status = '모집중'
            ORDER BY mp.created_at DESC
            LIMIT 10
        ");
        $stmt->execute([$hobby_id]);
        $meetup_posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        debug_log("모집 공고 " . count($meetup_posts) . "개 찾음");
    } catch (PDOException $e) {
        debug_log("모집 공고 검색 에러: " . $e->getMessage());
        $error_message = '모집 공고를 불러오는 중 오류가 발생했습니다.';
    }
}

debug_log("페이지 로드 완료 - 추천 결과 개수: " . count($recommendations));
?>

<!-- 나머지 HTML 코드는 동일 -->
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $site_title; ?></title>
    <link rel="stylesheet" href="../css/hobby_recommendation-style.css">
</head>
<body>
    <!-- 디버그 모드일 때 상태 표시 -->
    <?php if (isset($_GET['debug'])): ?>
        <div style="background: #fff3cd; padding: 10px; margin: 10px; border: 1px solid #ffeaa7;">
            <strong>디버그 모드</strong><br>
            POST 요청: <?php echo ($_SERVER['REQUEST_METHOD'] == 'POST') ? '✅' : '❌'; ?><br>
            설문 제출: <?php echo (isset($_POST['submit_survey'])) ? '✅' : '❌'; ?><br>
            추천 결과: <?php echo count($recommendations); ?>개<br>
            에러 메시지: <?php echo $error_message ?: '없음'; ?>
        </div>
    <?php endif; ?>

    <!-- 기존 HTML 계속... -->
    <nav class="navbar">
        <!-- 네비게이션 코드 -->
    </nav>

    <main class="main-container">
        <?php if ($error_message): ?>
            <div class="alert alert-error">
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>

        <div class="content-wrapper">
            <div class="left-section">
                <?php if (empty($recommendations)): ?>
                    <!-- 설문조사 폼 표시 -->
                    <p>설문을 진행해주세요...</p>
                <?php else: ?>
                    <!-- 추천 결과 표시 -->
                    <h2>추천 결과</h2>
                    <p><?php echo count($recommendations); ?>개의 취미를 추천드려요!</p>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // 폼 제출 시 디버그 정보
        document.getElementById('surveyForm')?.addEventListener('submit', function(e) {
            console.log('폼 제출 시도');
            console.log('폼 데이터:', new FormData(this));
            
            // 모든 라디오 버튼 체크
            const required_fields = ['physical_preference', 'activity_preference', 'group_preference', 'cost_preference', 'time_preference'];
            const missing_fields = [];
            
            required_fields.forEach(field => {
                const checked = document.querySelector(`input[name="${field}"]:checked`);
                if (!checked) {
                    missing_fields.push(field);
                }
            });
            
            if (missing_fields.length > 0) {
                console.error('누락된 필드:', missing_fields);
                alert('누락된 답변: ' + missing_fields.join(', '));
                e.preventDefault();
                return false;
            }
            
            console.log('모든 필드 완료 - 제출 진행');
        });
    </script>
</body>
</html>
