const express = require('express');
const router = express.Router();
const Meeting = require('../models/Meeting');
const { verifyToken } = require('../utils/auth');
const axios = require('axios');

const AI_AGENT_URL = 'http://127.0.0.1:8000';

// --- (GET, DELETE 등 다른 라우터는 변경 없음) ---
// 모든 모임 목록 조회
router.get('/', async (req, res) => {
    try {
        const meetings = await Meeting.find()
            .populate('host', 'nickname')
            .populate('participants')
            .sort({ createdAt: -1 });
        res.json(meetings);
    } catch (error) {
        res.status(500).json({ message: '서버 오류가 발생했습니다.' });
    }
});

// 마감 임박 모임 조회 API
router.get('/closing-soon', async (req, res) => {
    try {
        const now = new Date();
        const meetings = await Meeting.aggregate([
            {
                $match: {
                    date: { $gte: now }, 
                    $expr: { $lt: [{ $size: "$participants" }, "$maxParticipants"] }
                }
            },
            { $sort: { date: 1 } },
            { $limit: 4 }
        ]);

        const populatedMeetings = await Meeting.populate(meetings, [
            { path: 'host', select: 'nickname' },
            { path: 'participants', select: 'nickname' }
        ]);

        res.json(populatedMeetings);
    } catch (error) {
        console.error("마감 임박 모임 조회 에러:", error);
        res.status(500).json({ message: '서버 오류가 발생했습니다.' });
    }
});


// 특정 모임 상세 정보 조회 API
router.get('/:id', async (req, res) => {
    try {
        const meetingId = req.params.id;
        const meeting = await Meeting.findById(meetingId)
            .populate('host', 'nickname avatar') 
            .populate('participants', 'nickname avatar');

        if (!meeting) {
            return res.status(404).json({ message: '모임을 찾을 수 없습니다.' });
        }

        const similarMeetings = await Meeting.find({
            category: meeting.category,
            _id: { $ne: meetingId }
        })
        .limit(3)
        .populate('host', 'nickname');

        res.json({ ...meeting.toObject(), similarMeetings });

    } catch (error) {
        console.error(`Error fetching meeting ${req.params.id}:`, error);
        res.status(500).json({ message: '서버 오류가 발생했습니다.' });
    }
});


/**
 * ------------------------------------------------------------------
 * [수정] POST / - 새로운 모임 생성 (호스트가 본인인 모임 추천 제외)
 * ------------------------------------------------------------------
 */
router.post('/', verifyToken, async (req, res) => {
    const { title, description, coverImage, category, location, date, maxParticipants } = req.body;
    const host = req.user.userId; // 현재 로그인한 사용자(호스트) ID

    try {
        const agentResponse = await axios.post(`${AI_AGENT_URL}/agent/invoke`, {
            user_input: {
                title,
                description,
                time: new Date(date).toLocaleString('ko-KR'),
                location
            }
        });

        const recommendations = JSON.parse(agentResponse.data.final_answer);

        if (recommendations && recommendations.recommendations.length > 0) {
            
            // 👇 --- [수정] 추천 목록에서 본인이 호스트인 모임은 제외하는 로직 --- 👇
            const recommendedIds = recommendations.recommendations.map(rec => rec.meeting_id);
            const recommendedMeetingsFromDB = await Meeting.find({ '_id': { $in: recommendedIds } });

            const filteredRecs = recommendations.recommendations.filter(rec => {
                const meeting = recommendedMeetingsFromDB.find(m => m._id.toString() === rec.meeting_id);
                // DB에서 찾은 모임의 호스트 ID와 현재 사용자 ID가 다를 경우에만 포함
                return meeting && meeting.host.toString() !== host;
            });
            // ----------------------------------------------------------------

            // 필터링 후에도 추천할 모임이 남아있다면
            if (filteredRecs.length > 0) {
                console.log('AI가 추천한 모임 (본인 모임 제외):', filteredRecs);
                return res.status(200).json({
                    action: 'recommend',
                    recommendations: { // 원본 구조 유지
                        summary: recommendations.summary,
                        recommendations: filteredRecs
                    },
                    newMeetingData: req.body
                });
            }
        }
        
        console.log('AI가 유사 모임을 찾지 못했거나, 본인 모임만 추천되어 신규 모임을 생성합니다.');
        const newMeeting = new Meeting({
            title, description, coverImage, category, location, date, maxParticipants, host,
            participants: [host]
        });

        const savedMeeting = await newMeeting.save();
        
        try {
            await axios.post(`${AI_AGENT_URL}/meetings/add`, {
                meeting_id: savedMeeting._id.toString(),
                title: savedMeeting.title,
                description: savedMeeting.description,
                time: new Date(savedMeeting.date).toLocaleString('ko-KR'),
                location: savedMeeting.location
            });
            console.log(`Pinecone에 모임(ID: ${savedMeeting._id}) 추가 요청 성공.`);
        } catch (aiError) {
            console.error("AI 서버(Pinecone)에 모임 추가 중 오류:", aiError.message);
        }
        
        res.status(201).json({
            action: 'created',
            meeting: savedMeeting
        });

    } catch (error) {
        console.error("모임 생성/추천 과정에서 에러 발생:", error);
        res.status(500).json({ message: '모임 생성 중 서버 오류가 발생했습니다.' });
    }
});


