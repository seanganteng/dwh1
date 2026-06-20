<?php require_once __DIR__ . '/inc/db.php';

// Inisialisasi koneksi. Jika gagal, db.php akan menampilkan halaman error.
$pdo_oltp = getOltpPDO();
$pdo_dwh = getPDO(); // mengambil koneksi ke db_dwh3project

// Definisikan 4 query
$queries = [
    'query1' => [
        'title' => 'Total Pendapatan per Bulan',
        'desc' => 'Mengagregasi pendapatan penjualan berdasarkan tahun dan bulan. OLTP perlu mengekstrak tanggal dari timestamp, sedangkan DWH langsung membaca dimensi tahun/bulan yang sudah tersedia.',
        'oltp_sql' => "SELECT 
    EXTRACT(YEAR FROM o.order_purchase_timestamp) AS tahun,
    EXTRACT(MONTH FROM o.order_purchase_timestamp) AS bulan,
    SUM(oi.price) AS total_revenue
FROM orders o
JOIN order_items oi ON o.order_id = oi.order_id
GROUP BY tahun, bulan
ORDER BY tahun DESC, bulan DESC",
        'dwh_sql' => "SELECT 
    w.tahun,
    w.bulan,
    SUM(fp.total_harga) AS total_revenue
FROM fakta_penjualan fp
JOIN dim_waktu w ON fp.waktu_id = w.waktu_id
GROUP BY w.tahun, w.bulan
ORDER BY w.tahun DESC, w.bulan DESC"
    ],
    'query2' => [
        'title' => 'Pendapatan & Jumlah per Kategori Produk',
        'desc' => 'Mengagregasi jumlah terjual dan pendapatan per kategori produk. OLTP perlu menggabungkan tabel produk dengan terjemahan kategori, sedangkan DWH menggunakan tabel dimensi yang sudah terhubung.',
        'oltp_sql' => "SELECT 
    pct.product_category_name_english AS kategori,
    COUNT(oi.order_item_id) AS total_qty,
    SUM(oi.price) AS total_revenue
FROM order_items oi
JOIN products p ON oi.product_id = p.product_id
LEFT JOIN product_category_translation pct ON p.product_category_name = pct.product_category_name
GROUP BY pct.product_category_name_english
ORDER BY total_revenue DESC",
        'dwh_sql' => "SELECT 
    dp.kategori_inggris AS kategori,
    SUM(fp.jumlah) AS total_qty,
    SUM(fp.total_harga) AS total_revenue
FROM fakta_penjualan fp
JOIN dim_produk dp ON fp.produk_key = dp.produk_key
GROUP BY dp.kategori_inggris
ORDER BY total_revenue DESC"
    ],
    'query3' => [
        'title' => 'Distribusi Pelanggan per Negara Bagian',
        'desc' => 'Agregasi sederhana untuk menghitung jumlah pelanggan di setiap negara bagian. Membandingkan tabel customer flat di OLTP dengan tabel dimensi pelanggan di DWH.',
        'oltp_sql' => "SELECT 
    customer_state AS state,
    COUNT(*) AS total_customers
FROM customers
GROUP BY customer_state
ORDER BY total_customers DESC",
        'dwh_sql' => "SELECT 
    state,
    COUNT(*) AS total_customers
FROM dim_pelanggan
GROUP BY state
ORDER BY total_customers DESC"
    ],
    'query4' => [
        'title' => 'Rata-rata Waktu Pengiriman per Kategori',
        'desc' => 'Menghitung rata-rata waktu pengiriman pesanan (dalam hari) untuk setiap kategori produk. OLTP perlu menggabungkan orders, order_items, dan products, sedangkan DWH menggunakan kolom lama_pengiriman di fakta_penjualan yang sudah terhubung dengan dimensi produk.',
        'oltp_sql' => "SELECT 
    pct.product_category_name_english AS kategori,
    AVG(EXTRACT(DAY FROM (o.order_delivered_customer_date - o.order_purchase_timestamp))) AS avg_delivery_days
FROM orders o
JOIN order_items oi ON o.order_id = oi.order_id
JOIN products p ON oi.product_id = p.product_id
LEFT JOIN product_category_translation pct ON p.product_category_name = pct.product_category_name
WHERE o.order_delivered_customer_date IS NOT NULL
GROUP BY pct.product_category_name_english
ORDER BY avg_delivery_days DESC",
        'dwh_sql' => "SELECT
    dp.kategori,
    AVG(fp.durasi_pengiriman) AS rata_rata_hari_pengiriman
FROM fakta_pengiriman fp
JOIN dim_produk dp
    ON fp.produk_key = dp.produk_key
GROUP BY dp.kategori
ORDER BY rata_rata_hari_pengiriman DESC"
    ]
];

