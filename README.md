# Philomath Mini Mart

A PHP 8 mini-mart system with secure login, inventory, transactional checkout, printable receipts, sales history, and store settings.

## Run locally

```bash
php -S localhost:8000
```

Open `http://localhost:8000` and sign in with `admin` / `admin123`. Change the default password immediately under **Settings**.

## Requirements

- PHP 8.1 or newer
- PDO SQLite extension
- Write access to the project directory (the `data/` directory is created automatically)

## Architecture

- `index.php` — responsive application shell
- `api.php` — authenticated JSON API
- `lib/bootstrap.php` — sessions, database, migrations, and initial data
- `assets/` — application CSS and JavaScript
- `data/philomart.sqlite` — runtime database (ignored by Git)

Checkout uses a database transaction, so a receipt and its stock deductions always succeed or fail together. Prices are read from the database during checkout rather than trusted from the browser.

> GitHub Pages cannot execute PHP. Deploy this version to PHP hosting, Apache/Nginx, or use PHP's built-in server.
