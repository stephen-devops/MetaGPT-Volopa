## Code: app/Notifications/ApprovalRequiredNotification.php

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
use Illuminate\Support\Facades\URL;
use Carbon\Carbon;

class ApprovalRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The mass payment file instance.
     *
     * @var MassPaymentFile
     */
    protected MassPaymentFile $massPaymentFile;

    /**
     * The user who created the file.
     *
     * @var User|null
     */
    protected ?User $createdBy;

    /**
     * Additional notification data.
     *
     * @var array
     */
    protected array $notificationData;

    /**
     * Priority level for the notification.
     *
     * @var string
     */
    protected string $priority;

    /**
     * Notification type identifier.
     *
     * @var string
     */
    protected string $notificationType;

    /**
     * The number of times the notification may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the notification can run.
     *
     * @var int
     */
    public int $timeout = 60;

    /**
     * Create a new notification instance.
     *
     * @param MassPaymentFile $massPaymentFile
     * @param User|null $createdBy
     * @param array $additionalData
     */
    public function __construct(
        MassPaymentFile $massPaymentFile,
        ?User $createdBy = null,
        array $additionalData = []
    ) {
        $this->massPaymentFile = $massPaymentFile;
        $this->createdBy = $createdBy;
        $this->notificationData = $additionalData;
        $this->priority = $this->determinePriority($massPaymentFile);
        $this->notificationType = 'mass_payment_approval_required';

        // Set queue based on priority
        $this->onQueue($this->determineQueue());
        
        // Set connection
        $this->onConnection(config('queue.default', 'redis'));
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable): array
    {
        $channels = ['database']; // Bell notifications via database

        // Add email for high-priority or high-value payments
        if ($this->shouldSendEmail($notifiable)) {
            $channels[] = 'mail';
        }

        // Add SMS for urgent notifications
        if ($this->shouldSendSms($notifiable)) {
            $channels[] = 'sms';
        }

        // Add webhook for external integrations
        if ($this->shouldSendWebhook($notifiable)) {
            $channels[] = 'webhook';
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
        $message = (new MailMessage)
            ->subject($this->getEmailSubject())
            ->greeting($this->getEmailGreeting($notifiable))
            ->line($this->getEmailIntroduction())
            ->line($this->getFileDetails())
            ->action(
                'Review and Approve',
                $this->getApprovalUrl()
            )
            ->line($this->getEmailFooter());

        // Add priority styling for urgent notifications
        if ($this->priority === 'urgent') {
            $message->level('error');
        } elseif ($this->priority === 'high') {
            $message->level('warning');
        }

        // Add additional lines for compliance requirements
        if ($this->requiresComplianceReview()) {
            $message->line('⚠️ This payment requires compliance review due to amount or destination.');
        }

        return $message;
    }

    /**
     * Get the database representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function toDatabase($notifiable): array
    {
        return [
            'id' => $this->id,
            'type' => $this->notificationType,
            'title' => $this->getBellNotificationTitle(),
            'message' => $this->getBellNotificationMessage(),
            'icon' => $this->getBellNotificationIcon(),
            'priority' => $this->priority,
            'action_url' => $this->getApprovalUrl(),
            'action_text' => 'Review & Approve',
            'mass_payment_file' => [
                'id' => $this->massPaymentFile->id,
                'filename' => $this->massPaymentFile->original_filename,
                'currency' => $this->massPaymentFile->currency,
                'total_amount' => $this->massPaymentFile->total_amount,
                'total_rows' => $this->massPaymentFile->total_rows,
                'status' => $this->massPaymentFile->status,
                'created_at' => $this->massPaymentFile->created_at->toISOString(),
                'client_id' => $this->massPaymentFile->client_id,
                'tcc_account_id' => $this->massPaymentFile->tcc_account_id
            ],
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
                'email' => $this->createdBy->email
            ] : null,
            'metadata' => array_merge($this->notificationData, [
                'requires_compliance_review' => $this->requiresComplianceReview(),
                'approval_deadline' => $this->getApprovalDeadline(),
                'risk_level' => $this->calculateRiskLevel(),
                'notification_sent_at' => Carbon::now()->toISOString(),
                'expires_at' => $this->getNotificationExpiry()->toISOString()
            ]),
            'expires_at' => $this->getNotificationExpiry()->toISOString()
        ];
    }

    /**
     * Get the SMS representation of the notification.
     *
     * @param mixed $notifiable
     * @return string
     */
    public function toSms($notifiable): string
    {
        $amount = number_format($this->massPaymentFile->total_amount, 2);
        $currency = $this->massPaymentFile->currency;
        
        return "URGENT: Mass payment of {$amount} {$currency} requires your approval. " .
               "File: {$this->massPaymentFile->original_filename}. " .
               "Review at: " . $this->getApprovalUrl();
    }

    /**
     * Get the webhook representation of the notification.
     *
     * @param mixed $notifiable
     * @return array
     */
    public function toWebhook($notifiable): array
    {
        return [
            'event' => 'mass_payment.approval_required',
            'notification_id' => $this->id,
            'timestamp' => Carbon::now()->toISOString(),
            'client_id' => $this->massPaymentFile->client_id,
            'mass_payment_file' => [
                'id' => $this->massPaymentFile->id,
                'filename' => $this->massPaymentFile->original_filename,
                'currency' => $this->massPaymentFile->currency,
                'total_amount' => $this->massPaymentFile->total_amount,
                'total_rows' => $this->massPaymentFile->total_rows,
                'status' => $this->massPaymentFile->status,
                'created_at' => $this->massPaymentFile->created_at->toISOString(),
                'tcc_account_id' => $this->massPaymentFile->tcc_account_id
            ],
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
                'email' => $this->createdBy->email
            ] : null,
            'notifiable' => [
                'id' => $notifiable->id,
                'type' => class_basename($notifiable),
                'email' => $notifiable->email ?? null
            ],
            'approval_url' => $this->getApprovalUrl(),
            'priority' => $this->priority,
            'risk_level' => $this->calculateRiskLevel(),
            'compliance_required' => $this->requiresComplianceReview(),
            'approval_deadline' => $this->getApprovalDeadline(),
            'metadata' => $this->notificationData
        ];
    }

    /**
     * Determine notification priority based on file characteristics.
     *
     * @param MassPaymentFile $massPaymentFile
     * @return string
     */
    private function determinePriority(MassPaymentFile $massPaymentFile): string
    {
        $amount = $massPaymentFile->total_amount;
        $currency = $massPaymentFile->currency;
        $metadata = $massPaymentFile->metadata ?? [];

        // Check for explicit priority in metadata
        if (isset($metadata['priority'])) {
            return $metadata['priority'];
        }

        // High-value thresholds by currency
        $highValueThresholds = [
            'USD' => 1000000.00,
            'EUR' => 900000.00,
            'GBP' => 800000.00,
            'SGD' => 1300000.00,
            'HKD' => 7800000.00,
            'AUD' => 1400000.00,
            'CAD' => 1300000.00,
            'JPY' => 110000000.00,
            'default' => 500000.00
        ];

        $urgentValueThresholds = [
            'USD' => 5000000.00,
            'EUR' => 4500000.00,
            'GBP' => 4000000.00,
            'SGD' => 6500000.00,
            'HKD' => 39000000.00,
            'AUD' => 7000000.00,
            'CAD' => 6500000.00,
            'JPY' => 550000000.00,
            'default' => 2500000.00
        ];

        $urgentThreshold = $urgentValueThresholds[$currency] ?? $urgentValueThresholds['default'];
        $highThreshold = $highValueThresholds[$currency] ?? $highValueThresholds['default'];

        if ($amount >= $urgentThreshold) {
            return 'urgent';
        } elseif ($amount >= $highThreshold) {
            return 'high';
        } elseif ($amount >= 100000.00) {
            return 'normal';
        } else {
            return 'low';
        }
    }

    /**
     * Determine the appropriate queue for this notification.
     *
     * @return string
     */
    private function determineQueue(): string
    {
        switch ($this->priority) {
            case 'urgent':
                return 'notifications_urgent';
            case 'high':
                return 'notifications_high';
            case 'low':
                return 'notifications_low';
            default:
                return 'notifications';
        }
    }

    /**
     * Determine if email should be sent.
     *
     * @param mixed $notifiable
     * @return bool
     */
    private function shouldSendEmail($notifiable): bool
    {
        // Always send email for urgent and high priority
        if (in_array($this->priority, ['urgent', 'high'])) {
            return true;
        }

        // Check user preferences
        $preferences = $notifiable->notification_preferences ?? [];
        
        return $preferences['email_approval_notifications'] ?? true;
    }

    /**
     * Determine if SMS should be sent.
     *
     * @param mixed $notifiable
     * @return bool
     */
    private function shouldSendSms($notifiable): bool
    {
        // Only send SMS for urgent notifications
        if ($this->priority !== 'urgent') {
            return false;
        }

        // Check if user has phone number
        if (empty($notifiable->phone)) {
            return false;
        }

        // Check user preferences
        $preferences = $notifiable->notification_preferences ?? [];
        
        return $preferences['sms_urgent_notifications'] ?? false;
    }

    /**
     * Determine if webhook should be sent.
     *
     * @param mixed $notifiable
     * @return bool
     */
    private function shouldSendWebhook($notifiable): bool
    {
        // Check if client has webhook configured
        $clientSettings = $notifiable->client_settings ?? [];
        
        return !empty($clientSettings['webhook_url']) && 
               ($clientSettings['webhook_notifications']['approval_required'] ?? false);
    }

    /**
     * Get email subject.
     *
     * @return string
     */
    private function getEmailSubject(): string
    {
        $priorityPrefix = '';
        if ($this->priority === 'urgent') {
            $priorityPrefix = '[URGENT] ';
        } elseif ($this->priority === 'high') {
            $priorityPrefix = '[HIGH PRIORITY] ';
        }

        $amount = number_format($this->massPaymentFile->total_amount, 2);
        $currency = $this->massPaymentFile->currency;

        return $priorityPrefix . "Approval Required: Mass Payment {$amount} {$currency}";
    }

    /**
     * Get email greeting.
     *
     * @param mixed $notifiable
     * @return string
     */
    private function getEmailGreeting($notifiable): string
    {
        $name = $notifiable->first_name ?? $notifiable->name ?? 'User';
        
        return "Hello {$name},";
    }

    /**
     * Get email introduction.
     *
     * @return string
     */
    private function getEmailIntroduction(): string
    {
        $creatorName = $this->createdBy ? $this->createdBy->name : 'Unknown User';
        $filename = $this->massPaymentFile->original_filename;

        return "A mass payment file '{$filename}' created by {$creatorName} is ready for approval.";
    }

    /**
     * Get file details for email.
     *
     * @return string
     */
    private function getFileDetails(): string
    {
        $amount = number_format($this->massPaymentFile->total_amount, 2);
        $currency = $this->massPaymentFile->currency;
        $rows = number_format($this->massPaymentFile->total_rows);
        $createdAt = $this->massPaymentFile->created_at->format('M j, Y \a\t g:i A T');

        $details = "**File Details:**\n";
        $details .= "• Amount: {$amount} {$currency}\n";
        $details .= "• Number of payments: {$rows}\n";
        $details .= "• Created: {$createdAt}\n";

        if ($this->requiresComplianceReview()) {
            $details .= "• Status: Requires compliance review";
        }

        return $details;
    }

    /**
     * Get email footer.
     *
     * @return string
     */
    private function getEmailFooter(): string
    {
        $deadline = $this->getApprovalDeadline();
        
        if ($deadline) {
            return "Please review and approve by {$deadline} to ensure timely processing.";
        }

        return 'Please review and approve as