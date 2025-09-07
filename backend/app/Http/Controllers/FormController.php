<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Response;
use Throwable;

class FormController extends Controller
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;

    public function __construct()
    {
        $this->apiKey = env('OPENAI_API_KEY');
        $this->model = env('OPENAI_MODEL');
        $this->baseUrl = env('OPENAI_BASE_URL');
    }

    /**
     * Handles the chat request and calls the OpenAI API.
     */
    public function index(Request $request): JsonResponse
    {
        if (!$this->apiKey || !$this->model || !$this->baseUrl) {
            return response()->json([
                'error' => 'Missing required environment variables. Please set OPENAI_API_KEY, OPENAI_MODEL, and OPENAI_BASE_URL.'
            ], 500);
        }

        try {
            //Ejemplo
            $response = $this->callOpenAI($request->input('message', 'Quiero un formulario para la controlar los invitados de la inaguración de mi restaurante'));

            if ($response->failed()) {
                return $this->handleUpstreamError($response);
            }

            $message = $this->extractMessage($response->json());

            if ($message === null) {
                return $this->handleUnexpectedResponse($response->json());
            }

            return response()->json([
                'message' => $message,
            ]);

        } catch (Throwable $e) {
            return $this->handleException($e);
        }
    }

    /**
     * Calls the OpenAI API with a given message.
     */
    private function callOpenAI(string $message): Response
    {
        return Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Content-Type' => 'application/json',
        ])->post($this->baseUrl . '/v1/chat/completions', [
            'model' => $this->model,
            'response_format' => [ "type" => "json_object" ],
            'messages' => [
                [
                    'role' => 'system',
                    'content' => <<<EOT
    Eres un generador de formularios en JSON.  
    El usuario describe qué campos quiere y tú debes devolver únicamente un JSON válido con esta estructura:
    
    {
      "formTitle": "Texto",
      "themeColor": "#HEXCOLOR",
      "font": "Nombre de fuente",
      "fields": [
        {
          "type": "text | email | date | select | password | checkbox | radio | number | textarea",
          "label": "Texto visible en el formulario",
          "name": "nombreInternoCampo",
          "required": true | false,
          "options": ["Opción1", "Opción2"] // solo si es select o radio
        }
      ]
    }
    
    Reglas:
    - No devuelvas explicación ni texto adicional, solo el JSON.
    - Incluye "formTitle", "themeColor" y "font" siempre.
    - `fields` debe ser un array con los campos pedidos por el usuario.
    EOT
                ],
                [
                    'role' => 'user',
                    'content' => $message,
                ],
            ],
        ]);
    }

    /**
     * Handles the case where the upstream API request fails.
     */
    private function handleUpstreamError(Response $response): JsonResponse
    {
        return response()->json([
            'error' => 'Upstream request failed',
            'status' => $response->status(),
            'body' => $response->json() ?? $response->body(),
        ], 502);
    }

    /**
     * Extracts the message from the API response.
     */
    private function extractMessage(array $data): mixed
    {
        $content = $data['choices'][0]['message']['content'] ?? null;
    
        // Si viene como JSON en string, intenta parsearlo
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
    
        return $content;
    }
    
    /**
     * Handles the case of an unexpected response format.
     */
    private function handleUnexpectedResponse(array $data): JsonResponse
    {
        return response()->json([
            'error' => 'Unexpected response format from model provider',
            'example_expected' => [
                'choices' => [
                    ['message' => ['content' => '...']]
                ]
            ],
            'received' => $data,
        ], 500);
    }

    /**
     * Handles general exceptions.
     */
    private function handleException(Throwable $e): JsonResponse
    {
        return response()->json([
            'error' => 'Exception while calling the model provider',
            'message' => $e->getMessage(),
        ], 500);
    }
}