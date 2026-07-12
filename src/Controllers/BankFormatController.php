<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Csv\BankFormatRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 *   GET    /api/bank-formats        saved CSV layouts
 *   PATCH  /api/bank-formats/{id}   rename / re-assign the default account
 *   DELETE /api/bank-formats/{id}   forget a layout (next upload re-maps it)
 */
final class BankFormatController
{
    public function __construct(private BankFormatRepository $formats)
    {
    }

    public function list(Request $request, Response $response): Response
    {
        return $this->json($response, ['formats' => $this->formats->all()]);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->formats->find($id) === null) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }

        $body   = (array) $request->getParsedBody();
        $fields = [];
        if (isset($body['name']) && trim((string) $body['name']) !== '') {
            $fields['name'] = trim((string) $body['name']);
        }
        if (array_key_exists('account_id', $body)) {
            $fields['account_id'] = (int) $body['account_id'] ?: null;
        }
        if ($fields === []) {
            return $this->json($response, ['error' => 'validation', 'message' => 'Nothing to update'], 400);
        }

        $this->formats->update($id, $fields);

        return $this->json($response, ['updated' => true, 'format' => $this->formats->find($id)]);
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $this->formats->delete((int) $args['id']);

        return $this->json($response, ['deleted' => true]);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