// "무시하고 생성" 요청을 처리하는 API (변경 없음)
router.post('/force-create', verifyToken, async (req, res) => {
    try {
        console.log('AI 추천 무시하고 강제 생성을 요청받았습니다.');
        const { title, description, coverImage, category, location, date, maxParticipants } = req.body;
        const host = req.user.userId;

        const newMeeting = new Meeting({
            title, description, coverImage, category, location, date, maxParticipants, host,
            participants: [host]
        });

        const savedMeeting = await newMeeting.save();

        try {
            await axios.post(`${AI_AGENT_URL}/meetings/add`, {
                meeting_id: savedMeeting._id.toString(),
                title: savedMeeting.title,
                description: savedMeeting.description,
                time: new Date(savedMeeting.date).toLocaleString('ko-KR'),
                location: savedMeeting.location
            });
            console.log(`Pinecone에 강제 생성된 모임(ID: ${savedMeeting._id}) 추가 요청 성공.`);
        } catch (aiError) {
            console.error("AI 서버(Pinecone)에 모임 추가 중 오류:", aiError.message);
        }

        res.status(201).json({ meeting: savedMeeting });

    } catch (error) {
        console.error("모임 강제 생성 에러:", error);
        res.status(400).json({ message: '모임 생성에 실패했습니다.', error: error.message });
    }
});

// --- (DELETE, 참여/취소 등 다른 라우터는 변경 없음) ---
// 모임 삭제 API
router.delete('/:id', verifyToken, async (req, res) => {
    try {
        const meetingId = req.params.id;
        const meeting = await Meeting.findById(meetingId);

        if (!meeting) {
            return res.status(404).json({ message: '모임을 찾을 수 없습니다.' });
        }

        if (meeting.host.toString() !== req.user.userId) {
            return res.status(403).json({ message: '모임을 삭제할 권한이 없습니다.' });
        }

        try {
            console.log('AI 서버에 Pinecone 데이터 삭제를 요청합니다...');
            await axios.delete(`${AI_AGENT_URL}/meetings/delete/${meetingId}`);
            console.log(`Pinecone에 모임(ID: ${meetingId}) 삭제 요청 성공.`);
        } catch (aiError) {
            console.error("AI 서버(Pinecone)에서 모임 정보를 삭제하는 중 오류 발생:", aiError.message);
        }

        await Meeting.findByIdAndDelete(meetingId);
        
        res.json({ message: '모임이 성공적으로 삭제되었습니다.' });

    } catch (error) {
        console.error("모임 삭제 에러:", error);
        res.status(500).json({ message: '서버 오류가 발생했습니다.' });
    }
});

// 모임 참여 신청 API
router.post('/:id/join', verifyToken, async (req, res) => {
    try {
        const meeting = await Meeting.findById(req.params.id);
        if (!meeting) {
            return res.status(404).json({ message: '모임을 찾을 수 없습니다.' });
        }
        if (meeting.participants.length >= meeting.maxParticipants) {
            return res.status(400).json({ message: '모집 인원이 가득 찼습니다.' });
        }
        if (meeting.participants.includes(req.user.userId)) {
            return res.status(400).json({ message: '이미 참여하고 있는 모임입니다.' });
        }
        meeting.participants.push(req.user.userId);
        await meeting.save();
        
        const updatedMeeting = await Meeting.findById(req.params.id)
            .populate('host', 'nickname')
            .populate('participants', 'nickname');

        res.json({ message: '모임 참여 신청이 완료되었습니다.', meeting: updatedMeeting });
    } catch (error) {
        console.error("모임 참여 에러:", error);
        res.status(500).json({ message: '서버 오류가 발생했습니다.' });
    }
});

// 모임 참여 취소 API
router.post('/:id/leave', verifyToken, async (req, res) => {
    try {
        const meeting = await Meeting.findById(req.params.id);
        if (!meeting) {
            return res.status(404).json({ message: '모임을 찾을 수 없습니다.' });
        }
        if (meeting.host.toString() === req.user.userId) {
            return res.status(400).json({ message: '호스트는 모임을 떠날 수 없습니다. 모임을 삭제해주세요.' });
        }
        const participantIndex = meeting.participants.indexOf(req.user.userId);
        if (participantIndex === -1) {
            return res.status(400).json({ message: '참여하고 있는 모임이 아닙니다.' });
        }
        meeting.participants.splice(participantIndex, 1);
        await meeting.save();
        
        const updatedMeeting = await Meeting.findById(req.params.id)
            .populate('host', 'nickname')
            .populate('participants', 'nickname');
            
        res.json({ message: '모임 참여가 취소되었습니다.', meeting: updatedMeeting });
    } catch (error) {
        console.error("모임 나가기 에러:", error);
        res.status(500).json({ message: '서버 오류가 발생했습니다.' });
    }
});

module.exports = router;