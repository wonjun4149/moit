const express = require("express");
const router = express.Router();
const Contact = require("../models/Contact");
const jwt = require("jsonwebtoken");

// --- JWT 인증 미들웨어 ---
// 토큰을 확인하여 관리자만 특정 API를 사용할 수 있도록 합니다.
const authenticateToken = (req, res, next) => {
  const token = req.cookies.token;

  if (!token) {
    return res.status(401).json({ message: "인증 토큰이 없습니다. 로그인해주세요." });
  }

  try {
    const decoded = jwt.verify(token, process.env.JWT_SECRET);
    req.user = decoded; // 요청 객체에 사용자 정보 추가
    next(); // 다음 미들웨어 또는 라우트 핸들러로 이동
  } catch (error) {
    return res.status(403).json({ message: "토큰이 유효하지 않습니다." });
  }
};

// --- API 라우트 ---

// POST /api/contact - 문의 등록 (누구나 가능)
router.post("/", async (req, res) => {
  try {
    const { name, email, phone, message } = req.body;

    if (!name || !email || !phone || !message) {
      return res.status(400).json({ message: "모든 필수 필드를 입력해주세요." });
    }

    const contact = new Contact({ name, email, phone, message });
    await contact.save();

    res.status(201).json({ message: "문의가 성공적으로 접수되었습니다." });
  } catch (error) {
    console.error("Contact form error:", error);
    res.status(500).json({ message: "서버 오류가 발생했습니다." });
  }
});

// GET /api/contact - 모든 문의 조회 (관리자만 가능)
router.get("/", authenticateToken, async (req, res) => {
  try {
    const contacts = await Contact.find().sort({ createdAt: -1 });
    res.json(contacts);
  } catch (error) {
    console.error("Get contacts error:", error);
    res.status(500).json({ message: "서버 오류가 발생했습니다." });
  }
});

// GET /api/contact/:id - 특정 문의 조회 (관리자만 가능)
router.get("/:id", authenticateToken, async (req, res) => {
  try {
    const contact = await Contact.findById(req.params.id);
    if (!contact) {
      return res.status(404).json({ message: "문의를 찾을 수 없습니다." });
    }
    res.json(contact);
  } catch (error) {
    console.error("Get single contact error:", error);
    res.status(500).json({ message: "서버 오류가 발생했습니다." });
  }
});

// PUT /api/contact/:id - 문의 상태 수정 (관리자만 가능)
router.put("/:id", authenticateToken, async (req, res) => {
  try {
    const { status } = req.body;

    // 상태 값이 유효한지 다시 한번 확인
    const allowedStatus = ['pending', 'in progress', 'completed', '대기중', '진행중', '완료'];
    if (!status || !allowedStatus.includes(status)) {
        return res.status(400).json({ message: '유효하지 않은 상태 값입니다.' });
    }
    
    const contact = await Contact.findByIdAndUpdate(
      req.params.id,
      { status },
      { new: true }
    );

    if (!contact) {
      return res.status(404).json({ message: "문의를 찾을 수 없습니다." });
    }

    res.json({ message: "문의 상태가 성공적으로 수정되었습니다.", contact });
  } catch (error) {
    console.error("Update status error:", error);
    res.status(500).json({ message: "서버 오류가 발생했습니다." });
  }
});

// DELETE /api/contact/:id - 문의 삭제 (관리자만 가능)
router.delete("/:id", authenticateToken, async (req, res) => {
  try {
    const contact = await Contact.findByIdAndDelete(req.params.id);

    if (!contact) {
      return res.status(404).json({ message: "문의를 찾을 수 없습니다." });
    }
    res.json({ message: "문의가 성공적으로 삭제되었습니다." });
  } catch (error) {
    console.error("Delete contact error:", error);
    res.status(500).json({ message: "서버 오류가 발생했습니다." });
  }
});

module.exports = router;
