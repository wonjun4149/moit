const jwt = require('jsonwebtoken');

const verifyToken = (req, res, next) => {
    const token = req.cookies.token;
    if (!token) {
        return res.status(401).json({ message: '인증이 필요합니다. 로그인해주세요.' });
    }
    try {
        const decoded = jwt.verify(token, process.env.JWT_SECRET);
        req.user = decoded; // 요청 객체에 사용자 정보(userId, username 등) 추가
        next();
    } catch (error) {
        return res.status(401).json({ message: '유효하지 않은 토큰입니다.' });
    }
};

module.exports = { verifyToken };