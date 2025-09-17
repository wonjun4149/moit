<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = $_POST['title'] ?? '';
    $description = $_POST['description'] ?? '';
    $category = $_POST['category'] ?? '';
    $location = $_POST['location'] ?? '';

    if (empty($title) || empty($category) || empty($location)) {
        http_response_code(400);
        echo json_encode(['error' => 'Title, category, and location are required.']);
        exit;
    }

    try {
        $pdo = getDBConnection();

        $sql = "
            SELECT id, title, description, category, location, max_members, image_path, 
                   (SELECT COUNT(*) FROM meeting_participants WHERE meeting_id = m.id) + 1 AS current_members
            FROM meetings m
            WHERE category = :category AND location = :location AND (
                title LIKE :new_title_keyword OR :new_title LIKE CONCAT('%', title, '%')
            )
        ";

        $params = [
            'category' => $category,
            'location' => $location,
            'new_title' => $title,
            'new_title_keyword' => '%' . $title . '%'
        ];

        // Exclude the meeting being created if it has an ID (for future edits)
        if (isset($_POST['meeting_id'])) {
            $sql .= " AND id != :meeting_id";
            $params['meeting_id'] = $_POST['meeting_id'];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $similar_meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($similar_meetings);

    } catch (PDOException $e) {
        http_response_code(500);
        error_log("Similar meeting check error: " . $e->getMessage());
        echo json_encode(['error' => 'Database error while checking for similar meetings.']);
    }

} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>