<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Drive\DriveFile;

class BackupAutoDetectDatabase extends Command
{
    protected $signature = 'backup:auto-upload';
    protected $description = 'Automatically backup ALL MySQL or MongoDB databases and upload to Google Drive';

    protected $driveParentFolderId;
    protected $mysqlDumpPath;
    protected $mongoDumpPath;

    public function __construct()
    {
        parent::__construct();
        $this->driveParentFolderId = env('GOOGLE_DRIVE_FOLDER_ID');
        $this->mysqlDumpPath = env('MYSQLDUMP_PATH', 'mysqldump');
        $this->mongoDumpPath = env('MONGODUMP_PATH', 'mongodump');
    }

    public function handle()
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.$connection");

        if (!$dbConfig) {
            $this->error("❌ Database configuration not found for: $connection");
            return 1;
        }

        if ($dbConfig['driver'] === 'mysql') {
            return $this->backupEachMySQLDatabase($dbConfig);
        } elseif ($dbConfig['driver'] === 'mongodb') {
            return $this->backupEachMongoDatabase($dbConfig);
        } else {
            $this->error("❌ Unsupported database driver: {$dbConfig['driver']}");
            return 1;
        }
    }

    protected function backupEachMySQLDatabase($db)
    {
        $this->info("🔍 Retrieving MySQL database list...");

        $connection = mysqli_connect($db['host'], $db['username'], $db['password']);
        $result = mysqli_query($connection, 'SHOW DATABASES');
        while ($row = mysqli_fetch_assoc($result)) {
            $database = $row['Database'];
            if (in_array($database, ['information_schema', 'performance_schema', 'mysql', 'sys'])) {
                continue;
            }

            $filename = "$database-backup.sql";
            $filepath = storage_path("app/{$filename}");

            $command = sprintf(
                '%s --user=%s --password=%s --host=%s %s > %s',
                escapeshellcmd($this->mysqlDumpPath),
                escapeshellarg($db['username']),
                escapeshellarg($db['password']),
                escapeshellarg($db['host']),
                escapeshellarg($database),
                escapeshellarg($filepath)
            );

            $this->info("💾 Backing up MySQL database: $database");
            exec($command, $output, $returnVar);

            if ($returnVar === 0) {
                $this->uploadToDrive($filepath, $filename, $database);
            } else {
                $this->error("❌ Failed to backup $database");
            }
        }
        return 0;
    }

    protected function backupEachMongoDatabase($db)
    {
        $this->info("🔍 Retrieving MongoDB database list...");

        $uri = "mongodb://{$db['username']}:{$db['password']}@{$db['host']}:{$db['port']}";
        $databases = json_decode(shell_exec("mongo --quiet --eval \"db.adminCommand('listDatabases')\" --username {$db['username']} --password {$db['password']} --host {$db['host']}"), true);

        foreach ($databases['databases'] as $database) {
            $dbName = $database['name'];
            if (in_array($dbName, ['admin', 'local', 'config'])) {
                continue;
            }

            $filename = "$dbName-backup.gz";
            $filepath = storage_path("app/{$filename}");

            $command = sprintf(
                '%s --uri=%s --db=%s --archive=%s --gzip',
                escapeshellcmd($this->mongoDumpPath),
                escapeshellarg($uri),
                escapeshellarg($dbName),
                escapeshellarg($filepath)
            );

            $this->info("💾 Backing up MongoDB database: $dbName");
            exec($command, $output, $returnVar);

            if ($returnVar === 0) {
                $this->uploadToDrive($filepath, $filename, $dbName);
            } else {
                $this->error("❌ Failed to backup $dbName");
            }
        }
        return 0;
    }

    /**
     * Uploads a file directly to the Google Drive root folder (or specified parent folder).
     *
     * @param string $filepath   Full path to the local file.
     * @param string $filename   Name of the file to be saved in Drive.
     * @return void
     */
    protected function uploadToDrive($filepath, $filename)
    {
        try {
            $this->info("☁️ Uploading $filename to Google Drive folder...");

            $client = new GoogleClient();
            $client->setAuthConfig(storage_path('app/google/service-account.json'));
            $client->addScope(GoogleDrive::DRIVE);

            $driveService = new GoogleDrive($client);

            // Step 1: Find existing backup files in the parent folder
            // $response = $driveService->files->listFiles([
            //     'q' => sprintf("'%s' in parents and name contains '-backup' and trashed = false", $this->driveParentFolderId),
            //     'orderBy' => 'createdTime desc',
            //     'fields' => 'files(id, name, createdTime)',
            // ]);

            // $existingBackups = $response->getFiles();

            // Step 2: If more than 2 existing backups, delete the oldest
            // if (count($existingBackups) >= 3) {
            //     $oldest = array_slice($existingBackups, -1)[0];
            //     $this->info("🗑️ Deleting oldest backup: {$oldest->name}");
            //     $driveService->files->delete($oldest->id);
            // }

            // Step 3: Upload new backup
            $fileMetadata = new DriveFile([
                'name' => $filename,
                'parents' => [$this->driveParentFolderId],
            ]);

            $file = $driveService->files->create($fileMetadata, [
                'data' => file_get_contents($filepath),
                'mimeType' => 'application/octet-stream',
                'uploadType' => 'multipart',
                'fields' => 'id',
            ]);

            $this->info("✅ Uploaded: $filename (ID: {$file->id})");

            // Step 4: Delete local file
            unlink($filepath);
            $this->info("🗑️ Local backup file deleted.");
        } catch (\Exception $e) {
            $this->error("❌ Google Drive upload failed for $filename: " . $e->getMessage());
        }
    }
}
