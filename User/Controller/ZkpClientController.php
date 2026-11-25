<?php
declare(strict_types=1);

namespace AstrX\User\Controller;

use AstrX\Kernel\AbstractController;
use AstrX\Result\DiagnosticsCollector;
use AstrX\Result\Result;

/**
 * The offline key-login client, as a download: the login, sign-up and
 * settings pages link here, so a visitor can fetch zkp_client.py from the site
 * they are signing in to instead of looking for a checkout.
 *
 * The file is the same for everyone and holds no secret. It is served as an
 * attachment with nosniff, so a browser saves it rather than running or
 * rendering it.
 */
final class ZkpClientController extends AbstractController
{
    public const string FILE = __DIR__ . '/../../resources/zkp/zkp_client.py';

    public function __construct(DiagnosticsCollector $collector)
    {
        parent::__construct($collector);
    }

    /** @return Result<mixed> */
    public function handle(): Result
    {
        $body = is_file(self::FILE) ? file_get_contents(self::FILE) : false;
        if ($body === false) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: text/x-python; charset=utf-8');
        header('Content-Disposition: attachment; filename="zkp_client.py"');
        header('Content-Length: ' . strlen($body));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=3600');
        echo $body;
        exit;
    }
}
