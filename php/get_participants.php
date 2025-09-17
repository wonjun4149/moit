<?php
require_once 'config.php';

if (isset($_GET['meeting_id'])) {
    $meeting_id = $_GET['meeting_id'];
    try {
        $pdo = getDBConnection();
        $sql = "
            SELECT u.nickname 
            FROM users u
            JOIN meeting_participants mp ON u.id = mp.user_id
            WHERE mp.meeting_id = :meeting_id
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['meeting_id' => $meeting_id]);
        $participants = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // 개설자 닉네임도 참여자 목록에 추가
        $sql_organizer = "
            SELECT u.nickname 
            FROM users u
            JOIN meetings m ON u.id = m.organizer_id
            WHERE m.id = :meeting_id
        ";
        $stmt_organizer = $pdo->prepare($sql_organizer);
        $stmt_organizer->execute(['meeting_id' => $meeting_id]);
        $organizer_nickname = $stmt_organizer->fetchColumn();

        if ($organizer_nickname) {
            // 참여자 목록에 개설자 추가 (이미 참여자로 등록되지 않은 경우)
            if (!in_array($organizer_nickname, $participants)) {
                array_unshift($participants, $organizer_nickname . ' (개설자)');
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode($participants);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['error' => 'Meeting ID is required']);
}
?>