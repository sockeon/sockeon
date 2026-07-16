<?php

/**
 * HandlesWebSocketMessages trait
 *
 * Manages WebSocket message processing and routing
 *
 * @package     Sockeon\Sockeon
 * @author      Sockeon
 * @copyright   Copyright (c) 2025
 */

namespace Sockeon\Sockeon\Traits\WebSocket;

use Throwable;

trait HandlesWebSocketMessages
{
    /**
     * Handle an incoming WebSocket message
     *
     * @param string $clientId The client ID
     * @param string $payload The message payload
     * @return void
     */
    public function handleMessage(string $clientId, string $payload): void
    {
        try {
            // Validate payload is not empty
            if (empty($payload)) {
                $this->sendErrorMessage($clientId, 'Empty message payload');
                return;
            }

            // Validate payload length
            if (strlen($payload) > $this->server->getMaxMessageSize()) {
                $this->sendErrorMessage($clientId, 'Message payload too large');
                return;
            }

            $message = json_decode($payload, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $errorMsg = 'Invalid JSON format: ' . json_last_error_msg();
                $this->server->getLogger()->warning("Invalid JSON received from client: $clientId", [
                    'error' => json_last_error_msg(),
                    'payload' => substr($payload, 0, 100) . (strlen($payload) > 100 ? '...' : ''),
                    'client_id' => $clientId,
                ]);
                $this->sendErrorMessage($clientId, $errorMsg);
                return;
            }

            if (!is_array($message)) {
                $this->sendErrorMessage($clientId, 'Message must be a JSON object');
                return;
            }

            /** @var array<string, mixed> $typedMessage */
            $typedMessage = $message;
            $validationErrors = $this->validateMessageStructure($typedMessage);
            if (!empty($validationErrors)) {
                $errorMsg = 'Message validation failed: ' . implode(', ', array_values($validationErrors));
                $this->server->getLogger()->warning("Message validation failed for client: $clientId", [
                    'errors' => $validationErrors,
                    'payload' => substr($payload, 0, 100) . (strlen($payload) > 100 ? '...' : ''),
                    'client_id' => $clientId,
                ]);
                $this->sendErrorMessage($clientId, $errorMsg);
                return;
            }

            $event = $message['event'];
            $data = $message['data'] ?? [];

            if (!is_array($data)) {
                $this->sendErrorMessage($clientId, 'Data field must be an object/array');
                return;
            }

            $this->server->getLogger()->debug("Processing WebSocket message", [
                'client_id' => $clientId,
                'event' => $event,
                'data_size' => count($data),
                'data_keys' => array_keys($data),
            ]);

            $router = $this->server->getRouter();
            if (!$router->dispatch($clientId, $event, $data)) {
                $this->server->getLogger()->warning("Unknown WebSocket event received from client: $clientId", [
                    'event' => $event,
                    'client_id' => $clientId,
                ]);
                $this->sendErrorMessage($clientId, "Event '{$event}' does not exist");
            }
        } catch (Throwable $e) {
            $this->server->getLogger()->exception($e, [
                'clientId' => $clientId,
                'context' => 'WebSocketHandler::handleMessage',
                'payload_preview' => substr($payload, 0, 100),
            ]);

            $this->sendErrorMessage($clientId, 'Internal server error processing message');
        }
    }

