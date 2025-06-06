<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Google\Service\Drive\DriveFile;

class BackupDatabaseToDrive extends Command
{
    protected $signature = 'db:backup-to-drive';
    protected $description = 'Backup MySQL database and upload to Google Drive using Service Account';

    // Set your Google Drive folder ID here
    protected $driveFolderId =  '1a5WoD6VY4mDj1B5k2vpATzGRrBY4y9B5';

    // Optional: full path to mysqldump, or just 'mysqldump' if in PATH
    // protected $mysqldumpPath = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    protected $mysqldumpPath = 'mysqldump';

    public function __construct()
    {
        parent::__construct();
        $this->driveFolderId = env('GOOGLE_DRIVE_FOLDER_ID', '1a5WoD6VY4mDj1B5k2vpATzGRrBY4y9B5');
    }

    public function handle()
    {
        $this->info("🚀 Starting full MySQL backup...");

        $filename = env('DB_BACKUP_FILENAME', 'default-backup.sql');
        $filepath = storage_path("app/{$filename}");

        $db = config('database.connections.mysql');
        if (!$db) {
            $this->error("❌ MySQL configuration not found.");
            return 1;
        }

        // Delete old local backup if exists (optional since we overwrite)
        if (file_exists($filepath)) {
            unlink($filepath);
            $this->info("🗑️ Deleted old local backup: {$filename}");
        }

        // mysqldump --all-databases
        $command = sprintf(
            '%s --user=%s --password=%s --host=%s --all-databases > %s',
            escapeshellcmd($this->mysqldumpPath),
            escapeshellarg($db['username']),
            escapeshellarg($db['password']),
            escapeshellarg($db['host']),
            escapeshellarg($filepath)
        );

        $this->line("🔧 Dumping all databases...");
        exec($command, $output, $returnVar);

        if ($returnVar !== 0) {
            $this->error("❌ mysqldump failed with return code: $returnVar");
            return 1;
        }

        $this->info("✅ All databases dumped to: $filepath");

        try {
            $client = new GoogleClient();
            $client->setAuthConfig(storage_path('app/google/service-account.json'));
            $client->addScope(GoogleDrive::DRIVE_FILE);

            $driveService = new GoogleDrive($client);

            // Delete old backups on Google Drive with this exact filename
            $optParams = [
                'q' => sprintf("'%s' in parents and name = '%s'", $this->driveFolderId, $filename),
                'fields' => 'files(id, name)'
            ];
            $files = $driveService->files->listFiles($optParams);

            foreach ($files->getFiles() as $file) {
                $driveService->files->delete($file->getId());
                $this->info("🗑️ Deleted old Google Drive backup: {$file->getName()} (ID: {$file->getId()})");
            }

            $fileMetadata = new DriveFile([
                'name' => $filename,
                'parents' => [$this->driveFolderId],
            ]);

            $this->line("☁️ Uploading to Google Drive folder: {$this->driveFolderId}...");

            $file = $driveService->files->create($fileMetadata, [
                'data' => file_get_contents($filepath),
                'mimeType' => 'application/sql',
                'uploadType' => 'multipart',
                'fields' => 'id',
            ]);

            $this->info("✅ Upload complete. File ID: {$file->id}");

            // Delete local file
            unlink($filepath);
            $this->info("🗑️ Local backup file deleted.");
        } catch (\Exception $e) {
            $this->error("❌ Google Drive upload failed: " . $e->getMessage());
            return 1;
        }

        $this->info("🎉 Full database backup process completed!");
        return 0;
    }
}
