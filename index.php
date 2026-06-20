<?php
require_once __DIR__ . '/inc/db.php';

// Koneksi database dan parameter filter dari URL
$pdo = getPDO();
$selectedYear = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : null;
$selectedMonth = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;

// Opsi untuk filter Top 10 berdasarkan produk atau kategori
$topByOptions = [
    'product' => 'Produk',
    'category' => 'Kategori',
];
$selectedTopBy = isset($_GET['top_by']) && array_key_exists($_GET['top_by'], $topByOptions) ? $_GET['top_by'] : 'product';

// Nama bulan yang digunakan untuk label filter dan tampilan
$months = [
    1 => 'Januari',
    2 => 'Februari',
    3 => 'Maret',
    4 => 'April',
    5 => 'Mei',
    6 => 'Juni',
    7 => 'Juli',
    8 => 'Agustus',
    9 => 'September',
    10 => 'Oktober',
    11 => 'November',
    12 => 'Desember',
];

// Ambil daftar tahun yang tersedia di data untuk filter
try {
    $yearsStmt = $pdo->query('SELECT DISTINCT tahun FROM dim_waktu ORDER BY tahun DESC');
    $years = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $years = [];
}

// Ambil daftar bulan yang tersedia sesuai filter tahun yang dipilih
try {
    if ($selectedYear) {
        $monthsStmt = $pdo->prepare('SELECT DISTINCT bulan FROM dim_waktu WHERE tahun = :y ORDER BY bulan');
        $monthsStmt->execute([':y' => $selectedYear]);
    } else {
        $monthsStmt = $pdo->query('SELECT DISTINCT bulan FROM dim_waktu ORDER BY bulan');
    }
    $availableMonths = $monthsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $availableMonths = [];
}

// Ambil nilai KPI utama untuk dashboard summary
$kpis = [];
try {
    $kpis['total_customers'] = (int) $pdo->query('SELECT COUNT(*) FROM dim_pelanggan')->fetchColumn();
} catch (Exception $e) { $kpis['total_customers'] = 0; }
try {
    $kpis['total_products'] = (int) $pdo->query('SELECT COUNT(*) FROM dim_produk')->fetchColumn();
} catch (Exception $e) { $kpis['total_products'] = 0; }
try {
    $kpis['total_categories'] = (int) $pdo->query("SELECT COUNT(DISTINCT kategori) FROM dim_produk")->fetchColumn();
} catch (Exception $e) { $kpis['total_categories'] = 0; }
try {
    $orderWhere = [];
    $orderParams = [];
    $orderQuery = "SELECT COALESCE(SUM(fp.jumlah), 0) FROM fakta_penjualan fp JOIN dim_waktu w ON fp.waktu_id = w.waktu_id";
    if ($selectedYear) {
        $orderWhere[] = 'w.tahun = :y';
        $orderParams[':y'] = $selectedYear;
    }
    if ($selectedMonth) {
        $orderWhere[] = 'w.bulan = :m';
        $orderParams[':m'] = $selectedMonth;
    }
    if ($orderWhere) {
        $orderQuery .= ' WHERE ' . implode(' AND ', $orderWhere);
    }
    $stmt = $pdo->prepare($orderQuery);
    $stmt->execute($orderParams);
    $kpis['total_orders'] = (int) $stmt->fetchColumn();
} catch (Exception $e) { $kpis['total_orders'] = 0; }
try {
    if ($selectedYear) {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(fp.total_harga),0) FROM fakta_penjualan fp JOIN dim_waktu w ON fp.waktu_id = w.waktu_id WHERE w.tahun = :y");
        $stmt->execute([':y' => $selectedYear]);
        $kpis['total_revenue'] = (float) $stmt->fetchColumn();
    } else {
        $kpis['total_revenue'] = (float) $pdo->query('SELECT COALESCE(SUM(total_harga),0) FROM fakta_penjualan')->fetchColumn();
    }
} catch (Exception $e) { $kpis['total_revenue'] = 0; }
try {
    $avg = $pdo->query('SELECT AVG(durasi_pengiriman) FROM fakta_pengiriman')->fetchColumn();
    $kpis['avg_delivery_days'] = $avg !== null ? round((float)$avg,2) : null;
} catch (Exception $e) { $kpis['avg_delivery_days'] = null; }

// Ambil data penggunaan metode pembayaran untuk chart Payment
try {
    $paymentWhere = [];
    $paymentParams = [];
    if ($selectedYear) {
        $paymentWhere[] = 'w.tahun = :y';
        $paymentParams[':y'] = $selectedYear;
    }
    if ($selectedMonth) {
        $paymentWhere[] = 'w.bulan = :m';
        $paymentParams[':m'] = $selectedMonth;
    }
    $paymentQuery = 'SELECT mp.metode_pembayaran, COUNT(*) AS jumlah
        FROM fakta_pembayaran fp
        JOIN dim_metode_pembayaran mp ON fp.metode_key = mp.metode_key
        JOIN dim_waktu w ON fp.waktu_id = w.waktu_id';
    if ($paymentWhere) {
        $paymentQuery .= ' WHERE ' . implode(' AND ', $paymentWhere);
    }
    $paymentQuery .= ' GROUP BY mp.metode_pembayaran ORDER BY jumlah DESC';
    $stmt = $pdo->prepare($paymentQuery);
    $stmt->execute($paymentParams);
    $paymentMethods = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $paymentMethods = [];
}

$top10Items = [];
// Ambil data Top 10 untuk chart produk atau kategori paling laris
try {
    $top10Query = $selectedTopBy === 'category'
        ? "SELECT dp.kategori_inggris AS label, SUM(fp.jumlah) AS total_qty
            FROM fakta_penjualan fp
            JOIN dim_produk dp ON fp.produk_key = dp.produk_key
            JOIN dim_waktu w ON fp.waktu_id = w.waktu_id"
        : "SELECT dp.produk_id AS label, SUM(fp.jumlah) AS total_qty
            FROM fakta_penjualan fp
            JOIN dim_produk dp ON fp.produk_key = dp.produk_key
            JOIN dim_waktu w ON fp.waktu_id = w.waktu_id";
    if ($selectedYear) {
        $top10Query .= ' WHERE w.tahun = :y';
    }
    if ($selectedMonth) {
        $top10Query .= $selectedYear ? ' AND w.bulan = :m' : ' WHERE w.bulan = :m';
    }
    $top10Query .= $selectedTopBy === 'category'
        ? ' GROUP BY dp.kategori_inggris ORDER BY total_qty DESC LIMIT 10'
        : ' GROUP BY dp.produk_id ORDER BY total_qty DESC LIMIT 10';
    $top10Stmt = $pdo->prepare($top10Query);
    $top10Params = [];
    if ($selectedYear) {
        $top10Params[':y'] = $selectedYear;
    }
    if ($selectedMonth) {
        $top10Params[':m'] = $selectedMonth;
    }
    $top10Stmt->execute($top10Params);
    $top10Items = $top10Stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $top10Items = [];
}

// Ambil 5 state dengan jumlah pelanggan terbanyak untuk analisis persebaran
$top5States = [];
try {
    $stateQuery = "SELECT state, COUNT(*) AS total_customers
        FROM dim_pelanggan
        WHERE state IS NOT NULL AND state != ''
        GROUP BY state
        ORDER BY total_customers DESC
        LIMIT 5";
    $stateStmt = $pdo->prepare($stateQuery);
    $stateStmt->execute();
    $top5States = $stateStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $top5States = [];
}

