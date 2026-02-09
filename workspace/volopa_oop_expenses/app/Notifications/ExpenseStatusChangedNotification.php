## Code: app/Notifications/ExpenseStatusChangedNotification.php

```php
<?php

namespace App\Notifications;

use App\Models\OopExpense;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ExpenseStatusChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * The expense that had its status changed.
     */
    private OopExpense $expense;

    /**
     * The user who changed the expense status (approver/rejector).
     */
    private ?User $actionUser;

    /**
     * The previous status of the expense.
     */
    private string $previousStatus;

    /**
     * The new status of the expense.
     */
    private string $newStatus;

    /**
     * Additional notes or reason for the status change.
     */
    private ?string $notes;

    /**
     * Notification queue name.
     */
    public string $queue = 'notifications';

    /**
     * Number of times to attempt the notification.
     */
    public int $tries = 3;

    /**
     * Timeout for the notification job.
     */
    public int $timeout = 30;

    /**
     * Valid expense statuses.
     */
    const VALID_STATUSES = [
        'pending',
        'approved',
        'rejected',
    ];

    /**
     * Create a new notification instance.
     */
    public function __construct(
        OopExpense $expense, 
        string $previousStatus, 
        string $newStatus,
        ?User $actionUser = null,
        ?string $notes = null
    ) {
        $this->expense = $expense;
        $this->previousStatus = $previousStatus;
        $this->newStatus = $newStatus;
        $this->actionUser = $actionUser;
        $this->notes = $notes;
        
        // Set queue delay
        $this->delay = now()->addSeconds(5);
        
        // Log notification creation
        Log::info('Expense status change notification created', [
            'expense_id' => $expense->id,
            'previous_status' => $previousStatus,
            'new_status' => $newStatus,
            'action_user_id' => $actionUser?->id,
            'expense_user_id' => $expense->user_id,
        ]);
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via(mixed $notifiable): array
    {
        $channels = ['database'];
        
        // Add mail channel based on user preferences or configuration
        if ($this->shouldSendEmail($notifiable)) {
            $channels[] = 'mail';
        }
        
        // Add other channels based on configuration
        if ($this->shouldSendSlack($notifiable)) {
            $channels[] = 'slack';
        }
        
        return $channels;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $mailMessage = new MailMessage();
        
        try {
            $subject = $this->getEmailSubject();
            $greeting = $this->getEmailGreeting($notifiable);
            $actionUrl = $this->getExpenseUrl();
            
            $mailMessage->subject($subject)
                ->greeting($greeting)
                ->line($this->getStatusChangeMessage())
                ->line('Expense Details:')
                ->line('• Merchant: ' . $this->expense->merchant_name)
                ->line('• Amount: ' . $this->expense->getFormattedAmountAttribute())
                ->line('• Date: ' . $this->expense->date->format('F j, Y'))
                ->line('• Description: ' . ($this->expense->description ?? 'N/A'));
            
            if ($this->actionUser) {
                $actionText = $this->getActionText();
                $mailMessage->line("• {$actionText}: " . $this->actionUser->name);
            }
            
            if ($this->notes) {
                $mailMessage->line('• Notes: ' . $this->notes);
            }
            
            $mailMessage->action('View Expense', $actionUrl)
                ->line('Thank you for using our expense management system!');
            
            // Set mail priority based on status change
            if ($this->isUrgentStatusChange()) {
                $mailMessage->priority('high');
            }
            
            return $mailMessage;
            
        } catch (\Exception $e) {
            Log::error('Error creating mail notification', [
                'expense_id' => $this->expense->id,
                'notifiable_id' => $notifiable->id ?? null,
                'error' => $e->getMessage(),
            ]);
            
            // Return a basic mail message on error
            return $mailMessage->subject('Expense Status Update')
                ->line('Your expense status has been updated.')
                ->line('Please check your dashboard for details.');
        }
    }

    /**
     * Get the database representation of the notification.
     */
    public function toDatabase(mixed $notifiable): array
    {
        return [
            'type' => 'expense_status_changed',
            'expense_id' => $this->expense->id,
            'expense_uuid' => $this->expense->uuid ?? null,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
            'action_user_id' => $this->actionUser?->id,
            'action_user_name' => $this->actionUser?->name,
            'expense_data' => [
                'merchant_name' => $this->expense->merchant_name,
                'amount' => $this->expense->amount,
                'currency' => $this->expense->currency,
                'date' => $this->expense->date->format('Y-m-d'),
                'description' => $this->expense->description,
                'client_id' => $this->expense->client_id,
            ],
            'notes' => $this->notes,
            'timestamp' => now()->toISOString(),
            'url' => $this->getExpenseUrl(),
            'title' => $this->getNotificationTitle(),
            'message' => $this->getStatusChangeMessage(),
            'priority' => $this->getNotificationPriority(),
        ];
    }

    /**
     * Get the Slack representation of the notification.
     */
    public function toSlack(mixed $notifiable): array
    {
        $color = $this->getSlackColor();
        $fields = $this->getSlackFields();
        
        return [
            'text' => $this->getNotificationTitle(),
            'attachments' => [
                [
                    'color' => $color,
                    'title' => 'Expense Details',
                    'title_link' => $this->getExpenseUrl(),
                    'fields' => $fields,
                    'footer' => 'Expense Management System',
                    'ts' => now()->timestamp,
                ],
            ],
        ];
    }

    /**
     * Get the notification title.
     */
    private function getNotificationTitle(): string
    {
        $statusText = $this->getStatusDisplayText($this->newStatus);
        return "Expense {$statusText} - {$this->expense->merchant_name}";
    }

    /**
     * Get the status change message.
     */
    private function getStatusChangeMessage(): string
    {
        $previousText = $this->getStatusDisplayText($this->previousStatus);
        $newText = $this->getStatusDisplayText($this->newStatus);
        
        $message = "Your expense status has been changed from {$previousText} to {$newText}.";
        
        if ($this->actionUser && in_array($this->newStatus, ['approved', 'rejected'])) {
            $actionText = $this->newStatus === 'approved' ? 'approved' : 'rejected';
            $message .= " This expense was {$actionText} by {$this->actionUser->name}.";
        }
        
        return $message;
    }

    /**
     * Get email subject line.
     */
    private function getEmailSubject(): string
    {
        $statusText = ucfirst($this->newStatus);
        return "Expense {$statusText} - {$this->expense->merchant_name}";
    }

    /**
     * Get email greeting.
     */
    private function getEmailGreeting(mixed $notifiable): string
    {
        $userName = $notifiable->name ?? 'User';
        return "Hello {$userName},";
    }

    /**
     * Get action text for email.
     */
    private function getActionText(): string
    {
        return match ($this->newStatus) {
            'approved' => 'Approved by',
            'rejected' => 'Rejected by',
            default => 'Updated by',
        };
    }

    /**
     * Get expense URL for notifications.
     */
    private function getExpenseUrl(): string
    {
        $baseUrl = config('app.frontend_url', config('app.url'));
        return rtrim($baseUrl, '/') . "/expenses/{$this->expense->id}";
    }

    /**
     * Get status display text.
     */
    private function getStatusDisplayText(string $status): string
    {
        return match ($status) {
            'pending' => 'Pending Review',
            'approved' => 'Approved',
            'rejected' => 'Rejected',
            default => ucfirst($status),
        };
    }

    /**
     * Get notification priority.
     */
    private function getNotificationPriority(): string
    {
        return match ($this->newStatus) {
            'approved' => 'high',
            'rejected' => 'high',
            'pending' => 'normal',
            default => 'low',
        };
    }

    /**
     * Check if this is an urgent status change.
     */
    private function isUrgentStatusChange(): bool
    {
        return in_array($this->newStatus, ['approved', 'rejected']);
    }

    /**
     * Check if email should be sent to the notifiable.
     */
    private function shouldSendEmail(mixed $notifiable): bool
    {
        // Check user preferences if available
        if (method_exists($notifiable, 'hasNotificationPreference')) {
            return $notifiable->hasNotificationPreference('expense_status_email', true);
        }
        
        // Check configuration
        $sendEmailByDefault = config('notifications.expense_status.email', true);
        
        // Always send email for approved/rejected status changes
        if (in_array($this->newStatus, ['approved', 'rejected'])) {
            return true;
        }
        
        return $sendEmailByDefault;
    }

    /**
     * Check if Slack notification should be sent.
     */
    private function shouldSendSlack(mixed $notifiable): bool
    {
        // Check user preferences if available
        if (method_exists($notifiable, 'hasNotificationPreference')) {
            return $notifiable->hasNotificationPreference('expense_status_slack', false);
        }
        
        // Check configuration
        return config('notifications.expense_status.slack', false);
    }

    /**
     * Get Slack color based on status.
     */
    private function getSlackColor(): string
    {
        return match ($this->newStatus) {
            'approved' => 'good',
            'rejected' => 'danger',
            'pending' => 'warning',
            default => '#cccccc',
        };
    }

    /**
     * Get Slack fields for the notification.
     */
    private function getSlackFields(): array
    {
        $fields = [
            [
                'title' => 'Merchant',
                'value' => $this->expense->merchant_name,
                'short' => true,
            ],
            [
                'title' => 'Amount',
                'value' => $this->expense->getFormattedAmountAttribute(),
                'short' => true,
            ],
            [
                'title' => 'Date',
                'value' => $this->expense->date->format('M j, Y'),
                'short' => true,
            ],
            [
                'title' => 'Status Change',
                'value' => $this->getStatusDisplayText($this->previousStatus) . ' → ' . $this->getStatusDisplayText($this->newStatus),
                'short' => true,
            ],
        ];
        
        if ($this->actionUser) {
            $fields[] = [
                'title' => $this->getActionText(),
                'value' => $this->actionUser->name,
                'short' => true,
            ];
        }
        
        if ($this->notes) {
            $fields[] = [
                'title' => 'Notes',
                'value' => $this->notes,
                'short' => false,
            ];
        }
        
        return $fields;
    }

    /**
     * Determine if the notification should be stored in the database.
     */
    public function shouldStore(mixed $notifiable): bool
    {
        return true; // Always store expense status change notifications
    }

    /**
     * Get the notification's unique identifier.
     */
    public function uniqueId(): string
    {
        return "expense_status_change_{$this->expense->id}_{$this->newStatus}_" . now()->timestamp;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Expense status change notification failed', [
            'expense_id' => $this->expense->id,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,
            'action_user_id' => $this->actionUser?->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'expense-notification',
            'expense:' . $this->expense->id,
            'status:' . $this->newStatus,
            'client:' . $this->expense->client_id,
            'user:' . $this->expense->user_id,
        ];
    }

    /**
     * Calculate the number of seconds to wait before retrying the job.
     */
    public function backoff(): array
    {
        return [30, 60, 180]; // Wait 30s, then 60s, then 180s between retries
    }

    /**
     * Create notification for expense approval.
     */
    public static function forApproval(OopExpense $expense, User $approver, ?string $notes = null): self
    {
        return new self($expense, 'pending', 'approved', $approver, $notes);
    }

    /**
     * Create notification for expense rejection.
     */
    public static function forRejection(OopExpense $expense, User $rejector, ?string $notes = null): self
    {
        return new self($expense, 'pending', 'rejected', $rejector, $notes);
    }

    /**
     * Create notification for expense status update.
     */
    public static function forStatusUpdate(OopExpense $expense, string $previousStatus, string $newStatus, ?User $actionUser = null, ?string $notes = null): self
    {
        return new self($expense, $previousStatus, $newStatus, $actionUser, $notes);
    }

    /**
     * Get notification metadata for tracking.
     */
    public function getMetadata(): array
    {
        return [
            'expense_id' => $this->expense->id,
            'expense_user_id' => $this->expense->user_id,
            'client_id' => $this->expense->client_id,
            'previous_status' => $this->previousStatus,
            'new_status' => $this->newStatus,