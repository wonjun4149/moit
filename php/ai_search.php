<?php
require_once 'config.php';

// 응답을 JSON으로 설정
header('Content-Type: application/json');

// POST 요청만 허용
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method.']);
    exit;
}

// JSON 요청 본문 읽기
$json_data = file_get_contents('php://input');
$data = json_decode($json_data, true);

$query = $data['query'] ?? null;

if (empty($query)) {
    echo json_encode(['success' => false, 'error' => '검색어가 비어있습니다.']);
    exit;
}

// AI 에이전트가 기대하는 형식으로 데이터 구성
$agent_input = [
    'user_input' => [
        'messages' => [
            ['user', $query]
        ]
    ]
];

$ch = curl_init('http://127.0.0.1:8000/agent/invoke');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($agent_input));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
curl_setopt($ch, CURLOPT_TIMEOUT, 60); // AI가 생각할 시간을 넉넉하게 60초로 설정

$agent_response_json = curl_exec($ch);

if (curl_errno($ch)) {
    $error_msg = curl_error($ch);
    curl_close($ch);
    echo json_encode(['success' => false, 'error' => 'AI 에이전트 호출에 실패했습니다: ' . $error_msg]);
    exit;
}

curl_close($ch);

$response_data = json_decode($agent_response_json, true);
$final_answer = $response_data['final_answer'] ?? 'AI로부터 답변을 받지 못했습니다. 다시 시도해주세요.';

echo json_encode(['success' => true, 'answer' => $final_answer]);
?>