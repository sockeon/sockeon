<?php
/**
 * Request class
 * 
 * Handles HTTP request data encapsulation and provides convenient
 * methods to access request parameters, headers, and body
 * 
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Http;

class Request
{
    /**
     * HTTP method (GET, POST, PUT, etc.)
     * @var string
     */
    protected string $method;
    
    /**
     * Request path
     * @var string
     */
    protected string $path;
    
    /**
     * HTTP protocol version
     * @var string
     */
    protected string $protocol;
    
    /**
     * Request headers
     * @var array<string, string>
     */
    protected array $headers;
    
    /**
     * Query parameters
     * @var array<string, mixed>
     */
    protected array $query;
    
    /**
     * Path parameters
     * @var array<string, string>
     */
    protected array $params;
    
    /**
     * Request body
     * @var mixed
     */
    protected mixed $body;
    
    /**
     * Raw request data
     * @var array<string, mixed>
     */
    protected array $rawData;
    
    /**
     * Normalized headers cache (lowercase keys)
     * @var array<string, string>|null
     */
    protected ?array $normalizedHeaders = null;

    /**
     * Constructor
     * 
     * @param array<string, mixed> $requestData The parsed HTTP request data
     */
    public function __construct(array $requestData)
    {
        $this->method = isset($requestData['method']) ?
            (is_string($requestData['method']) ? $requestData['method'] : '') : '';

        $this->path = isset($requestData['path']) ?
            (is_string($requestData['path']) ? $requestData['path'] : '/') : '/';

        $this->protocol = isset($requestData['protocol']) ?
            (is_string($requestData['protocol']) ? $requestData['protocol'] : '') : '';

        $this->headers = [];
        if (isset($requestData['headers']) && is_array($requestData['headers'])) {
            foreach ($requestData['headers'] as $key => $value) {
                $headerKey = is_string($key) ? $key : '';
                $headerValue = is_string($value) ? $value : '';
                if (!empty($headerKey)) {
                    $this->headers[$headerKey] = $headerValue;
                }
            }
        }

        $this->query = [];
        if (isset($requestData['query']) && is_array($requestData['query'])) {
            foreach ($requestData['query'] as $key => $value) {
                $queryKey = is_string($key) ? $key : '';
                if (!empty($queryKey)) {
                    $this->query[$queryKey] = $value;
                }
            }
        }

        $this->params = [];
        if (isset($requestData['params']) && is_array($requestData['params'])) {
            foreach ($requestData['params'] as $key => $value) {
                $paramKey = is_string($key) ? $key : '';
                $paramValue = is_string($value) ? $value : '';
                if (!empty($paramKey)) {
                    $this->params[$paramKey] = $paramValue;
                }
            }
        }

        $this->rawData = $requestData;

        $body = $requestData['body'] ?? null;
        if (is_string($body) && $this->isJson()) {
            $decodedBody = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $this->body = $decodedBody;
                return;
            }
        } elseif (is_string($body) && $this->isFormData()) {
            $formData = [];
            parse_str($body, $formData);
            $this->body = $formData;
            return;
        }
        $this->body = $body;
    }

    /**
     * Check if the request has form data content type
     * 
     * @return bool True if request has form data content type
     */
    public function isFormData(): bool
    {
        $contentType = $this->getHeader('Content-Type');
        if (!is_string($contentType)) {
            return false;
        }
        return str_contains($contentType, 'application/x-www-form-urlencoded');
    }

    /**
     * Get HTTP method
     * 
     * @return string The HTTP method
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * Get request path
     * 
     * @return string The request path
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Get HTTP protocol
     * 
     * @return string The HTTP protocol version
     */
    public function getProtocol(): string
    {
        return $this->protocol;
    }

    /**
     * Get all headers
     * 
     * @return array<string, string> All request headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * Get a specific header value
     * 
     * @param string $name The header name (case-insensitive)
     * @param mixed $default Default value if header is not found
     * @return mixed The header value or default
     */
    public function getHeader(string $name, mixed $default = null): mixed
    {
        if ($this->normalizedHeaders === null) {
            $this->normalizedHeaders = [];
            foreach ($this->headers as $key => $value) {
                $this->normalizedHeaders[strtolower($key)] = $value;
            }
        }
        
        $name = strtolower($name);
        return $this->normalizedHeaders[$name] ?? $default;
    }

    /**
     * Get all query parameters
     * 
     * @return array<string, mixed> All query parameters
     */
    public function getQueryParams(): array
    {
        return $this->query;
    }

    /**
     * Get a specific query parameter
     * 
     * @param string $name The query parameter name
     * @param mixed $default Default value if parameter is not found
     * @return mixed The parameter value or default
     */
    public function getQuery(string $name, mixed $default = null): mixed
    {
        return $this->query[$name] ?? $default;
    }

    /**
     * Get all path parameters
     * 
     * @return array<string, string> All path parameters
     */
    public function getPathParams(): array
    {
        return $this->params;
    }

    /**
     * Get a specific path parameter
     * 
     * @param string $name The path parameter name
     * @param mixed $default Default value if parameter is not found
     * @return mixed The parameter value or default
     */
    public function getParam(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    /**
     * Get request body
     * 
     * @return mixed The request body
     */
    public function getBody(): mixed
    {
        return $this->body;
    }

    /**
     * Get request body as array (similar to Laravel's all() method)
     * 
     * @return array<mixed, mixed> The request body as an array
     */
    public function all(): array
    {
        if (is_array($this->body)) {
            return $this->body;
        }
        
        if (is_string($this->body) && !empty($this->body)) {
            $decoded = json_decode($this->body, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }
        
        return [];
    }

    /**
     * Check if the request has a JSON content type
     * 
     * @return bool True if request has JSON content type
     */
    public function isJson(): bool
    {
        $contentType = $this->getHeader('Content-Type');
        if (!is_string($contentType)) {
            return false;
        }
        return str_contains($contentType, 'application/json');
    }
    
    /**
     * Check if the request is an XHR/AJAX request
     * 
     * @return bool True if the request is an AJAX request
     */
    public function isAjax(): bool
    {
        return $this->getHeader('X-Requested-With') === 'XMLHttpRequest';
    }
    
    /**
     * Check if this is a specific HTTP method
     * 
     * @param string $method The HTTP method to check (case-insensitive)
     * @return bool True if the request method matches
     */
    public function isMethod(string $method): bool
    {
        return strtoupper($this->method) === strtoupper($method);
    }
    
    /**
     * Get the request URL
     * 
     * @param bool $includeQuery Whether to include the query string
     * @return string The full request URL
     */
    public function getUrl(bool $includeQuery = true): string
    {
        $host = $this->getHeader('Host');
        if (!is_string($host)) {
            $host = '';
        }
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
        $url = "{$protocol}://{$host}{$this->path}";
        
        if ($includeQuery && !empty($this->query)) {
            $url .= '?' . http_build_query($this->query);
        }
        
        return $url;
    }
    
    /**
     * Get client IP address
     * 
     * @param bool $fallbackToDefault Whether to return a default IP if none found
     * @return string|null The client IP address or null/default
     */
    public function getIpAddress(bool $fallbackToDefault = false): ?string
    {
        $ipHeaders = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_CLIENT_IP',            // Proxy
            'HTTP_X_FORWARDED_FOR',      // Load balancer/proxy
            'HTTP_X_FORWARDED',          // Proxy
            'HTTP_X_CLUSTER_CLIENT_IP',  // Cluster
            'HTTP_FORWARDED_FOR',        // Proxy
            'HTTP_FORWARDED',            // Proxy
            'REMOTE_ADDR'                // Standard
        ];

        foreach ($ipHeaders as $header) {
            $ip = $this->getHeader($header);
            if (is_string($ip) && $ip !== '' && $ip !== 'unknown') {
                if (str_contains($ip, ',')) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        foreach ($ipHeaders as $header) {
            if (isset($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                if (is_string($ip) && $ip !== '' && $ip !== 'unknown') {
                    if (str_contains($ip, ',')) {
                        $ip = trim(explode(',', $ip)[0]);
                    }
                    
                    if (filter_var($ip, FILTER_VALIDATE_IP)) {
                        return $ip;
                    }
                }
            }
        }
        
        return $fallbackToDefault ? '127.0.0.1' : null;
    }

    /**
     * Create from raw request array
     * 
     * @param array<string, mixed> $request The raw request array
     * @return self New Request instance
     */
    public static function fromArray(array $request): self
    {
        return new self($request);
    }

    /**
     * Convert to array
     * 
     * @return array<string, mixed> The request as an array
     */
    public function toArray(): array
    {
        return $this->rawData;
    }
    
    /**
     * Set custom data in the request
     * 
     * @param string $key The data key
     * @param mixed $value The data value
     * @return self For method chaining
     */
    public function setAttribute(string $key, mixed $value): self
    {
        $this->rawData[$key] = $value;
        return $this;
    }
    
    /**
     * Get custom data from the request
     * 
     * @param string $key The data key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed The data value or default
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->rawData[$key] ?? $default;
    }

    /**
     * Magic method to access request data as properties
     * Checks body data first, then query parameters
     * 
     * @param string $name The property name
     * @return mixed The property value or null
     */
    public function __get(string $name): mixed
    {
        $bodyData = $this->all();
        if (array_key_exists($name, $bodyData)) {
            return $bodyData[$name];
        }
        
        if (array_key_exists($name, $this->query)) {
            return $this->query[$name];
        }
        
        if (array_key_exists($name, $this->params)) {
            return $this->params[$name];
        }
        
        return null;
    }

    /**
     * Magic method to check if a property exists
     * 
     * @param string $name The property name
     * @return bool True if the property exists
     */
    public function __isset(string $name): bool
    {
        $bodyData = $this->all();
        return array_key_exists($name, $bodyData) || 
               array_key_exists($name, $this->query) || 
               array_key_exists($name, $this->params);
    }

    /**
     * Get a specific input value (body, query, or path parameter)
     * 
     * @param string $key The input key
     * @param mixed $default Default value if key doesn't exist
     * @return mixed The input value or default
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->__get($key) ?? $default;
    }

    /**
     * Check if a specific input exists
     * 
     * @param string $key The input key
     * @return bool True if the input exists
     */
    public function has(string $key): bool
    {
        return $this->__isset($key);
    }
}
