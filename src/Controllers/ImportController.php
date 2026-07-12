<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Csv\BankFormatRepository;
use App\Services\ImportService;
use App\Services\LogService;
use InvalidArgumentException;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

/**
 * The CSV import flow. Nothing is parsed or persisted until the user confirms
 * a column mapping, and nothing enters the ledger until they commit the review.
 *
 *   POST   /api/imports                 multipart: statement, account_id?  -> upload + preview
 *   GET    /api/imports/{id}/preview    re-open the mapping screen
 *   POST   /api/imports/{id}/validate   {mapping}                          -> dry-run report
 *   POST   /api/imports/{id}/stage      {mapping, account_id?, save_format?} -> review queue
 *   POST   /api/imports/{id}/remap      back to the mapping screen
 *   DELETE /api/imports/{id}            discard the upload and its file
 */
final class ImportController
{
    /**
     * Browsers are inconsistent about CSV: Chrome sends text/csv, Excel-installed
     * Windows sends application/vnd.ms-excel, and some send octet-stream. The
     * extension is the reliable signal, so the MIME list is advisory.
     */
    private const ALLOWED_EXT = ['csv', 'txt', 'tsv'];

    private const MAX_BYTES = 25 * 1024 * 1024;

    public function __construct(
        private PDO $pdo,
        private ImportService $imports,
        private BankFormatRepository $formats,
        private string $uploadDir,
        private LogService $log,
    ) {
    }

