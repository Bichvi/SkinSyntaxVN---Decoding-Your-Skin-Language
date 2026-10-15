// Shared by the AI Idol Studio and the admin notification bell: warns when a programme still
// awaiting script approval is close to (or past) its airtime.
(function (global) {
    'use strict';
    if (global.AiIdolApproval) return;
    // Mirrors the backend: video work starts only after approval (~8 min per product, min 5), and a
    // slot more than AI_IDOL_BROADCAST_LATE_GRACE_MINUTES (30) late is never aired automatically.
    const LATE_GRACE_MINUTES = 30;
    const APPROVAL_HEADS_UP_MINUTES = 30;
    const formatDate = value => new Date(value).toLocaleString('vi-VN', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'});
    const formatClock = value => {
        const date = new Date(value);
        return date.toDateString() === new Date().toDateString() ? date.toLocaleTimeString('vi-VN', {hour:'2-digit',minute:'2-digit'}) : formatDate(value);
    };
    function approvalWarning(item, now = Date.now()) {
        if (!item || item.status !== 'AWAITING_APPROVAL' || !item.scheduled_at) return null;
        const airtime = new Date(item.scheduled_at).getTime();
        if (!Number.isFinite(airtime)) return null;
        const products = Math.max(Number(item.progress?.total || item.product_ids?.length || 1), 1);
        const prep = Math.max(5, products * 8);
        const left = Math.floor((airtime - now) / 60000);
        if (left >= prep + APPROVAL_HEADS_UP_MINUTES) return null;
        if (left >= prep) {
            const deadline = airtime - prep * 60000;
            return {level: 'soon', short: `Duyệt trước ${formatClock(deadline)} để kịp giờ phát`,
                text: `Còn ${left} phút đến giờ phát nhưng kịch bản chưa được duyệt. Sau khi duyệt cần khoảng ${prep} phút để tạo video, hãy duyệt trước ${formatClock(deadline)}.`};
        }
        if (left + LATE_GRACE_MINUTES >= prep) {
            const lead = left > 0 ? `Chỉ còn ${left} phút đến giờ phát` : left === 0 ? 'Đã tới giờ phát' : `Đã quá giờ phát ${-left} phút`;
            return {level: 'urgent', short: 'Không kịp giờ phát · duyệt ngay',
                text: `${lead} mà kịch bản chưa được duyệt, trong khi cần khoảng ${prep} phút để tạo video. Duyệt ngay thì chương trình phát trễ khoảng ${prep - left} phút; hoặc đổi lịch.`};
        }
        return {level: 'missed', short: 'Lỡ giờ phát · cần đổi lịch',
            text: `Dù duyệt ngay, video vẫn xong muộn hơn giờ phát quá ${LATE_GRACE_MINUTES} phút nên chương trình sẽ không tự phát. Hãy đổi lịch rồi duyệt kịch bản.`};
    }
    global.AiIdolApproval = {approvalWarning, formatDate};
}(window));