// Ambil data revenue dan jumlah produk terjual untuk chart revenue/time series
try {
    $where = [];
    $params = [];
    if ($selectedYear) {
        $where[] = 'w.tahun = :y';
        $params[':y'] = $selectedYear;
    }
    if ($selectedMonth) {
        $where[] = 'w.bulan = :m';
        $params[':m'] = $selectedMonth;
    }

    if ($selectedYear && $selectedMonth) {
        $sql = "SELECT w.tanggal AS label, SUM(fp.total_harga) AS revenue, SUM(fp.jumlah) AS qty
            FROM fakta_penjualan fp
            JOIN dim_waktu w ON fp.waktu_id = w.waktu_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY w.tanggal ORDER BY w.tanggal';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($selectedYear) {
        $sql = "SELECT w.bulan, SUM(fp.total_harga) AS revenue, SUM(fp.jumlah) AS qty
            FROM fakta_penjualan fp
            JOIN dim_waktu w ON fp.waktu_id = w.waktu_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY w.bulan ORDER BY w.bulan';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($selectedMonth) {
        $sql = "SELECT w.tahun, SUM(fp.total_harga) AS revenue, SUM(fp.jumlah) AS qty
            FROM fakta_penjualan fp
            JOIN dim_waktu w ON fp.waktu_id = w.waktu_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' GROUP BY w.tahun ORDER BY w.tahun';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->query("SELECT w.tahun, w.bulan, SUM(fp.total_harga) AS revenue, SUM(fp.jumlah) AS qty
            FROM fakta_penjualan fp
            JOIN dim_waktu w ON fp.waktu_id = w.waktu_id
            GROUP BY w.tahun, w.bulan
            ORDER BY w.tahun, w.bulan");
        $sales = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Exception $e) {
    $sales = [];
}

// Siapkan label dan data
// Siapkan label dan nilai chart berdasarkan level filter yang digunakan
$labels = [];
$dataRevenue = [];
$dataQty = [];
foreach ($sales as $row) {
    if ($selectedYear && $selectedMonth) {
        $labels[] = date('j M', strtotime($row['label']));
    } elseif ($selectedYear) {
        $labels[] = str_pad($row['bulan'],2,'0',STR_PAD_LEFT);
    } elseif ($selectedMonth) {
        $labels[] = $row['tahun'];
    } else {
        $labels[] = $row['tahun'] . '-' . str_pad($row['bulan'],2,'0',STR_PAD_LEFT);
    }
    $dataRevenue[] = (float) $row['revenue'];
    $dataQty[] = isset($row['qty']) ? (int) $row['qty'] : 0;
}

$revenueInsightCurrent = 'Tidak ada data revenue untuk ditampilkan.';
$revenueInsightFuture = '';
$revenueInsightAction = '';
if (!empty($labels) && !empty($dataRevenue)) {
    $periodLabel = 'periode';
    if ($selectedYear && !$selectedMonth) {
        $periodLabel = 'bulan';
    } elseif ($selectedMonth && !$selectedYear) {
        $periodLabel = 'tahun';
    } elseif ($selectedYear && $selectedMonth) {
        $periodLabel = 'hari';
    }

    $filterLabel = 'semua periode';
    if ($selectedYear && $selectedMonth) {
        $filterLabel = $months[$selectedMonth] . ' ' . $selectedYear;
    } elseif ($selectedYear) {
        $filterLabel = 'tahun ' . $selectedYear;
    } elseif ($selectedMonth) {
        $filterLabel = 'bulan ' . $months[$selectedMonth];
    }

    $count = count($dataRevenue);
    $maxIndex = array_search(max($dataRevenue), $dataRevenue, true);
    $minIndex = array_search(min($dataRevenue), $dataRevenue, true);
    $latestIndex = $count - 1;
    $latestRevenue = $dataRevenue[$latestIndex];
    $latestLabel = $labels[$latestIndex];
    $avgRevenue = array_sum($dataRevenue) / $count;

    if ($count > 1) {
        $prevRevenue = $dataRevenue[$latestIndex - 1];
        if ($latestRevenue > $prevRevenue) {
            $trend = 'mengalami kenaikan';
        } elseif ($latestRevenue < $prevRevenue) {
            $trend = 'mengalami penurunan';
        } else {
            $trend = 'stabil';
        }
        $revenueInsightCurrent = sprintf(
            'Data chart revenue untuk %s menunjukkan revenue %s dibanding %s %s sebelumnya, dengan nilai tertinggi di %s (R$ %s) dan terendah di %s (R$ %s).',
            $filterLabel,
            $trend,
            $periodLabel,
            $periodLabel,
            $labels[$maxIndex],
            number_format($dataRevenue[$maxIndex], 2, ',', '.'),
            $labels[$minIndex],
            number_format($dataRevenue[$minIndex], 2, ',', '.')
        );

        if ($latestRevenue >= $avgRevenue) {
            $revenueInsightFuture = 'Tren akhir untuk ' . $filterLabel . ' menunjukkan revenue yang relatif kuat. Jika faktor eksternal tidak berubah, peluang berikutnya lebih besar untuk mempertahankan atau meningkatkan level ini.';
        } else {
            $revenueInsightFuture = 'Revenue akhir-akhir ini untuk ' . $filterLabel . ' masih di bawah rata-rata periode yang diamati. Jika tidak ada intervensi, momentum ini berisiko membuat kinerja tetap lemah.';
        }
    } else {
        $revenueInsightCurrent = sprintf(
            'Data chart revenue untuk %s hanya tersedia pada %s dengan revenue R$ %s.',
            $filterLabel,
            $latestLabel,
            number_format($latestRevenue, 2, ',', '.')
        );
        $revenueInsightFuture = 'Dengan data terbatas untuk ' . $filterLabel . ', gunakan lebih banyak periode untuk melihat tren yang lebih jelas.';
    }

    $revenueInsightAction = sprintf(
        'Fokuskan upaya pada %s dengan revenue terendah dalam grafik untuk %s. Perkuat promosi dan pengelolaan stok di %s tersebut, sambil memelihara momentum di %s yang menunjukkan revenue tertinggi.',
        $periodLabel,
        $filterLabel,
        $labels[$minIndex],
        $labels[$maxIndex]
    );
}

$paymentLabels = [];
$paymentData = [];
$paymentInsightCurrent = 'Tidak ada data metode pembayaran untuk periode yang dipilih.';
$paymentInsightFuture = '';
$paymentInsightAction = '';
$paymentFilterLabel = 'semua periode';
if ($selectedYear && $selectedMonth) {
    $paymentFilterLabel = $months[$selectedMonth] . ' ' . $selectedYear;
} elseif ($selectedYear) {
    $paymentFilterLabel = 'tahun ' . $selectedYear;
} elseif ($selectedMonth) {
    $paymentFilterLabel = 'bulan ' . $months[$selectedMonth];
}
$paymentTotal = 0;
foreach ($paymentMethods as $row) {
    $paymentLabels[] = $row['metode_pembayaran'];
    $paymentData[] = (int) $row['jumlah'];
    $paymentTotal += (int) $row['jumlah'];
}
if ($paymentTotal > 0) {
    $topMethod = $paymentMethods[0]['metode_pembayaran'];
    $topCount = (int) $paymentMethods[0]['jumlah'];
    $topShare = round($topCount / $paymentTotal * 100, 1);
    $secondMethod = count($paymentMethods) > 1 ? $paymentMethods[1]['metode_pembayaran'] : null;
    $paymentInsightCurrent = sprintf(
        'Untuk %s, metode pembayaran paling populer adalah %s dengan %s transaksi (%s%% dari total).',
        $paymentFilterLabel,
        $topMethod,
        number_format($topCount, 0, ',', '.'),
        number_format($topShare, 1, ',', '.')
    );
    if ($secondMethod) {
        $paymentInsightCurrent .= sprintf(' Metode ini unggul dibanding %s.', $secondMethod);
    }
    if ($topShare >= 70) {
        $paymentInsightFuture = 'Dominasi metode ini menunjukkan pelanggan sangat bergantung padanya. Jika tidak ada perubahan, persentase penggunaan cenderung tetap tinggi.';
        $paymentInsightAction = sprintf(
            'Pastikan pengalaman checkout untuk %s optimal dan pertimbangkan dukungan khusus agar konversi tetap tinggi.',
            $topMethod
        );
    } else {
        $paymentInsightFuture = 'Dominasi tidak terlalu kuat, sehingga ada peluang untuk mendorong adopsi metode alternatif.';
        $paymentInsightAction = sprintf(
            'Pertahankan kualitas untuk %s sambil mengedukasi pelanggan tentang metode alternatif agar pengalaman checkout lebih fleksibel.',
            $topMethod
        );
    }
}
$top10Labels = [];
$top10Data = [];
$top10InsightCurrent = 'Tidak ada data top 10 untuk periode yang dipilih.';
$top10InsightFuture = '';
$top10InsightAction = '';
$top10FilterLabel = 'semua periode';
if ($selectedYear && $selectedMonth) {
    $top10FilterLabel = $months[$selectedMonth] . ' ' . $selectedYear;
} elseif ($selectedYear) {
    $top10FilterLabel = 'tahun ' . $selectedYear;
} elseif ($selectedMonth) {
    $top10FilterLabel = 'bulan ' . $months[$selectedMonth];
}
foreach ($top10Items as $row) {
    $top10Labels[] = $row['label'];
    $top10Data[] = (int) $row['total_qty'];
}
$top10Total = array_sum($top10Data);
if (!empty($top10Data)) {
    $top10LabelType = $selectedTopBy === 'category' ? 'kategori' : 'produk';
    $top1Label = $top10Items[0]['label'];
    $top1Qty = (int) $top10Items[0]['total_qty'];
    $top2Qty = count($top10Items) > 1 ? (int) $top10Items[1]['total_qty'] : 0;
    $top1Share = $top10Total > 0 ? round($top1Qty / $top10Total * 100, 1) : 0;
    $top10InsightCurrent = sprintf(
        'Untuk %s, %s terlaris adalah %s dengan %s unit (%s%% dari total top 10).',
        $top10FilterLabel,
        $top10LabelType,
        $top1Label,
        number_format($top1Qty, 0, ',', '.'),
        number_format($top1Share, 1, ',', '.')
    );
    if ($top2Qty > 0) {
        $gap = $top1Qty - $top2Qty;
        $gapShare = $top2Qty > 0 ? round($gap / $top2Qty * 100, 1) : 0;
        if ($gapShare >= 20) {
            $top10InsightCurrent .= sprintf(' Top %s unggul %s%% dibanding %s berikutnya.', $top1Label, number_format($gapShare, 1, ',', '.'), $top10Items[1]['label']);
        } else {
            $top10InsightCurrent .= sprintf(' Persaingan masih ketat dengan %s kedua hanya %s%% di bawah.', $top10Items[1]['label'], number_format($gapShare, 1, ',', '.'));
        }
    }
    if ($top1Share >= 50) {
        $top10InsightFuture = 'Dominasi produk/kategori teratas terlihat kuat; kecenderungan ini kemungkinan akan berlanjut jika pola pembelian tidak berubah.';
        $top10InsightAction = sprintf('Pastikan stok dan promosi untuk %s tetap kuat, serta pertahankan eksposurnya untuk mengunci posisi teratas.', $top1Label);
    } elseif ($top1Share >= 30) {
        $gapShare = isset($gapShare) ? $gapShare : 0;
        $top10InsightFuture = sprintf('Top %s masih memimpin, namun selisihnya dengan pesaing cukup kecil (%s%%). Posisi bisa bergeser jika pesaing mendapat dorongan tambahan.', $top1Label, number_format($gapShare, 1, ',', '.'));
        $top10InsightAction = sprintf('Perkuat pemasaran untuk %s sambil memantau pesaing terdekat agar posisi tidak mudah tergeser.', $top1Label);
    } else {
        $top10InsightFuture = 'Distribusi top 10 cukup merata, sehingga potensi perubahan peringkat antar produk/kategori masih tinggi.';
        $top10InsightAction = sprintf('Kembangkan promosi untuk beberapa %s teratas dan fokus pada diversifikasi agar tidak bergantung pada satu pemenang tunggal.', $top10LabelType);
    }
}
$stateLabels = [];
$stateData = [];
foreach ($top5States as $row) {
    $stateLabels[] = $row['state'];
    $stateData[] = (int) $row['total_customers'];
}

$avgStateLabels = [];
$avgStateData = [];
try {
    $avgStateQuery = "SELECT dp.state, ROUND(AVG(fp.durasi_pengiriman), 2) AS avg_delivery_days
        FROM fakta_pengiriman fp
        JOIN dim_pelanggan dp ON fp.pelanggan_key = dp.pelanggan_key
        JOIN dim_waktu w ON fp.waktu_id = w.waktu_id";
    $avgStateWhere = [];
    $avgStateParams = [];
    if ($selectedYear) {
        $avgStateWhere[] = 'w.tahun = :y';
        $avgStateParams[':y'] = $selectedYear;
    }
    if ($selectedMonth) {
        $avgStateWhere[] = 'w.bulan = :m';
        $avgStateParams[':m'] = $selectedMonth;
    }
    if ($avgStateWhere) {
        $avgStateQuery .= ' WHERE ' . implode(' AND ', $avgStateWhere);
    }
    $avgStateQuery .= ' GROUP BY dp.state ORDER BY avg_delivery_days DESC';
    $avgStateStmt = $pdo->prepare($avgStateQuery);
    $avgStateStmt->execute($avgStateParams);
    $avgStateItems = $avgStateStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($avgStateItems as $row) {
        $avgStateLabels[] = $row['state'];
        $avgStateData[] = (float) $row['avg_delivery_days'];
    }
} catch (Exception $e) {
    $avgStateLabels = [];
    $avgStateData = [];
}

$avgCategoryLabels = [];
$avgCategoryData = [];
try {
    $avgCategoryQuery = "SELECT
        dp.kategori_inggris AS kategori,
        COUNT(*) AS total_pengiriman,
        ROUND(
            AVG(fp.durasi_pengiriman),
            2
        ) AS rata_rata_hari_pengiriman
    FROM fakta_pengiriman fp
    JOIN dim_produk dp
        ON fp.produk_key = dp.produk_key
    JOIN dim_waktu w ON fp.waktu_id = w.waktu_id";
    $avgCategoryWhere = [];
    $avgCategoryParams = [];
    if ($selectedYear) {
        $avgCategoryWhere[] = 'w.tahun = :y';
        $avgCategoryParams[':y'] = $selectedYear;
    }
    if ($selectedMonth) {
        $avgCategoryWhere[] = 'w.bulan = :m';
        $avgCategoryParams[':m'] = $selectedMonth;
    }
    if ($avgCategoryWhere) {
        $avgCategoryQuery .= ' WHERE ' . implode(' AND ', $avgCategoryWhere);
    }
    $avgCategoryQuery .= ' GROUP BY dp.kategori_inggris ORDER BY rata_rata_hari_pengiriman DESC';
    $avgCategoryStmt = $pdo->prepare($avgCategoryQuery);
    $avgCategoryStmt->execute($avgCategoryParams);
    $avgCategoryItems = $avgCategoryStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($avgCategoryItems as $row) {
        $avgCategoryLabels[] = $row['kategori'];
        $avgCategoryData[] = (float) $row['rata_rata_hari_pengiriman'];
    }
} catch (Exception $e) {
    $avgCategoryLabels = [];
    $avgCategoryData = [];
}
$ratingLabels = [];
$ratingData = [];
$ratingMonthLabels = [];
$ratingMonthData = [];
try {
    $ratingYearQuery = "SELECT dw.tahun AS label, ROUND(AVG(fr.skor_review), 2) AS avg_rating
        FROM fakta_review fr
        JOIN dim_waktu dw ON fr.waktu_id = dw.waktu_id";
    $ratingMonthQuery = "SELECT CONCAT(dw.tahun, '-', LPAD(dw.bulan::text, 2, '0')) AS label, ROUND(AVG(fr.skor_review), 2) AS avg_rating
        FROM fakta_review fr
        JOIN dim_waktu dw ON fr.waktu_id = dw.waktu_id";
    $ratingWhere = [];
    $ratingParams = [];
    if ($selectedYear) {
        $ratingWhere[] = 'dw.tahun = :y';
        $ratingParams[':y'] = $selectedYear;
    }
    if ($selectedMonth) {
        $ratingWhere[] = 'dw.bulan = :m';
        $ratingParams[':m'] = $selectedMonth;
    }
    if ($ratingWhere) {
        $whereClause = ' WHERE ' . implode(' AND ', $ratingWhere);
        $ratingYearQuery .= $whereClause;
        $ratingMonthQuery .= $whereClause;
    }
    $ratingYearQuery .= ' GROUP BY dw.tahun ORDER BY dw.tahun';
    $ratingMonthQuery .= " GROUP BY CONCAT(dw.tahun, '-', LPAD(dw.bulan::text, 2, '0')) ORDER BY CONCAT(dw.tahun, '-', LPAD(dw.bulan::text, 2, '0'))";
    $ratingStmt = $pdo->prepare($ratingYearQuery);
    $ratingStmt->execute($ratingParams);
    $ratingItems = $ratingStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ratingItems as $row) {
        $ratingLabels[] = $row['label'];
        $ratingData[] = (float) $row['avg_rating'];
    }
    $ratingMonthStmt = $pdo->prepare($ratingMonthQuery);
    $ratingMonthStmt->execute($ratingParams);
    $ratingMonthItems = $ratingMonthStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($ratingMonthItems as $row) {
        $ratingMonthLabels[] = $row['label'];
        $ratingMonthData[] = (float) $row['avg_rating'];
    }
} catch (Exception $e) {
    $ratingLabels = [];
    $ratingData = [];
    $ratingMonthLabels = [];
    $ratingMonthData = [];
}

$topSellerLabelsQty = [];
$topSellerQty = [];
$topSellerLabelsRevenue = [];
$topSellerRevenue = [];
// Ambil data seller untuk chart Top Seller berdasarkan quantity dan revenue
try {
    $sellerQuery = "SELECT ds.seller_id AS label, SUM(fp.jumlah) AS total_qty, SUM(fp.total_harga) AS total_revenue
        FROM fakta_penjualan fp
        JOIN dim_seller ds ON fp.seller_key = ds.seller_key
        JOIN dim_waktu w ON fp.waktu_id = w.waktu_id";
    $sellerWhere = [];
    $sellerParams = [];
    if ($selectedYear) {
        $sellerWhere[] = 'w.tahun = :y';
        $sellerParams[':y'] = $selectedYear;
    }
    if ($selectedMonth) {
        $sellerWhere[] = 'w.bulan = :m';
        $sellerParams[':m'] = $selectedMonth;
    }
    if ($sellerWhere) {
        $sellerQuery .= ' WHERE ' . implode(' AND ', $sellerWhere);
    }
    $sellerQuery .= ' GROUP BY ds.seller_id';
    $sellerStmt = $pdo->prepare($sellerQuery);
    $sellerStmt->execute($sellerParams);
    $topSellerItems = $sellerStmt->fetchAll(PDO::FETCH_ASSOC);

    $topSellerByQty = $topSellerItems;
    usort($topSellerByQty, fn($a, $b) => $b['total_qty'] <=> $a['total_qty']);
    $topSellerByQty = array_slice($topSellerByQty, 0, 10);
    foreach ($topSellerByQty as $row) {
        $topSellerLabelsQty[] = $row['label'];
        $topSellerQty[] = (int) $row['total_qty'];
    }

    $topSellerByRevenue = $topSellerItems;
    usort($topSellerByRevenue, fn($a, $b) => $b['total_revenue'] <=> $a['total_revenue']);
    $topSellerByRevenue = array_slice($topSellerByRevenue, 0, 10);
    foreach ($topSellerByRevenue as $row) {
        $topSellerLabelsRevenue[] = $row['label'];
        $topSellerRevenue[] = (float) $row['total_revenue'];
    }

    $topCustomerLabelsRevenue = [];
    $topCustomerRevenue = [];
    // Ambil Top 10 pelanggan berdasarkan revenue pembelian
    try {
        $customerQuery = "SELECT COALESCE(dp.pelanggan_id, CAST(fp.pelanggan_key AS varchar)) AS label, SUM(fp.total_harga) AS total_revenue
            FROM fakta_penjualan fp
            LEFT JOIN dim_pelanggan dp ON fp.pelanggan_key = dp.pelanggan_key
            JOIN dim_waktu w ON fp.waktu_id = w.waktu_id";
        $customerWhere = [];
        $customerParams = [];
        if ($selectedYear) {
            $customerWhere[] = 'w.tahun = :y';
            $customerParams[':y'] = $selectedYear;
        }
        if ($selectedMonth) {
            $customerWhere[] = 'w.bulan = :m';
            $customerParams[':m'] = $selectedMonth;
        }
        if ($customerWhere) {
            $customerQuery .= ' WHERE ' . implode(' AND ', $customerWhere);
        }
        $customerQuery .= ' GROUP BY COALESCE(dp.pelanggan_id, CAST(fp.pelanggan_key AS varchar)) ORDER BY total_revenue DESC LIMIT 10';
        $customerStmt = $pdo->prepare($customerQuery);
        $customerStmt->execute($customerParams);
        $topCustomerItems = $customerStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($topCustomerItems as $row) {
            $topCustomerLabelsRevenue[] = $row['label'];
            $topCustomerRevenue[] = (float) $row['total_revenue'];
        }
    } catch (Exception $e) {
        $topCustomerLabelsRevenue = [];
        $topCustomerRevenue = [];
    }
} catch (Exception $e) {
    $topSellerLabelsQty = [];
    $topSellerQty = [];
    $topSellerLabelsRevenue = [];
    $topSellerRevenue = [];
    $topCustomerLabelsRevenue = [];
    $topCustomerRevenue = [];
}

?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard Data Warehouse</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        Chart.defaults.font.family = "'Plus Jakarta Sans', sans-serif";
        Chart.defaults.color = '#64748b';
    </script>
    <style>
        /* Helper tata letak chart dan peta */
        .chart-wrap { min-height: 260px; position: relative; box-sizing: border-box; padding-bottom: 1.2rem; }
        .chart-wrap canvas { width: 100% !important; height: 100% !important; display: block; }
        .card.h-100 .chart-wrap { min-height: 220px; }

        /* Lencana kecil untuk marker peta */
        .marker-badge {
            display: inline-block;
            background: rgba(0,0,0,0.7);
            color: #fff;
            padding: 3px 8px;
            border-radius: 14px;
            font-size: 12px;
            line-height: 1;
            border: 0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.25);
            white-space: nowrap;
        }
    </style>
