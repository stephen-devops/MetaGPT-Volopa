<?php

namespace App\Policies;

use App\Models\User;
use App\Models\PocketExpenseFileUpload;
use App\Models\UserFeaturePermission;
use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Auth\Access\Response;

/**
 * PocketExpenseUploadPolicy
 * 
 * Authorization policy for file upload operations in the batch expense upload system.
 * This policy controls access to CSV file upload operations, processing status monitoring,
 * and upload management following the platform's role-based access control system where
 * Primary Administrators have full access by default, and other users need explicit
 * permissions granted through the UserFeaturePermission system.
 * 
 * Platform roles hierarchy:
 * - Primary Admin: Full access to all upload operations within their client
 * - Admin: Full access to all upload operations within their client
 * - Business User: Access based on delegated permissions and ownership
 * - Card User: Limited access to their own uploads only
 */
class PocketExpenseUploadPolicy
{
    use HandlesAuthorization;

    /**
     * OOP Feature ID constant for checking OOP-specific permissions
     *
     * @var int
     */
    private const OOP_FEATURE_ID = 16;

    /**
     * Primary Administrator role name
     *
     * @var string
     */
    private const ROLE_PRIMARY_ADMIN = 'Primary Administrator';

    /**
     * Administrator role name
     *
     * @var string
     */
    private const ROLE_ADMIN = 'Admin';

    /**
     * Business User role name
     *
     * @var string
     */
    private const ROLE_BUSINESS_USER = 'Business User';

    /**
     * Card User role name
     *
     * @var string
     */
    private const ROLE_CARD_USER = 'Card User';

    /**
     * Maximum file size in KB (10MB)
     *
     * @var int
     */
    private const MAX_FILE_SIZE_KB = 10240;

    /**
     * Maximum number of records per CSV file
     *
     * @var int
     */
    private const MAX_RECORDS_PER_FILE = 200;

    /**
     * Determine whether the user can upload CSV files for batch expense processing.
     * 
     * Primary Admins and Admins can upload files for any user within their client.
     * Business Users and Card Users need explicit OOP feature permission and can only
     * upload files for themselves or users they manage.
     *
     * @param User $user
     * @param int|null $targetClientId
     * @return Response|bool
     */
    public function upload(User $user, ?int $targetClientId = null): Response|bool
    {
        // If no target client specified, use user's client
        $clientId = $targetClientId ?? $user->client_id;

        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $clientId) {
            return Response::deny('You can only upload files for your own client.');
        }

        // Primary Administrators can upload files
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can upload files
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users and Card Users need explicit OOP feature permission
        if ($this->hasOOPFeaturePermission($user)) {
            return Response::allow();
        }

