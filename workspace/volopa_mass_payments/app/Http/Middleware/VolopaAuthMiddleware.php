## Code: app/Http/Middleware/VolopaAuthMiddleware.php

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
use Carbon\Carbon;
use Exception;

class VolopaAuthMiddleware
{
    /**
     * OAuth2 access token header name
     */
    protected string $oauth2Header;

    /**
     * WSSE authentication header name
     */
    protected string $wsseHeader;

    /**
     * Token cache TTL in minutes
     */
    protected int $tokenCacheTtl;

    /**
     * Maximum token age in minutes
     */
    protected int $maxTokenAge;

    /**
     * Rate limiting per minute
     */
    protected int $rateLimitPerMinute;

    /**
     * Enable request logging
     */
    protected bool $enableRequestLogging;

    /**
     * Enable IP whitelist checking
     */
    protected bool $enableIpWhitelist;

    /**
     * IP whitelist array
     */
    protected array $ipWhitelist;

    /**
     * OAuth2 token validation endpoint
     */
    protected string $oauth2ValidationEndpoint;

    /**
     * WSSE nonce cache prefix
     */
    protected string $nonceCachePrefix;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->oauth2Header = config('volopa.auth.oauth2_header', 'Authorization');
        $this->wsseHeader = config('volopa.auth.wsse_header', 'X-WSSE');
        $this->tokenCacheTtl = config('volopa.auth.token_cache_ttl', 60);
        $this->maxTokenAge = config('volopa.auth.max_token_age', 300);
        $this->rateLimitPerMinute = config('mass-payments.security.rate_limit_per_minute', 60);
        $this->enableRequestLogging = config('volopa.auth.enable_request_logging', true);
        $this->enableIpWhitelist = !empty(config('mass-payments.security.ip_whitelist'));
        $this->ipWhitelist = $this->parseIpWhitelist(config('mass-payments.security.ip_whitelist', ''));
        $this->oauth2ValidationEndpoint = config('volopa.auth.oauth2_validation_endpoint', '');
        $this->nonceCachePrefix = 'volopa_wsse_nonce_';
    }

    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return BaseResponse
     */
    public function handle(Request $request, Closure $next): BaseResponse
    {
        $startTime = microtime(true);
        $requestId = uniqid('req_', true);

        // Log incoming request if enabled
        if ($this->enableRequestLogging) {
            $this->logIncomingRequest($request, $requestId);
        }

        try {
            // Check IP whitelist if enabled
            if ($this->enableIpWhitelist && !$this->isIpWhitelisted($request->ip())) {
                $this->logSecurityEvent('ip_not_whitelisted', [
                    'ip' => $request->ip(),
                    'request_id' => $requestId,
                ]);
                
                return $this->createUnauthorizedResponse('Access denied from this IP address');
            }

            // Check rate limiting
            if (!$this->checkRateLimit($request)) {
                $this->logSecurityEvent('rate_limit_exceeded', [
                    'ip' => $request->ip(),
                    'request_id' => $requestId,
                ]);
                
                return $this->createRateLimitResponse();
            }

            // Attempt authentication
            $authResult = $this->authenticateRequest($request, $requestId);
            
            if (!$authResult['success']) {
                $this->logSecurityEvent('authentication_failed', [
                    'reason' => $authResult['error'],
                    'ip' => $request->ip(),
                    'request_id' => $requestId,
                    'user_agent' => $request->userAgent(),
                ]);
                
                return $this->createUnauthorizedResponse($authResult['error']);
            }

            // Set authenticated user data in request
            $request->merge([
                'auth_user_id' => $authResult['user_id'],
                'auth_client_id' => $authResult['client_id'],
                'auth_method' => $authResult['method'],
                'auth_scope' => $authResult['scope'] ?? [],
                'request_id' => $requestId,
            ]);

            // Log successful authentication
            if ($this->enableRequestLogging) {
                Log::info('Request authenticated successfully', [
                    'user_id' => $authResult['user_id'],
                    'client_id' => $authResult['client_id'],
                    'method' => $authResult['method'],
                    'request_id' => $requestId,
                    'ip' => $request->ip(),
                ]);
            }

            // Continue with the request
            $response = $next($request);

            // Log response if enabled
            if ($this->enableRequestLogging) {
                $processingTime = round((microtime(true) - $startTime) * 1000, 2);
                $this->logOutgoingResponse($request, $response, $processingTime, $requestId);
            }

            return $response;

        } catch (Exception $e) {
            Log::error('Authentication middleware error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_id' => $requestId,
                'ip' => $request->ip(),
            ]);

            return $this->createErrorResponse('Authentication service unavailable');
        }
    }

    /**
     * Authenticate the incoming request
     *
     * @param Request $request
     * @param string $requestId
     * @return array
     */
    protected function authenticateRequest(Request $request, string $requestId): array
    {
        // Check for OAuth2 Bearer token
        $authHeader = $request->header($this->oauth2Header);
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return $this->authenticateOAuth2($authHeader, $requestId);
        }

        // Check for WSSE authentication
        $wsseHeader = $request->header($this->wsseHeader);
        if ($wsseHeader) {
            return $this->authenticateWSSE($wsseHeader, $request, $requestId);
        }

        // Check for API key in query parameters (fallback)
        $apiKey = $request->query('api_key');
        if ($apiKey) {
            return $this->authenticateApiKey($apiKey, $requestId);
        }

        return [
            'success' => false,
            'error' => 'No authentication credentials provided',
        ];
    }

    /**
     * Authenticate using OAuth2 Bearer token
     *
     * @param string $authHeader
     * @param string $requestId
     * @return array
     */
    protected function authenticateOAuth2(string $authHeader, string $requestId): array
    {
        $token = substr($authHeader, 7); // Remove 'Bearer ' prefix

        if (empty($token)) {
            return [
                'success' => false,
                'error' => 'Empty Bearer token provided',
            ];
        }

        // Check token cache first
        $cacheKey = 'oauth2_token_' . hash('sha256', $token);
        $cachedTokenData = Cache::get($cacheKey);

        if ($cachedTokenData) {
            Log::debug('Using cached OAuth2 token data', [
                'request_id' => $requestId,
                'user_id' => $cachedTokenData['user_id'],
                'client_id' => $cachedTokenData['client_id'],
            ]);

            return [
                'success' => true,
                'user_id' => $cachedTokenData['user_id'],
                'client_id' => $cachedTokenData['client_id'],
                'method' => 'oauth2',
                'scope' => $cachedTokenData['scope'] ?? [],
            ];
        }

        // Validate token with OAuth2 provider
        $tokenData = $this->validateOAuth2Token($token, $requestId);

        if (!$tokenData['valid']) {
            return [
                'success' => false,
                'error' => $tokenData['error'] ?? 'Invalid OAuth2 token',
            ];
        }

        // Cache valid token data
        Cache::put($cacheKey, [
            'user_id' => $tokenData['user_id'],
            'client_id' => $tokenData['client_id'],
            'scope' => $tokenData['scope'] ?? [],
            'expires_at' => $tokenData['expires_at'] ?? null,
        ], $this->tokenCacheTtl);

        return [
            'success' => true,
            'user_id' => $tokenData['user_id'],
            'client_id' => $tokenData['client_id'],
            'method' => 'oauth2',
            'scope' => $tokenData['scope'] ?? [],
        ];
    }

    /**
     * Authenticate using WSSE credentials
     *
     * @param string $wsseHeader
     * @param Request $request
     * @param string $requestId
     * @return array
     */
    protected function authenticateWSSE(string $wsseHeader, Request $request, string $requestId): array
    {
        $wsseData = $this->parseWSSEHeader($wsseHeader);

        if (!$wsseData) {
            return [
                'success' => false,
                'error' => 'Invalid WSSE header format',
            ];
        }

        // Validate required WSSE fields
        $requiredFields = ['Username', 'PasswordDigest', 'Nonce', 'Created'];
        foreach ($requiredFields as $field) {
            if (empty($wsseData[$field])) {
                return [
                    'success' => false,
                    'error' => "Missing WSSE field: {$field}",
                ];
            }
        }

        // Check timestamp to prevent replay attacks
        $createdTime = $this->parseWSSETimestamp($wsseData['Created']);
        if (!$createdTime) {
            return [
                'success' => false,
                'error' => 'Invalid WSSE timestamp format',
            ];
        }

        $currentTime = Carbon::now();
        $timeDiff = abs($currentTime->diffInMinutes($createdTime));

        if ($timeDiff > $this->maxTokenAge) {
            return [
                'success' => false,
                'error' => 'WSSE timestamp too old or too far in future',
            ];
        }

        // Check nonce to prevent replay attacks
        $nonceCacheKey = $this->nonceCachePrefix . hash('sha256', $wsseData['Nonce']);
        if (Cache::has($nonceCacheKey)) {
            return [
                'success' => false,
                'error' => 'WSSE nonce already used (replay attack detected)',
            ];
        }

        // Validate WSSE credentials
        $credentialsValid = $this->validateWSSECredentials($wsseData, $request, $requestId);

        if (!$credentialsValid['valid']) {
            return [
                'success' => false,
                'error' => $credentialsValid['error'] ?? 'Invalid WSSE credentials',
            ];
        }

        // Store nonce to prevent replay
        Cache::put($nonceCacheKey, true, $this->maxTokenAge);

        return [
            'success' => true,
            'user_id' => $credentialsValid['user_id'],
            'client_id' => $credentialsValid['client_id'],
            'method' => 'wsse',
            'scope' => ['mass_payments'],
        ];
    }

    /**
     * Authenticate using API key (fallback method)
     *
     * @param string $apiKey
     * @param string $requestId
     * @return array
     */
    protected function authenticateApiKey(string $apiKey, string $requestId): array
    {
        if (empty($apiKey)) {
            return [
                'success' => false,
                'error' => 'Empty API key provided',
            ];
        }

        // Check API key cache
        $cacheKey = 'api_key_' . hash('sha256', $apiKey);
        $cachedKeyData = Cache::get($cacheKey);

        if ($cachedKeyData) {
            return [
                'success' => true,
                'user_id' => $cachedKeyData['user_id'],
                'client_id' => $cachedKeyData['client_id'],
                'method' => 'api_key',
                'scope' => ['mass_payments'],
            ];
        }

        // Validate API key with database or external service
        $keyData = $this->validateApiKey($apiKey, $requestId);

        if (!$keyData['valid']) {
            return [
                'success' => false,
                'error' => 'Invalid API key',
            ];
        }

        // Cache valid API key data
        Cache::put($cacheKey, [
            'user_id' => $keyData['user_id'],
            'client_id' => $keyData['client_id'],
        ], $this->tokenCacheTtl);

        return [
            'success' => true,
            'user_id' => $keyData['user_id'],
            'client_id' => $keyData['client_id'],
            'method' => 'api_key',
            'scope' => ['mass_payments'],
        ];
    }

    /**
     * Validate OAuth2 token with provider
     *
     * @param string $token
     * @param string $requestId
     * @return array
     */
    protected function validateOAuth2Token(string $token, string $requestId): array
    {
        // For demo purposes - in real implementation this would call OAuth2 provider
        // This is a mock implementation based on token pattern
        
        if (empty($this->oauth2ValidationEndpoint)) {
            // Mock validation for development
            if (str_starts_with($token, 'volopa_')) {
                $tokenParts = explode('_', $token);
                if (count($tokenParts) >= 3) {
                    return [
                        'valid' => true,
                        'user_id' => (int) ($tokenParts[1] ?? 1),
                        'client_id' => (int) ($tokenParts[2] ?? 1),
                        'scope' => ['mass_payments'],
                        'expires_at' => now()->addHours(1)->timestamp,
                    ];
                }
            }
        } else {
            // Real OAuth2 validation would go here
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(10)
                    ->withHeaders([
                        'Authorization' => 'Bearer ' . $token,
                        'Accept' => 'application/json',
                    ])
                    ->post($this->oauth2ValidationEndpoint, [
                        'token' => $token,
                    ]);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    if ($data['active'] ?? false) {
                        return [
                            'valid' => true,
                            'user_id' => $data['user_id'] ?? null,
                            'client_id' => $data['client_id'] ?? null,
                            'scope' => $data['scope'] ?? [],
                            'expires_at' => $data['exp'] ?? null,
                        ];
                    }
                }
            } catch (Exception $e) {
                Log::error('OAuth2 token validation failed', [
                    