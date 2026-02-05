## Code: app/Notifications/ExpenseProcessingCompleted.php

```php
<?php

namespace App\Notifications;

use App\Models\PocketExpenseFileUpload;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;

/**
 * ExpenseProcessingCompleted Notification
 * 
 * Notification sent when expense CSV upload processing is completed.
 * Supports email and database channels with detailed processing results.
 * Includes links to view upload status and created expenses.
 * Handles both successful and failed processing scenarios.
 */
class ExpenseProcessingCompleted extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The upload record.
     *
     * @var PocketExpenseFileUpload
     */
    private PocketExpenseFileUpload $upload;

    /**
     * Processing result data.
     *
     * @var array<string, mixed>
     */
    private array $result;

    /**
     * Notification type constants.
     */
    private const TYPE_SUCCESS = 'success';
    private const TYPE_PARTIAL_SUCCESS = 'partial_success';
    private const TYPE_FAILURE = 'failure';

    /**
     * Create a new notification instance.
     *
     * @param PocketExpenseFileUpload $upload
     * @param array<string, mixed> $result
     */
    public function __construct(PocketExpenseFileUpload $upload, array $result = [])
    {
        $this->upload = $upload;
        $this->result = array_merge([
            'processed' => 0,
            'failed' => 0,
            'total_batches' => 0,
            'processing_time' => null,
            'errors' => [],
        ], $result);

        // Set queue configuration
        $this->onQueue(Config::get('pocket_expense.queue.notification_queue', 'notifications'));
        $this->delay(now()->addSeconds(5)); // Small delay to ensure database is updated
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array<int, string>
     */
    public function via($notifiable): array
    {
        $channels = ['database']; // Always store in database

        // Add email channel if enabled and user has email
        if ($this->shouldSendEmail($notifiable)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return MailMessage
     */
    public function toMail($notifiable): MailMessage
    {
        $type = $this->getNotificationType();
        $mailMessage = new MailMessage();

        switch ($type) {
            case self::TYPE_SUCCESS:
                return $this->buildSuccessEmail($mailMessage, $notifiable);
            
            case self::TYPE_PARTIAL_SUCCESS:
                return $this->buildPartialSuccessEmail($mailMessage, $notifiable);
            
            case self::TYPE_FAILURE:
                return $this->buildFailureEmail($mailMessage, $notifiable);
            
            default:
                return $this->buildGenericEmail($mailMessage, $notifiable);
        }
    }

    /**
     * Get the database representation of the notification.
     *
     * @param mixed $notifiable
     * @return array<string, mixed>
     */
    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'expense_processing_completed',
            'upload_id' => $this->upload->id,
            'upload_uuid' => $this->upload->uuid,
            'original_filename' => $this->upload->original_filename,
            'target_user_id' => $this->upload->target_user_id,
            'client_id' => $this->upload->client_id,
            'status' => $this->upload->status,
            'notification_type' => $this->getNotificationType(),
            'result' => $this->result,
            'summary' => $this->buildSummary(),
            'links' => $this->buildActionLinks(),
            'timestamp' => now()->toISOString(),
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array<string, mixed>
     */
    public function toArray($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * Determine the notification type based on processing results.
     *
     * @return string
     */
    private function getNotificationType(): string
    {
        $processed = $this->result['processed'] ?? 0;
        $failed = $this->result['failed'] ?? 0;
        $total = $processed + $failed;

        if ($total === 0) {
            return self::TYPE_FAILURE;
        }

        if ($failed === 0) {
            return self::TYPE_SUCCESS;
        }

        if ($processed > 0) {
            return self::TYPE_PARTIAL_SUCCESS;
        }

        return self::TYPE_FAILURE;
    }

    /**
     * Check if email should be sent to the notifiable.
     *
     * @param mixed $notifiable
     * @return bool
     */
    private function shouldSendEmail($notifiable): bool
    {
        // Check if notifications are enabled
        if (!Config::get('pocket_expense.notifications.enabled', true)) {
            return false;
        }

        // Check if email channel is enabled
        $enabledChannels = Config::get('pocket_expense.notifications.channels.email', true);
        if (!$enabledChannels) {
            return false;
        }

        // Check if notifiable has email
        if (!($notifiable instanceof User) || empty($notifiable->email)) {
            return false;
        }

        // Check user preferences (if implemented)
        // This could check user notification preferences table
        return true;
    }

    /**
     * Build success email message.
     *
     * @param MailMessage $mailMessage
     * @param mixed $notifiable
     * @return MailMessage
     */
    private function buildSuccessEmail(MailMessage $mailMessage, $notifiable): MailMessage
    {
        $processed = $this->result['processed'] ?? 0;
        $targetUser = $this->getTargetUserName();

        return $mailMessage
            ->subject('Expense Upload Processing Completed Successfully')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your expense upload has been processed successfully.')
            ->line("**File:** {$this->upload->original_filename}")
            ->line("**Target User:** {$targetUser}")
            ->line("**Expenses Created:** {$processed}")
            ->line("**Processing Time:** {$this->getProcessingTimeFormatted()}")
            ->action('View Upload Status', $this->getUploadStatusUrl())
            ->action('View Expenses', $this->getExpensesUrl())
            ->line('All expenses have been created and are ready for review.')
            ->line('Thank you for using our expense management system!');
    }

    /**
     * Build partial success email message.
     *
     * @param MailMessage $mailMessage
     * @param mixed $notifiable
     * @return MailMessage
     */
    private function buildPartialSuccessEmail(MailMessage $mailMessage, $notifiable): MailMessage
    {
        $processed = $this->result['processed'] ?? 0;
        $failed = $this->result['failed'] ?? 0;
        $targetUser = $this->getTargetUserName();

        return $mailMessage
            ->subject('Expense Upload Processing Completed with Warnings')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your expense upload has been processed with some issues.')
            ->line("**File:** {$this->upload->original_filename}")
            ->line("**Target User:** {$targetUser}")
            ->line("**Expenses Created:** {$processed}")
            ->line("**Failed Records:** {$failed}")
            ->line("**Processing Time:** {$this->getProcessingTimeFormatted()}")
            ->action('View Upload Details', $this->getUploadStatusUrl())
            ->action('View Created Expenses', $this->getExpensesUrl())
            ->line('Some records could not be processed due to data issues.')
            ->line('Please review the upload details to see which records failed.')
            ->line('You may need to correct and re-upload the failed records.');
    }

    /**
     * Build failure email message.
     *
     * @param MailMessage $mailMessage
     * @param mixed $notifiable
     * @return MailMessage
     */
    private function buildFailureEmail(MailMessage $mailMessage, $notifiable): MailMessage
    {
        $failed = $this->result['failed'] ?? 0;
        $targetUser = $this->getTargetUserName();
        $errors = $this->result['errors'] ?? [];
        $primaryError = !empty($errors) ? $errors[0]['message'] ?? 'Unknown error' : 'Processing failed';

        return $mailMessage
            ->subject('Expense Upload Processing Failed')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Unfortunately, your expense upload processing has failed.')
            ->line("**File:** {$this->upload->original_filename}")
            ->line("**Target User:** {$targetUser}")
            ->line("**Failed Records:** {$failed}")
            ->line("**Primary Error:** {$primaryError}")
            ->action('View Upload Details', $this->getUploadStatusUrl())
            ->line('No expenses were created from this upload.')
            ->line('Please check the error details and try uploading again.')
            ->line('If the problem persists, please contact support.');
    }

    /**
     * Build generic email message.
     *
     * @param MailMessage $mailMessage
     * @param mixed $notifiable
     * @return MailMessage
     */
    private function buildGenericEmail(MailMessage $mailMessage, $notifiable): MailMessage
    {
        $targetUser = $this->getTargetUserName();

        return $mailMessage
            ->subject('Expense Upload Processing Update')
            ->greeting('Hello ' . $notifiable->name . '!')
            ->line('Your expense upload processing has been completed.')
            ->line("**File:** {$this->upload->original_filename}")
            ->line("**Target User:** {$targetUser}")
            ->line("**Status:** {$this->upload->status}")
            ->action('View Upload Details', $this->getUploadStatusUrl())
            ->line('Please check the upload details for more information.');
    }

    /**
     * Build summary data for the notification.
     *
     * @return array<string, mixed>
     */
    private function buildSummary(): array
    {
        $processed = $this->result['processed'] ?? 0;
        $failed = $this->result['failed'] ?? 0;
        $total = $processed + $failed;
        $successRate = $total > 0 ? round(($processed / $total) * 100, 2) : 0;

        return [
            'total_records' => $total,
            'processed_records' => $processed,
            'failed_records' => $failed,
            'success_rate' => $successRate,
            'processing_time' => $this->getProcessingTimeFormatted(),
            'file_size_mb' => round($this->upload->file_size / 1024 / 1024, 2),
            'upload_date' => $this->upload->create_time->toDateString(),
            'completion_date' => now()->toDateString(),
        ];
    }

    /**
     * Build action links for the notification.
     *
     * @return array<string, string>
     */
    private function buildActionLinks(): array
    {
        return [
            'upload_status' => $this->getUploadStatusUrl(),
            'view_expenses' => $this->getExpensesUrl(),
            'client_dashboard' => $this->getClientDashboardUrl(),
        ];
    }

    /**
     * Get the target user name.
     *
     * @return string
     */
    private function getTargetUserName(): string
    {
        if ($this->upload->targetUser) {
            return $this->upload->targetUser->name ?? 'Unknown User';
        }

        return 'Unknown User';
    }

    /**
     * Get formatted processing time.
     *
     * @return string
     */
    private function getProcessingTimeFormatted(): string
    {
        if (empty($this->result['processing_time'])) {
            if ($this->upload->started_at && $this->upload->completed_at) {
                $seconds = $this->upload->started_at->diffInSeconds($this->upload->completed_at);
                return $this->formatDuration($seconds);
            }
            return 'Unknown';
        }

        return $this->formatDuration($this->result['processing_time']);
    }

    /**
     * Format duration in seconds to human readable format.
     *
     * @param int $seconds
     * @return string
     */
    private function formatDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return "{$seconds} seconds";
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;

        if ($minutes < 60) {
            if ($remainingSeconds > 0) {
                return "{$minutes} minutes {$remainingSeconds} seconds";
            }
            return "{$minutes} minutes";
        }

        $hours = floor($minutes / 60);
        $remainingMinutes = $minutes % 60;

        if ($remainingMinutes > 0) {
            return "{$hours} hours {$remainingMinutes} minutes";
        }

        return "{$hours} hours";
    }

    /**
     * Get URL for upload status page.
     *
     * @return string
     */
    private function getUploadStatusUrl(): string
    {
        return URL::route('api.pocket-expenses.uploads.status', [
            'uploadId' => $this->upload->id
        ]);
    }

    /**
     * Get URL for expenses listing page.
     *
     * @return string
     */
    private function getExpensesUrl(): string
    {
        return URL::route('api.pocket-expenses.index', [
            'user_id' => $this->upload->target_user_id,
            'client_id' => $this->upload->client_id,
            'status' => 'submitted',
            'created_after' => $this->upload->create_time->toDateString(),
        ]);
    }

    /**
     * Get URL for client dashboard.
     *
     * @return string
     */
    private function getClientDashboardUrl(): string
    {
        return URL::route('dashboard.clients.show', [
            'client' => $this->upload->client_id
        ]);
    }

    /**
     * Get notification title based on type.
     *
     * @return string
     */
    public function getTitle(): string
    {
        switch ($this->getNotificationType()) {
            case self::TYPE_SUCCESS:
                return 'Expense Upload Completed Successfully';
            
            case self::TYPE_PARTIAL_SUCCESS:
                return 'Expense Upload Completed with Issues';
            
            case self::TYPE_FAILURE:
                return 'Expense Upload Processing Failed';
            
            default:
                return 'Expense Upload Processing Update';
        }
    }

    /**
     * Get notification message for display.
     *
     * @return string
     */
    public function getMessage(): string
    {
        $processed = $this->result['processed'] ?? 0;
        $failed = $this->result['failed'] ?? 0;
        $filename = $this->upload->original_filename;

        switch ($this->getNotificationType()) {
            case self::TYPE_SUCCESS:
                return "Successfully processed {