<?php
/**
 * Response class
 * 
 * Handles HTTP response generation with status codes, headers, and body
 * 
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Http;

class Response
{
    /**
     * HTTP status code
     * @var int
     */
    protected int $statusCode = 200;
    
    /**
     * Response headers
     * @var array<string, string>
     */
    protected array $headers = [];
    
    /**
     * Response body
     * @var mixed
     */
    protected mixed $body;
    
    /**
     * Content type
     * @var string
     */
    protected string $contentType = 'text/html';
    
    /**
     * HTTP status texts
     * @var array<int, string>
     */
    protected static array $statusTexts = [
        200 => 'OK',
        201 => 'Created',
        204 => 'No Content',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        500 => 'Internal Server Error'
    ];

    /**
     * Constructor
     * 
     * @param mixed $body The response body
     * @param int $statusCode The HTTP status code
     * @param array<string, string> $headers Additional response headers
     */
    public function __construct(mixed $body = null, int $statusCode = 200, array $headers = [])
    {
        $this->body = $body;
        $this->statusCode = $statusCode;
        $this->headers = $headers;
        
        if (is_array($body) || is_object($body)) {
            $this->setContentType('application/json');
        }
    }
    
    /**
     * Set response body
     * 
     * @param mixed $body The response body
     * @return self
     */
    public function setBody(mixed $body): self
    {
        $this->body = $body;
        return $this;
    }
    
    /**
     * Get response body
     * 
     * @return mixed The response body
     */
    public function getBody(): mixed
    {
        return $this->body;
    }
    
    /**
     * Set HTTP status code
     * 
     * @param int $code The HTTP status code
     * @return self
     */
    public function setStatusCode(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }
    
    /**
     * Get HTTP status code
     * 
     * @return int The HTTP status code
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
    
    /**
     * Set content type
     * 
     * @param string $contentType The content type
     * @return self
     */
    public function setContentType(string $contentType): self
    {
        $this->contentType = $contentType;
        $this->headers['Content-Type'] = $contentType;
        return $this;
    }
    
    /**
     * Get content type
     * 
     * @return string The content type
     */
    public function getContentType(): string
    {
        return $this->contentType;
    }
    
    /**
     * Set response header
     * 
     * @param string $name The header name
     * @param string $value The header value
     * @return self
     */
    public function setHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }
    
    /**
     * Get response header
     * 
     * @param string $name The header name
     * @return string|null The header value
     */
    public function getHeader(string $name): ?string
    {
        return $this->headers[$name] ?? null;
    }
    
    /**
     * Get all response headers
     * 
     * @return array<string, string> The headers
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
    
    /**
     * Get the formatted HTTP response string
     * 
     * @return string The HTTP response
     */
    public function toString(): string
    {
        $body = $this->getBodyString();
        
        $headers = [
            "HTTP/1.1 {$this->statusCode} " . $this->getStatusText($this->statusCode),
            "Content-Type: {$this->contentType}",
            "Connection: close",
            "Content-Length: " . strlen($body),
            "X-Powered-By: Sockeon"
        ];
        
        $securityHeaders = [
            'X-Content-Type-Options' => 'nosniff',
            'X-XSS-Protection' => '1; mode=block',
            'X-Frame-Options' => 'SAMEORIGIN'
        ];
        
        foreach ($securityHeaders as $name => $value) {
            if (!isset($this->headers[$name])) {
                $this->headers[$name] = $value;
            }
        }
        
        foreach ($this->headers as $name => $value) {
            $headers[] = "{$name}: {$value}";
        }
        
        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    /**
     * Get the string representation of the body
     * 
     * @return string The body as a string
     */
    protected function getBodyString(): string
    {
        if (is_array($this->body) || is_object($this->body)) {
            $json = json_encode($this->body);
            if ($json === false) {
                return '{}';
            }
            return $json;
        }
        
        if ($this->body === null) {
            return '';
        }

        if (is_string($this->body)) {
            return $this->body;
        }

        if (is_numeric($this->body) || is_bool($this->body)) {
            return (string) $this->body;
        }

        return '';
    }

    /**
     * Get HTTP status text from status code
     * 
     * @param int $code The HTTP status code
     * @return string The corresponding status text
     */
    protected function getStatusText(int $code): string
    {
        return self::$statusTexts[$code] ?? 'Unknown';
    }

    /**
     * Create a JSON response
     * 
     * @param mixed $data The data to return
     * @param int $statusCode The HTTP status code
     * @param array<string, string> $headers Additional headers
     * @return self
     */
    public static function json(mixed $data, int $statusCode = 200, array $headers = []): self
    {
        $headers['Content-Type'] = 'application/json';
        return new self($data, $statusCode, $headers);
    }

    /**
     * Create a success response (200 OK)
     * 
     * @param mixed $data The data to return
     * @param array<string, string> $headers Additional headers
     * @return self
     */
    public static function ok(mixed $data = null, array $headers = []): self
    {
        return new self($data, 200, $headers);
    }

    /**
     * Create a 201 Created response
     * 
     * @param mixed $data The data to return
     * @param array<string, string> $headers Additional headers
     * @return self
     */
    public static function created(mixed $data = null, array $headers = []): self
    {
        return new self($data, 201, $headers);
    }

    /**
     * Create a 404 Not Found response
     * 
     * @param mixed $data The error message or data
     * @param array<string, string> $headers Additional headers
     * @return self
     */
    public static function notFound(mixed $data = 'Not Found', array $headers = []): self
    {
        if (is_string($data)) {
            $data = ['error' => $data];
        }
        return self::json($data, 404, $headers);
    }

    /**
     * Create a 400 Bad Request response
     * 
     * @param mixed $data The error message or data
     * @param array<string, string> $headers Additional headers
     * @return self
     */
    public static function badRequest(mixed $data = 'Bad Request', array $headers = []): self
    {
        if (is_string($data)) {
            $data = ['error' => $data];
        }
        return self::json($data, 400, $headers);
    }

    /**
     * Create a 500 Internal Server Error response
     * 
     * @param mixed $data The error message or data
     * @param array<string, string> $headers Additional headers
     * @return self
     */
    public static function serverError(mixed $data = 'Internal Server Error', array $headers = []): self
    {
        if (is_string($data)) {
            $data = ['error' => $data];
        }
        return self::json($data, 500, $headers);
    }
    
    /**
     * Create a 401 Unauthorized response
     * 
     * @param mixed $data The error message or data
     * @param array<string, string> $headers Additional headers
     * @return self
     */
    public static function unauthorized(mixed $data = 'Unauthorized', array $headers = []): self
    {
        if (is_string($data)) {
            $data = ['error' => $data];
        }
        return self::json($data, 401, $headers);
    }
    
    /**
     * Create a 403 Forbidden response
     * 
     * @param mixed $data The error message or data
     * @param array<string, string> $headers Additional headers
     * @return self
     */
    public static function forbidden(mixed $data = 'Forbidden', array $headers = []): self
    {
        if (is_string($data)) {
            $data = ['error' => $data];
        }
        return self::json($data, 403, $headers);
    }
    
    /**
     * Create a 204 No Content response
     * 
     * @param array<string, string> $headers Additional headers
     * @return self
     */
    public static function noContent(array $headers = []): self
    {
        return new self(null, 204, $headers);
    }
    
    /**
     * Create a 429 Too Many Requests response
     * 
     * @param mixed $data The error message or data
     * @param array<string, string> $headers Additional headers
     * @return self
     */
    public static function tooManyRequests(mixed $data = 'Too Many Requests', array $headers = []): self
    {
        if (is_string($data)) {
            $data = ['error' => $data];
        }
        return self::json($data, 429, $headers);
    }
    
    /**
     * Create a redirect response
     * 
     * @param string $url The URL to redirect to
     * @param int $status The HTTP status code (301, 302, 303, 307, 308)
     * @param array<string, string> $headers Additional headers
     * @return self
     */
    public static function redirect(string $url, int $status = 302, array $headers = []): self
    {
        $headers['Location'] = $url;
        return new self(null, $status, $headers);
    }
    
    /**
     * Create a file download response
     * 
     * @param string $content The file content
     * @param string $filename The suggested filename for the download
     * @param string $contentType The content type
     * @param array<string, string> $headers Additional headers
     * @return self
     */
    public static function download(string $content, string $filename, string $contentType = 'application/octet-stream', array $headers = []): self
    {
        $headers['Content-Disposition'] = 'attachment; filename="' . $filename . '"';
        
        return (new self($content, 200, $headers))
            ->setContentType($contentType);
    }
}
