# DWH Dashboard (PHP + PostgreSQL)

Simple dashboard to display KPIs from `db_dwh3project`.

Setup
1. Put this folder in your webroot (e.g. `c:\xampp\htdocs\dwhbaru`).
2. Enable PHP `pdo_pgsql` extension and ensure PostgreSQL is running.
3. Edit `inc/db.php` to set database credentials.
4. Open `http://localhost/dwhbaru/` in your browser.

Queries used by the dashboard are simple aggregates on the dimension and fact tables described in your SQL.