</head>
<body class="p-4">
<div class="container">
    <h1 class="mb-4 text-gradient">Dashboard Data Warehouse</h1>

    <form method="get" class="row g-3 mb-4 align-items-end">
        <div class="col-6 col-md-3">
            <label for="year" class="form-label">Tahun</label>
            <select id="year" name="year" class="form-select">
                <option value="">Semua tahun</option>
                <?php foreach ($years as $y): ?>
                    <option value="<?php echo $y; ?>" <?php echo ($selectedYear && $selectedYear == $y) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label for="top_by" class="form-label">Top 10 Berdasarkan</label>
            <select id="top_by" name="top_by" class="form-select">
                <?php foreach ($topByOptions as $value => $label): ?>
                    <option value="<?php echo $value; ?>" <?php echo ($selectedTopBy === $value) ? 'selected' : ''; ?>><?php echo $label; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label for="month" class="form-label">Bulan</label>
            <select id="month" name="month" class="form-select">
                <option value="">Semua bulan</option>
                <?php foreach ($availableMonths as $num): ?>
                    <option value="<?php echo $num; ?>" <?php echo ($selectedMonth && $selectedMonth == $num) ? 'selected' : ''; ?>><?php echo $months[$num]; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto d-flex align-items-end gap-2">
            <button class="btn btn-primary">Terapkan</button>
            <a href="index.php" class="btn btn-secondary">Reset</a>
            <a href="perbandingan.php" class="btn btn-info text-white">Perbandingan</a>
            <a href="segmentasi.php" class="btn btn-warning text-white">Segmentasi Data</a>
        </div>
    </form>

    <div class="row g-3 mb-4">

        <div class="col-6 col-md-3">
            <div class="card kpi">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Total Pelanggan</h6>
                    <h3><?php echo number_format($kpis['total_customers']); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Total Produk</h6>
                    <h3><?php echo number_format($kpis['total_products']); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Total Kategori</h6>
                    <h3><?php echo number_format($kpis['total_categories']); ?></h3>
                </div>
            </div>
        </div>
        <!-- Kartu 'Total Order' dihapus sesuai permintaan -->
        <div class="col-6 col-md-3">
            <div class="card kpi">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Total Produk Terjual</h6>
                    <h3><?php echo number_format($kpis['total_orders']); ?></h3>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card kpi">
                <div class="card-body">
                    <h6 class="card-subtitle mb-2 text-muted">Revenue</h6>
                    <h3>R$ <?php echo number_format($kpis['total_revenue'],2,',','.'); ?></h3>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4 align-items-stretch">
        <div class="col-12 col-lg-8">
            <div class="card h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h5 class="card-title mb-1">Revenue Per Waktu</h5>
                            <p class="muted mb-1">Filter tahun untuk melihat revenue per bulan</p>
                        </div>
                    </div>
                    <div class="chart-wrap mb-3">
                        <canvas id="revenueChart"></canvas>
                    </div>
                    <details class="alert alert-light border mt-3 p-3" open>
                        <summary class="fw-semibold mb-3" style="cursor: pointer;">Insight</summary>
                        <div class="mt-2">
                            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br><?php echo $revenueInsightCurrent; ?></p>
                            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br><?php echo $revenueInsightFuture; ?></p>
                            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br><?php echo $revenueInsightAction; ?></p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4 d-flex">
            <div class="card h-100 w-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title">Metode Pembayaran Terpopuler</h5>
                    <div class="chart-wrap flex-fill mb-3">
                        <canvas id="paymentChart"></canvas>
                    </div>
                    <details class="alert alert-light border mt-auto mb-0 p-3" open>
                        <summary class="fw-semibold mb-3" style="cursor: pointer;">Insight Pembayaran</summary>
                        <div class="mt-2">
                            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br><?php echo $paymentInsightCurrent; ?></p>
                            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br><?php echo $paymentInsightFuture; ?></p>
                            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br><?php echo $paymentInsightAction; ?></p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Top 10 <?php echo $selectedTopBy === 'category' ? 'Kategori' : 'Produk'; ?> Terlaris</h5>
                    <p class="text-muted">Menampilkan top 10 <?php echo strtolower($topByOptions[$selectedTopBy]); ?> berdasarkan jumlah terjual</p>
                    <div class="chart-wrap" style="min-height: 380px;">
                        <canvas id="top10Chart"></canvas>
                    </div>
                    <details class="alert alert-light border mt-3 p-3" open>
                        <summary class="fw-semibold mb-3" style="cursor: pointer;">Insight Top 10</summary>
                        <div class="mt-2">
                            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br><?php echo $top10InsightCurrent; ?></p>
                            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br><?php echo $top10InsightFuture; ?></p>
                            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br><?php echo $top10InsightAction; ?></p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <h5 class="card-title mb-1">Top 10 Seller Terbaik</h5>
                            <p class="text-muted mb-0">Pilih tampilan jumlah barang terjual atau total revenue.</p>
                        </div>
                        <div style="width: 220px;">
                            <select id="sellerMetricSelect" class="form-select form-select-sm">
                                <option value="qty">Jumlah Barang Terjual</option>
                                <option value="revenue">Total Revenue</option>
                            </select>
                        </div>
                    </div>
                    <div class="chart-wrap" style="min-height: 400px;">
                        <canvas id="topSellerChart"></canvas>
                    </div>
                    <details class="alert alert-light border mt-3 p-3" open>
                        <summary class="fw-semibold mb-3" style="cursor: pointer;">Insight Top Seller</summary>
                        <div id="sellerInsight" class="mt-2">
                            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>Memuat data seller teratas berdasarkan metric yang dipilih.</p>
                            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>Insight akan diperbarui saat metric berubah.</p>
                            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>Gunakan informasi ini untuk memantau seller dengan performa tinggi.</p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Top 10 Pelanggan Berdasarkan Revenue Pembelian</h5>
                    <p class="text-muted">Menampilkan 10 pelanggan dengan total revenue pembelian terbesar.</p>
                    <div class="chart-wrap" style="min-height: 380px;">
                        <canvas id="topCustomerRevenueChart"></canvas>
                    </div>
                    <details class="alert alert-light border mt-3 p-3" open>
                        <summary class="fw-semibold mb-3" style="cursor: pointer;">Insight Pelanggan</summary>
                        <div id="customerInsight" class="mt-2">
                            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>Memuat data pelanggan dengan revenue pembelian tertinggi.</p>
                            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>Insight akan diperbarui berdasarkan distribusi revenue top customer.</p>
                            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>Gunakan informasi ini untuk mempertahankan pelanggan utama dan meningkatkan segmentasi loyalitas.</p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-lg-8">
            <div class="card h-100">
                <div class="card-body">
                    <div class="mb-2">
                        <h5 id="mapTitle" class="mb-2">Peta Persebaran Pelanggan di Brazil</h5>
                        <div>
                            <button id="btnPelanggan" onclick="loadMapData('pelanggan')" class="btn btn-primary btn-sm">Pelanggan</button>
                            <button id="btnSeller" onclick="loadMapData('seller')" class="btn btn-secondary btn-sm">Seller</button>
                        </div>
                    </div>
                    <div id="map" style="width: 100%; height: 520px;"></div>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-4">
            <div class="card h-100">
                <div class="card-body d-flex flex-column">
                    <h5 class="card-title">Top 5 State dengan Pelanggan Terbanyak</h5>
                    <p class="text-muted">Menampilkan 5 state dengan jumlah pelanggan terbanyak</p>
                    <div class="chart-wrap flex-fill">
                        <canvas id="stateCustomersChart"></canvas>
                    </div>
                    <details class="alert alert-light border mt-3 p-3" open>
                        <summary class="fw-semibold mb-3" style="cursor: pointer;">Insight State</summary>
                        <div id="stateInsight" class="mt-2">
                            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>Memuat 5 state dengan pelanggan terbanyak berdasarkan data saat ini.</p>
                            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>Insight akan diperbarui berdasarkan konsentrasi pelanggan di state teratas.</p>
                            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>Gunakan informasi ini untuk mengutamakan support dan strategi penetrasi di state utama.</p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">Rata-rata Waktu Pengiriman per State</h5>
                    <div class="chart-wrap" style="min-height: 340px;">
                        <canvas id="avgStateChart"></canvas>
                    </div>
                    <details class="alert alert-light border mt-3 p-3" open>
                        <summary class="fw-semibold mb-3" style="cursor: pointer;">Insight Waktu Pengiriman per State</summary>
                        <div id="avgStateInsight" class="mt-2">
                            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>Memuat rata-rata waktu pengiriman untuk setiap state.</p>
                            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>Insight akan memperlihatkan state dengan potensi keterlambatan yang paling besar.</p>
                            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>Periksa state dengan rata-rata pengiriman tertinggi untuk mempercepat pengiriman.</p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
        <div class="col-12">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">Rata-rata Waktu Pengiriman per Kategori</h5>
                    <div class="chart-wrap" style="min-height: 320px;" id="avgCategoryWrap">
                        <canvas id="avgCategoryChart"></canvas>
                    </div>
                    <details class="alert alert-light border mt-3 p-3" open>
                        <summary class="fw-semibold mb-3" style="cursor: pointer;">Insight Waktu Pengiriman per Kategori</summary>
                        <div id="avgCategoryInsight" class="mt-2">
                            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>Memuat rata-rata waktu pengiriman untuk setiap kategori produk.</p>
                            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>Insight ini akan menunjukkan kategori yang paling rentan terhadap keterlambatan.</p>
                            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>Fokus perbaikan pada kategori produk dengan rata-rata pengiriman tertinggi.</p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">Rata-rata Rating Kepuasan Pelanggan per Tahun</h5>
                    <div class="chart-wrap" style="min-height: 280px;">
                        <canvas id="ratingChart"></canvas>
                    </div>
                    <details class="alert alert-light border mt-3 p-3" open>
                        <summary class="fw-semibold mb-3" style="cursor: pointer;">Insight Rating Tahunan</summary>
                        <div id="ratingInsight" class="mt-2">
                            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>Memuat rating kepuasan pelanggan setiap tahun.</p>
                            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>Insight akan mengidentifikasi tren rating sepanjang waktu.</p>
                            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>Lihat tahun dengan rating tertinggi dan terendah untuk memperbaiki layanan.</p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-6">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">Rata-rata Rating Kepuasan Pelanggan per Bulan</h5>
                    <div class="chart-wrap" style="min-height: 280px;">
                        <canvas id="ratingMonthChart"></canvas>
                    </div>
                    <details class="alert alert-light border mt-3 p-3" open>
                        <summary class="fw-semibold mb-3" style="cursor: pointer;">Insight Rating Bulanan</summary>
                        <div id="ratingMonthInsight" class="mt-2">
                            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>Memuat rating kepuasan pelanggan per bulan.</p>
                            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>Insight ini membantu melihat apakah kepuasan menguat atau melemah.</p>
                            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>Tindak lanjuti bulan dengan rating terendah untuk meningkatkan pengalaman pelanggan.</p>
                        </div>
                    </details>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
