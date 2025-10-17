<?php
require_once 'config.php';

// 로그인 및 세션 데이터 확인
if (!isLoggedIn() || !isset($_SESSION['recommendations'])) {
    redirect('meeting.php');
}

$reco_data = $_SESSION['recommendations'];
$summary = htmlspecialchars($reco_data['summary'] ?? 'AI가 추천 내용을 생성하지 못했습니다.');
$recommendations = $reco_data['recommendations'] ?? [];

?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>유사 모임 추천</title>
    <link rel="stylesheet" href="../css/style.css"> 
    <style>
        body { font-family: sans-serif; background-color: #f4f4f9; color: #333; }
        .container { max-width: 800px; margin: 40px auto; padding: 20px; background-color: #fff; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1, h2 { color: #0056b3; }
        .summary { background-color: #e9f5ff; padding: 15px; border-left: 5px solid #007bff; border-radius: 5px; margin-bottom: 20px; }
        .recommendation-list .item { border-bottom: 1px solid #eee; padding: 15px 0; }
        .recommendation-list .item:last-child { border-bottom: none; }
        .item-title { font-weight: bold; font-size: 1.2em; color: #333; }
        .btn-group { margin-top: 30px; display: flex; justify-content: space-between; align-items: center; }
        .btn { display: inline-block; padding: 12px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; text-align: center; }
        .btn-join { background-color: #28a745; color: white; }
        .btn-create { background-color: #007bff; color: white; }
        .btn-back { background-color: #6c757d; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <h1>유사한 모임이 이미 존재합니다!</h1>
        <p>새로운 모임을 만드는 대신, 아래 모임에 참여해 보시는 건 어떠세요?</p>
        
        <div class="summary">
            <p><?= nl2br($summary) ?></p>
        </div>

        <h2>추천 모임 목록</h2>
        <div class="recommendation-list">
            <?php if (empty($recommendations)): ?>
                <p>추천할 모임이 없습니다.</p>
            <?php else: ?>
                <?php foreach ($recommendations as $rec): ?>
                    <div class="item">
                        <span class="item-title"><?= htmlspecialchars($rec['title']) ?></span>
                        <a href="join_meeting.php?id=<?= urlencode($rec['meeting_id']) ?>" class="btn btn-join" style="float: right;">참여하기</a>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="btn-group">
            <a href="create_confirmed.php" class="btn btn-create">무시하고 새로 만들기</a>
            <a href="meeting.php" class="btn btn-back">모임 목록으로 돌아가기</a>
        </div>
    </div>
</body>
</html>