// Periksa status koneksi setiap database
$oltp_status = '❌ Gagal';
$dwh_status = '❌ Gagal';
try {
    $pdo_oltp->query('SELECT 1');
    $oltp_status = '✅ Terkoneksi';
} catch (Exception $e) {
    $oltp_status = '❌ Gagal';
}
try {
    $pdo_dwh->query('SELECT 1');
    $dwh_status = '✅ Terkoneksi';
} catch (Exception $e) {
    $dwh_status = '❌ Gagal';
}

$results = [];

// Eksekusi dan hitung benchmark
foreach ($queries as $key => $q) {
    // Eksekusi OLTP
    $t0 = microtime(true);
    try {
        $stmt_oltp = $pdo_oltp->query($q['oltp_sql']);
        $rows_oltp = $stmt_oltp->fetchAll();
        $oltp_time = (microtime(true) - $t0); // detik
        $oltp_err = null;
        $oltp_count = count($rows_oltp);
    } catch (Exception $e) {
        $oltp_time = 0;
        $oltp_err = $e->getMessage();
        $oltp_count = 0;
    }

    // Eksekusi DWH
    $t0 = microtime(true);
    try {
        $stmt_dwh = $pdo_dwh->query($q['dwh_sql']);
        $rows_dwh = $stmt_dwh->fetchAll();
        $dwh_time = (microtime(true) - $t0); // detik
        $dwh_err = null;
        $dwh_count = count($rows_dwh);
    } catch (Exception $e) {
        $dwh_time = 0;
        $dwh_err = $e->getMessage();
        $dwh_count = 0;
    }

    $results[$key] = [
        'oltp_time' => $oltp_time,
        'oltp_count' => $oltp_count,
        'oltp_error' => $oltp_err,
        'dwh_time' => $dwh_time,
        'dwh_count' => $dwh_count,
        'dwh_error' => $dwh_err,
    ];
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Benchmark: OLTP vs Data Warehouse</title>
    <!-- Font Google untuk tampilan halaman -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --bg-color: #f1f5f9;
            --card-border: rgba(0, 0, 0, 0.08);
            --text-main: #1e293b;
            --text-muted: #64748b;
            --primary: #0284c7;
            --oltp-color: #e11d48;
            --dwh-color: #059669;
        }

        * {
            box-sizing: border-box;
        }

        body {
            background-color: var(--bg-color);
            color: var(--text-main);
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
        }

        a { color: var(--primary); }

        .card-custom {
            background: #fff;
            border: 1px solid var(--card-border);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06);
            transition: all 0.2s ease;
        }

        .card-custom:hover {
            border-color: rgba(0, 0, 0, 0.12);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
        }

        h1, h2, h3, h4, h5 { font-weight: 700; letter-spacing: -0.025em; }

        .text-gradient {
            background: linear-gradient(135deg, #0284c7 0%, #4f46e5 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .btn-premium {
            background: linear-gradient(135deg, #0284c7 0%, #4f46e5 100%);
            border: none; color: white;
            padding: 8px 20px; font-weight: 600; font-size: 0.875rem;
            border-radius: 10px;
            transition: all 0.2s ease;
        }
        .btn-premium:hover {
            transform: translateY(-1px);
            box-shadow: 0 3px 10px rgba(79, 70, 229, 0.35);
            color: #fff;
        }

        .btn-secondary-custom {
            background: rgba(0, 0, 0, 0.03);
            border: 1px solid rgba(0, 0, 0, 0.1);
            color: var(--text-main);
            padding: 8px 20px; font-weight: 600; font-size: 0.875rem;
            border-radius: 10px;
            transition: all 0.2s ease;
        }
        .btn-secondary-custom:hover {
            background: rgba(0, 0, 0, 0.06);
            color: var(--text-main);
        }

        .code-block {
            display: block;
            background: #f8fafc !important;
            border: 1px solid rgba(0, 0, 0, 0.08);
            border-radius: 10px;
            padding: 16px;
            font-family: 'JetBrains Mono', monospace;
            font-size: 0.875rem;
            color: #1e293b;
            overflow-x: auto;
            overflow-y: auto;
            white-space: pre;
            word-break: normal;
            word-wrap: normal;
            max-height: 350px;
        }

        .badge-oltp {
            background-color: rgba(225, 29, 72, 0.1);
            color: #be123c;
            border: 1px solid rgba(225, 29, 72, 0.25);
            font-weight: 600;
        }

        .badge-dwh {
            background-color: rgba(5, 150, 105, 0.1);
            color: #047857;
            border: 1px solid rgba(5, 150, 105, 0.25);
            font-weight: 600;
        }

        .badge-winner {
            background: linear-gradient(135deg, rgba(5, 150, 105, 0.12) 0%, rgba(2, 132, 199, 0.12) 100%);
            color: #059669;
            border: 1px solid rgba(5, 150, 105, 0.3);
            font-weight: 700;
            font-size: 0.85rem;
            padding: 6px 12px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .nav-link-custom { color: var(--text-muted); transition: color 0.2s; }
        .nav-link-custom:hover { color: var(--primary); }

        .breadcrumb-item.active { color: var(--text-main) !important; }

        .metric-value { font-size: 1.25rem; font-weight: 700; }

        #benchmarkChart { max-width: 100%; height: auto !important; }

        .badge-winner svg { flex-shrink: 0; }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 576px) {
            body.p-4 { padding: 0.75rem !important; }
            body.py-5 { padding-top: 0.75rem !important; padding-bottom: 0.75rem !important; }
            .card-custom { padding: 1rem !important; border-radius: 10px; }
            h1 { font-size: 1.25rem !important; }
            h4 { font-size: 0.95rem !important; }
            h3 { font-size: 1.1rem !important; }
            .metric-value { font-size: 1rem !important; }
            .metric-value span { font-size: 0.65rem !important; }
            .code-block { font-size: 0.65rem; padding: 10px; max-height: 180px; }
            .btn-premium, .btn-secondary-custom { padding: 7px 12px; font-size: 0.8rem; width: 100%; justify-content: center; }
            .badge-winner { font-size: 0.65rem; padding: 3px 8px; }
            .badge-winner svg { width: 12px; height: 12px; }
            .header-buttons { flex-direction: column; width: 100%; }
            .header-buttons a { width: 100%; }
            .d-flex.gap-5 { gap: 1.25rem !important; }
            #benchmarkChart { max-height: 350px !important; }
            .row.g-4.mb-5 { margin-bottom: 1.5rem !important; }
        }

        @media (min-width: 577px) and (max-width: 991px) {
            .card-custom { padding: 1.25rem !important; }
            .breadcrumb { font-size: 0.825rem; }
            .code-block { max-height: 240px; font-size: 0.75rem; }
            .container { padding-left: 1rem; padding-right: 1rem; }
            #benchmarkChart { max-height: 240px !important; }
            .d-flex.gap-5 { gap: 2rem !important; }
        }

        @media (min-width: 992px) {
            .code-block { max-height: 300px; font-size: 0.8rem; }
            .container { padding-left: 1rem; padding-right: 1rem; }
        }

        @media (max-width: 400px) {
            .badge-winner { font-size: 0.6rem; padding: 2px 6px; }
        }
    </style>
</head>
<body class="p-4 py-5">
<div class="container">

    <!-- Bagian Header -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4 gap-3">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="index.php" class="nav-link-custom text-decoration-none">Dashboard</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Benchmarking</li>
                </ol>
            </nav>
            <h1 class="text-gradient m-0">Database Query Benchmarking</h1>
            <p class="text-muted m-0 mt-1">Perbandingan kinerja real-time antara OLTP (db_dwh3) & Data Warehouse (db_dwh3project).</p>
        </div>
        <div class="d-flex gap-2 header-buttons">
            <a href="perbandingan.php" class="btn btn-premium d-flex align-items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                </svg>
                Ulangi Benchmark
            </a>
            <a href="index.php" class="btn btn-secondary-custom">Kembali ke Dashboard</a>
        </div>
    </div>

    <!-- Overview & Grafik -->
    <div class="row g-3 mb-4">
        <div class="col-12 col-xl-7">
            <div class="card-custom p-4 h-100">
                <h4 class="mb-3">Visualisasi Waktu Eksekusi (Detik)</h4>
                <div style="position: relative; height: 400px; width: 100%; padding-bottom: 50px;">
                    <canvas id="benchmarkChart"></canvas>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-5">
            <div class="card-custom p-4 h-100">
                <div>
                    <h4 class="mb-3">Informasi Sistem & Database</h4>
                    <p class="text-muted small">Proses benchmarking ini mengukur kecepatan eksekusi query langsung ke database PostgreSQL lokal pada kedua skema database.</p>

                    <div class="p-3 rounded" style="background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.08);">
                        <div class="d-flex justify-content-between mb-2">
                            <span class="text-muted">OLTP Database (db_dwh3):</span>
                            <span class="fw-semibold" style="font-size: 0.9rem;"><?php echo $oltp_status; ?></span>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">DWH Database (db_dwh3project):</span>
                            <span class="fw-semibold" style="font-size: 0.9rem;"><?php echo $dwh_status; ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Kartu Detail Query -->
    <h3 class="mb-4 text-gradient">Detail Perbandingan Query</h3>

    <div class="d-flex flex-column gap-4">
        <?php foreach ($queries as $key => $q):
            $res = $results[$key];
            $oltp_time = $res['oltp_time'];
            $dwh_time = $res['dwh_time'];

            $winner = '';
            $ratio = 1;
            if ($oltp_time > 0 && $dwh_time > 0) {
                if ($dwh_time < $oltp_time) {
                    $winner = 'DWH';
                    $ratio = $oltp_time / $dwh_time;
                } else {
                    $winner = 'OLTP';
                    $ratio = $dwh_time / $oltp_time;
                }
            }
        ?>
            <div class="card-custom p-4">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-3 pb-3 border-bottom gap-2">
                    <div>
                        <h4 class="mb-1" style="color: var(--text-main);"><?php echo htmlspecialchars($q['title']); ?></h4>
                        <p class="text-muted m-0 small"><?php echo htmlspecialchars($q['desc']); ?></p>
                    </div>
                    <div>
                        <?php if ($winner === 'DWH'): ?>
                            <span class="badge-winner">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.006 5.404.434c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.434 2.082-5.005Z" clip-rule="evenodd" />
                                </svg>
                                DWH Lebih Cepat (<?php echo number_format($ratio, 1); ?>x)
                            </span>
                        <?php elseif ($winner === 'OLTP'): ?>
                            <span class="badge-winner" style="background: linear-gradient(135deg, rgba(244, 63, 94, 0.2) 0%, rgba(56, 189, 248, 0.2) 100%); color: #e11d48; border: 1px solid rgba(244, 63, 94, 0.35);">
                                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10.788 3.21c.448-1.077 1.976-1.077 2.424 0l2.082 5.006 5.404.434c1.164.093 1.636 1.545.749 2.305l-4.117 3.527 1.257 5.273c.271 1.136-.964 2.033-1.96 1.425L12 18.354 7.373 21.18c-.996.608-2.231-.29-1.96-1.425l1.257-5.273-4.117-3.527c-.887-.76-.415-2.212.749-2.305l5.404-.434 2.082-5.005Z" clip-rule="evenodd" />
                                </svg>
                                OLTP Lebih Cepat (<?php echo number_format($ratio, 1); ?>x)
                            </span>
                        <?php else: ?>
                            <span class="badge bg-secondary">N/A</span>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Metrik -->
                <div class="row g-2 mb-3">
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded" style="background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.06);">
                            <div class="small text-muted mb-1">Waktu OLTP</div>
                            <div class="metric-value" style="color: var(--oltp-color);"><?php echo number_format($oltp_time, 4); ?> <span style="font-size: 0.85rem;">detik</span></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded" style="background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.06);">
                            <div class="small text-muted mb-1">Baris Hasil OLTP</div>
                            <div class="metric-value" style="color: var(--text-main);"><?php echo number_format($res['oltp_count']); ?></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded" style="background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.06);">
                            <div class="small text-muted mb-1">Waktu DWH</div>
                            <div class="metric-value" style="color: var(--dwh-color);"><?php echo number_format($dwh_time, 4); ?> <span style="font-size: 0.85rem;">detik</span></div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="p-3 rounded" style="background: rgba(0,0,0,0.02); border: 1px solid rgba(0,0,0,0.06);">
                            <div class="small text-muted mb-1">Baris Hasil DWH</div>
                            <div class="metric-value" style="color: var(--text-main);"><?php echo number_format($res['dwh_count']); ?></div>
                        </div>
                    </div>
                </div>

                <!-- Blok Kode -->
                <div class="row g-3">
                    <div class="col-12 col-lg-6">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-semibold text-muted">SQL Query (OLTP Schema)</span>
                            <span class="badge badge-oltp small">Normalized</span>
                        </div>
                        <pre class="code-block"><code><?php echo htmlspecialchars($q['oltp_sql']); ?></code></pre>
                    </div>
                    <div class="col-12 col-lg-6">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <span class="small fw-semibold text-muted">SQL Query (DWH Schema)</span>
                            <span class="badge badge-dwh small">Star Schema</span>
                        </div>
                        <pre class="code-block"><code><?php echo htmlspecialchars($q['dwh_sql']); ?></code></pre>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

