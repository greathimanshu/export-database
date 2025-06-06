# 📦 Laravel MySQL Database Backup to Google Drive

This project is a custom Laravel Artisan command that backs up a MySQL database and uploads it to Google Drive — using a Google Service Account.

> 👑 Owner: **greatHimanshu**

---

## 🚀 Features

- 🔐 Secure upload using Google Service Account
- 💾 Backup database using `mysqldump`
- ☁️ Save `.sql` file to Google Drive
- 🧹 Delete local file after upload
- 📘 Clean and simple output

---

## 🔧 Requirements

- PHP 8.1+
- Laravel 12
- Composer
- Google Drive API access
- MySQL in XAMPP/WAMP (with `mysqldump`)
- A Google Cloud project and service account JSON key

---

## 📁 Installation Guide

### 1. Clone the project

```bash
git clone https://github.com/greatHimanshu/export-database.git
cd export-database
composer install
```

### 2. Set up `.env`

```env
APP_NAME=Laravel
APP_ENV=local
APP_KEY=base64:GENERATED_KEY
APP_DEBUG=true
APP_URL=http://localhost

LOG_CHANNEL=stack
LOG_LEVEL=debug

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=export_database
DB_USERNAME=root
DB_PASSWORD=

BROADCAST_DRIVER=log
CACHE_DRIVER=file
QUEUE_CONNECTION=sync
SESSION_DRIVER=file
SESSION_LIFETIME=120

GOOGLE_DRIVE_FOLDER_ID=your_drive_folder_id_here
DB_BACKUP_FILENAME=all-databases-backup-local.sql
```

---

## ☁️ Google Drive API Setup

1. Visit [Google Cloud Console](https://console.cloud.google.com/).
2. Create a new project.
3. Enable the Google Drive API.
4. Create a Service Account and download the JSON key.
5. Save this file at:

```
storage/app/google/service-account.json
```

6. Create a new folder in Google Drive.
7. Give “Editor” access to the **Service Account Email** for that folder.
8. Extract the Folder ID from the Google Drive URL:

```
https://drive.google.com/drive/u/0/folders/YOUR_FOLDER_ID
```

---

## ⚙️ Configure Command

Open the file:

```php
app/Console/Commands/BackupDatabaseToDrive.php
```

Edit these 2 lines:

```php
protected $driveFolderId = 'YOUR_GOOGLE_DRIVE_FOLDER_ID';
protected $mysqldumpPath = 'C:\xampp\mysql\bin\mysqldump.exe'; // or just 'mysqldump' if it's in PATH
```

---

## ▶️ Run Backup Command

```bash
php artisan db:backup-to-drive
```

The output will look like:

```
Starting database backup...
Database dumped to storage\app\backup-YYYY-MM-DD_HH-MM-SS.sql
Uploading dump to Google Drive...
Upload successful. File ID: ...
Local dump file deleted.
Backup process completed successfully.
```

---

## 🔁 Automatic Backup (Scheduler)

Add a schedule in `app/Console/Kernel.php`:

```php
$schedule->command('db:backup-to-drive')->dailyAt('02:00');
```

Add a cron job in Linux:

```bash
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

On Windows, use Task Scheduler to run `php artisan schedule:run` every minute.

---

## 📂 Where are files saved?

- In the Google Drive folder you specified
- File name format: `all-databases-backup-local.sql` 

---

## 👑 Author

Made with ❤️ by **greatHimanshu**  
🔗 GitHub: [greatHimanshu](https://github.com/greatHimanshu)

---

## 🛡 License

This project is licensed under the [MIT License](LICENSE).
