<?php
namespace App\Enums;
enum SyncJobStatus: string { case Queued='queued'; case Running='running'; case Completed='completed'; case Failed='failed'; case Retrying='retrying'; case Cancelled='cancelled'; }
