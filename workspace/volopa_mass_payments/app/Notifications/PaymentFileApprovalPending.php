## Code: app/Notifications/PaymentFileApprovalPending.php

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
use Illuminate\Support\Facades\Config;
use Carbon\Carbon;

class PaymentFileApprovalPending extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The mass payment file requiring approval.
     */
    public MassPaymentFile $massPaymentFile;

    /**
     * The user who uploaded the file (optional).
     */
    public ?User $uploader;

    /**
     * Additional notification metadata.
     */
    public array $metadata;

    /**
     * Email template for notification.
     */
    private const EMAIL_TEMPLATE = 'emails.mass_payment_approval_pending';

    /**
     * Default notification channels.
     */
    private const DEFAULT_CHANNELS = ['mail', 'database'];

    /**
     * High priority notification channels.
     */
    private const HIGH_PRIORITY_CHANNELS = ['mail', 'database', 'webhook'];

    /**
     * Notification timeout for queued delivery.
     */
    public int $timeout = 300; // 5 minutes

    /**
     * Number of retry attempts for failed delivery.
     */
    public int $tries = 3;

    /**
     * Delay before retry attempts.
     */
    public int $backoff = 60; // 1 minute

    /**
     * High-value threshold for priority notifications.
     */
    private const HIGH_VALUE_THRESHOLD = 50000.00;

    /**
     * Create a new notification instance.
     */
    public function __construct(MassPaymentFile $massPaymentFile, ?User $uploader = null, array $metadata = [])
    {
        $this->massPaymentFile = $massPaymentFile;
        $this->uploader = $uploader;
        $this->metadata = array_merge([
            'priority' => $this->determinePriority($massPaymentFile),
            'requires_urgent_attention' => $this->requiresUrgentAttention($massPaymentFile),
            'notification_type' => 'approval_pending',
            'created_at' => Carbon::now()->toISOString(),
        ], $metadata);

        // Configure queue settings based on priority
        $this->configurePriority();
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(object $notifiable): array
    {
        // Default channels
        $channels = self::DEFAULT_CHANNELS;

        // Add webhook for high-value or urgent files
        if ($this->metadata['priority'] === 'high' || $this->metadata['requires_urgent_attention']) {
            $channels[] = 'webhook';
        }

        // Add SMS for critical amounts
        if ($this->massPaymentFile->total_amount > 100000.00) {
            $channels[] = 'sms';
        }

        // Check user preferences if available
        if ($notifiable instanceof User && method_exists($notifiable, 'getNotificationChannels')) {
            $userChannels = $notifiable->getNotificationChannels('mass_payment_approval');
            if (!empty($userChannels)) {
                $channels = array_intersect($channels, $userChannels);
            }
        }

        return array_unique($channels);
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $subject = $this->getEmailSubject();
        $actionUrl = $this->getApprovalUrl();

        $mailMessage = (new MailMessage)
            ->subject($subject)
            ->greeting($this->getGreeting($notifiable))
            ->line($this->getIntroductionLine())
            ->line($this->getFileDetailsLine())
            ->line($this->getAmountLine())
            ->action('Review and Approve', $actionUrl)
            ->line($this->getFooterLine());

        // Add priority styling
        if ($this->metadata['priority'] === 'high') {
            $mailMessage->priority(1);
        }

        // Add urgent attention warning
        if ($this->metadata['requires_urgent_attention']) {
            $mailMessage->line('⚠️ **URGENT**: This file requires immediate attention due to high value or regulatory requirements.');
        }

        // Add upload information
        if ($this->uploader) {
            $mailMessage->line("Uploaded by: {$this->uploader->name} ({$this->uploader->email})");
        }

        // Add validation summary
        if ($this->massPaymentFile->hasValidationErrors()) {
            $mailMessage->line("⚠️ Note: This file has {$this->massPaymentFile->invalid_instructions} validation errors that may require review.");
        }

        return $mailMessage;
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'mass_payment_approval_pending',
            'title' => $this->getNotificationTitle(),
            'message' => $this->getNotificationMessage(),
            'priority' => $this->metadata['priority'],
            'urgent' => $this->metadata['requires_urgent_attention'],
            'mass_payment_file' => [
                'id' => $this->massPaymentFile->id,
                'filename' => $this->massPaymentFile->filename,
                'currency' => $this->massPaymentFile->currency,
                'total_amount' => $this->massPaymentFile->total_amount,
                'total_instructions' => $this->massPaymentFile->total_instructions,
                'valid_instructions' => $this->massPaymentFile->valid_instructions,
                'invalid_instructions' => $this->massPaymentFile->invalid_instructions,
                'status' => $this->massPaymentFile->status,
                'created_at' => $this->massPaymentFile->created_at->toISOString(),
            ],
            'uploader' => $this->uploader ? [
                'id' => $this->uploader->id,
                'name' => $this->uploader->name,
                'email' => $this->uploader->email,
            ] : null,
            'actions' => [
                [
                    'label' => 'Review File',
                    'url' => $this->getApprovalUrl(),
                    'type' => 'primary',
                ],
                [
                    'label' => 'View Details',
                    'url' => $this->getDetailsUrl(),
                    'type' => 'secondary',
                ],
            ],
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get the webhook representation of the notification.
     */
    public function toWebhook(object $notifiable): array
    {
        return [
            'event' => 'mass_payment_approval_pending',
            'timestamp' => Carbon::now()->toISOString(),
            'priority' => $this->metadata['priority'],
            'urgent' => $this->metadata['requires_urgent_attention'],
            'data' => [
                'mass_payment_file' => [
                    'id' => $this->massPaymentFile->id,
                    'filename' => $this->massPaymentFile->filename,
                    'currency' => $this->massPaymentFile->currency,
                    'total_amount' => $this->massPaymentFile->total_amount,
                    'formatted_amount' => $this->getFormattedAmount(),
                    'total_instructions' => $this->massPaymentFile->total_instructions,
                    'valid_instructions' => $this->massPaymentFile->valid_instructions,
                    'invalid_instructions' => $this->massPaymentFile->invalid_instructions,
                    'success_rate' => $this->massPaymentFile->getSuccessRate(),
                    'status' => $this->massPaymentFile->status,
                    'created_at' => $this->massPaymentFile->created_at->toISOString(),
                ],
                'uploader' => $this->uploader ? [
                    'id' => $this->uploader->id,
                    'name' => $this->uploader->name,
                    'email' => $this->uploader->email,
                ] : null,
                'notifiable' => [
                    'type' => get_class($notifiable),
                    'id' => $notifiable->id ?? null,
                    'email' => $notifiable->email ?? null,
                ],
                'actions' => [
                    'approval_url' => $this->getApprovalUrl(),
                    'details_url' => $this->getDetailsUrl(),
                    'api_endpoint' => $this->getApiEndpoint(),
                ],
            ],
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get the SMS representation of the notification.
     */
    public function toSms(object $notifiable): string
    {
        $amount = $this->getFormattedAmount();
        $urgentFlag = $this->metadata['requires_urgent_attention'] ? '[URGENT] ' : '';
        
        return "{$urgentFlag}Mass payment file '{$this->massPaymentFile->filename}' ({$amount}) is awaiting your approval. " .
               "Review at: " . $this->getShortUrl();
    }

    /**
     * Configure notification priority and queue settings.
     */
    private function configurePriority(): void
    {
        if ($this->metadata['priority'] === 'high' || $this->metadata['requires_urgent_attention']) {
            $this->onQueue('high_priority');
            $this->timeout = 600; // 10 minutes for high priority
            $this->tries = 5;
            $this->backoff = 30; // 30 seconds between retries
        } else {
            $this->onQueue('notifications');
        }
    }

    /**
     * Determine notification priority based on file characteristics.
     */
    private function determinePriority(MassPaymentFile $massPaymentFile): string
    {
        // High value files get high priority
        if ($massPaymentFile->total_amount > self::HIGH_VALUE_THRESHOLD) {
            return 'high';
        }

        // Regulated currencies get medium priority
        $regulatedCurrencies = ['INR', 'CNY', 'TRY'];
        if (in_array($massPaymentFile->currency, $regulatedCurrencies)) {
            return 'medium';
        }

        // Large number of instructions gets medium priority
        if ($massPaymentFile->total_instructions > 1000) {
            return 'medium';
        }

        return 'normal';
    }

    /**
     * Check if file requires urgent attention.
     */
    private function requiresUrgentAttention(MassPaymentFile $massPaymentFile): bool
    {
        // Files over $100k require urgent attention
        if ($massPaymentFile->total_amount > 100000.00) {
            return true;
        }

        // Files with high validation error rate
        if ($massPaymentFile->total_instructions > 0) {
            $errorRate = ($massPaymentFile->invalid_instructions / $massPaymentFile->total_instructions) * 100;
            if ($errorRate > 25) {
                return true;
            }
        }

        // End of business day files (if time-sensitive)
        $now = Carbon::now();
        if ($now->hour >= 16 && $now->isWeekday()) {
            return true;
        }

        return false;
    }

    /**
     * Get email subject line.
     */
    private function getEmailSubject(): string
    {
        $urgentPrefix = $this->metadata['requires_urgent_attention'] ? '[URGENT] ' : '';
        $amount = $this->getFormattedAmount();
        
        return "{$urgentPrefix}Mass Payment Approval Required - {$this->massPaymentFile->filename} ({$amount})";
    }

    /**
     * Get greeting for the notification recipient.
     */
    private function getGreeting(object $notifiable): string
    {
        $name = $notifiable->name ?? 'Approver';
        $timeGreeting = $this->getTimeBasedGreeting();
        
        return "{$timeGreeting}, {$name}!";
    }

    /**
     * Get time-based greeting.
     */
    private function getTimeBasedGreeting(): string
    {
        $hour = Carbon::now()->hour;
        
        if ($hour < 12) {
            return 'Good morning';
        } elseif ($hour < 17) {
            return 'Good afternoon';
        } else {
            return 'Good evening';
        }
    }

    /**
     * Get introduction line for email.
     */
    private function getIntroductionLine(): string
    {
        return 'A new mass payment file has been uploaded and validated, and is now awaiting your approval.';
    }

    /**
     * Get file details line.
     */
    private function getFileDetailsLine(): string
    {
        $successRate = $this->massPaymentFile->getSuccessRate();
        
        return "File: {$this->massPaymentFile->filename} | " .
               "Instructions: {$this->massPaymentFile->total_instructions} " .
               "({$this->massPaymentFile->valid_instructions} valid, {$this->massPaymentFile->invalid_instructions} invalid) | " .
               "Success Rate: {$successRate}%";
    }

    /**
     * Get amount line for email.
     */
    private function getAmountLine(): string
    {
        $amount = $this->getFormattedAmount();
        $currency = $this->massPaymentFile->currency;
        
        return "Total Amount: {$amount} | Currency: {$currency}";
    }

    /**
     * Get footer line for email.
     */
    private function getFooterLine(): string
    {
        if ($this->metadata['requires_urgent_attention']) {
            return 'This file requires immediate attention. Please review and approve as soon as possible.';
        }
        
        return 'Please review the file details and approve or reject the payment instructions.';
    }

    /**
     * Get notification title for database storage.
     */
    private function getNotificationTitle(): string
    {
        $urgentFlag = $this->metadata['requires_urgent_attention'] ? '[URGENT] ' : '';
        return "{$urgentFlag}Payment File Approval Required";
    }

    /**
     * Get notification message for database storage.
     */
    private function getNotificationMessage(): string
    {
        $amount = $this->getFormattedAmount();
        return "Mass payment file '{$this->massPaymentFile->filename}' with {$this->massPaymentFile->total_instructions} instructions totaling {$amount} is awaiting approval.";
    }

    /**
     * Get formatted amount string.
     */
    private function getFormattedAmount(): string
    {
        return number_format($this->massPaymentFile->total_amount, 2) . ' ' . $this->massPaymentFile->currency;
    }

    /**
     * Get URL for file approval.
     */
    private function getApprovalUrl(): string
    {
        return URL::route('api.v1.mass-payment-files.show', ['id' => $this->massPaymentFile->id]);
    }

    /**
     * Get URL for file details.
     */
    private function getDetailsUrl(): string
    {
        return URL::route('api.v1.mass-payment-files.show', ['id' => $this->massPaymentFile->id]);
    }

    /**
     * Get API endpoint for programmatic access.
     */
    private function getApiEndpoint(): string
    {
        return URL::route('api.v1.mass-payment-files.approve', ['id' => $this->massPaymentFile->id]);
    }

    /**
     * Get shortened URL for SMS.
     */
    private