</div>

<script>
    const labels = [
        'Pendapatan per Bulan',
        'Pendapatan & Jumlah per Kategori',
        'Distribusi Pelanggan per State',
        'Rata-rata Waktu Kirim per Kategori'
    ];

    const oltpTimes = [
        <?php echo $results['query1']['oltp_time']; ?>,
        <?php echo $results['query2']['oltp_time']; ?>,
        <?php echo $results['query3']['oltp_time']; ?>,
        <?php echo $results['query4']['oltp_time']; ?>
    ];

    const dwhTimes = [
        <?php echo $results['query1']['dwh_time']; ?>,
        <?php echo $results['query2']['dwh_time']; ?>,
        <?php echo $results['query3']['dwh_time']; ?>,
        <?php echo $results['query4']['dwh_time']; ?>
    ];

    document.addEventListener("DOMContentLoaded", function() {
        const isMobile = window.innerWidth < 768;
        const ctx = document.getElementById('benchmarkChart').getContext('2d');
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'OLTP (db_dwh3)',
                        data: oltpTimes,
                        backgroundColor: '#f43f5e',
                        borderColor: '#e11d48',
                        borderWidth: 1,
                        borderRadius: 6
                    },
                    {
                        label: 'DWH (db_dwh3project)',
                        data: dwhTimes,
                        backgroundColor: '#10b981',
                        borderColor: '#059669',
                        borderWidth: 1,
                        borderRadius: 6
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'x',
                scales: {
                    x: {
                        grid: { color: 'rgba(0, 0, 0, 0.06)' },
                        ticks: { 
                            color: '#64748b',
                            maxRotation: isMobile ? 75 : 45,
                            minRotation: isMobile ? 60 : 30,
                            font: { size: isMobile ? 9 : 11 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: 'rgba(0, 0, 0, 0.06)' },
                        ticks: { 
                            color: '#64748b',
                            font: { size: isMobile ? 10 : 11 }
                        }
                    }
                },
                plugins: {
                    legend: { 
                        labels: { 
                            color: '#1e293b',
                            font: { size: isMobile ? 9 : 12 }
                        } 
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return context.dataset.label + ': ' + context.raw.toFixed(4) + ' detik';
                            }
                        }
                    }
                }
            }
        });
    });
</script>
</body>
</html>