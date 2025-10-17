<?php
require_once 'config.php';

function getMeetings($sort_order = 'latest') {
    $current_user_id = $_SESSION['user_id'] ?? null;
    $pdo = getDBConnection();

    $order_by_sql = 'm.created_at DESC'; // 기본값: 최신순
    if ($sort_order === 'deadline') {
        // 마감 임박순: 날짜가 빠른 순, 같은 날짜면 시간이 빠른 순
        $order_by_sql = 'm.meeting_date ASC, m.meeting_time ASC';
    }

    $sql = "
        SELECT 
            m.id, m.title, m.description, m.category, m.location, 
            m.max_members, m.image_path, m.created_at, m.organizer_id,
            m.meeting_date, m.meeting_time,
            u.nickname AS organizer_nickname,
            (SELECT COUNT(*) FROM meeting_participants mp WHERE mp.meeting_id = m.id) AS current_members_count,
            (CASE 
                WHEN EXISTS (
                    SELECT 1 FROM meeting_participants mp 
                    WHERE mp.meeting_id = m.id AND mp.user_id = :current_user_id
                ) THEN 1
                ELSE 0 
            END) AS is_joined
        FROM meetings m
        JOIN users u ON m.organizer_id = u.id
        WHERE CONCAT(m.meeting_date, ' ', m.meeting_time) >= NOW()
        ORDER BY $order_by_sql
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['current_user_id' => $current_user_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$sort = $_GET['sort'] ?? 'latest';
$meetings = getMeetings($sort);

header('Content-Type: application/json');
echo json_encode($meetings);
?>