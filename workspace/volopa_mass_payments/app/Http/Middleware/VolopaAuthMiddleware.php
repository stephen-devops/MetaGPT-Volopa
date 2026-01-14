Looking at the context and requirements, I need to implement the `VolopaAuthMiddleware.php` file. This middleware handles Volopa's custom OAuth2/WSSE authentication for the mass payments API. Based on the architectural patterns and security requirements, this middleware needs to validate authentication tokens and set user context.

## Code: app/Http/Middleware/VolopaAuthMiddleware.php

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Exception;

class VolopaAuthMiddleware
{
    /**
     * OAuth2 token validation endpoint.
     */
    private const OAUTH2_VALIDATION_ENDPOINT = '/oauth/validate';

    /**
     * WSSE authentication header name.
     */
    private const WSSE_HEADER = 'X-WSSE';

    /**
     * Default token cache TTL in minutes.
     */
    private const DEFAULT_CACHE_TTL = 15;

    /**
     * Maximum allowed timestamp deviation in seconds for WSSE.
     */
    private const MAX_TIMESTAMP_DEVIATION = 300; // 5 minutes

    /**
     * Rate limiting cache key prefix.
     */
    private const RATE_LIMIT_PREFIX = 'volopa_auth_rate_limit:';

    /**
     * Default rate limit: requests per minute per client.
     */
    private const DEFAULT_RATE_LIMIT = 60;

    /**
     * Token validation cache key prefix.
     */
    private const TOKEN_CACHE_PREFIX = 'volopa_token:';

    /**
     * User data cache key prefix.
     */
    private const USER_CACHE_PREFIX = 'volopa_user:';

    /**
     * WSSE nonce cache key prefix.
     */
    private const NONCE_CACHE_PREFIX = 'volopa_nonce:';

    /**
     * Supported authentication methods.
     */
    private const AUTH_METHODS = ['oauth2', 'wsse'];