    public function create(Request $request, Response $response): Response
    {
        $files = $request->getUploadedFiles()['statement'] ?? null;
        if ($files instanceof UploadedFileInterface) {
            $files = [$files];
        }
        if (!is_array($files) || $files === []) {
            return $this->json($response, ['error' => 'no_file', 'message' => 'Attach a CSV as `statement`'], 400);
        }

        $body      = (array) $request->getParsedBody();
        $accountId = (int) ($body['account_id'] ?? 0) ?: null;

        $file = $files[0];
        $name = $file->getClientFilename() ?? 'statement.csv';

        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $this->json($response, ['error' => 'upload_failed', 'message' => 'Upload error ' . $file->getError()], 400);
        }
        if (($file->getSize() ?? 0) > self::MAX_BYTES) {
            return $this->json($response, ['error' => 'too_large', 'message' => 'Statements are capped at 25 MB'], 413);
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ALLOWED_EXT, true)) {
            return $this->json($response, [
                'error'   => 'unsupported_type',
                'message' => "Only CSV statements are supported (got .{$ext}). Export your statement as CSV.",
            ], 415);
        }

        // Hash before accepting so an identical re-upload is caught early.
        $tmp = tempnam(sys_get_temp_dir(), 'finup_');
        $file->moveTo($tmp);
        $sha256 = hash_file('sha256', $tmp);

        $dupe = $this->pdo->prepare('SELECT id, status, stored_path FROM uploads WHERE file_sha256 = ?');
        $dupe->execute([$sha256]);
        if (($existing = $dupe->fetch(PDO::FETCH_ASSOC)) !== false) {
            // Only an upload that produced data blocks a re-upload. A stale
            // 'mapping' or 'failed' attempt is discarded so the retry proceeds.
            if (in_array($existing['status'], ['committed', 'review'], true)) {
                @unlink($tmp);

                return $this->json($response, [
                    'error'   => 'duplicate_file',
                    'message' => "This exact file was already uploaded and is {$existing['status']} "
                               . "(upload #{$existing['id']}). Delete that one first to re-import it.",
                ], 409);
            }
            @unlink((string) $existing['stored_path']);
            $this->pdo->prepare('DELETE FROM uploads WHERE id = ?')->execute([$existing['id']]);
        }

        $safe   = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
        $stored = rtrim($this->uploadDir, '/') . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . $safe;
        if (!@rename($tmp, $stored)) {
            @unlink($tmp);

            return $this->json($response, ['error' => 'storage_failed', 'message' => 'Could not store the upload'], 500);
        }

        $this->pdo->prepare(
            "INSERT INTO uploads (account_id, original_name, stored_path, mime_type, file_sha256, status)
             VALUES (?,?,?,?,?, 'mapping')"
        )->execute([$accountId, $name, $stored, $file->getClientMediaType() ?? 'text/csv', $sha256]);

        $uploadId = (int) $this->pdo->lastInsertId();
        $this->log->info('upload', 'received', "Received '{$name}'", $uploadId);

        try {
            return $this->json($response, $this->imports->preview($uploadId));
        } catch (Throwable $e) {
            $this->pdo->prepare("UPDATE uploads SET status = 'failed', error_message = ? WHERE id = ?")
                ->execute([$e->getMessage(), $uploadId]);
            $this->log->error('csv_parse', 'error', $e->getMessage(), $uploadId);

            return $this->json($response, ['error' => 'unreadable', 'message' => $e->getMessage(), 'upload_id' => $uploadId], 422);
        }
    }

    public function preview(Request $request, Response $response, array $args): Response
    {
        try {
            return $this->json($response, $this->imports->preview((int) $args['id']));
        } catch (Throwable $e) {
            return $this->json($response, ['error' => 'preview_failed', 'message' => $e->getMessage()], 400);
        }
    }

    public function validate(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();

        try {
            $result = $this->imports->validate(
                (int) $args['id'],
                (array) ($body['mapping'] ?? []),
                (int) ($body['account_id'] ?? 0) ?: null
            );
        } catch (InvalidArgumentException $e) {
            return $this->json($response, ['error' => 'invalid_mapping', 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return $this->json($response, ['error' => 'validate_failed', 'message' => $e->getMessage()], 400);
        }

        return $this->json($response, $result);
    }

    public function stage(Request $request, Response $response, array $args): Response
    {
        $body = (array) $request->getParsedBody();

        $saveFormat = null;
        if (!empty($body['save_format']['name'])) {
            $saveFormat = ['name' => (string) $body['save_format']['name']];
        }

        $openingBalance = array_key_exists('opening_balance', $body) && $body['opening_balance'] !== null
            ? (int) $body['opening_balance']    // paise
            : null;

        try {
            $result = $this->imports->stage(
                (int) $args['id'],
                (array) ($body['mapping'] ?? []),
                (int) ($body['account_id'] ?? 0) ?: null,
                $saveFormat,
                $openingBalance
            );
        } catch (InvalidArgumentException $e) {
            return $this->json($response, ['error' => 'invalid_mapping', 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return $this->json($response, ['error' => 'stage_failed', 'message' => $e->getMessage()], 400);
        }

        return $this->json($response, $result);
    }

    /**
     * Return a staged (or failed) upload to the mapping screen, discarding the
     * rows it produced. Committed uploads are immutable — delete them instead.
     */
    public function remap(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $stmt = $this->pdo->prepare('SELECT status FROM uploads WHERE id = ?');
        $stmt->execute([$id]);
        $status = $stmt->fetchColumn();

        if ($status === false) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }
        if ($status === 'committed') {
            return $this->json($response, [
                'error'   => 'invalid_state',
                'message' => 'This upload is already in the ledger and cannot be remapped.',
            ], 409);
        }

        $this->pdo->prepare('DELETE FROM staged_transactions WHERE upload_id = ?')->execute([$id]);
        $this->pdo->prepare("UPDATE uploads SET status = 'mapping', error_message = NULL WHERE id = ?")->execute([$id]);

        try {
            return $this->json($response, $this->imports->preview($id));
        } catch (Throwable $e) {
            return $this->json($response, ['error' => 'preview_failed', 'message' => $e->getMessage()], 400);
        }
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        $id   = (int) $args['id'];
        $stmt = $this->pdo->prepare('SELECT status, stored_path FROM uploads WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return $this->json($response, ['error' => 'not_found'], 404);
        }
        if ($row['status'] === 'committed') {
            return $this->json($response, [
                'error'   => 'invalid_state',
                'message' => 'Committed uploads cannot be deleted here — remove their transactions from the Ledger.',
            ], 409);
        }

        @unlink((string) $row['stored_path']);
        $this->pdo->prepare('DELETE FROM uploads WHERE id = ?')->execute([$id]);   // staged rows cascade

        return $this->json($response, ['deleted' => true, 'id' => $id]);
    }

    private function json(Response $response, array $payload, int $status = 200): Response
    {
        $response->getBody()->write((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
