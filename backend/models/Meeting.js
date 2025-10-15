const mongoose = require('mongoose');

const meetingSchema = new mongoose.Schema({
    title: { type: String, required: true, trim: true },
    description: { type: String, required: true },
    coverImage: { type: String }, // 필수 항목이 아님
    category: { type: String, required: true },
    location: { type: String, required: true },
    date: { type: Date, required: true },
    maxParticipants: { type: Number, required: true, min: 2 },
    host: {
        type: mongoose.Schema.Types.ObjectId,
        ref: 'User',
        required: true
    },
    participants: [{
        type: mongoose.Schema.Types.ObjectId,
        ref: 'User'
    }],
}, { timestamps: true });

meetingSchema.path('participants').validate(function(value) {
    return value.length <= this.maxParticipants;
}, '참여 인원이 가득 찼습니다.');

const Meeting = mongoose.model('Meeting', meetingSchema);

module.exports = Meeting;