    /**
     * Send an error message to a specific client
     *
     * @param string $clientId The client ID
     * @param string $errorMessage The error message
     * @return void
     */
    public function sendErrorMessage(string $clientId, string $errorMessage): void
    {
        try {
            $errorResponse = [
                'event' => 'error',
                'data' => [
                    'message' => $errorMessage,
                    'timestamp' => time(),
                ],
            ];

            $encodedMessage = json_encode($errorResponse);
            if ($encodedMessage === false) {
                $encodedMessage = '{"event":"error","data":{"message":"JSON encoding error","timestamp":' . time() . '}}';
            }

            if (!$this->server->isClientConnected($clientId)) {
                $this->server->getLogger()->warning("Client not found: $clientId");

                return;
            }

            $payload = $this->server->getEngine()->framesOutboundWebSocket()
                ? $this->encodeWebSocketFrame($encodedMessage)
                : $encodedMessage;

            if (!$this->server->getEngine()->send($clientId, $payload)) {
                $this->server->getLogger()->warning("Failed to send error message to client: $clientId", [
                    'error_message' => $errorMessage,
                ]);
            } else {
                $this->server->getLogger()->debug("Sent error message to client: $clientId", [
                    'error_message' => $errorMessage,
                ]);
            }
        } catch (Throwable $e) {
            $this->server->getLogger()->exception($e, [
                'clientId' => $clientId,
                'context' => 'sendErrorMessage',
                'error_message' => $errorMessage,
            ]);
        }
    }

    /**
     * Validate the structure of a WebSocket message
     *
     * @param array<string, mixed> $message The message to validate
     * @return array<string, string> Array of validation errors
     */
    protected function validateMessageStructure(array $message): array
    {
        /** @var array<string, string> $errors */
        $errors = [];

        // Validate event field
        if (!isset($message['event'])) {
            $errors['event'] = 'Missing required field: event';
        } elseif (!is_string($message['event'])) {
            $errors['event'] = 'Event field must be a string';
        } elseif (empty($message['event'])) {
            $errors['event'] = 'Event field cannot be empty';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $message['event'])) {
            $errors['event'] = 'Invalid event name format (only alphanumeric, dots, underscores, hyphens allowed)';
        }

        // Validate data field
        if (!isset($message['data'])) {
            $errors['data'] = 'Missing required field: data';
        } elseif (!is_array($message['data'])) {
            $errors['data'] = 'Data field must be an object/array';
        }

        // Validate no extra fields (optional - can be removed if you want to allow extra fields)
        $allowedFields = ['event', 'data'];
        $extraFields = array_diff(array_keys($message), $allowedFields);
        if (!empty($extraFields)) {
            $errors['extra_fields'] = 'Message contains unsupported fields: ' . implode(', ', $extraFields);
        }

        return $errors;
    }

    /**
     * Create a properly formatted WebSocket message
     *
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @return array<string, mixed> The formatted message
     */
    protected function createMessage(string $event, array $data = []): array
    {
        return [
            'event' => $event,
            'data' => $data,
        ];
    }

    /**
     * Send a message to a specific client
     *
     * @param string $clientId The client ID
     * @param string $event The event name
     * @param array<string, mixed> $data The data to send
     * @return bool True if message was sent successfully
     */
    public function sendMessage(string $clientId, string $event, array $data = []): bool
    {
        try {
            $message = $this->createMessage($event, $data);
            $encodedMessage = json_encode($message);

            if ($encodedMessage === false) {
                $this->server->getLogger()->error("Failed to encode message for client: $clientId", [
                    'event' => $event,
                    'data' => $data,
                ]);
                return false;
            }

            if (!$this->server->isClientConnected($clientId)) {
                $this->server->getLogger()->warning("Client not found: $clientId");
                return false;
            }

            $payload = $this->server->getEngine()->framesOutboundWebSocket()
                ? $this->encodeWebSocketFrame($encodedMessage)
                : $encodedMessage;

            if (!$this->server->getEngine()->send($clientId, $payload)) {
                $this->server->getLogger()->warning("Failed to send message to client: $clientId", [
                    'event' => $event,
                ]);
                return false;
            }

            $this->server->getLogger()->debug("Sent message to client: $clientId", [
                'event' => $event,
                'data_size' => count($data),
            ]);
            return true;
        } catch (Throwable $e) {
            $this->server->getLogger()->exception($e, [
                'clientId' => $clientId,
                'context' => 'sendMessage',
                'event' => $event,
            ]);
            return false;
        }
    }
}