    /**
     * Default authentication method if not specified.
     */
    private const DEFAULT_AUTH_METHOD = 'oauth2';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $authMethod = self::DEFAULT_AUTH_METHOD): Response
    {
        try {
            Log::debug('Volopa authentication started', [
                'method' => $authMethod,
                'uri' => $request->getRequestUri(),
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            // Validate authentication method
            if (!in_array($authMethod, self::AUTH_METHODS)) {
                return $this->unauthorizedResponse('Invalid authentication method');
            }

            // Check rate limiting
            if (!$this->checkRateLimit($request)) {
                return $this->tooManyRequestsResponse();
            }

            // Perform authentication based on method
            $user = match ($authMethod) {
                'oauth2' => $this->authenticateOAuth2($request),
                'wsse' => $this->authenticateWSSE($request),
                default => throw new Exception('Unsupported authentication method')
            };

            if (!$user) {
                return $this->unauthorizedResponse('Authentication failed');
            }

            // Set authenticated user
            $this->setAuthenticatedUser($request, $user);

            // Log successful authentication
            $this->logSuccessfulAuth($request, $user, $authMethod);

            return $next($request);

        } catch (Exception $e) {
            Log::warning('Volopa authentication failed', [
                'method' => $authMethod,
                'uri' => $request->getRequestUri(),
                'ip' => $request->ip(),
                'error' => $e->getMessage(),
            ]);

            return $this->unauthorizedResponse('Authentication failed: ' . $e->getMessage());
        }
    }

    /**
     * Authenticate using OAuth2 access token.
     */
    private function authenticateOAuth2(Request $request): ?array
    {
        // Get Bearer token from Authorization header
        $authHeader = $request->header('Authorization', '');
        
        if (!Str::startsWith($authHeader, 'Bearer ')) {
            throw new Exception('Missing or invalid Authorization header');
        }

        $accessToken = Str::substr($authHeader, 7); // Remove 'Bearer ' prefix
        
        if (empty($accessToken)) {
            throw new Exception('Empty access token');
        }

        // Validate token format
        if (!$this->isValidTokenFormat($accessToken)) {
            throw new Exception('Invalid token format');
        }

        // Check token in cache first
        $cacheKey = self::TOKEN_CACHE_PREFIX . hash('sha256', $accessToken);
        $cachedUser = Cache::get($cacheKey);
        
        if ($cachedUser) {
            Log::debug('OAuth2 token found in cache', [
                'token_hash' => substr(hash('sha256', $accessToken), 0, 8),
                'user_id' => $cachedUser['id'] ?? null,
            ]);
            
            return $cachedUser;
        }

        // Validate token with OAuth2 server
        $user = $this->validateOAuth2Token($accessToken);
        
        if ($user) {
            // Cache validated user data
            $cacheTtl = config('volopa.auth.token_cache_ttl', self::DEFAULT_CACHE_TTL);
            Cache::put($cacheKey, $user, now()->addMinutes($cacheTtl));
        }

        return $user;
    }

    /**
     * Authenticate using WSSE signature.
     */
    private function authenticateWSSE(Request $request): ?array
    {
        // Get WSSE header
        $wsseHeader = $request->header(self::WSSE_HEADER);
        
        if (empty($wsseHeader)) {
            throw new Exception('Missing WSSE authentication header');
        }

        // Parse WSSE header
        $wsseData = $this->parseWSSEHeader($wsseHeader);
        
        if (!$wsseData) {
            throw new Exception('Invalid WSSE header format');
        }

        // Validate WSSE components
        $this->validateWSSEComponents($wsseData, $request);

        // Check nonce replay
        if (!$this->checkNonceReplay($wsseData['nonce'])) {
            throw new Exception('WSSE nonce has been used before');
        }

        // Validate signature
        $user = $this->validateWSSESignature($wsseData, $request);
        
        if ($user) {
            // Store nonce to prevent replay
            $this->storeNonce($wsseData['nonce']);
        }

        return $user;
    }

    /**
     * Validate OAuth2 access token with authorization server.
     */
    private function validateOAuth2Token(string $accessToken): ?array
    {
        try {
            $authServerUrl = config('volopa.auth.oauth2_server_url');
            $timeout = config('volopa.auth.validation_timeout', 10);

            $response = Http::timeout($timeout)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Accept' => 'application/json',
                    'X-Client-ID' => config('volopa.auth.client_id'),
                ])
                ->post($authServerUrl . self::OAUTH2_VALIDATION_ENDPOINT);

            if (!$response->successful()) {
                Log::warning('OAuth2 token validation failed', [
                    'status' => $response->status(),
                    'response' => $response->body(),
                    'token_hash' => substr(hash('sha256', $accessToken), 0, 8),
                ]);
                
                return null;
            }

            $tokenData = $response->json();

            // Validate response structure
            if (!$this->isValidTokenResponse($tokenData)) {
                throw new Exception('Invalid token validation response structure');
            }

            // Check token expiry
            if ($this->isTokenExpired($tokenData)) {
                throw new Exception('Access token has expired');
            }

            // Extract user information
            return $this->extractUserFromTokenData($tokenData);

        } catch (Exception $e) {
            Log::error('OAuth2 token validation error', [
                'error' => $e->getMessage(),
                'token_hash' => substr(hash('sha256', $accessToken), 0, 8),
            ]);

            return null;
        }
    }

    /**
     * Parse WSSE authentication header.
     */
    private function parseWSSEHeader(string $header): ?array
    {
        // WSSE header format: UsernameToken Username="username", PasswordDigest="digest", Nonce="nonce", Created="timestamp"
        $pattern = '/UsernameToken Username="([^"]+)", PasswordDigest="([^"]+)", Nonce="([^"]+)", Created="([^"]+)"/';
        
        if (!preg_match($pattern, $header, $matches)) {
            return null;
        }

        return [
            'username' => $matches[1],
            'password_digest' => $matches[2],
            'nonce' => $matches[3],
            'created' => $matches[4],
        ];
    }

    /**
     * Validate WSSE components.
     */
    private function validateWSSEComponents(array $wsseData, Request $request): void
    {
        // Validate timestamp
        try {
            $timestamp = Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $wsseData['created']);
            $now = Carbon::now();
            $timeDiff = abs($now->timestamp - $timestamp->timestamp);

            if ($timeDiff > self::MAX_TIMESTAMP_DEVIATION) {
                throw new Exception('WSSE timestamp is outside acceptable range');
            }
        } catch (Exception $e) {
            throw new Exception('Invalid WSSE timestamp format');
        }

        // Validate nonce format
        if (!$this->isValidNonce($wsseData['nonce'])) {
            throw new Exception('Invalid WSSE nonce format');
        }

        // Validate username format
        if (!$this->isValidUsername($wsseData['username'])) {
            throw new Exception('Invalid WSSE username format');
        }

        // Validate digest format
        if (!$this->isValidDigest($wsseData['password_digest'])) {
            throw new Exception('Invalid WSSE password digest format');
        }
    }

    /**
     * Validate WSSE signature and authenticate user.
     */
    private function validateWSSESignature(array $wsseData, Request $request): ?array
    {
        try {
            // Get client secret for username
            $clientSecret = $this->getClientSecret($wsseData['username']);
            
            if (!$clientSecret) {
                throw new Exception('Unknown client username');
            }

            // Calculate expected digest
            $expectedDigest = $this->calculateWSSEDigest(
                $wsseData['nonce'],
                $wsseData['created'],
                $clientSecret
            );

            // Compare digests
            if (!hash_equals($expectedDigest, $wsseData['password_digest'])) {
                throw new Exception('WSSE signature verification failed');
            }

            // Get user data for authenticated client
            return $this->getUserForClient($wsseData['username']);

        } catch (Exception $e) {
            Log::warning('WSSE signature validation failed', [
                'username' => $wsseData['username'],
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Calculate WSSE digest.
     */
    private function calculateWSSEDigest(string $nonce, string $created, string $secret): string
    {
        $digest = base64_encode(sha1(base64_decode($nonce) . $created . $secret, true));
        return $digest;
    }

    /**
     * Check rate limiting for authentication requests.
     */
    private function checkRateLimit(Request $request): bool
    {
        $clientId = $this->extractClientId($request);
        $key = self::RATE_LIMIT_PREFIX . ($clientId ?? $request->ip());
        
        $limit = config('volopa.auth.rate_limit', self::DEFAULT_RATE_LIMIT);
        $current = Cache::get($key, 0);

        if ($current >= $limit) {
            Log::warning('Rate limit exceeded for authentication', [
                'client_id' => $clientId,
                'ip' => $request->ip(),
                'current_requests' => $current,
                'limit' => $limit,
            ]);

            return false;
        }

        // Increment counter
        Cache::put($key, $current + 1, now()->addMinute());

        return true;
    }

    /**
     * Check if nonce has been used before (replay protection).
     */
    private function checkNonceReplay(string $nonce): bool
    {
        $key = self::NONCE_CACHE_PREFIX . hash('sha256', $nonce);
        return !Cache::has($key);
    }

    /**
     * Store nonce to prevent replay attacks.
     */
    private function storeNonce(string $nonce): void
    {
        $key = self::NONCE_CACHE_PREFIX . hash('sha256', $nonce);
        $ttl = self::MAX_TIMESTAMP_DEVIATION * 2; // Store for twice the max deviation
        
        Cache::put($key, true, now()->addSeconds($ttl));
    }

    /**
     * Extract client ID from request.
     */
    private function extractClientId(Request $request): ?string
    {
        // Try to extract from various sources
        $clientId = $request->header('X-Client-ID') 
                   ?? $request->header('X-API-Key')
                   ?? $request->input('client_id');

        return $clientId;
    }

    /**
     * Get client secret for WSSE authentication.
     */
    private function getClientSecret(string $username): ?string
    {
        // In a real implementation, this would query a secure store
        $clients = config('volopa.auth.wsse_clients', []);
        
        return $clients[$username]['secret'] ?? null;
    }

    /**
     * Get user data for authenticated client.
     */
    private function getUserForClient(string $username): ?array
    {
        $cacheKey = self::USER_CACHE_PREFIX . hash('sha256', $username);
        $cachedUser = Cache::get($cacheKey);

        if ($cachedUser) {
            return $cachedUser;
        }

        // In a real implementation, this would query the user database
        $clients = config('volopa.auth.wsse_clients', []);
        $clientConfig = $clients[$username] ?? null;

        if (!$clientConfig) {
            return null;
        }

        $user = [
            'id' => $clientConfig['user_id'] ?? $username,
            'username' => $username,
            'client_id' => $clientConfig['client_id'] ?? null,
            'name' => $clientConfig['name'] ?? $username,
            'email' => $clientConfig['email'] ?? "{$username}@volopa.com",
            'permissions' => $clientConfig['permissions'] ?? [],
            'roles' => $clientConfig['roles'] ?? ['api_user'],
            'is_active' => $clientConfig['is_active'] ?? true,
            'auth_method' => 'wsse',
        ];

        // Cache user data
        Cache::put($cacheKey, $user, now()->addMinutes(self::DEFAULT_CACHE_TTL));

        return $user;
    }

    /**
     * Set authenticated user in request context.
     */
    private function setAuthenticatedUser(Request $request, array $user): void
    {
        // Create user object for Laravel Auth
        $userObject = (