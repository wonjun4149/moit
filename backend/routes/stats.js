const express = require('express');
const router = express.Router();
const Meeting = require('../models/Meeting');
const User = require('../models/User');

router.get('/', async (req, res) => {
    try {
        // 1. 총 모임 수 계산
        const totalMeetings = await Meeting.countDocuments();

        // 2. 가장 인기 있는 카테고리 찾기
        const popularCategoryAgg = await Meeting.aggregate([
            { $group: { _id: '$category', count: { $sum: 1 } } },
            { $sort: { count: -1 } },
            { $limit: 1 }
        ]);
        const popularCategory = popularCategoryAgg.length > 0 ? popularCategoryAgg[0]._id : '아직 없어요';

        // 3. 최근 일주일간 가입한 새 멤버 수 계산
        const oneWeekAgo = new Date();
        oneWeekAgo.setDate(oneWeekAgo.getDate() - 7);
        const newUsersThisWeek = await User.countDocuments({ createdAt: { $gte: oneWeekAgo } });

        res.json({
            totalMeetings,
            popularCategory,
            newUsersThisWeek
        });

    } catch (error) {
        console.error("통계 데이터 조회 에러:", error);
        res.status(500).json({ message: '서버 오류가 발생했습니다.' });
    }
});

module.exports = router;