// Data chart yang dikirim dari PHP ke JavaScript
const labels = <?php echo json_encode($labels); ?>;
const dataRevenue = <?php echo json_encode($dataRevenue); ?>;
const paymentLabels = <?php echo json_encode($paymentLabels); ?>;
const paymentData = <?php echo json_encode($paymentData); ?>;
const top10Labels = <?php echo json_encode($top10Labels); ?>;
const top10Data = <?php echo json_encode($top10Data); ?>;
const stateLabels = <?php echo json_encode($stateLabels); ?>;
const stateData = <?php echo json_encode($stateData); ?>;
const avgStateLabels = <?php echo json_encode($avgStateLabels); ?>;
const avgStateData = <?php echo json_encode($avgStateData); ?>;
const avgCategoryLabels = <?php echo json_encode($avgCategoryLabels); ?>;
const avgCategoryData = <?php echo json_encode($avgCategoryData); ?>;
const ratingLabels = <?php echo json_encode($ratingLabels); ?>;
const ratingData = <?php echo json_encode($ratingData); ?>;
const ratingMonthLabels = <?php echo json_encode($ratingMonthLabels); ?>;
const ratingMonthData = <?php echo json_encode($ratingMonthData); ?>;
const topSellerLabelsQty = <?php echo json_encode($topSellerLabelsQty); ?>;
const topSellerQty = <?php echo json_encode($topSellerQty); ?>;
const topSellerLabelsRevenue = <?php echo json_encode($topSellerLabelsRevenue); ?>;
const topSellerRevenue = <?php echo json_encode($topSellerRevenue); ?>;
const topCustomerLabelsRevenue = <?php echo json_encode($topCustomerLabelsRevenue); ?>;
const topCustomerRevenue = <?php echo json_encode($topCustomerRevenue); ?>;
const dataQty = <?php echo json_encode($dataQty); ?>;

