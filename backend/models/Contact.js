const mongoose = require('mongoose');

const contactSchema = new mongoose.Schema({
  name: { type: String, required: true, trim: true },
  email: { type: String, required: true, trim: true },
  phone: { type: String, required: true, trim: true },
  message: { type: String, required: true },
  status: {
    type: String,
    // 허용되는 값 목록에 영문 상태를 추가하여 문제를 해결합니다.
    enum: ['대기중', '진행중', '완료', 'pending', 'in progress', 'completed'],
    default: '대기중',
  },
}, { timestamps: true });

module.exports = mongoose.model('Contact', contactSchema);

