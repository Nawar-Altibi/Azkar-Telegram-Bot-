<?php

namespace App\Console\Commands;

use App\Services\GroupPostService;
use Illuminate\Console\Command;

class PostDueGroupPostsCommand extends Command
{
    protected $signature = 'adhkar:post-due-groups';

    protected $description = 'Post due morning/evening wird images to configured group chats.';

    public function handle(GroupPostService $groups): int
    {
        $posted = $groups->postDue();
        $this->info("Posted {$posted} group wird(s).");

        return self::SUCCESS;
    }
}
