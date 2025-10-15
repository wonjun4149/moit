const mongoose = require('mongoose');

const surveyResultSchema = new mongoose.Schema({
  // 어떤 사용자의 결과인지 연결합니다.
  userId: {
    type: mongoose.Schema.Types.ObjectId,
    ref: 'User',
    required: true,
    unique: true, // 한 사용자는 하나의 결과만 가집니다.
  },
  // 사용자의 설문 답변을 저장합니다.
  answers: {
    type: Object,
    required: true,
  },
  // 답변을 바탕으로 추천된 취미 목록을 저장합니다.
  recommendations: {
    type: Array,
    required: true,
  },
}, { timestamps: true });

module.exports = mongoose.model('SurveyResult', surveyResultSchema);