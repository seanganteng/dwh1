<?php
function getPDOForDb(string $db): PDO
{
    // Ubah pengaturan ini sesuai lingkungan Anda
    $host = 'postgres.railway.internal';
    $port = '5432';
    $user = 'postgres';
    $pass = 'rUQCRgVgccITRegFsTFjayhCfGZsqIUF';
    
    $dsn = "pgsql:host={$host};port={$port};dbname={$db}";

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        // Tampilkan halaman error sederhana jika koneksi gagal
        http_response_code(500);
        echo "<h1>Database connection error to {$db}</h1>";
        echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
        exit;
    }
}

function getPDO(): PDO
{
    return getPDOForDb('db_dwh3project');
}

function getOltpPDO(): PDO
{
    return getPDOForDb('db_dwh3');
}
