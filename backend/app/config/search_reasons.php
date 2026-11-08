<?php
// Copy hiển thị cho khối gợi ý khi tìm kiếm ít/không có kết quả (Smart Search Bước 06).
// Không ghi tên hãng kiểu "shop chưa có sản phẩm của X". Escape ở view, không escape ở đây.
return [
    'sections' => [
        'category_alt' => 'Lựa chọn tương đương cùng công dụng',
        'same_brand'   => 'Cùng dòng sản phẩm bạn đang quan tâm',
        'personalized' => 'Hợp với làn da của bạn',
        'semantic'     => 'Có thể bạn đang tìm',
        'trending'     => 'Được mua nhiều tuần này',
        'best_sellers' => 'Sản phẩm bán chạy', // fallback PHP
        'similar'      => 'Sản phẩm tương tự', // U08: trang chi tiết sản phẩm
        'routine'      => 'Hoàn thiện routine của bạn', // U09: giỏ hàng
        'conflict'     => 'Cảnh báo hoạt chất', // U09: giỏ hàng
    ],
    'reasons' => [
        'shared_ingredients' => 'Chung hoạt chất %s',
        'fits_skin_type'     => 'Hợp %s',
        'targets_concern'    => 'Hỗ trợ %s',
        'same_category'      => 'Cùng công dụng',
        'same_brand'         => 'Cùng dòng sản phẩm',
        'semantic_match'     => 'Gần với từ khóa',
        'trending'           => 'Bán chạy',
        'bought_together'    => 'Thường mua cùng',
        'completes_routine'  => 'Hoàn thiện routine: %s', // U09
    ],
    'labels' => [
        'da_kho'      => 'da khô',
        'da_dau'      => 'da dầu',
        'da_hon_hop'  => 'da hỗn hợp',
        'da_thuong'   => 'da thường',
        'da_nhay_cam' => 'da nhạy cảm',
        // param của fits_skin_type là loại da trong hồ sơ khách; mã 2 "Da thường/Mọi loại da" -> "Hợp da thường"
        // đọc đúng, còn "Hợp mọi loại da" sẽ mô tả sai sản phẩm.
        'moi_loai_da' => 'da thường',
        'da_mun'      => 'da mụn',
        'mun'         => 'da mụn',
        'tham_nam'    => 'thâm nám',
        'lao_hoa'     => 'chống lão hóa',
        'lo_chan_long' => 'lỗ chân lông',
        'cap_am'      => 'cấp ẩm',
        'sang_da'     => 'sáng da',
        'kiem_dau'    => 'kiềm dầu',
        'phuc_hoi'    => 'phục hồi da',
    ],
];
