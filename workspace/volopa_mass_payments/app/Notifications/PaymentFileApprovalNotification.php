{
  "Development Plan": [
    "app/Notifications/PaymentFileApprovalNotification.php"
  ],
  "Incremental Change": [
    "app/Notifications/PaymentFileApprovalNotification.php: Laravel notification class for payment file approval workflow with mail and database channels, multi-tenant awareness, and configurable notification preferences"
  ]
}

## Code: app/Notifications/PaymentFileApprovalNotification.php

```php
<?php

namespace App\Notifications;

use App\Models\MassPaymentFile;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;

class PaymentFileApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Mass payment file instance
     */
    protected MassPaymentFile $massPaymentFile;

    /**
     * Notification action type
     */
    protected string $action;

    /**
     * User who performed the action
     */
    protected ?User $actionUser;

    /**
     * Additional notification data
     */
    protected array $notificationData;

    /**
     * Notification priority
     */
    protected string $priority;

    /**
     * Available notification actions
     */
    public const ACTION_APPROVAL_REQUIRED = 'approval_required';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_PROCESSING_STARTED = 'processing_started';
    public const ACTION_PROCESSING_COMPLETED = 'processing_completed';
    public const ACTION_PROCESSING_FAILED = 'processing_failed';
    public const ACTION_UPLOADED = 'uploaded';
    public const ACTION_VALIDATION_FAILED = 'validation_failed';

    /**
     * Available notification priorities
     */
    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';
    public const PRIORITY_URGENT = 'urgent';

    /**
     * Create a new notification instance.
     *
     * @param MassPaymentFile $massPaymentFile
     * @param string $action
     * @param User|null $actionUser
     * @param array $notificationData
     * @param string $priority
     */
    public function __construct(
        MassPaymentFile $massPaymentFile,
        string $action = self::ACTION_APPROVAL_REQUIRED,
        ?User $actionUser = null,
        array $notificationData = [],
        string $priority = self::PRIORITY_NORMAL
    ) {
        $this->massPaymentFile = $massPaymentFile->withoutRelations();
        $this->action = $action;
        $this->actionUser = $actionUser?->withoutRelations();
        $this->notificationData = $notificationData;
        $this->priority = $priority;

        // Set queue configuration
        $this->onQueue(config('mass-payments.queue.notification_queue', 'notifications'));
        
        // Set delay based on priority
        $delay = $this->getDelayForPriority($priority);
        if ($delay > 0) {
            $this->delay(now()->addSeconds($delay));
        }
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        $channels = config('mass-payments.processing.notification_channels', ['mail', 'database']);
        
        // Filter channels based on user preferences if available
        if (method_exists($notifiable, 'getNotificationChannelsFor')) {
            $userChannels = $notifiable->getNotificationChannelsFor('mass_payment_approval');
            if (!empty($userChannels)) {
                $channels = array_intersect($channels, $userChannels);
            }
        }

        // Always include database channel for audit trail
        if (!in_array('database', $channels)) {
            $channels[] = 'database';
        }

        // Remove disabled channels based on system configuration
        $disabledChannels = config('mass-payments.notifications.disabled_channels', []);
        $channels = array_diff($channels, $disabledChannels);

        Log::debug('Notification channels determined', [
            'file_id' => $this->massPaymentFile->id,
            'action' => $this->action,
            'channels' => $channels,
            'notifiable_type' => get_class($notifiable),
            'notifiable_id' => $notifiable->id ?? null,
        ]);

        return array_values($channels);
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return MailMessage
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $mailMessage = new MailMessage();
        
        // Set email configuration
        $fromEmail = config('mass-payments.notifications.email_from', config('mail.from.address', 'noreply@volopa.com'));
        $replyToEmail = config('mass-payments.notifications.email_reply_to', 'support@volopa.com');
        
        $mailMessage->from($fromEmail, config('app.name', 'Volopa'))
                   ->replyTo($replyToEmail, 'Volopa Support');

        // Set priority header
        if ($this->priority === self::PRIORITY_HIGH || $this->priority === self::PRIORITY_URGENT) {
            $mailMessage->priority(1); // High priority
        }

        // Build email content based on action
        return match ($this->action) {
            self::ACTION_APPROVAL_REQUIRED => $this->buildApprovalRequiredMail($mailMessage, $notifiable),
            self::ACTION_APPROVED => $this->buildApprovedMail($mailMessage, $notifiable),
            self::ACTION_REJECTED => $this->buildRejectedMail($mailMessage, $notifiable),
            self::ACTION_PROCESSING_STARTED => $this->buildProcessingStartedMail($mailMessage, $notifiable),
            self::ACTION_PROCESSING_COMPLETED => $this->buildProcessingCompletedMail($mailMessage, $notifiable),
            self::ACTION_PROCESSING_FAILED => $this->buildProcessingFailedMail($mailMessage, $notifiable),
            self::ACTION_UPLOADED => $this->buildUploadedMail($mailMessage, $notifiable),
            self::ACTION_VALIDATION_FAILED => $this->buildValidationFailedMail($mailMessage, $notifiable),
            default => $this->buildDefaultMail($mailMessage, $notifiable),
        };
    }

    /**
     * Get the database representation of the notification.
     *
     * @param mixed $notifiable
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'mass_payment_file_approval',
            'action' => $this->action,
            'priority' => $this->priority,
            'file_id' => $this->massPaymentFile->id,
            'file_data' => [
                'original_filename' => $this->massPaymentFile->original_filename,
                'total_amount' => $this->massPaymentFile->total_amount,
                'currency' => $this->massPaymentFile->currency,
                'status' => $this->massPaymentFile->status,
                'client_id' => $this->massPaymentFile->client_id,
                'uploaded_by' => $this->massPaymentFile->uploaded_by,
                'approved_by' => $this->massPaymentFile->approved_by,
                'created_at' => $this->massPaymentFile->created_at?->toISOString(),
                'updated_at' => $this->massPaymentFile->updated_at?->toISOString(),
            ],
            'action_user' => $this->actionUser ? [
                'id' => $this->actionUser->id,
                'name' => $this->actionUser->name ?? 'Unknown User',
                'email' => $this->actionUser->email ?? null,
            ] : null,
            'notification_data' => $this->notificationData,
            'message' => $this->getNotificationMessage(),
            'action_url' => $this->getActionUrl(),
            'created_at' => now()->toISOString(),
            'expires_at' => $this->getExpirationTime()?->toISOString(),
        ];
    }

    /**
     * Get the array representation of the notification.
     *
     * @param mixed $notifiable
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * Build approval required email
     */
    protected function buildApprovalRequiredMail(MailMessage $mailMessage, mixed $notifiable): MailMessage
    {
        $uploaderName = $this->actionUser?->name ?? 'Unknown User';
        $fileName = $this->massPaymentFile->original_filename;
        $amount = number_format($this->massPaymentFile->total_amount, 2) . ' ' . $this->massPaymentFile->currency;
        
        return $mailMessage
            ->subject('Payment File Approval Required - ' . $fileName)
            ->greeting('Hello ' . ($notifiable->name ?? 'User') . ',')
            ->line("A new mass payment file requires your approval.")
            ->line("**File Details:**")
            ->line("• File: {$fileName}")
            ->line("• Total Amount: {$amount}")
            ->line("• Uploaded by: {$uploaderName}")
            ->line("• Upload Date: " . $this->massPaymentFile->created_at->format('d M Y, H:i'))
            ->action('Review and Approve', $this->getActionUrl())
            ->line('Please review the payment details carefully before approving.')
            ->when($this->priority === self::PRIORITY_HIGH, function ($message) {
                return $message->line('⚠️ **This is a high-priority payment file that requires immediate attention.**');
            })
            ->salutation('Best regards, The Volopa Team');
    }

    /**
     * Build approved email
     */
    protected function buildApprovedMail(MailMessage $mailMessage, mixed $notifiable): MailMessage
    {
        $approverName = $this->actionUser?->name ?? 'Unknown Approver';
        $fileName = $this->massPaymentFile->original_filename;
        $amount = number_format($this->massPaymentFile->total_amount, 2) . ' ' . $this->massPaymentFile->currency;
        
        return $mailMessage
            ->subject('Payment File Approved - ' . $fileName)
            ->greeting('Hello ' . ($notifiable->name ?? 'User') . ',')
            ->line("Your mass payment file has been approved and is now being processed.")
            ->line("**Approval Details:**")
            ->line("• File: {$fileName}")
            ->line("• Total Amount: {$amount}")
            ->line("• Approved by: {$approverName}")
            ->line("• Approval Date: " . now()->format('d M Y, H:i'))
            ->action('View Payment Status', $this->getActionUrl())
            ->line('You will receive another notification once processing is complete.')
            ->salutation('Best regards, The Volopa Team');
    }

    /**
     * Build rejected email
     */
    protected function buildRejectedMail(MailMessage $mailMessage, mixed $notifiable): MailMessage
    {
        $approverName = $this->actionUser?->name ?? 'Unknown Approver';
        $fileName = $this->massPaymentFile->original_filename;
        $rejectionReason = $this->notificationData['rejection_reason'] ?? 'No reason provided';
        
        return $mailMessage
            ->subject('Payment File Rejected - ' . $fileName)
            ->greeting('Hello ' . ($notifiable->name ?? 'User') . ',')
            ->line("Your mass payment file has been rejected and will not be processed.")
            ->line("**Rejection Details:**")
            ->line("• File: {$fileName}")
            ->line("• Rejected by: {$approverName}")
            ->line("• Rejection Date: " . now()->format('d M Y, H:i'))
            ->line("• Reason: {$rejectionReason}")
            ->action('View File Details', $this->getActionUrl())
            ->line('Please review the rejection reason and make necessary corrections before resubmitting.')
            ->salutation('Best regards, The Volopa Team');
    }

    /**
     * Build processing started email
     */
    protected function buildProcessingStartedMail(MailMessage $mailMessage, mixed $notifiable): MailMessage
    {
        $fileName = $this->massPaymentFile->original_filename;
        $amount = number_format($this->massPaymentFile->total_amount, 2) . ' ' . $this->massPaymentFile->currency;
        $instructionCount = $this->notificationData['instruction_count'] ?? 'Unknown';
        
        return $mailMessage
            ->subject('Payment Processing Started - ' . $fileName)
            ->greeting('Hello ' . ($notifiable->name ?? 'User') . ',')
            ->line("Your mass payment file is now being processed.")
            ->line("**Processing Details:**")
            ->line("• File: {$fileName}")
            ->line("• Total Amount: {$amount}")
            ->line("• Number of Payments: {$instructionCount}")
            ->line("• Processing Started: " . now()->format('d M Y, H:i'))
            ->action('Track Processing Status', $this->getActionUrl())
            ->line('You will receive updates on the processing progress.')
            ->salutation('Best regards, The Volopa Team');
    }

    /**
     * Build processing completed email
     */
    protected function buildProcessingCompletedMail(MailMessage $mailMessage, mixed $notifiable): MailMessage
    {
        $fileName = $this->massPaymentFile->original_filename;
        $successfulCount = $this->notificationData['successful_count'] ?? 0;
        $failedCount = $this->notificationData['failed_count'] ?? 0;
        $totalAmount = number_format($this->notificationData['total_amount_processed'] ?? $this->massPaymentFile->total_amount, 2) . ' ' . $this->massPaymentFile->currency;
        
        return $mailMessage
            ->subject('Payment Processing Complete - ' . $fileName)
            ->greeting('Hello ' . ($notifiable->name ?? 'User') . ',')
            ->line("Your mass payment file has been processed successfully.")
            ->line("**Processing Summary:**")
            ->line("• File: {$fileName}")
            ->line("• Total Amount Processed: {$totalAmount}")
            ->line("• Successful Payments: {$successfulCount}")
            ->when($failedCount > 0, function ($message) use ($failedCount) {
                return $message->line("• Failed Payments: {$failedCount}")
                              ->line("⚠️ Some payments failed - please review the failed transactions.");
            })
            ->line("• Completion Date: " . now()->format('d M Y, H:i'))
            ->action('View Processing Report', $this->getActionUrl())
            ->salutation('Best regards, The Volopa Team');
    }

    /**
     * Build processing failed email
     */
    protected function buildProcessingFailedMail(MailMessage $mailMessage, mixed $notifiable): MailMessage
    {
        $fileName = $this->massPaymentFile->original_filename;
        $errorMessage = $this->notificationData['error_message'] ?? 'Processing error occurred';
        
        return $mailMessage
            ->subject('Payment Processing Failed - ' . $fileName)
            ->greeting('Hello ' . ($notifiable