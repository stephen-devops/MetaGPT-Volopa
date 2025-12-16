{
  "Development Plan": [
    "app/Notifications/ApprovalRequiredNotification.php"
  ],
  "Incremental Change": [
    "app/Notifications/ApprovalRequiredNotification.php: Laravel notification class for sending approval alerts to approvers with mail and database channels"
  ]
}

## Code: app/Notifications/ApprovalRequiredNotification.php

```php
<?php

namespace App\Notifications;

use App\Models\PaymentFile;
use App\Models\Approval;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;

class ApprovalRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The payment file requiring approval.
     */
    private PaymentFile $paymentFile;

    /**
     * The approval instance.
     */
    private Approval $approval;

    /**
     * Notification timeout in hours.
     */
    private const NOTIFICATION_TIMEOUT_HOURS = 72;

    /**
     * Default queue name for notification processing.
     */
    private const DEFAULT_QUEUE = 'notifications';

    /**
     * Priority levels for different approval amounts.
     */
    private const PRIORITY_LEVELS = [
        'high' => 50000.00,
        'medium' => 10000.00,
        'low' => 0.00,
    ];

    /**
     * Create a new notification instance.
     *
     * @param PaymentFile $paymentFile Payment file requiring approval
     * @param Approval $approval Approval instance
     */
    public function __construct(PaymentFile $paymentFile, Approval $approval)
    {
        $this->paymentFile = $paymentFile;
        $this->approval = $approval;
        
        // Set queue configuration
        $this->onQueue(self::DEFAULT_QUEUE);
        $this->delay(now());

        Log::info('ApprovalRequiredNotification created', [
            'payment_file_id' => $this->paymentFile->id,
            'approval_id' => $this->approval->id,
            'approver_id' => $this->approval->approver_id,
        ]);
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        $channels = ['database'];

        // Add mail channel if user has email notifications enabled
        if ($this->shouldSendEmail($notifiable)) {
            $channels[] = 'mail';
        }

        Log::info('Notification channels determined', [
            'payment_file_id' => $this->paymentFile->id,
            'approval_id' => $this->approval->id,
            'approver_id' => $notifiable->id ?? null,
            'channels' => $channels,
        ]);

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param mixed $notifiable
     * @return MailMessage
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        Log::info('Generating mail notification', [
            'payment_file_id' => $this->paymentFile->id,
            'approval_id' => $this->approval->id,
            'approver_email' => $notifiable->email ?? 'unknown',
        ]);

        $priority = $this->getApprovalPriority();
        $approvalUrl = $this->getApprovalUrl();
        $deadlineDate = $this->getApprovalDeadline();

        $mailMessage = (new MailMessage)
            ->subject($this->getMailSubject($priority))
            ->greeting($this->getMailGreeting($notifiable))
            ->line($this->getMailIntroduction())
            ->line('**File Details:**')
            ->line("• File Name: {$this->paymentFile->original_name}")
            ->line("• Total Amount: {$this->getFormattedAmount()}")
            ->line("• Payment Instructions: {$this->paymentFile->valid_records}")
            ->line("• Upload Date: {$this->paymentFile->created_at->format('F j, Y \\a\\t g:i A')}")
            ->line("• Uploaded by: {$this->paymentFile->user->name ?? 'Unknown User'}")
            ->line('')
            ->line('**Approval Deadline:** ' . $deadlineDate->format('F j, Y \\a\\t g:i A T'))
            ->action('Review and Approve', $approvalUrl)
            ->line('You can approve or reject this payment file by clicking the button above.')
            ->line('If you cannot access the approval system, please contact your system administrator.')
            ->salutation('Best regards,')
            ->salutation('Volopa Payments Team');

        // Add priority styling based on amount
        if ($priority === 'high') {
            $mailMessage->priority(1); // High priority
        } elseif ($priority === 'medium') {
            $mailMessage->priority(3); // Normal priority
        }

        return $mailMessage;
    }

    /**
     * Get the database representation of the notification.
     *
     * @param mixed $notifiable
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        $priority = $this->getApprovalPriority();
        $approvalUrl = $this->getApprovalUrl();
        $deadlineDate = $this->getApprovalDeadline();

        $databaseData = [
            'type' => 'approval_required',
            'priority' => $priority,
            'payment_file_id' => $this->paymentFile->id,
            'approval_id' => $this->approval->id,
            'title' => $this->getNotificationTitle(),
            'message' => $this->getDatabaseMessage(),
            'data' => [
                'payment_file' => [
                    'id' => $this->paymentFile->id,
                    'original_name' => $this->paymentFile->original_name,
                    'total_amount' => $this->paymentFile->total_amount,
                    'formatted_amount' => $this->getFormattedAmount(),
                    'currency' => $this->paymentFile->currency,
                    'valid_records' => $this->paymentFile->valid_records,
                    'invalid_records' => $this->paymentFile->invalid_records,
                    'uploaded_by' => $this->paymentFile->user->name ?? 'Unknown User',
                    'uploaded_by_id' => $this->paymentFile->user_id,
                    'upload_date' => $this->paymentFile->created_at->toISOString(),
                    'status' => $this->paymentFile->status,
                ],
                'approval' => [
                    'id' => $this->approval->id,
                    'status' => $this->approval->status,
                    'created_at' => $this->approval->created_at->toISOString(),
                    'deadline' => $deadlineDate->toISOString(),
                ],
                'actions' => [
                    'approve_url' => $approvalUrl . '/approve',
                    'reject_url' => $approvalUrl . '/reject',
                    'view_url' => $approvalUrl,
                ],
                'metadata' => [
                    'requires_urgent_attention' => $priority === 'high',
                    'business_hours_only' => false,
                    'auto_expire_at' => $deadlineDate->toISOString(),
                ],
            ],
            'created_at' => Carbon::now()->toISOString(),
        ];

        Log::info('Database notification data prepared', [
            'payment_file_id' => $this->paymentFile->id,
            'approval_id' => $this->approval->id,
            'priority' => $priority,
            'approver_id' => $notifiable->id ?? null,
        ]);

        return $databaseData;
    }

    /**
     * Get the array representation of the notification for other channels.
     *
     * @param mixed $notifiable
     * @return array<string, mixed>
     */
    public function toArray(mixed $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * Determine if the user should receive email notifications.
     *
     * @param mixed $notifiable
     * @return bool
     */
    private function shouldSendEmail(mixed $notifiable): bool
    {
        // Check if notifiable has email
        if (!isset($notifiable->email) || empty($notifiable->email)) {
            return false;
        }

        // Check if user has email notifications enabled (default to true)
        $emailNotificationsEnabled = $notifiable->email_notifications_enabled ?? true;

        // Always send email for high priority approvals
        $priority = $this->getApprovalPriority();
        if ($priority === 'high') {
            return true;
        }

        return $emailNotificationsEnabled;
    }

    /**
     * Get the approval priority based on amount.
     *
     * @return string
     */
    private function getApprovalPriority(): string
    {
        $amount = $this->paymentFile->total_amount;

        if ($amount >= self::PRIORITY_LEVELS['high']) {
            return 'high';
        } elseif ($amount >= self::PRIORITY_LEVELS['medium']) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Get the approval URL for the notification.
     *
     * @return string
     */
    private function getApprovalUrl(): string
    {
        return URL::signedRoute('api.v1.approvals.show', [
            'id' => $this->approval->id,
        ], Carbon::now()->addHours(self::NOTIFICATION_TIMEOUT_HOURS));
    }

    /**
     * Get the approval deadline.
     *
     * @return Carbon
     */
    private function getApprovalDeadline(): Carbon
    {
        return $this->approval->created_at->addHours(self::NOTIFICATION_TIMEOUT_HOURS);
    }

    /**
     * Get the formatted amount with currency symbol.
     *
     * @return string
     */
    private function getFormattedAmount(): string
    {
        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
        ];

        $symbol = $symbols[$this->paymentFile->currency] ?? '';
        return $symbol . number_format($this->paymentFile->total_amount, 2);
    }

    /**
     * Get the mail subject based on priority.
     *
     * @param string $priority
     * @return string
     */
    private function getMailSubject(string $priority): string
    {
        $priorityText = '';
        if ($priority === 'high') {
            $priorityText = '[URGENT] ';
        } elseif ($priority === 'medium') {
            $priorityText = '[IMPORTANT] ';
        }

        return $priorityText . 'Payment File Approval Required - ' . $this->getFormattedAmount();
    }

    /**
     * Get the mail greeting.
     *
     * @param mixed $notifiable
     * @return string
     */
    private function getMailGreeting(mixed $notifiable): string
    {
        $name = $notifiable->first_name ?? $notifiable->name ?? 'Approver';
        return "Hello {$name},";
    }

    /**
     * Get the mail introduction text.
     *
     * @return string
     */
    private function getMailIntroduction(): string
    {
        $priority = $this->getApprovalPriority();
        
        $introText = 'A payment file requires your approval before it can be processed.';
        
        if ($priority === 'high') {
            $introText = '**URGENT:** A high-value payment file requires your immediate approval.';
        } elseif ($priority === 'medium') {
            $introText = '**IMPORTANT:** A payment file requires your approval before processing can continue.';
        }

        return $introText;
    }

    /**
     * Get the notification title for database storage.
     *
     * @return string
     */
    private function getNotificationTitle(): string
    {
        $priority = $this->getApprovalPriority();
        
        $titlePrefix = '';
        if ($priority === 'high') {
            $titlePrefix = 'URGENT: ';
        } elseif ($priority === 'medium') {
            $titlePrefix = 'IMPORTANT: ';
        }

        return $titlePrefix . 'Payment Approval Required';
    }

    /**
     * Get the database message text.
     *
     * @return string
     */
    private function getDatabaseMessage(): string
    {
        return "Payment file '{$this->paymentFile->original_name}' ({$this->getFormattedAmount()}) requires your approval. " .
               "Total instructions: {$this->paymentFile->valid_records}. " .
               "Deadline: {$this->getApprovalDeadline()->format('M j, Y g:i A')}.";
    }

    /**
     * Get notification tags for grouping.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return [
            'approval_required',
            'payment_file:' . $this->paymentFile->id,
            'approval:' . $this->approval->id,
            'priority:' . $this->getApprovalPriority(),
            'currency:' . $this->paymentFile->currency,
        ];
    }

    /**
     * Determine the notification's timeout.
     *
     * @return Carbon|null
     */
    public function retryUntil(): ?Carbon
    {
        return Carbon::now()->addHours(self::NOTIFICATION_TIMEOUT_HOURS);
    }

    /**
     * Handle notification failure.
     *
     * @param mixed $notifiable
     * @param string $channel
     * @param \Exception $exception
     * @return void
     */
    public function failed(mixed $notifiable, string $channel, \Exception $exception): void
    {
        Log::error('ApprovalRequiredNotification delivery failed', [
            'payment_file_id' => $this->paymentFile->id,
            'approval_id' => $this->approval->id,
            'approver_id' => $notifiable->id ?? null,
            'channel' => $channel,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Attempt to create a fallback database notification if mail failed
        if ($channel === 'mail') {
            try {
                $notifiable->notify(new static($this->paymentFile, $this->approval));
                Log::info('Fallback notification sent via database', [
                    'payment_file_id' => $this->paymentFile->id,
                    'approval_id' => $this->approval->id,
                    'approver_id' => $notifiable->id ?? null,
                ]);
            } catch (\Exception $e) {
                Log::error('Fallback notification also failed', [
                    'payment_file_id' => $this->paymentFile->id,
                    'approval_id' => $this->approval->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Get the unique identifier for the notification.
     *
     * @return string
     */
    public function getId(): string
    {
        return 'approval_required_' . $this->paymentFile->id . '_' . $this->approval->id;
    }

    /**
     * Check