        return Response::deny('You do not have permission to upload expense files.');
    }

    /**
     * Determine whether the user can view upload records.
     * 
     * Users can view upload records based on their role and ownership:
     * - Primary Admins and Admins can view all uploads within their client
     * - Business Users can view uploads they created or for users they manage
     * - Card Users can only view their own uploads
     *
     * @param User $user
     * @return Response|bool
     */
    public function viewAny(User $user): Response|bool
    {
        // Primary Administrators have full access within their client
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins have full access within their client
        if ($this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users and Card Users need explicit OOP feature permission
        if ($this->hasOOPFeaturePermission($user)) {
            return Response::allow();
        }

        return Response::deny('You do not have permission to view upload records.');
    }

    /**
     * Determine whether the user can view the specific upload record.
     * 
     * Users can only view uploads within their own client context.
     * Access is further restricted based on role and ownership:
     * - Primary Admins and Admins can view any upload in their client
     * - Business Users can view uploads they created or for users they manage
     * - Card Users can only view their own uploads
     *
     * @param User $user
     * @param PocketExpenseFileUpload $pocketExpenseFileUpload
     * @return Response|bool
     */
    public function viewUpload(User $user, PocketExpenseFileUpload $pocketExpenseFileUpload): Response|bool
    {
        // Check basic view access first
        if (!$this->viewAny($user)) {
            return Response::deny('You do not have permission to view upload records.');
        }

        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $pocketExpenseFileUpload->client_id) {
            return Response::deny('You can only view uploads within your own client.');
        }

        // Primary Admins and Admins can view any upload within their client
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can view uploads they created or for users they manage
        if ($this->isBusinessUser($user)) {
            if ($this->canAccessUpload($user, $pocketExpenseFileUpload)) {
                return Response::allow();
            }
        }

        // Card Users can only view their own uploads
        if ($this->isCardUser($user)) {
            if ($pocketExpenseFileUpload->user_id === $user->id) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to view this upload.');
    }

    /**
     * Determine whether the user can update the upload record.
     * 
     * Updates are typically limited to status changes and metadata updates.
     * Only certain statuses allow updates, and users can only update uploads
     * they have access to manage.
     *
     * @param User $user
     * @param PocketExpenseFileUpload $pocketExpenseFileUpload
     * @return Response|bool
     */
    public function update(User $user, PocketExpenseFileUpload $pocketExpenseFileUpload): Response|bool
    {
        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $pocketExpenseFileUpload->client_id) {
            return Response::deny('You can only update uploads within your own client.');
        }

        // Check if upload can be updated based on status
        if (!$this->canUploadBeModified($pocketExpenseFileUpload)) {
            return Response::deny('This upload cannot be modified in its current status.');
        }

        // Primary Admins and Admins can update any upload within their client
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can update uploads they created or manage
        if ($this->isBusinessUser($user)) {
            if ($this->canManageUpload($user, $pocketExpenseFileUpload)) {
                return Response::allow();
            }
        }

        // Card Users can only update their own uploads
        if ($this->isCardUser($user)) {
            if ($pocketExpenseFileUpload->user_id === $user->id && $this->hasOOPFeaturePermission($user)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to update this upload.');
    }

    /**
     * Determine whether the user can delete the upload record.
     * 
     * Deletion is more restrictive than updates:
     * - Completed uploads cannot be deleted to preserve audit trail
     * - Processing uploads cannot be deleted to prevent data corruption
     * - Only failed or validation failed uploads can typically be deleted
     *
     * @param User $user
     * @param PocketExpenseFileUpload $pocketExpenseFileUpload
     * @return Response|bool
     */
    public function delete(User $user, PocketExpenseFileUpload $pocketExpenseFileUpload): Response|bool
    {
        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $pocketExpenseFileUpload->client_id) {
            return Response::deny('You can only delete uploads within your own client.');
        }

        // Check if upload can be deleted based on status
        if (!$this->canUploadBeDeleted($pocketExpenseFileUpload)) {
            return Response::deny('This upload cannot be deleted in its current status.');
        }

        // Primary Admins can delete any upload within their client (with restrictions)
        if ($this->isPrimaryAdmin($user)) {
            return Response::allow();
        }

        // Admins can delete uploads within their client (with restrictions)
        if ($this->isAdmin($user)) {
            // Cannot delete completed uploads to preserve audit trail
            if (!$pocketExpenseFileUpload->isCompleted()) {
                return Response::allow();
            }
            return Response::deny('Completed uploads can only be deleted by Primary Administrators.');
        }

        // Business Users can delete uploads they created (with restrictions)
        if ($this->isBusinessUser($user)) {
            if ($this->canManageUpload($user, $pocketExpenseFileUpload) && !$pocketExpenseFileUpload->isCompleted()) {
                return Response::allow();
            }
        }

        // Card Users can delete their own uploads (with restrictions)
        if ($this->isCardUser($user)) {
            if ($pocketExpenseFileUpload->user_id === $user->id && 
                $this->hasOOPFeaturePermission($user) && 
                !$pocketExpenseFileUpload->isCompleted()) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to delete this upload.');
    }

    /**
     * Determine whether the user can reprocess failed uploads.
     * 
     * Reprocessing allows users to retry failed validation or processing.
     * This is typically available for uploads that failed validation or processing.
     *
     * @param User $user
     * @param PocketExpenseFileUpload $pocketExpenseFileUpload
     * @return Response|bool
     */
    public function reprocess(User $user, PocketExpenseFileUpload $pocketExpenseFileUpload): Response|bool
    {
        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $pocketExpenseFileUpload->client_id) {
            return Response::deny('You can only reprocess uploads within your own client.');
        }

        // Check if upload can be reprocessed based on status
        if (!$pocketExpenseFileUpload->canBeReprocessed()) {
            return Response::deny('This upload cannot be reprocessed in its current status.');
        }

        // Primary Admins and Admins can reprocess any upload within their client
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can reprocess uploads they created or manage
        if ($this->isBusinessUser($user)) {
            if ($this->canManageUpload($user, $pocketExpenseFileUpload)) {
                return Response::allow();
            }
        }

        // Card Users can reprocess their own uploads
        if ($this->isCardUser($user)) {
            if ($pocketExpenseFileUpload->user_id === $user->id && $this->hasOOPFeaturePermission($user)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to reprocess this upload.');
    }

    /**
     * Determine whether the user can cancel in-progress uploads.
     * 
     * Cancellation is only available for uploads that are currently being processed
     * or are in queue for processing. Completed uploads cannot be cancelled.
     *
     * @param User $user
     * @param PocketExpenseFileUpload $pocketExpenseFileUpload
     * @return Response|bool
     */
    public function cancel(User $user, PocketExpenseFileUpload $pocketExpenseFileUpload): Response|bool
    {
        // Must be within the same client context for multi-tenancy
        if ($user->client_id !== $pocketExpenseFileUpload->client_id) {
            return Response::deny('You can only cancel uploads within your own client.');
        }

        // Check if upload can be cancelled based on status
        if (!$pocketExpenseFileUpload->canBeCancelled()) {
            return Response::deny('This upload cannot be cancelled in its current status.');
        }

        // Primary Admins and Admins can cancel any upload within their client
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can cancel uploads they created or manage
        if ($this->isBusinessUser($user)) {
            if ($this->canManageUpload($user, $pocketExpenseFileUpload)) {
                return Response::allow();
            }
        }

        // Card Users can cancel their own uploads
        if ($this->isCardUser($user)) {
            if ($pocketExpenseFileUpload->user_id === $user->id && $this->hasOOPFeaturePermission($user)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to cancel this upload.');
    }

    /**
     * Determine whether the user can download the CSV template.
     * 
     * Template download is generally available to all users who have
     * upload permissions, as it helps them format their files correctly.
     *
     * @param User $user
     * @return Response|bool
     */
    public function downloadTemplate(User $user): Response|bool
    {
        // Users need basic upload permission to download templates
        return $this->upload($user);
    }

    /**
     * Determine whether the user can download upload files.
     * 
     * Users can download original upload files for uploads they have access to.
     * This is useful for troubleshooting and record keeping.
     *
     * @param User $user
     * @param PocketExpenseFileUpload $pocketExpenseFileUpload
     * @return Response|bool
     */
    public function downloadFile(User $user, PocketExpenseFileUpload $pocketExpenseFileUpload): Response|bool
    {
        // Same permissions as viewing the upload
        return $this->viewUpload($user, $pocketExpenseFileUpload);
    }

    /**
     * Determine whether the user can view upload logs and details.
     * 
     * Upload logs contain detailed processing information including
     * validation errors and processing statistics.
     *
     * @param User $user
     * @param PocketExpenseFileUpload $pocketExpenseFileUpload
     * @return Response|bool
     */
    public function viewLogs(User $user, PocketExpenseFileUpload $pocketExpenseFileUpload): Response|bool
    {
        // Same permissions as viewing the upload
        return $this->viewUpload($user, $pocketExpenseFileUpload);
    }

    /**
     * Determine whether the user can view uploads for a specific user.
     * 
     * This is used for filtering uploads in list views based on user ownership.
     * Admins can view any user's uploads, while others are more restricted.
     *
     * @param User $user
     * @param int $targetUserId
     * @return Response|bool
     */
    public function viewUserUploads(User $user, int $targetUserId): Response|bool
    {
        // Check basic view access first
        if (!$this->viewAny($user)) {
            return Response::deny('You do not have permission to view upload records.');
        }

        // Verify target user is in same client
        if (!$this->isSameClient($user, $targetUserId)) {
            return Response::deny('You can only view uploads for users in your own client.');
        }

        // Users can always view their own uploads
        if ($user->id === $targetUserId) {
            return Response::allow();
        }

        // Primary Admins and Admins can view any user's uploads within their client
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users can view uploads for users they manage
        if ($this->isBusinessUser($user)) {
            if ($this->canManageUser($user, $targetUserId)) {
                return Response::allow();
            }
        }

        return Response::deny('You do not have permission to view uploads for this user.');
    }

    /**
     * Determine whether the user can perform bulk operations on uploads.
     * 
     * Bulk operations require higher privileges due to their potential impact.
     * Only admins and users with explicit management permissions can perform bulk operations.
     *
     * @param User $user
     * @return Response|bool
     */
    public function bulkOperations(User $user): Response|bool
    {
        // Primary Admins and Admins can perform bulk operations
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users with management permissions can perform limited bulk operations
        if ($this->isBusinessUser($user) && $this->hasManagementPermissions($user)) {
            return Response::allow();
        }

        return Response::deny('Bulk upload operations require administrative privileges.');
    }

    /**
     * Determine whether the user can export upload data and reports.
     * 
     * Export operations are restricted based on data access rights.
     * Users can only export data they have access to view.
     *
     * @param User $user
     * @return Response|bool
     */
    public function export(User $user): Response|bool
    {
        // Check basic view access first
        if (!$this->viewAny($user)) {
            return Response::deny('You do not have permission to export upload data.');
        }

        // Primary Admins and Admins can export all client data
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users and Card Users can export their accessible data
        if ($this->hasOOPFeaturePermission($user)) {
            return Response::allow();
        }

        return Response::deny('You do not have permission to export upload data.');
    }

    /**
     * Check if a user is a Primary Administrator.
     *
     * @param User $user
     * @return bool
     */
    private function isPrimaryAdmin(User $user): bool
    {
        return $user->role === self::ROLE_PRIMARY_ADMIN;
    }

    /**
     * Check if a user is an Administrator.
     *
     * @param User $user
     * @return bool
     */
    private function isAdmin(User $user): bool
    {
        return $user->role === self::ROLE_ADMIN;
    }

    /**
     * Check if a user is a Business User.
     *
     * @param User $user
     * @return bool
     */
    private function isBusinessUser(User $user): bool
    {
        return $user->role === self::ROLE_BUSINESS_USER;
    }

    /**
     * Check if a user is a Card User.
     *
     * @param User $user
     * @return bool
     */
    private function isCardUser(User $user): bool
    {
        return $user->role === self::ROLE_CARD_USER;
    }

    /**
     * Check if a user has OOP feature permission.
     * 
     * This determines if a user has been granted access to the OOP expense
     * management feature. Primary Admins and Admins have this by default.
     *
     * @param User $user
     * @return bool
     */
    private function hasOOPFeaturePermission(User $user): bool
    {
        // Primary Admins and Admins have OOP permission by default
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return true;
        }

        // Check explicit permission records for other users
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $user->client_id)
            ->where('feature_id', self::OOP_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if a user has management permissions.
     * 
     * This determines if a user can manage other users' uploads
     * beyond their own, typically used for delegation scenarios.
     *
     * @param User $user
     * @return bool
     */
    private function hasManagementPermissions(User $user): bool
    {
        // Primary Admins and Admins have management permissions by default
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return true;
        }

        // Check if user has been granted management permissions
        return UserFeaturePermission::where('user_id', $user->id)
            ->where('client_id', $user->client_id)
            ->where('feature_id', self::OOP_FEATURE_ID)
            ->where('is_enabled', true)
            ->whereJsonContains('details_json->can_manage', true)
            ->exists();
    }

    /**
     * Check if a user can access a specific upload.
     * 
     * This determines if a user has the right to view or interact
     * with a specific upload based on ownership and delegation.
     *
     * @param User $user
     * @param PocketExpenseFileUpload $pocketExpenseFileUpload
     * @return bool
     */
    private function canAccessUpload(User $user, PocketExpenseFileUpload $pocketExpenseFileUpload): bool
    {
        // User can access their own uploads
        if ($pocketExpenseFileUpload->user_id === $user->id) {
            return true;
        }

        // User can access uploads they created for others
        if ($pocketExpenseFileUpload->created_by_user_id === $user->id) {
            return true;
        }

        // Check if user can manage the upload owner
        return $this->canManageUser($user, $pocketExpenseFileUpload->user_id);
    }

    /**
     * Check if a user can manage a specific upload.
     * 
     * This is more restrictive than canAccessUpload and includes
     * additional checks for upload status and modification rights.
     *
     * @param User $user
     * @param PocketExpenseFileUpload $pocketExpenseFileUpload
     * @return bool
     */
    private function canManageUpload(User $user, PocketExpenseFileUpload $pocketExpenseFileUpload): bool
    {
        // Check if user has basic access to the upload
        if (!$this->canAccessUpload($user, $pocketExpenseFileUpload)) {
            return false;
        }

        // User can manage their own uploads
        if ($pocketExpenseFileUpload->user_id === $user->id) {
            return true;
        }

        // Check if user has been granted management rights for this user
        return $this->hasManagementPermissions($user) && $this->canManageUser($user, $pocketExpenseFileUpload->user_id);
    }

    /**
     * Check if a user can manage another user.
     * 
     * This determines if a user has been granted management rights
     * over another user through the permission delegation system.
     *
     * @param User $user
     * @param int $targetUserId
     * @return bool
     */
    private function canManageUser(User $user, int $targetUserId): bool
    {
        // Check if user has granted permissions to the target user
        $hasGrantedPermissions = UserFeaturePermission::where('grantor_id', $user->id)
            ->where('user_id', $targetUserId)
            ->where('client_id', $user->client_id)
            ->where('feature_id', self::OOP_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();

        if ($hasGrantedPermissions) {
            return true;
        }

        // Check if user is designated as manager for the target user
        return UserFeaturePermission::where('manager_user_id', $user->id)
            ->where('user_id', $targetUserId)
            ->where('client_id', $user->client_id)
            ->where('feature_id', self::OOP_FEATURE_ID)
            ->where('is_enabled', true)
            ->exists();
    }

    /**
     * Check if a target user belongs to the same client as the requesting user.
     * 
     * This is a crucial multi-tenancy check to ensure users can only
     * access uploads within their own client context.
     *
     * @param User $user
     * @param int $targetUserId
     * @return bool
     */
    private function isSameClient(User $user, int $targetUserId): bool
    {
        $targetUser = User::find($targetUserId);
        
        if (!$targetUser) {
            return false;
        }

        return $user->client_id === $targetUser->client_id;
    }

    /**
     * Check if an upload can be modified based on its current status.
     * 
     * Only certain upload statuses allow modifications to prevent
     * data corruption during processing.
     *
     * @param PocketExpenseFileUpload $pocketExpenseFileUpload
     * @return bool
     */
    private function canUploadBeModified(PocketExpenseFileUpload $pocketExpenseFileUpload): bool
    {
        // Uploads can only be modified if they are not currently processing
        return !$pocketExpenseFileUpload->isInProgress() && !$pocketExpenseFileUpload->isCompleted();
    }

    /**
     * Check if an upload can be deleted based on its current status.
     * 
     * Deletion is more restrictive to preserve audit trails and
     * prevent data loss during processing.
     *
     * @param PocketExpenseFileUpload $pocketExpenseFileUpload
     * @return bool
     */
    private function canUploadBeDeleted(PocketExpenseFileUpload $pocketExpenseFileUpload): bool
    {
        // Uploads can be deleted if they failed, have validation errors, or are uploaded but not processing
        return $pocketExpenseFileUpload->isFailed() || 
               $pocketExpenseFileUpload->isValidationFailed() || 
               $pocketExpenseFileUpload->isUploaded();
    }

    /**
     * Authorize upload creation with file validation.
     * 
     * This provides detailed authorization for file upload including
     * validation of file size, format, and user permissions.
     *
     * @param User $user
     * @param array<string, mixed> $uploadData
     * @return Response|bool
     */
    public function authorizeUploadCreation(User $user, array $uploadData): Response|bool
    {
        // Basic upload permission check
        if (!$this->upload($user)) {
            return Response::deny('You do not have permission to upload files.');
        }

        $fileSize = $uploadData['file_size'] ?? 0;
        $fileName = $uploadData['file_name'] ?? '';
        $targetUserId = $uploadData['user_id'] ?? $user->id;

        // Validate file size
        if ($fileSize > (self::MAX_FILE_SIZE_KB * 1024)) {
            return Response::deny('File size exceeds the maximum allowed limit of ' . self::MAX_FILE_SIZE_KB . 'KB.');
        }

        // Validate file format
        if (!$this->isValidFileFormat($fileName)) {
            return Response::deny('Invalid file format. Only CSV and TXT files are allowed.');
        }

        // Check if target user is in same client (if uploading for someone else)
        if ($targetUserId !== $user->id && !$this->isSameClient($user, $targetUserId)) {
            return Response::deny('You can only upload files for users in your own client.');
        }

        // Additional validation for Business Users uploading for others
        if ($this->isBusinessUser($user) && $targetUserId !== $user->id) {
            if (!$this->canManageUser($user, $targetUserId)) {
                return Response::deny('You can only upload files for users you manage.');
            }
        }

        return Response::allow();
    }

    /**
     * Check if the uploaded file has a valid format.
     *
     * @param string $fileName
     * @return bool
     */
    private function isValidFileFormat(string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        return in_array($extension, ['csv', 'txt']);
    }

    /**
     * Get the user's effective permissions for upload operations.
     * 
     * This returns a summary of what upload operations the user
     * can perform, useful for UI authorization decisions.
     *
     * @param User $user
     * @return array<string, bool>
     */
    public function getEffectivePermissions(User $user): array
    {
        return [
            'can_upload' => $this->upload($user),
            'can_view_any' => $this->viewAny($user),
            'can_download_template' => $this->downloadTemplate($user),
            'can_export' => $this->export($user),
            'can_bulk_operations' => $this->bulkOperations($user),
            'has_oop_permission' => $this->hasOOPFeaturePermission($user),
            'has_management_permissions' => $this->hasManagementPermissions($user),
            'is_primary_admin' => $this->isPrimaryAdmin($user),
            'is_admin' => $this->isAdmin($user),
            'is_business_user' => $this->isBusinessUser($user),
            'is_card_user' => $this->isCardUser($user),
            'max_file_size_kb' => self::MAX_FILE_SIZE_KB,
            'max_records_per_file' => self::MAX_RECORDS_PER_FILE,
        ];
    }

    /**
     * Check if user can view upload reports and analytics.
     * 
     * This determines access to reporting features for upload statistics
     * and processing analytics. Access is based on role and data scope.
     *
     * @param User $user
     * @return Response|bool
     */
    public function viewReports(User $user): Response|bool
    {
        // Check basic view access first
        if (!$this->viewAny($user)) {
            return Response::deny('You do not have permission to view upload reports.');
        }

        // Primary Admins and Admins can view all reports
        if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
            return Response::allow();
        }

        // Business Users with management permissions can view reports for managed users
        if ($this->isBusinessUser($user) && $this->hasManagementPermissions($user)) {
            return Response::allow();
        }

        // Card Users can view their own upload reports
        if ($this->isCardUser($user) && $this->hasOOPFeaturePermission($user)) {
            return Response::allow();
        }

        return Response::deny('You do not have permission to view upload reports.');
    }

    /**
     * Authorize specific upload status changes.
     * 
     * This validates that a user can change an upload from one status to another
     * based on their role, permissions, and the upload's current state.
     *
     * @param User $user
     * @param PocketExpenseFileUpload $pocketExpenseFileUpload
     * @param string $newStatus
     * @return Response|bool
     */
    public function changeStatus(User $user, PocketExpenseFileUpload $pocketExpenseFileUpload, string $newStatus): Response|bool
    {
        // Must be within the same client context
        if ($user->client_id !== $pocketExpenseFileUpload->client_id) {
            return Response::deny('You can only change status for uploads within your own client.');
        }

        // Check if the status transition is valid
        if (!$pocketExpenseFileUpload->isValidStatusTransition($pocketExpenseFileUpload->status, $newStatus)) {
            return Response::deny("Invalid status transition from '{$pocketExpenseFileUpload->status}' to '{$newStatus}'.");
        }

        // Handle specific status changes
        switch ($newStatus) {
            case PocketExpenseFileUpload::STATUS_VALIDATING:
            case PocketExpenseFileUpload::STATUS_PROCESSING:
                // These are typically system-controlled, but admins can manually trigger
                if ($this->isPrimaryAdmin($user) || $this->isAdmin($user)) {
                    return Response::allow();
                }
                return Response::deny('Only administrators can manually trigger processing status changes.');
                
            case PocketExpenseFileUpload::STATUS_FAILED:
            case PocketExpenseFileUpload::STATUS_COMPLETED:
                // These are system-controlled status changes
                return Response::deny('This status change is controlled by the system.');
                
            default:
                // For other status changes, use update permissions
                return $this->update($user, $pocketExpenseFileUpload);
        }
    }
}