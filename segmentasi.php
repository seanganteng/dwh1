<?php
require_once __DIR__ . '/inc/db.php';

$pdo = getPDO();

$selectedYear = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : null;
$selectedMonth = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;

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

// Daftar tahun yang tersedia untuk filter
try {
    $yearsStmt = $pdo->query('SELECT DISTINCT tahun FROM dim_waktu ORDER BY tahun DESC');
    $years = $yearsStmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Exception $e) {
    $years = [];
}

// Daftar bulan yang tersedia berdasarkan tahun yang dipilih
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

// Bangun klausa WHERE berdasarkan filter yang dipilih
$whereClause = [];
$params = [];
if ($selectedYear) {
    $whereClause[] = 'w.tahun = :y';
    $params[':y'] = $selectedYear;
}
if ($selectedMonth) {
    $whereClause[] = 'w.bulan = :m';
    $params[':m'] = $selectedMonth;
}
$whereSql = $whereClause ? 'WHERE ' . implode(' AND ', $whereClause) : '';

// Top 10 pelanggan berdasarkan total revenue
$topCustomers = [];
try {
    $query = "SELECT
        COALESCE(dp.pelanggan_id, CAST(fp.pelanggan_key AS varchar)) AS pelanggan_id,
        SUM(fp.jumlah) AS total_qty,
        SUM(fp.total_harga) AS total_revenue
    FROM fakta_penjualan fp
    LEFT JOIN dim_pelanggan dp ON fp.pelanggan_key = dp.pelanggan_key
    JOIN dim_waktu w ON fp.waktu_id = w.waktu_id
    $whereSql
    GROUP BY COALESCE(dp.pelanggan_id, CAST(fp.pelanggan_key AS varchar))
    ORDER BY total_revenue DESC
    LIMIT 10";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $topCustomers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $topCustomers = [];
}

// Top 10 seller berdasarkan total revenue
$topSellers = [];
try {
    $query = "SELECT
        ds.seller_id,
        SUM(fp.jumlah) AS total_qty,
        SUM(fp.total_harga) AS total_revenue
    FROM fakta_penjualan fp
    JOIN dim_seller ds ON fp.seller_key = ds.seller_key
    JOIN dim_waktu w ON fp.waktu_id = w.waktu_id
    $whereSql
    GROUP BY ds.seller_id
    ORDER BY total_revenue DESC
    LIMIT 10";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $topSellers = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $topSellers = [];
}

// Benefit untuk pelanggan
$customerBenefits = [
    'Gold' => 'Cashback 15%, VIP Treatment, Exclusive Access',
    'Silver' => 'Cashback 10%, Early Access',
    'Bronze' => 'Cashback 5%, Birthday Discount'
];

// Benefit untuk seller
$sellerBenefits = [
    'Gold' => 'Fee 0%, Premium Support, Featured Placement',
    'Silver' => 'Fee 5%, Priority Support',
    'Bronze' => 'Fee 10%, Standard Support'
];
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Segmentasi Data - Top 10 Pelanggan & Seller</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="p-4">
<div class="container">
    <div class="row mb-4">
        <div class="col-12 col-md-8">
            <h1 class="mb-0 text-gradient">Segmentasi Data</h1>
        </div>
        <div class="col-12 col-md-4 mt-2 mt-md-0 text-md-end">
            <a href="index.php" class="btn btn-secondary">Kembali ke Dashboard</a>
        </div>
    </div>

    <!-- Penjelasan Segmentasi -->
    <div class="alert alert-info mb-4">
        <h5 class="alert-heading"><strong>Cara Penentuan Tier (Segmentasi):</strong></h5>
        <hr>
        <p class="mb-2">Segmentasi dilakukan berdasarkan <strong>peringkat (ranking) total revenue</strong> dari 10 pelanggan/seller teratas:</p>
        <ul class="mb-0">
            <li><strong>Gold</strong>: Peringkat 1-3 (Top 3) - Revenue tertinggi</li>
            <li><strong>Silver</strong>: Peringkat 4-6 - Revenue menengah</li>
            <li><strong>Bronze</strong>: Peringkat 7-10 - Revenue terendah dari top 10</li>
        </ul>
    </div>

    <!-- Filter -->
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
            <a href="segmentasi.php" class="btn btn-secondary">Reset</a>
        </div>
    </form>

    <!-- Top 10 Pelanggan -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">Top 10 Pelanggan Berdasarkan Total Revenue</h5>
                    <p class="text-muted">Segmentasi: Gold (Top 3), Silver (4-6), Bronze (7-10)</p>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>ID Pelanggan</th>
                                    <th>Tier</th>
                                    <th>Revenue</th>
                                    <th>Benefit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topCustomers)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">Tidak ada data.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($topCustomers as $i => $row): ?>
                                        <?php
                                            $tier = $i < 3 ? 'Gold' : ($i < 6 ? 'Silver' : 'Bronze');
                                        ?>
                                        <tr>
                                            <td><?php echo $i + 1; ?></td>
                                            <td><?php echo htmlspecialchars($row['pelanggan_id'] ?? '-'); ?></td>
                                            <td>
                                                <?php if ($tier === 'Gold'): ?>
                                                    <span class="badge bg-warning text-dark">Gold</span>
                                                <?php elseif ($tier === 'Silver'): ?>
                                                    <span class="badge bg-secondary">Silver</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Bronze</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>R$ <?php echo number_format((float)($row['total_revenue'] ?? 0), 2, ',', '.'); ?></td>
                                            <td><?php echo $customerBenefits[$tier]; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Top 10 Seller -->
    <div class="row g-3 mb-4">
        <div class="col-12">
            <div class="card h-100">
                <div class="card-body">
                    <h5 class="card-title">Top 10 Seller Berdasarkan Total Revenue</h5>
                    <p class="text-muted">Segmentasi: Gold (Top 3), Silver (4-6), Bronze (7-10)</p>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>ID Seller</th>
                                    <th>Tier</th>
                                    <th>Revenue</th>
                                    <th>Benefit</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($topSellers)): ?>
                                    <tr>
                                        <td colspan="5" class="text-center text-muted">Tidak ada data.</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($topSellers as $i => $row): ?>
                                        <?php
                                            $tier = $i < 3 ? 'Gold' : ($i < 6 ? 'Silver' : 'Bronze');
                                        ?>
                                        <tr>
                                            <td><?php echo $i + 1; ?></td>
                                            <td><?php echo htmlspecialchars($row['seller_id'] ?? '-'); ?></td>
                                            <td>
                                                <?php if ($tier === 'Gold'): ?>
                                                    <span class="badge bg-warning text-dark">Gold</span>
                                                <?php elseif ($tier === 'Silver'): ?>
                                                    <span class="badge bg-secondary">Silver</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Bronze</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>R$ <?php echo number_format((float)($row['total_revenue'] ?? 0), 2, ',', '.'); ?></td>
                                            <td><?php echo $sellerBenefits[$tier]; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
</body>
</html>