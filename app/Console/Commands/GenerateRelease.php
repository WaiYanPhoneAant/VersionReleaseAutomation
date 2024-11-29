<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class GenerateRelease extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'release:generate
                            {--dry-run : Show the calculated version without tagging}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a new Git tag, create a JSON version history file, and release version based on Conventional Commits';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Get the latest tag
        $latestTag = $this->getLatestTag();
        $this->info("Latest Tag: $latestTag");

        // Parse commits since the latest tag
        $commits = $this->getCommitsSinceTag($latestTag);

        if (empty($commits)) {
            $this->warn('No new commits since the last tag. Exiting.');
            return Command::SUCCESS;
        }

        // Classify commits and calculate the next version
        [$nextVersion, $changeLogCommits] = $this->calculateNextVersion($latestTag, $commits);

        $this->info("Next Version: $nextVersion");
        $this->createVersionHistoryFile($nextVersion, $changeLogCommits);


        if ($this->option('dry-run')) {
            $this->info('Dry run complete. No tag or file was created.');
            return Command::SUCCESS;
        }

        // Create the new tag
        $this->createGitTag($nextVersion);

        // Create the release
        $this->createGitRelease($nextVersion, $changeLogCommits);

        // Create the version history JSON file

        return Command::SUCCESS;
    }

    protected function getLatestTag()
    {
        $process = new Process(['git', 'describe', '--tags', '--abbrev=0']);
        $process->run();

        if (!$process->isSuccessful()) {
            return '0.0.0';
        }

        return trim($process->getOutput());
    }

    protected function getCommitsSinceTag($tag)
    {
        $process = new Process(['git', 'log', "{$tag}..HEAD", '--pretty=%s']);
        $process->run();

        return array_filter(explode("\n", $process->getOutput()));
    }

    protected function calculateNextVersion($currentVersion, $commits)
    {
        [$major, $minor, $patch,$sub_patch] = explode('.', $currentVersion);

        $changeLogCommits = [];

        foreach ($commits as $commit) {
            if (str_contains($commit, 'feat!') || str_contains($commit, 'BREAKING CHANGE')) {
                $major++;
                $minor = 0;
                $patch = 0;
                $changeLogCommits['major'][] = $commit; // Breaking change commits
            } elseif (str_starts_with($commit, 'feat')) {
                $minor++;
                $patch = 0;
                $changeLogCommits['minor'][] = $commit; // Feature commits
            } elseif (str_starts_with($commit, 'fix')) {
                $patch++;
                $changeLogCommits['patch'][] = $commit; // Fix commits
            }else{
                $sub_patch++;
                $changeLogCommits['sub_patch'][] = $commit; // Fix commits
            }
            // Ignore normal commits (no change to $changeLogCommits)
        }

        return ["{$major}.{$minor}.{$patch}.{$sub_patch}", $changeLogCommits];
    }

    protected function createGitTag($version)
    {
        $process = new Process(['git', 'tag', $version]);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error('Failed to create Git tag.');
            return;
        }

        $this->info("Tag $version created successfully.");

        // Push the tag to the remote
        $process = new Process(['git', 'push', 'origin', $version]);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error('Failed to push Git tag.');
            return;
        }

        $this->info("Tag $version pushed to remote successfully.");
    }

    protected function createGitRelease($version, $changeLogCommits)
    {
        $changeLog = implode("\n", array_merge(
            $changeLogCommits['major'] ?? [],
            $changeLogCommits['minor'] ?? [],
            $changeLogCommits['patch'] ?? [],
            $changeLogCommits['sub_patch'] ?? []
        ));

        $process = new Process([
            'gh', 'release', 'create', $version,
            '--title', "Release $version",
            '--notes', $changeLog
        ]);
        $process->run();

        if (!$process->isSuccessful()) {
            $this->error('Failed to create GitHub release.');
            return;
        }

        $this->info("Release $version created successfully.");
    }

    protected function createVersionHistoryFile($version, $changeLogCommits)
    {
        // Rename existing system_version.json file
        $systemVersionFilePath = base_path('system_version.json');
        if (File::exists($systemVersionFilePath)) {
            $systemVersionContent = json_decode(File::get($systemVersionFilePath), true);
            $existingVersion = str_replace('.', '_', ltrim($systemVersionContent['version'], 'v'));
            $newFilePath = base_path("version_histories/{$existingVersion}.json");
            File::move($systemVersionFilePath, $newFilePath);
            $this->info("Renamed system_version.json to {$existingVersion}.json");
        }

        // Convert version format (e.g., v2.5.52 -> 2_5_52)
        $versionForFile = str_replace('.', '_', ltrim($version, 'v'));

        // Define the file path
        $directory = base_path('/');
        if (!File::exists($directory)) {
            File::makeDirectory($directory);
        }

        $filePath = "$directory/system_version.json";

        // Prepare the JSON structure
        $data = [
            'version' => $version,
            'type' => 'stable', // Adjust as needed
            'change_log' => array_map(fn($commit) => ['text' => $commit], $changeLogCommits),
        ];

        // Write JSON to file
        File::put($filePath, json_encode($data, JSON_PRETTY_PRINT));

        $this->info("Version history file created: $filePath");
    }
}
