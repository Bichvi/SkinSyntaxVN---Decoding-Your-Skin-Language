<?php
// Dữ liệu soạn sẵn cho khối "Khám phá nhanh" trang chủ (dự phòng khi Redis chưa có đủ từ khóa phổ biến).
return [
    'trending' => ['kem chống nắng', 'serum', 'sữa rửa mặt', 'retinol', 'trị mụn', 'dưỡng ẩm', 'tẩy trang', 'niacinamide'],
    'actives' => [
        ['q' => 'niacinamide', 'name' => 'Niacinamide', 'desc' => 'Mờ thâm, se lỗ chân lông'],
        ['q' => 'bha', 'name' => 'BHA', 'desc' => 'Thông thoáng lỗ chân lông, giảm mụn'],
        ['q' => 'aha', 'name' => 'AHA', 'desc' => 'Tẩy tế bào chết, da mịn sáng'],
        ['q' => 'retinol', 'name' => 'Retinol', 'desc' => 'Chống lão hóa, cải thiện nếp nhăn'],
        ['q' => 'vitamin c', 'name' => 'Vitamin C', 'desc' => 'Sáng da, chống oxy hóa'],
        ['q' => 'hyaluronic', 'name' => 'Hyaluronic Acid', 'desc' => 'Cấp ẩm sâu'],
        ['q' => 'ceramide', 'name' => 'Ceramide', 'desc' => 'Phục hồi hàng rào da'],
        ['q' => 'rau má', 'name' => 'Rau má', 'desc' => 'Làm dịu da kích ứng'],
    ],
];