let topSellerChart = null;

// Helper umum untuk membuat chart Chart.js berdasarkan konfigurasi yang diberikan
function createChart(canvasId, config) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) {
        console.warn(`${canvasId} canvas not found`);
        return null;
    }
    try {
        return new Chart(canvas, config);
    } catch (err) {
        console.error(`${canvasId} chart error:`, err);
        return null;
    }
}

function initCharts() {
    // Inisialisasi semua chart utama pada halaman
    createChart('revenueChart', {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    type: 'line',
                    label: 'Revenue',
                    data: dataRevenue,
                    borderColor: 'rgba(16, 185, 129, 1)',
                    backgroundColor: 'rgba(16, 185, 129, 0.4)',
                    tension: 0.2,
                    fill: true,
                    yAxisID: 'yRevenue'
                },
                {
                    type: 'bar',
                    label: 'Jumlah Produk Terjual',
                    data: dataQty,
                    backgroundColor: 'rgba(255, 159, 64, 0.7)',
                    borderColor: 'rgba(255, 159, 64, 1)',
                    borderWidth: 1,
                    yAxisID: 'yQty'
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                tooltip: {
                    mode: 'index',
                    intersect: false,
                    callbacks: {
                        title: function(items) {
                            if (!items || !items.length) return '';
                            return items[0].label;
                        },
                        label: function(context) {
                            const label = context.dataset.label || '';
                            const value = context.parsed && context.parsed.y !== undefined ? context.parsed.y : context.raw;
                            if (label === 'Revenue') {
                                return label + ': R$ ' + Number(value).toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2});
                            }
                            return label + ': ' + Number(value).toLocaleString('id-ID');
                        }
                    }
                }
            },
            scales: {
                yRevenue: {
                    type: 'linear',
                    position: 'left',
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) { return 'R$ ' + Number(value).toLocaleString('id-ID'); }
                    }
                },
                yQty: {
                    type: 'linear',
                    position: 'right',
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            }
        }
    });

    // Chart metode pembayaran paling populer
    createChart('paymentChart', {
        type: 'bar',
        data: {
            labels: paymentLabels,
            datasets: [{
                label: 'Transaction Count',
                data: paymentData,
                backgroundColor: 'rgba(99, 102, 241, 0.8)',
                borderColor: 'rgba(99, 102, 241, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });

    const isMobile = () => window.innerWidth < 768;

    // Konfigurasi chart Top 10 produk/kategori, responsif terhadap perangkat mobile
    function buildTop10Config() {
        const mobile = isMobile();
        return {
            type: 'bar',
            data: {
                labels: top10Labels,
                datasets: [{
                    label: 'Quantity Sold',
                    data: top10Data,
                    backgroundColor: 'rgba(255, 159, 64, 0.7)',
                    borderColor: 'rgba(255, 159, 64, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: mobile ? 'y' : 'x',
                responsive: true,
                maintainAspectRatio: false,
                scales: mobile ? {
                    x: { beginAtZero: true, ticks: { precision: 0 } },
                    y: {
                        ticks: {
                            callback: function(value, index) {
                                const label = this.getLabelForValue(index);
                                return label && label.length > 16 ? label.substring(0, 14) + '…' : label;
                            },
                            font: { size: 11 }
                        }
                    }
                } : {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 30,
                            callback: function(value, index) {
                                const label = this.getLabelForValue(index);
                                return label && label.length > 14 ? label.substring(0, 12) + '…' : label;
                            },
                            font: { size: 11 }
                        }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            title: function(items) {
                                return top10Labels[items[0].dataIndex] || '';
                            }
                        }
                    }
                }
            }
        };
    }

    // Render chart Top 10 produk/kategori
    let top10ChartInstance = createChart('top10Chart', buildTop10Config());

    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() {
            if (top10ChartInstance) {
                top10ChartInstance.destroy();
                top10ChartInstance = createChart('top10Chart', buildTop10Config());
            }
        }, 250);
    });

    // Chart distribusi pelanggan per state
    createChart('stateCustomersChart', {
        type: 'bar',
        data: {
            labels: stateLabels,
            datasets: [{
                label: 'Jumlah Pelanggan',
                data: stateData,
                backgroundColor: 'rgba(20, 184, 166, 0.8)',
                borderColor: 'rgba(20, 184, 166, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
    updateStateInsight();
    updateAvgStateInsight();
    updateAvgCategoryInsight();
    updateRatingInsight();
    updateRatingMonthInsight();

    // Fungsi untuk membangun teks insight berdasarkan data state pelanggan
    function updateStateInsight() {
        const insightEl = document.getElementById('stateInsight');
        if (!insightEl) return;

        if (!stateData.length || !stateLabels.length) {
            insightEl.innerHTML = '<p class="mb-0">Tidak ada data state untuk ditampilkan saat ini.</p>';
            return;
        }

        const totalCustomers = stateData.reduce((sum, value) => sum + value, 0);
        const topCustomers = Math.max(...stateData);
        const topIndex = stateData.indexOf(topCustomers);
        const topState = stateLabels[topIndex] || 'state teratas';
        const secondCustomers = stateData.slice().sort((a, b) => b - a)[1] || 0;
        const topShare = totalCustomers > 0 ? topCustomers / totalCustomers * 100 : 0;
        const gapShare = secondCustomers > 0 ? (topCustomers - secondCustomers) / secondCustomers * 100 : null;

        let currentText = `Saat ini, state dengan pelanggan terbanyak adalah ${topState} dengan ${topCustomers.toLocaleString('id-ID')} pelanggan.`;
        if (gapShare !== null) {
            currentText += gapShare >= 20
                ? ` State ini unggul sekitar ${gapShare.toFixed(1)}% dibanding state kedua.`
                : ` Persaingan dengan state kedua cukup ketat dengan selisih ${gapShare.toFixed(1)}%.`;
        }

        let futureText;
        if (topShare >= 50) {
            futureText = 'Konsentrasi pelanggan tinggi di state teratas, sehingga posisinya cenderung stabil kecuali ada perubahan besar.';
        } else if (gapShare !== null && gapShare >= 20) {
            futureText = 'State teratas masih memimpin tetapi posisi bisa berubah jika state kedua memperkuat akuisisi pelanggan.';
        } else {
            futureText = 'Distribusi pelanggan relatif seimbang di antara top 5 state, sehingga peluang perubahan peringkat masih besar.';
        }

        const actionText = topShare >= 50
            ? `Fokuskan dukungan dan akuisisi pelanggan tambahan di ${topState}, sambil menjaga layanan untuk state lain.`
            : 'Perkuat upaya pemasaran di beberapa state teratas dan pertimbangkan strategi lokal untuk meningkatkan penetrasi pelanggan.';

        insightEl.innerHTML = `
            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>${currentText}</p>
            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>${futureText}</p>
            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>${actionText}</p>
        `;
    }

    // Fungsi insight untuk chart rata-rata waktu pengiriman per state
    function updateAvgStateInsight() {
        const insightEl = document.getElementById('avgStateInsight');
        if (!insightEl) return;
        if (!avgStateData.length || !avgStateLabels.length) {
            insightEl.innerHTML = '<p class="mb-0">Tidak ada data waktu pengiriman per state untuk ditampilkan saat ini.</p>';
            return;
        }

        const maxValue = Math.max(...avgStateData);
        const minValue = Math.min(...avgStateData);
        const maxIndex = avgStateData.indexOf(maxValue);
        const minIndex = avgStateData.indexOf(minValue);
        const maxState = avgStateLabels[maxIndex] || 'state tertinggi';
        const minState = avgStateLabels[minIndex] || 'state terendah';
        const range = maxValue - minValue;

        const currentText = `Saat ini, state dengan rata-rata pengiriman paling lama adalah ${maxState} (${maxValue.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} hari), sementara ${minState} memiliki rata-rata paling cepat (${minValue.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} hari).`;
        const futureText = maxValue >= 10
            ? 'Negara bagian dengan waktu pengiriman yang paling lambat cenderung terus mengalami keterlambatan jika tidak ada intervensi logistik.'
            : range >= 3
                ? 'Variasi pengiriman antar state cukup besar, sehingga beberapa state masih dapat mempercepat prosesnya.'
                : 'Performa pengiriman antar state relatif konsisten, sehingga kecenderungan stabil lebih mungkin terjadi.';
        const actionText = range >= 3
            ? `Fokuskan perbaikan proses pada ${maxState} sambil meniru praktik ${minState} yang lebih cepat.`
            : `Pertahankan standar layanan saat ini dan cari peluang untuk mempercepat pengiriman di state yang lebih lambat.`;

        insightEl.innerHTML = `
            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>${currentText}</p>
            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>${futureText}</p>
            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>${actionText}</p>
        `;
    }

    // Fungsi insight untuk chart rata-rata waktu pengiriman per kategori
    function updateAvgCategoryInsight() {
        const insightEl = document.getElementById('avgCategoryInsight');
        if (!insightEl) return;
        if (!avgCategoryData.length || !avgCategoryLabels.length) {
            insightEl.innerHTML = '<p class="mb-0">Tidak ada data waktu pengiriman per kategori untuk ditampilkan saat ini.</p>';
            return;
        }

        const maxValue = Math.max(...avgCategoryData);
        const minValue = Math.min(...avgCategoryData);
        const maxIndex = avgCategoryData.indexOf(maxValue);
        const minIndex = avgCategoryData.indexOf(minValue);
        const maxCategory = avgCategoryLabels[maxIndex] || 'kategori tertinggi';
        const minCategory = avgCategoryLabels[minIndex] || 'kategori terendah';
        const range = maxValue - minValue;

        const currentText = `Saat ini, kategori dengan rata-rata pengiriman paling lama adalah ${maxCategory} (${maxValue.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} hari), sementara ${minCategory} memiliki rata-rata paling cepat (${minValue.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} hari).`;
        const futureText = maxValue >= 10
            ? 'Kategori dengan pengiriman lambat berisiko menyebabkan pengalaman pelanggan menurun jika tidak diatasi segera.'
            : range >= 3
                ? 'Perbedaan pengiriman antar kategori cukup menonjol, sehingga ada peluang optimasi khusus kategori.'
                : 'Rentang pengiriman antar kategori relatif kecil, sehingga peningkatan dapat dilakukan secara menyeluruh.';
        const actionText = range >= 3
            ? `Tinjau kembali proses logistik untuk kategori ${maxCategory} dan tingkatkan koordinasi bersama seller terkait.`
            : `Jaga konsistensi layanan dan dorong perbaikan kecil untuk menurunkan rata-rata pengiriman di semua kategori.`;

        insightEl.innerHTML = `
            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>${currentText}</p>
            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>${futureText}</p>
            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>${actionText}</p>
        `;
    }

    // Fungsi insight untuk chart rating kepuasan tahunan
    function updateRatingInsight() {
        const insightEl = document.getElementById('ratingInsight');
        if (!insightEl) return;
        if (!ratingData.length || !ratingLabels.length) {
            insightEl.innerHTML = '<p class="mb-0">Tidak ada data rating tahunan untuk ditampilkan saat ini.</p>';
            return;
        }

        const maxValue = Math.max(...ratingData);
        const minValue = Math.min(...ratingData);
        const maxIndex = ratingData.indexOf(maxValue);
        const minIndex = ratingData.indexOf(minValue);
        const bestYear = ratingLabels[maxIndex] || 'tahun terbaik';
        const worstYear = ratingLabels[minIndex] || 'tahun terendah';
        const average = ratingData.reduce((sum, value) => sum + value, 0) / ratingData.length;

        const currentText = `Saat ini, rating tertinggi tercatat pada ${bestYear} (${maxValue.toFixed(2)}), sementara ${worstYear} memiliki rating terendah (${minValue.toFixed(2)}). Rata-rata rating tahunan adalah ${average.toFixed(2)}.`;
        const futureText = average >= 4.2
            ? 'Secara keseluruhan rating tahunan cukup kuat dan cenderung stabil jika kualitas layanan tetap terjaga.'
            : average >= 3.5
                ? 'Rating berada pada level menengah, sehingga ada ruang untuk peningkatan kepuasan pelanggan.'
                : 'Rating masih rendah dan memerlukan perhatian segera untuk memperbaiki pengalaman pelanggan.';
        const actionText = average >= 4.2
            ? `Pertahankan upaya pada ${bestYear} dan terus ukur kinerja setiap tahun.`
            : ` Fokuskan perbaikan pada faktor yang paling memengaruhi rating, terutama untuk tahun ${worstYear}.`;

        insightEl.innerHTML = `
            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>${currentText}</p>
            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>${futureText}</p>
            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>${actionText}</p>
        `;
    }

    // Fungsi insight untuk chart rating kepuasan bulanan
    function updateRatingMonthInsight() {
        const insightEl = document.getElementById('ratingMonthInsight');
        if (!insightEl) return;
        if (!ratingMonthData.length || !ratingMonthLabels.length) {
            insightEl.innerHTML = '<p class="mb-0">Tidak ada data rating bulanan untuk ditampilkan saat ini.</p>';
            return;
        }

        const maxValue = Math.max(...ratingMonthData);
        const minValue = Math.min(...ratingMonthData);
        const maxIndex = ratingMonthData.indexOf(maxValue);
        const minIndex = ratingMonthData.indexOf(minValue);
        const bestMonth = ratingMonthLabels[maxIndex] || 'bulan terbaik';
        const worstMonth = ratingMonthLabels[minIndex] || 'bulan terendah';
        const firstRating = ratingMonthData[0] || 0;
        const lastRating = ratingMonthData[ratingMonthData.length - 1] || 0;
        const trend = lastRating - firstRating;

        const currentText = `Saat ini, rata-rata rating tertinggi tercatat pada ${bestMonth} (${maxValue.toFixed(2)}), sedangkan terendah pada ${worstMonth} (${minValue.toFixed(2)}).`;
        const futureText = trend > 0
            ? 'Rating bulanan menunjukkan tren peningkatan, menandakan perbaikan kepuasan pelanggan.'
            : trend < 0
                ? 'Rating bulanan menurun, jadi perlu diwaspadai agar pengalaman pelanggan tidak melemah.'
                : 'Rating bulanan relatif stabil tanpa perubahan signifikan antara awal dan akhir periode.';
        const actionText = trend < 0
            ? `Tinjau kembali operasi pada bulan terakhir untuk menemukan penyebab turunnya rating.`
            : `Pertahankan elemen layanan yang mendukung kenaikan rating dan perkuat bulan-bulan dengan pencapaian baik.`;

        insightEl.innerHTML = `
            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>${currentText}</p>
            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>${futureText}</p>
            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>${actionText}</p>
        `;
    }

    createChart('avgStateChart', {
        type: 'bar',
        data: {
            labels: avgStateLabels,
            datasets: [{
                label: 'Rata-rata Pengiriman (hari)',
                data: avgStateData,
                backgroundColor: 'rgba(54, 162, 235, 0.8)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { precision: 0 }
                }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const value = context.parsed.x;
                            const dsLabel = context.dataset && context.dataset.label ? context.dataset.label : 'Rata-rata';
                            return `${dsLabel}: ${value.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} hari`;
                        }
                    }
                }
            }
        }
    });

    const truncateCat = (lbl, max) => lbl && lbl.length > max ? lbl.substring(0, max - 2) + '…' : lbl;

    function buildCategoryChartConfig() {
        const mobile = window.innerWidth < 768;
        return {
            type: 'bar',
            data: {
                labels: avgCategoryLabels,
                datasets: [{
                    label: 'Rata-rata Pengiriman (hari)',
                    data: avgCategoryData,
                    backgroundColor: 'rgba(139, 92, 246, 0.8)',
                    borderColor: 'rgba(139, 92, 246, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: mobile ? 'y' : 'x',
                responsive: true,
                maintainAspectRatio: false,
                scales: mobile ? {
                    x: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    },
                    y: {
                        afterFit: function(scale) { scale.width = Math.max(scale.width, 140); },
                        ticks: {
                            autoSkip: false,
                            callback: function(value, index) {
                                return truncateCat(this.getLabelForValue(index), 18);
                            },
                            font: { size: 11 }
                        }
                    }
                } : {
                    x: {
                        ticks: {
                            autoSkip: false,
                            maxRotation: 75,
                            minRotation: 45,
                            align: 'start'
                        }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0 }
                    }
                },
                plugins: {
                    tooltip: {
                        callbacks: {
                            title: function(items) {
                                return avgCategoryLabels[items[0].dataIndex] || '';
                            },
                            label: function(context) {
                                const dsLabel = context.dataset && context.dataset.label ? context.dataset.label : 'Rata-rata';
                                const val = mobile ? context.parsed.x : context.parsed.y;
                                return `${dsLabel}: ${val.toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} hari`;
                            }
                        }
                    }
                }
            }
        };
    }

    let avgCategoryChartInstance = null;
    function renderAvgCategoryChart() {
        const mobile = window.innerWidth < 768;
        const wrap = document.getElementById('avgCategoryWrap');
        if (wrap) {
            wrap.style.minHeight = mobile ? '1000px' : '320px';
        }
        
        if (avgCategoryChartInstance) {
            avgCategoryChartInstance.destroy();
        }
        avgCategoryChartInstance = createChart('avgCategoryChart', buildCategoryChartConfig());
    }

    renderAvgCategoryChart();

    let catResizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(catResizeTimer);
        catResizeTimer = setTimeout(function() {
            renderAvgCategoryChart();
        }, 250);
    });

    const truncateCust = (lbl, max) => lbl && lbl.length > max ? lbl.substring(0, max - 2) + '…' : lbl;

    function buildCustomerChartConfig() {
        const mobile = window.innerWidth < 768;
        return {
            type: 'bar',
            data: {
                labels: topCustomerLabelsRevenue,
                datasets: [{
                    label: 'Revenue Pembelian',
                    data: topCustomerRevenue,
                    backgroundColor: 'rgba(236, 72, 153, 0.8)',
                    borderColor: 'rgba(236, 72, 153, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: mobile ? 'y' : 'x',
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            title: function(items) {
                                return topCustomerLabelsRevenue[items[0].dataIndex] || '';
                            },
                            label: function(context) {
                                const val = mobile ? context.parsed.x : context.parsed.y;
                                return `Revenue: R$ ${val.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
                            }
                        }
                    }
                },
                scales: mobile ? {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            callback: function(value) {
                                return 'R$ ' + Number(value).toLocaleString('id-ID');
                            }
                        }
                    },
                    y: {
                        afterFit: function(scale) { scale.width = Math.max(scale.width, 130); },
                        ticks: {
                            callback: function(value, index) {
                                return truncateCust(this.getLabelForValue(index), 16);
                            },
                            font: { size: 11 }
                        }
                    }
                } : {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            precision: 0,
                            callback: function(value) {
                                return 'R$ ' + Number(value).toLocaleString('id-ID');
                            }
                        }
                    },
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 30,
                            callback: function(value, index) {
                                return truncateCust(this.getLabelForValue(index), 14);
                            },
                            font: { size: 11 }
                        }
                    }
                }
            }
        };
    }

    function formatCurrency(value) {
        return 'R$ ' + Number(value).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function updateCustomerInsight() {
        const insightEl = document.getElementById('customerInsight');
        if (!insightEl) return;

        if (!topCustomerRevenue.length || !topCustomerLabelsRevenue.length) {
            insightEl.innerHTML = '<p class="mb-0">Tidak ada data customer untuk ditampilkan saat ini.</p>';
            return;
        }

        const totalRevenue = topCustomerRevenue.reduce((sum, value) => sum + value, 0);
        const topRevenue = Math.max(...topCustomerRevenue);
        const topIndex = topCustomerRevenue.indexOf(topRevenue);
        const topCustomer = topCustomerLabelsRevenue[topIndex] || 'pelanggan teratas';
        const secondRevenue = topCustomerRevenue.slice().sort((a, b) => b - a)[1] || 0;
        const topShare = totalRevenue > 0 ? topRevenue / totalRevenue * 100 : 0;
        const gapShare = secondRevenue > 0 ? (topRevenue - secondRevenue) / secondRevenue * 100 : null;

        let currentText = `Saat ini, pelanggan teratas adalah ${topCustomer} dengan revenue pembelian ${formatCurrency(topRevenue)}.`;
        if (gapShare !== null) {
            currentText += gapShare >= 20
                ? ` Pelanggan ini unggul sekitar ${gapShare.toFixed(1)}% dibanding pelanggan kedua.`
                : ` Persaingan dengan pelanggan kedua cukup ketat dengan selisih hanya ${gapShare.toFixed(1)}%.`;
        }

        let futureText;
        if (topShare >= 50) {
            futureText = 'Dominasi pelanggan teratas cukup kuat, sehingga kemungkinan besar akan tetap menjadi pelanggan utama jika pembelian tidak berubah.';
        } else if (gapShare !== null && gapShare >= 20) {
            futureText = 'Pelanggan teratas masih memimpin, tetapi ada ruang bagi pelanggan lain untuk menyusul jika pembelian mereka meningkat.';
        } else {
            futureText = 'Persaingan revenue pembelian antar pelanggan cukup ketat, sehingga peringkat pelanggan bisa berubah dengan cepat.';
        }

        const actionText = topShare >= 50
            ? `Pertahankan layanan khusus untuk ${topCustomer} dan pastikan pengalaman pembelian konsisten.`
            : `Perkuat hubungan dengan beberapa pelanggan top dan evaluasi peluang loyalitas untuk mempertahankan pendapatan.`;

        insightEl.innerHTML = `
            <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>${currentText}</p>
            <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>${futureText}</p>
            <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>${actionText}</p>
        `;
    }

    let customerChartInstance = createChart('topCustomerRevenueChart', buildCustomerChartConfig());
    updateCustomerInsight();

    let customerResizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(customerResizeTimer);
        customerResizeTimer = setTimeout(function() {
            if (customerChartInstance) {
                customerChartInstance.destroy();
                customerChartInstance = createChart('topCustomerRevenueChart', buildCustomerChartConfig());
                updateCustomerInsight();
            }
        }, 250);
    });

    // Rating charts
    createChart('ratingChart', {
        type: 'bar',
        data: {
            labels: ratingLabels,
            datasets: [{
                label: 'Rata-rata Rating (Skor)',
                data: ratingData,
                backgroundColor: 'rgba(54, 162, 235, 0.8)',
                borderColor: 'rgba(54, 162, 235, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 5,
                    ticks: { stepSize: 0.5 }
                }
            }
        }
    });

    createChart('ratingMonthChart', {
        type: 'bar',
        data: {
            labels: ratingMonthLabels,
            datasets: [{
                label: 'Rata-rata Rating (Skor)',
                data: ratingMonthData,
                backgroundColor: 'rgba(245, 158, 11, 0.8)',
                borderColor: 'rgba(245, 158, 11, 1)',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true,
                    max: 5,
                    ticks: { stepSize: 0.5 }
                }
            }
        }
    });
}

// Buat konfigurasi chart Top Seller berdasarkan metric yang dipilih (qty/revenue)
function getTopSellerChartConfig(metric) {
    const isRevenue = metric === 'revenue';
    const mobile = window.innerWidth < 768;
    const labels = isRevenue ? topSellerLabelsRevenue : topSellerLabelsQty;
    const data   = isRevenue ? topSellerRevenue : topSellerQty;

    const truncate = (lbl, max) => lbl && lbl.length > max ? lbl.substring(0, max - 2) + '…' : lbl;

    const xTicksMobile = {
        callback: function(value, index) {
            return truncate(this.getLabelForValue(index), 16);
        },
        font: { size: 11 }
    };
    const yAxisMobile = {
        afterFit: function(scale) { scale.width = Math.max(scale.width, 130); },
        ticks: xTicksMobile
    };
    const xTicksDesktop = {
        maxRotation: 45,
        minRotation: 30,
        callback: function(value, index) {
            return truncate(this.getLabelForValue(index), 14);
        },
        font: { size: 11 }
    };

    return {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: isRevenue ? 'Total Revenue' : 'Jumlah Terjual',
                data: data,
                backgroundColor: isRevenue ? 'rgba(16, 185, 129, 0.8)' : 'rgba(139, 92, 246, 0.8)',
                borderColor: isRevenue ? 'rgba(16, 185, 129, 1)' : 'rgba(139, 92, 246, 1)',
                borderWidth: 1
            }]
        },
        options: {
            indexAxis: mobile ? 'y' : 'x',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                title: {
                    display: true,
                    text: isRevenue ? 'Top 10 Seller berdasarkan Total Revenue' : 'Top 10 Seller berdasarkan Jumlah Terjual'
                },
                tooltip: {
                    callbacks: {
                        title: function(items) {
                            const idx = items[0].dataIndex;
                            return labels[idx] || '';
                        },
                        label: function(context) {
                            const value = mobile ? context.parsed.x : context.parsed.y;
                            return isRevenue
                                ? `Revenue: R$ ${value.toLocaleString('id-ID', {minimumFractionDigits: 2, maximumFractionDigits: 2})}`
                                : `Jumlah Terjual: ${value.toLocaleString('id-ID')}`;
                        }
                    }
                }
            },
            scales: mobile ? {
                x: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0,
                        callback: function(value) {
                            return isRevenue ? 'R$ ' + value.toLocaleString('id-ID') : value.toLocaleString('id-ID');
                        }
                    }
                },
                y: { ticks: xTicksMobile, afterFit: yAxisMobile.afterFit }
            } : {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0,
                        callback: function(value) {
                            return isRevenue ? 'R$ ' + value.toLocaleString('id-ID') : value.toLocaleString('id-ID');
                        }
                    }
                },
                x: { ticks: xTicksDesktop }
            }
        }
    };
}

let currentSellerMetric = 'qty';

// Render ulang chart Top Seller saat metric diubah atau ukuran layar berubah
function renderTopSellerChart(metric = 'qty') {
    currentSellerMetric = metric === 'revenue' ? 'revenue' : 'qty';
    if (topSellerChart) {
        topSellerChart.destroy();
        topSellerChart = null;
    }
    topSellerChart = createChart('topSellerChart', getTopSellerChartConfig(currentSellerMetric));
    updateSellerInsight(currentSellerMetric);
}

function formatSellerNumber(value, isRevenue) {
    if (isRevenue) {
        return 'R$ ' + Number(value).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    return Number(value).toLocaleString('id-ID');
}

// Update teks insight Top Seller berdasarkan data chart yang aktif
function updateSellerInsight(metric) {
    const isRevenue = metric === 'revenue';
    const labels = isRevenue ? topSellerLabelsRevenue : topSellerLabelsQty;
    const data = isRevenue ? topSellerRevenue : topSellerQty;
    const insightEl = document.getElementById('sellerInsight');
    if (!insightEl || data.length === 0) {
        if (insightEl) {
            insightEl.innerHTML = '<p class="mb-0">Tidak ada data seller untuk ditampilkan saat ini.</p>';
        }
        return;
    }

    const total = data.reduce((sum, value) => sum + value, 0);
    const topValue = Math.max(...data);
    const topIndex = data.indexOf(topValue);
    const topLabel = labels[topIndex] || 'seller teratas';
    const secondValues = data.slice().sort((a, b) => b - a);
    const secondValue = secondValues.length > 1 ? secondValues[1] : 0;
    const topShare = total > 0 ? (topValue / total) * 100 : 0;
    const gapShare = secondValue > 0 ? ((topValue - secondValue) / secondValue) * 100 : null;

    let currentText = `Saat ini, seller teratas berdasarkan ${isRevenue ? 'revenue' : 'jumlah terjual'} adalah ${topLabel} dengan ${formatSellerNumber(topValue, isRevenue)}.`;
    if (secondValue > 0) {
        currentText += gapShare >= 20
            ? ` Seller ini unggul sekitar ${gapShare.toFixed(1)}% dibanding seller kedua.`
            : ` Persaingan cukup ketat dengan seller kedua hanya ${gapShare.toFixed(1)}% lebih sedikit.`;
    }

    let futureText;
    if (topShare >= 50) {
        futureText = 'Dominasi seller teratas cukup kuat, sehingga posisinya kemungkinan besar akan bertahan jika pola penjualan tidak berubah.';
    } else if (gapShare !== null && gapShare >= 20) {
        futureText = 'Seller teratas masih memimpin, tetapi posisi bisa bergeser jika pesaing meningkatkan penjualan atau revenue.';
    } else {
        futureText = 'Persaingan seller cukup ketat, sehingga peringkat bisa berubah dengan cepat jika ada pergeseran permintaan.';
    }

    const actionText = isRevenue
        ? `Pantau seller ${topLabel} untuk memastikan revenue tetap optimal, dan pertimbangkan insentif jika pesaing mulai mengejar.`
        : `Pertahankan performa seller ${topLabel} dan perkuat dukungan pada seller lain yang menunjukkan potensi naik.`;

    insightEl.innerHTML = `
        <p class="mb-2"><strong>Apa yang terjadi saat ini?</strong><br>${currentText}</p>
        <p class="mb-2"><strong>Apa yang akan terjadi di masa depan?</strong><br>${futureText}</p>
        <p class="mb-0"><strong>Apa yang harus dilakukan?</strong><br>${actionText}</p>
    `;
}

window.addEventListener('load', () => {
    initCharts();
    renderTopSellerChart('qty');
    const sellerMetricSelect = document.getElementById('sellerMetricSelect');
    if (sellerMetricSelect) {
        sellerMetricSelect.addEventListener('change', function() {
            renderTopSellerChart(this.value);
        });
    }

    let sellerResizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(sellerResizeTimer);
        sellerResizeTimer = setTimeout(function() {
            renderTopSellerChart(currentSellerMetric);
        }, 250);
    });

    try { map.invalidateSize(); } catch (e) {}
});


// Inisialisasi peta Leaflet untuk visualisasi persebaran geografis
const map = L.map('map').setView([-14.2350, -51.9253], 4);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    attribution: '© OpenStreetMap contributors'
}).addTo(map);

let markersLayer = L.layerGroup().addTo(map);

// Ambil data peta dari backend dan tampilkan pada peta Leaflet
function loadMapData(tipe = 'pelanggan') {
    fetch(`distribusi_state.php?tipe=${tipe}`)
        .then(res => res.json())
        .then(json => {
            if (json.error) {
                console.error('Error:', json.error);
                return;
            }
            renderMarkers(json.data, json.max);
            try { map.invalidateSize(); } catch (e) {}
        })
        .catch(err => console.error('Kesalahan fetch:', err));
}

// Render marker peta berdasarkan data jumlah pengguna atau seller per lokasi
function renderMarkers(data, maxVal) {
    markersLayer.clearLayers();

    // Hitung nilai jumlah dan tentukan nilai minimum/maksimum
    const counts = data.map(d => (typeof d.jumlah === 'number' ? d.jumlah : 0));
    const minCount = counts.length ? Math.min(...counts) : 0;
    const maxCount = maxVal || (counts.length ? Math.max(...counts) : 1);

    data.forEach(item => {
        // Rentang radius dalam meter (sesuaikan dengan zoom level)
        const minRadius = 15000; // ukuran dasar lebih besar
        const maxRadius = 200000; // ukuran maksimum lebih besar untuk jumlah besar


        const count = item.jumlah || 0;

        let radius;
        // Normalisasi dan skala akar kuadrat agar area lebih sesuai dengan jumlah
        const norm = (maxCount === minCount) ? 0.5 : Math.max(0, Math.min(1, (count - minCount) / (maxCount - minCount)));
        const scaled = Math.sqrt(norm);
        radius = minRadius + scaled * (maxRadius - minRadius);

        function getColorByRatio(r) {
            if (r >= 0.66) return '#e74c3c'; // banyak - merah
            if (r >= 0.33) return '#f1c40f'; // sedang - kuning
            return '#2ecc71'; // sedikit - hijau
        }

        const ratio = count / (maxCount || 1);
        const color = getColorByRatio(ratio);

        const circle = L.circle([item.lat, item.lng], {
            color: color,
            fillColor: color,
            fillOpacity: 0.7,
            radius: radius
        });

        // Tambahkan marker pusat kecil agar lingkaran tidak terlihat kosong
        // pixelRadius diatur dari 6..18 sesuai nilai skala
        const pixelRadius = Math.round(6 + scaled * 12);
        const inner = L.circleMarker([item.lat, item.lng], {
            radius: pixelRadius,
            color: '#ffffff',
            weight: 2,
            fillColor: color,
            fillOpacity: 1
        });

        circle.bindPopup(`<b>${item.nama} (${item.state})</b><br>Jumlah: <strong>${item.jumlah.toLocaleString('id-ID')}</strong>`);
        circle.bindTooltip(`${item.nama}: ${item.jumlah.toLocaleString('id-ID')}`, { permanent: false, direction: 'top' });

        markersLayer.addLayer(circle);
        markersLayer.addLayer(inner);

        // Tambahkan lencana kecil yang menampilkan jumlah untuk membuat marker lebih informatif
        const badgeHtml = `<div class="marker-badge">${item.jumlah.toLocaleString('id-ID')}</div>`;
        const badgeIcon = L.divIcon({
            html: badgeHtml,
            className: '',
            iconSize: null,
            iconAnchor: [0, -(pixelRadius + 12)]
        });
        const badge = L.marker([item.lat, item.lng], { icon: badgeIcon, interactive: false });
        markersLayer.addLayer(badge);
    });
}

loadMapData('pelanggan');

</script>

</body>
</html>
