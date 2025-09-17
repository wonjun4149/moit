<?php
  require_once 'config.php';

  // 1. 로그인 상태 확인
  if (!isLoggedIn()) {
      redirect('login.php?error=login_required');
  }

  $meeting_id = null;

  // 2. POST 또는 GET 방식으로 meeting_id가 전송되었는지 확인
  if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['meeting_id'])) {
      $meeting_id = (int)$_POST['meeting_id'];
  } elseif ($_SERVER['REQUEST_METHOD'] == 'GET' && isset($_GET['id'])) {
      $meeting_id = (int)$_GET['id'];
  }

  // meeting_id가 유효한 경우에만 로직 실행
  if ($meeting_id) {
      $user_id = $_SESSION['user_id'];

      try {
          $pdo = getDBConnection();

          // 3. 유효성 검사
          // 3-1. 모임 정보 및 현재 참여 인원 확인
          $stmt = $pdo->prepare("
              SELECT m.organizer_id, m.max_members,
                     (SELECT COUNT(*) FROM meeting_participants mp WHERE mp.meeting_id = m.id) + 1 as current_participants
              FROM meetings m WHERE m.id = ?
          ");
          $stmt->execute([$meeting_id]);
          $meeting = $stmt->fetch();

          if (!$meeting) { die("존재하지 않는 모임입니다."); }

          // 3-2. 본인이 개설한 모임인지 확인
          if ($meeting['organizer_id'] == $user_id) {
              die("본인이 개설한 모임에는 참여할 수 없습니다. <a href='meeting.php'>돌아가기</a>");
          }

          // 3-3. 모임 정원이 꽉 찼는지 확인
          if ($meeting['current_participants'] >= $meeting['max_members']) {
              die("모임 정원이 모두 찼습니다. <a href='meeting.php'>돌아가기</a>");
          }

          // 3-4. 이미 참여한 모임인지 확인
          $stmt = $pdo->prepare("SELECT id FROM meeting_participants WHERE meeting_id = ? AND user_id = ?");
          $stmt->execute([$meeting_id, $user_id]);
          if ($stmt->fetch()) {
              die("이미 참여 신청한 모임입니다. <a href='meeting.php'>돌아가기</a>");
          }

          // 4. 모든 검사를 통과하면 참여자로 등록
          $stmt = $pdo->prepare("INSERT INTO meeting_participants (meeting_id, user_id) VALUES (?, ?)");
          $stmt->execute([$meeting_id, $user_id]);

          // 5. 성공 후 모임 목록 페이지로 리다이렉트
          redirect('meeting.php?join_success=1');

      } catch (PDOException $e) {
          die("데이터베이스 오류: " . $e->getMessage());
      }
  } else {
      // meeting_id가 없는 비정상적인 접근일 경우
      redirect('meeting.php');
  }
  ?>