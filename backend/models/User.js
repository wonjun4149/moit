const mongoose = require('mongoose');

const userSchema = new mongoose.Schema(
  {
    username: { // 아이디
      type: String, required: true, trim: true,
      lowercase: true, minlength: 4, unique: true
    },
    password: { type: String, required: true, select: false },
    name: { type: String, required: true, trim: true }, // <<< 추가: 이름
    nickname: { type: String, required: true, trim: true, unique: true }, // <<< 추가: 닉네임
    email: { type: String, required: true, trim: true, unique: true }, // <<< 추가: 이메일
    
    // --- 기존 필드 ---
    isLoggedIn: { type: Boolean, default: false },
    isActive: { type: Boolean, default: true },
    failedLoginAttempts: { type: Number, default: 0 },
    lastLoginAttempt: { type: Date },
    ipAddress: { type: String, trim: true },
    surveyResult: {
      type: mongoose.Schema.Types.ObjectId,
      ref: 'SurveyResult'
    }
  },
  { timestamps: true }
);

module.exports = mongoose.model('User', userSchema);