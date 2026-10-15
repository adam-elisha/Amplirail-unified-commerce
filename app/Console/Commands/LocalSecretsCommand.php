<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Connectors\Secrets\EnvSecretsResolver;
use Illuminate\Console\Command;

/**
 * LOCAL DEVELOPMENT ONLY. Generates random platform secrets into .env (field encryption, fingerprint and link-signing
 * keys). Refuses to run in production, where secrets live in AWS Secrets Manager.
 */
final class LocalSecretsCommand extends Command
{
    protected $signature = 'amplirail:secrets:local';

    protected $description = 'Generate local-only platform secrets into .env';

    public function handle(): int
    {
        if (app()->environment('production') || config('amplirail.secrets.driver') === 'aws') {
            $this->error('Refused: production and aws-driver environments read secrets from AWS Secrets Manager.');

            return self::FAILURE;
        }
        $path = base_path('.env');
        $env = is_file($path) ? (string) file_get_contents($path) : '';
        $added = [];
        foreach ((array) config('amplirail.secrets.refs') as $ref) {
            $var = EnvSecretsResolver::variableFor((string) $ref);
            if (preg_match('/^'.$var.'=.+$/m', $env)) {
                continue;
            }
            $env = (string) preg_replace('/^'.$var.'=.*\n?/m', '', $env);
            $env = rtrim($env)."\n".$var.'='.base64_encode(random_bytes(32))."\n";
            $added[] = $var;
        }
        file_put_contents($path, $env);
        $this->info($added === [] ? 'Local secrets already present.' : 'Added: '.implode(', ', $added));

        return self::SUCCESS;
    }
}
