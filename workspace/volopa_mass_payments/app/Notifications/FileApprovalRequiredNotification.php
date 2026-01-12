## Code: app/Notifications/FileApprovalRequiredNotification.php

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

class FileApprovalRequiredNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The mass payment file that requires approval.
     */
    public MassPaymentFile $massPaymentFile;

    /**
     * The user who uploaded the file.
     */
    public ?User $uploader;

    /**
     * Additional notification context data.
     */
    public array $context;

    /**
     * The number of times the notification may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the notification can run.
     */
    public int $timeout = 60;

    /**
     * Create a new notification instance.
     */
    public function __construct(MassPaymentFile $massPaymentFile, ?User $uploader = null, array $context = [])
    {
        $this->massPaymentFile = $massPaymentFile;
        $this->uploader = $uploader ?? $massPaymentFile->uploader;
        $this->context = $context;
        $this->onQueue('notifications');
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(mixed $notifiable): array
    {
        $channels = ['database'];

        // Add email channel based on user preferences or file importance
        if ($this->shouldSendEmail($notifiable)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $file = $this->massPaymentFile;
        $uploader = $this->uploader;
        
        Log::info('Sending approval required email notification', [
            'file_id' => $file->id,
            'recipient_id' => $notifiable->id,
            'recipient_email' => $notifiable->email
        ]);

        $approvalUrl = $this->getApprovalUrl();
        $detailsUrl = $this->getFileDetailsUrl();

        $mailMessage = (new MailMessage)
            ->subject($this->getEmailSubject())
            ->greeting($this->getEmailGreeting($notifiable))
            ->line($this->getEmailIntroduction())
            ->line('**File Details:**')
            ->line('• **Filename:** ' . $file->filename)
            ->line('• **Total Amount:** ' . $this->getFormattedTotalAmount())
            ->line('• **Currency:** ' . $file->currency)
            ->line('• **Total Rows:** ' . number_format($file->total_rows))
            ->line('• **Valid Rows:** ' . number_format($file->valid_rows))
            ->line('• **Error Rows:** ' . number_format($file->error_rows))
            ->line('• **Success Rate:** ' . $file->getSuccessRate() . '%')
            ->line('• **Uploaded By:** ' . ($uploader ? $uploader->name : 'Unknown'))
            ->line('• **Uploaded At:** ' . $file->created_at->format('d M Y H:i'))
            ->line('• **TCC Account:** ' . $file->tccAccount->account_number);

        // Add validation warnings if any
        if ($file->hasValidationErrors()) {
            $mailMessage->line('⚠️ **Note:** This file has validation errors that may require your attention.');
            $errorCount = $file->getValidationErrorCount();
            if ($errorCount > 0) {
                $mailMessage->line('• **Validation Errors:** ' . $errorCount . ' issue(s) found');
            }
        }

        // Add approval actions
        $mailMessage->action('Review & Approve File', $approvalUrl)
                   ->line('You can also view the full file details using the link below:')
                   ->action('View File Details', $detailsUrl)
                   ->line('**Important Notes:**')
                   ->line('• Please review all validation errors before approving')
                   ->line('• Ensure sufficient balance in the TCC account')
                   ->line('• Approval will initiate payment processing')
                   ->line('• This notification was sent to all users with approval permissions');

        // Add urgency indicator if applicable
        if ($this->isUrgentApproval()) {
            $mailMessage->line('🔔 **URGENT:** This file requires immediate attention.');
        }

        $mailMessage->salutation('Best regards,')
                   ->salutation('Volopa Mass Payments System');

        return $mailMessage;
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(mixed $notifiable): array
    {
        $file = $this->massPaymentFile;
        $uploader = $this->uploader;

        Log::info('Creating database notification for file approval', [
            'file_id' => $file->id,
            'recipient_id' => $notifiable->id
        ]);

        return [
            'type' => 'mass_payment_approval_required',
            'title' => 'Mass Payment File Requires Approval',
            'message' => $this->getDatabaseMessage(),
            'file_id' => $file->id,
            'filename' => $file->filename,
            'total_amount' => $file->total_amount,
            'currency' => $file->currency,
            'total_rows' => $file->total_rows,
            'valid_rows' => $file->valid_rows,
            'error_rows' => $file->error_rows,
            'success_rate' => $file->getSuccessRate(),
            'has_validation_errors' => $file->hasValidationErrors(),
            'validation_error_count' => $file->getValidationErrorCount(),
            'uploaded_by' => [
                'id' => $uploader?->id,
                'name' => $uploader?->name,
                'email' => $uploader?->email
            ],
            'uploaded_at' => $file->created_at->toISOString(),
            'tcc_account' => [
                'id' => $file->tccAccount->id,
                'account_number' => $file->tccAccount->account_number,
                'currency' => $file->tccAccount->currency
            ],
            'urgency' => $this->getUrgencyLevel(),
            'approval_url' => $this->getApprovalUrl(),
            'details_url' => $this->getFileDetailsUrl(),
            'context' => $this->context,
            'created_at' => now()->toISOString()
        ];
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray(mixed $notifiable): array
    {
        return $this->toDatabase($notifiable);
    }

    /**
     * Handle a notification failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('File approval notification failed', [
            'file_id' => $this->massPaymentFile->id,
            'error' => $exception->getMessage(),
            'context' => $this->context
        ]);
    }

    /**
     * Determine if email should be sent based on various factors.
     */
    private function shouldSendEmail(mixed $notifiable): bool
    {
        // Always send email for urgent approvals
        if ($this->isUrgentApproval()) {
            return true;
        }

        // Send email for large amounts
        if ($this->isLargeAmount()) {
            return true;
        }

        // Send email if file has validation errors
        if ($this->massPaymentFile->hasValidationErrors()) {
            return true;
        }

        // Check user email preferences (would come from user settings)
        if (method_exists($notifiable, 'wantsEmailNotifications')) {
            return $notifiable->wantsEmailNotifications('mass_payment_approvals');
        }

        // Default to sending email for approval notifications
        return true;
    }

    /**
     * Check if this is an urgent approval.
     */
    private function isUrgentApproval(): bool
    {
        // Urgent if specified in context
        if (!empty($this->context['urgent'])) {
            return true;
        }

        // Urgent if very large amount
        $thresholds = [
            'GBP' => 25000.00,
            'EUR' => 25000.00,
            'USD' => 25000.00,
            'INR' => 2500000.00
        ];

        $urgentThreshold = $thresholds[$this->massPaymentFile->currency] ?? 25000.00;
        
        return $this->massPaymentFile->total_amount >= $urgentThreshold;
    }

    /**
     * Check if this is a large amount requiring special attention.
     */
    private function isLargeAmount(): bool
    {
        $thresholds = [
            'GBP' => 10000.00,
            'EUR' => 10000.00,
            'USD' => 10000.00,
            'INR' => 1000000.00
        ];

        $largeThreshold = $thresholds[$this->massPaymentFile->currency] ?? 10000.00;
        
        return $this->massPaymentFile->total_amount >= $largeThreshold;
    }

    /**
     * Get the urgency level for the notification.
     */
    private function getUrgencyLevel(): string
    {
        if ($this->isUrgentApproval()) {
            return 'urgent';
        }

        if ($this->isLargeAmount()) {
            return 'high';
        }

        if ($this->massPaymentFile->hasValidationErrors()) {
            return 'medium';
        }

        return 'normal';
    }

    /**
     * Get the email subject line.
     */
    private function getEmailSubject(): string
    {
        $file = $this->massPaymentFile;
        $urgencyPrefix = $this->isUrgentApproval() ? '[URGENT] ' : '';
        
        return $urgencyPrefix . 'Mass Payment File Requires Approval - ' . 
               $this->getFormattedTotalAmount() . ' (' . $file->filename . ')';
    }

    /**
     * Get the email greeting.
     */
    private function getEmailGreeting(mixed $notifiable): string
    {
        $name = $notifiable->name ?? 'Approver';
        return "Hello {$name},";
    }

    /**
     * Get the email introduction text.
     */
    private function getEmailIntroduction(): string
    {
        $file = $this->massPaymentFile;
        $uploader = $this->uploader;
        
        $intro = 'A mass payment file has been uploaded and requires your approval before processing.';
        
        if ($uploader) {
            $intro .= " The file was uploaded by {$uploader->name}.";
        }

        if ($file->hasValidationErrors()) {
            $intro .= ' Please note that this file contains validation errors that may need your attention.';
        }

        return $intro;
    }

    /**
     * Get the database notification message.
     */
    private function getDatabaseMessage(): string
    {
        $file = $this->massPaymentFile;
        $uploader = $this->uploader;
        
        $message = "Mass payment file '{$file->filename}' ({$this->getFormattedTotalAmount()}) requires approval.";
        
        if ($uploader) {
            $message .= " Uploaded by {$uploader->name}.";
        }

        if ($file->hasValidationErrors()) {
            $errorCount = $file->getValidationErrorCount();
            $message .= " Contains {$errorCount} validation issue(s).";
        }

        return $message;
    }

    /**
     * Get formatted total amount with currency.
     */
    private function getFormattedTotalAmount(): string
    {
        $file = $this->massPaymentFile;
        
        $symbols = [
            'GBP' => '£',
            'EUR' => '€',
            'USD' => '$',
            'INR' => '₹'
        ];

        $symbol = $symbols[$file->currency] ?? $file->currency . ' ';
        
        return $symbol . number_format($file->total_amount, 2);
    }

    /**
     * Get the approval URL for the file.
     */
    private function getApprovalUrl(): string
    {
        return URL::to("/mass-payments/{$this->massPaymentFile->id}/approve");
    }

    /**
     * Get the file details URL.
     */
    private function getFileDetailsUrl(): string
    {
        return URL::to("/mass-payments/{$this->massPaymentFile->id}");
    }

    /**
     * Get notification metadata for tracking.
     */
    public function getNotificationMetadata(): array
    {
        return [
            'notification_type' => 'mass_payment_approval_required',
            'file_id' => $this->massPaymentFile->id,
            'filename' => $this->massPaymentFile->filename,
            'total_amount' => $this->massPaymentFile->total_amount,
            'currency' => $this->massPaymentFile->currency,
            'urgency_level' => $this->getUrgencyLevel(),
            'has_validation_errors' => $this->massPaymentFile->hasValidationErrors(),
            'uploader_id' => $this->uploader?->id,
            'context' => $this->context,
            'created_at' => now()->toISOString()
        ];
    }

    /**
     * Check if notification should be sent to a specific user.
     */
    public function shouldSendToUser(User $user): bool
    {
        // Don't send to the uploader unless specifically requested
        if ($user->id === $this->massPaymentFile->uploaded_by && empty($this->context['notify_uploader'])) {
            return false;
        }

        // Check if user has approval permissions
        if (!$user->hasRole('approver') && !$user->hasPermission('approve_mass_payments')) {
            return false;
        }

        // Check if user belongs to the same client
        if ($user->client_id !== $this->massPaymentFile->client_id) {
            return false;
        }

        // Check if user is active
        if (!$user->isActive()) {
            return false;
        }

        return true;
    }

    /**
     * Get notification priority for queue processing.
     */
    public function getPriority(): int
    {
        if ($this->isUrgentApproval()) {
            return 10; // High priority
        }

        if ($this->isLargeAmount()) {
            return 5; // Medium priority
        }

        return 1; // Normal priority
    }

    /**
     * Get delay before sending notification.
     */
    public function getDelay(): ?\DateTimeInterface
    {
        // Immediate delivery for urgent approvals
        if ($this->isUrgentApproval()) {
            return null;
        }

        // Small delay for normal approvals to batch notifications
        return now()->addMinutes(2);
    }

    /**
     * Create notification summary for bulk operations.
     */
    public function getSummary(): array
    {
        return [
            'type' => 'approval_required',
            'file_id' => $this->massPaymentFile->id,
            'filename' => $this->massPaymentFile->filename,
            'amount' => $this->getFormattedTotalAmount(),
            'urgency' => $this->getUrgencyLevel(),
            'has_errors' => $this->mass