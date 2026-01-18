<?php

/**
 * HandlesWebSocketFrames trait
 *
 * Manages WebSocket frame encoding and decoding for server
 *
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Traits\WebSocket;

use Throwable;

trait HandlesWebSocketFrames
{
    /**
     * Decode WebSocket frames from raw binary data
     *
     * @param string $data The raw WebSocket frame data
     * @return array{0: array<int, array<string, mixed>>, 1: string} An array containing [frames, remaining_data]
     */
    public function decodeWebSocketFrame(string $data): array
    {
        $frames = [];
        $originalDataLength = strlen($data);
        $dataLength = $originalDataLength; // Cache length to avoid repeated strlen() calls
        $dataOffset = 0; // Use offset instead of substr() for better performance

        // Maximum payload size
        $maxPayloadSize = $this->server->getMaxMessageSize();

        while ($dataOffset < $dataLength) {
            $remainingBytes = $dataLength - $dataOffset;

            // Make sure we have at least 2 bytes for the basic header
            if ($remainingBytes < 2) {
                // Don't log as error - this is expected when waiting for more data
                $this->logFrameDebug("Incomplete frame header (will buffer)", [
                    'remaining_bytes' => $remainingBytes,
                    'required_bytes' => 2,
                ]);
                break;
            }

            $firstByte = ord($data[$dataOffset]);
            $secondByte = ord($data[$dataOffset + 1]);

            $fin = ($firstByte & 0x80) == 0x80;
            $opcode = $firstByte & 0x0F;
            $masked = ($secondByte & 0x80) == 0x80;
            $payloadLength = $secondByte & 0x7F;

            // Validate opcode
            if (!$this->isValidOpcode($opcode)) {
                $this->logFrameError("Invalid opcode", ['opcode' => $opcode]);
                break;
            }

            $offset = $dataOffset + 2;
            $extendedPayloadLength = 0;

            // Extended payload length - 16 bits
            if ($payloadLength == 126) {
                // Make sure we have enough bytes for extended 16-bit length
                if ($remainingBytes < 4) {
                    $this->logFrameDebug("Incomplete extended 16-bit length (will buffer)", [
                        'remaining_bytes' => $remainingBytes,
                        'required_bytes' => 4,
                    ]);
                    break;
                }
                $unpacked = unpack('n', substr($data, $offset, 2));
                if (!$unpacked || !isset($unpacked[1])) {
                    $this->logFrameError("Failed to unpack 16-bit length");
                    break;
                }
                $extendedPayloadLength = $unpacked[1];
                $payloadLength = $extendedPayloadLength;
                $offset += 2;
            }
            // Extended payload length - 64 bits
            elseif ($payloadLength == 127) {
                // Make sure we have enough bytes for extended 64-bit length
                if ($remainingBytes < 10) {
                    $this->logFrameDebug("Incomplete extended 64-bit length (will buffer)", [
                        'remaining_bytes' => $remainingBytes,
                        'required_bytes' => 10,
                    ]);
                    break;
                }
                $unpacked = unpack('J', substr($data, $offset, 8));
                if (!$unpacked || !isset($unpacked[1])) {
                    $this->logFrameError("Failed to unpack 64-bit length");
                    break;
                }
                $extendedPayloadLength = $unpacked[1];
                $payloadLength = $extendedPayloadLength;
                $offset += 8;
            }

            // Validate payload length
            if ($payloadLength > $maxPayloadSize) {
                $this->logFrameError("Payload too large", [
                    'payload_length' => $payloadLength,
                    'max_allowed' => $maxPayloadSize,
                ]);
                break;
            }

            // Check if we have enough data for the entire frame
            $frameLength = $offset - $dataOffset;
            if ($masked) {
                $frameLength += 4;  // Add mask key length
            }
            $frameLength += $payloadLength; // @phpstan-ignore-line

            if ($remainingBytes < $frameLength) {
                // Don't log as error - this is expected when frames are fragmented
                // The remaining data will be buffered and processed on next read
                $this->logFrameDebug("Incomplete frame detected (will buffer)", [
                    'remaining_bytes' => $remainingBytes,
                    'required_bytes' => $frameLength,
                    'payload_length' => $payloadLength,
                ]);
                break;
            }

            // Process the frame
            try {
                if ($masked) {
                    $maskKey = substr($data, $offset, 4);
                    $offset += 4;

                    $payloadStart = $offset;
                    $payload = substr($data, $payloadStart, $payloadLength); // @phpstan-ignore-line

                    // Optimize unmasking for large payloads using pre-allocated string
                    // For large payloads, use str_repeat + bitwise operations is faster
                    if ($payloadLength > 1024) {
                        // For large payloads, process in chunks to reduce memory allocations
                        $unmaskedPayload = '';
                        $chunkSize = 4096;
                        $maskBytes = [
                            ord($maskKey[0]),
                            ord($maskKey[1]),
                            ord($maskKey[2]),
                            ord($maskKey[3]),
                        ];

                        for ($i = 0; $i < $payloadLength; $i += $chunkSize) {
                            $chunk = substr($payload, $i, min($chunkSize, $payloadLength - $i));
                            $chunkLength = strlen($chunk);
                            $unmaskedChunk = '';

                            // Pre-allocate string for chunk
                            for ($j = 0; $j < $chunkLength; $j++) {
                                $unmaskedChunk .= chr(ord($chunk[$j]) ^ $maskBytes[($i + $j) % 4]);
                            }

                            $unmaskedPayload .= $unmaskedChunk;
                        }
                    } else {
                        // For small payloads, use simple loop
                        $unmaskedPayload = '';
                        $maskBytes = [
                            ord($maskKey[0]),
                            ord($maskKey[1]),
                            ord($maskKey[2]),
                            ord($maskKey[3]),
                        ];
                        $length = strlen($payload);
                        for ($i = 0; $i < $length; $i++) {
                            $unmaskedPayload .= chr(ord($payload[$i]) ^ $maskBytes[$i % 4]);
                        }
                    }

                    $frames[] = [
                        'fin' => $fin,
                        'opcode' => $opcode,
                        'masked' => $masked,
                        'payload' => $unmaskedPayload,
                        'payload_length' => $payloadLength,
                    ];
                } else {
                    $payload = substr($data, $offset, $payloadLength);  // @phpstan-ignore-line
                    $frames[] = [
                        'fin' => $fin,
                        'opcode' => $opcode,
                        'masked' => $masked,
                        'payload' => $payload,
                        'payload_length' => $payloadLength,
                    ];
                }

                // Log successful frame decoding
                $this->logFrameDebug("Successfully decoded frame", [
                    'opcode' => $opcode,
                    'fin' => $fin,
                    'masked' => $masked,
                    'payload_length' => $payloadLength,
                ]);

            } catch (Throwable $e) {
                $this->logFrameError("Error processing frame", [
                    'error' => $e->getMessage(),
                    'opcode' => $opcode,
                    'payload_length' => $payloadLength,
                ]);
                break;
            }

            // Move to the next frame
            $dataOffset += $frameLength;
        }

        // Extract remaining data if any
        $remainingData = ($dataOffset < $dataLength) ? substr($data, $dataOffset) : '';

        // Log summary
        if (!empty($frames)) {
            $this->logFrameDebug("Frame decoding summary", [
                'frames_decoded' => count($frames),
                'original_data_length' => $originalDataLength,
                'remaining_bytes' => strlen($remainingData),
            ]);
        }

        return [$frames, $remainingData];
    }

    /**
     * Encode a message into a WebSocket frame
     *
     * @param string $payload The payload to encode
     * @param int $opcode The WebSocket opcode (1=text, 8=close, 9=ping, 10=pong)
     * @return string The encoded WebSocket frame
     */
    public function encodeWebSocketFrame(string $payload, int $opcode = 1): string
    {
        $payloadLength = strlen($payload);

        // Validate payload length
        $maxPayloadSize = $this->server->getMaxMessageSize();
        if ($payloadLength > $maxPayloadSize) {
            $this->logFrameError("Payload too large for encoding", [
                'payload_length' => $payloadLength,
                'max_allowed' => $maxPayloadSize,
            ]);
            throw new \InvalidArgumentException("Payload too large: $payloadLength bytes");
        }

        $header = '';

        // Set FIN bit and opcode
        $header .= chr(0x80 | $opcode);

        // Set payload length
        if ($payloadLength <= 125) {
            $header .= chr($payloadLength);
        } elseif ($payloadLength <= 65535) {
            $header .= chr(126) . pack('n', $payloadLength);
        } else {
            $header .= chr(127) . pack('J', $payloadLength);
        }

        $frame = $header . $payload;

        $this->logFrameDebug("Encoded WebSocket frame", [
            'opcode' => $opcode,
            'payload_length' => $payloadLength,
            'frame_length' => strlen($frame),
        ]);

        return $frame;
    }

    /**
     * Send a pong frame in response to a ping
     *
     * @param resource $client The client socket resource
     * @param string $payload The pong payload (usually empty)
     * @return void
     */
    public function sendPong($client, string $payload = ''): void
    {
        try {
            $pongFrame = $this->encodeWebSocketFrame($payload, 10);
            $bytesWritten = fwrite($client, $pongFrame);

            if ($bytesWritten === false || $bytesWritten < strlen($pongFrame)) {
                $this->logFrameError("Failed to send pong frame", [
                    'bytes_written' => $bytesWritten,
                    'frame_length' => strlen($pongFrame),
                ]);
            } else {
                $this->logFrameDebug("Sent pong frame", [
                    'payload_length' => strlen($payload),
                    'bytes_written' => $bytesWritten,
                ]);
            }
        } catch (Throwable $e) {
            $this->logFrameError("Error sending pong frame", ['error' => $e->getMessage()]);
        }
    }

    /**
     * Prepare a message for sending over WebSocket
     *
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @return string The encoded WebSocket message
     */
    public function prepareMessage(string $event, array $data): string
    {
        $message = json_encode([
            'event' => $event,
            'data' => $data,
        ]);

        if ($message === false) {
            $message = json_encode([
                'event' => 'error',
                'data' => ['message' => 'Failed to encode message'],
            ]);
            if ($message === false) {
                $message = '{"event":"error","data":{"message":"JSON encoding error"}}';
            }
        }

        return $this->encodeWebSocketFrame($message);
    }

    /**
     * Check if an opcode is valid
     *
     * @param int $opcode The opcode to validate
     * @return bool True if the opcode is valid
     */
    protected function isValidOpcode(int $opcode): bool
    {
        return in_array($opcode, [0, 1, 2, 8, 9, 10], true);
    }

    /**
     * Log frame debugging information
     *
     * @param string $message The log message
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    protected function logFrameDebug(string $message, array $context = []): void
    {
        if (isset($this->server)) {
            $this->server->getLogger()->debug("[WebSocket Frame] $message", $context);
        }
    }

    /**
     * Log frame error information
     *
     * @param string $message The error message
     * @param array<string, mixed> $context Additional context
     * @return void
     */
    protected function logFrameError(string $message, array $context = []): void
    {
        if (isset($this->server)) {
            $this->server->getLogger()->warning("[WebSocket Frame Error] $message", $context);
        }
    }
}
