<?php
require_once __DIR__ . '/../app/config/db.php';
global $db;

function summarizeExplain($explainResult): array {
    $raw = json_decode(json_encode($explainResult), true);
    $execStats = $raw['executionStats'] ?? [];
    $stages = [];

    $collectStages = function($stage) use (&$collectStages, &$stages) {
        if (!is_array($stage)) return;
        if (isset($stage['stage'])) $stages[] = $stage['stage'];
        if (isset($stage['inputStage'])) $collectStages($stage['inputStage']);
        if (isset($stage['inputStages'])) {
            foreach ($stage['inputStages'] as $s) $collectStages($s);
        }
    };

    if (isset($execStats['executionStages'])) {
        $collectStages($execStats['executionStages']);
    }

    $hasInMemorySort = in_array('SORT', $stages, true);
    $scanType = in_array('IXSCAN', $stages, true) ? 'IXSCAN' : (in_array('COLLSCAN', $stages, true) ? 'COLLSCAN' : implode(',', $stages));

    return [
        'scanType' => $scanType,
        'stages' => $stages,
        'nReturned' => $execStats['nReturned'] ?? 0,
        'executionTimeMillis' => $execStats['executionTimeMillis'] ?? 0,
        'totalKeysExamined' => $execStats['totalKeysExamined'] ?? 0,
        'totalDocsExamined' => $execStats['totalDocsExamined'] ?? 0,
        'hasInMemorySort' => $hasInMemorySort
    ];
}

$activeFilter = ['trang_thai' => ['$nin' => ['inactive', 'hidden', 'tam_an', 'taman', 'disabled', 'off', '0']]];

$queries = [
    'Q1_flash_deals' => [
        'collection' => 'san_pham',
        'command' => [
            'explain' => [
                'find' => 'san_pham',
                'filter' => [
                    '$and' => [
                        $activeFilter,
                        ['$or' => [['phan_tram_giam' => ['$gt' => 0]], ['tien_tiet_kiem' => ['$gt' => 0]]]]
                    ]
                ],
                'sort' => ['phan_tram_giam' => -1, 'tien_tiet_kiem' => -1, 'ma_san_pham' => -1],
                'limit' => 8
            ],
            'verbosity' => 'executionStats'
        ]
    ],
    'Q2_best_sellers_homepage' => [
        'collection' => 'san_pham',
        'command' => [
            'explain' => [
                'find' => 'san_pham',
                'filter' => $activeFilter,
                'sort' => ['so_luong_da_ban' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1],
                'limit' => 8
            ],
            'verbosity' => 'executionStats'
        ]
    ],
    'Q3_most_viewed' => [
        'collection' => 'san_pham',
        'command' => [
            'explain' => [
                'find' => 'san_pham',
                'filter' => $activeFilter,
                'sort' => ['luot_xem' => -1, 'ma_san_pham' => -1],
                'limit' => 8
            ],
            'verbosity' => 'executionStats'
        ]
    ],
    'Q4_top_rated' => [
        'collection' => 'san_pham',
        'command' => [
            'explain' => [
                'find' => 'san_pham',
                'filter' => $activeFilter,
                'sort' => ['diem_danh_gia' => -1, 'so_luong_danh_gia' => -1, 'ma_san_pham' => -1],
                'limit' => 8
            ],
            'verbosity' => 'executionStats'
        ]
    ],
    'Q5_newest' => [
        'collection' => 'san_pham',
        'command' => [
            'explain' => [
                'find' => 'san_pham',
                'filter' => $activeFilter,
                'sort' => ['ngay_tao' => -1, 'ma_san_pham' => -1],
                'limit' => 12
            ],
            'verbosity' => 'executionStats'
        ]
    ],
    'Q6_danh_gia_san_pham' => [
        'collection' => 'danh_gia_san_pham',
        'command' => [
            'explain' => [
                'find' => 'danh_gia_san_pham',
                'filter' => [
                    '$and' => [
                        ['$or' => [['ma_san_pham' => ['$in' => ['111', 111]]], ['id' => ['$in' => ['111', 111]]]]],
                        ['$or' => [['trang_thai' => ['$exists' => false]], ['trang_thai' => 'hien_thi'], ['trang_thai' => 'active']]]
                    ]
                ],
                'sort' => ['ngay_danh_gia' => -1, 'ma_danh_gia' => -1]
            ],
            'verbosity' => 'executionStats'
        ]
    ],
    'Q7_danh_gia' => [
        'collection' => 'danh_gia',
        'command' => [
            'explain' => [
                'find' => 'danh_gia',
                'filter' => [
                    '$or' => [['ma_san_pham' => ['$in' => ['111', 111]]], ['id' => ['$in' => ['111', 111]]]]
                ],
                'sort' => ['ngay_danh_gia' => -1, 'ma_danh_gia' => -1]
            ],
            'verbosity' => 'executionStats'
        ]
    ],
    'Q8_hoi_dap_san_pham' => [
        'collection' => 'hoi_dap_san_pham',
        'command' => [
            'explain' => [
                'find' => 'hoi_dap_san_pham',
                'filter' => [
                    '$and' => [
                        ['$or' => [['ma_san_pham' => ['$in' => ['111', 111]]], ['id' => ['$in' => ['111', 111]]]]],
                        ['trang_thai' => 'hien_thi']
                    ]
                ],
                'sort' => ['ngay_hoi' => -1, 'ma_hoi_dap' => -1],
                'limit' => 20
            ],
            'verbosity' => 'executionStats'
        ]
    ]
];

$results = [];

foreach ($queries as $qName => $qDef) {
    try {
        $cmd = new MongoDB\Driver\Command($qDef['command']);
        $cursor = $db->command($cmd);
        $explain = current($cursor->toArray());
        $summary = summarizeExplain($explain);
        $results[$qName] = $summary;
        echo sprintf(
            "%-25s | Scan: %-8s | docsExamined: %5d | keysExamined: %5d | timeMs: %3d | inMemorySort: %s\n",
            $qName,
            $summary['scanType'],
            $summary['totalDocsExamined'],
            $summary['totalKeysExamined'],
            $summary['executionTimeMillis'],
            $summary['hasInMemorySort'] ? 'YES' : 'NO'
        );
    } catch (Throwable $e) {
        echo "$qName ERROR: " . $e->getMessage() . "\n";
    }
}

file_put_contents(__DIR__ . '/baseline_explain.json', json_encode($results, JSON_PRETTY_PRINT));
