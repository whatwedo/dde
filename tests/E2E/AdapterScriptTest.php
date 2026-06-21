<?php

declare(strict_types=1);

namespace Tests\E2E;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises the built-in entrypoint adapters against a filesystem layout that
 * mirrors the whatwedo symfony base image (Debian, runit, php-fpm8.4 binary,
 * nginx `user` directive in /etc/nginx/directive.d/*.conf).
 *
 * Runs inside a throwaway Debian container so the adapters execute on GNU sed
 * exactly like in production, and so `id -gn` resolves a real group. Fixtures
 * are built inside the container (the host temp dir is not Docker-shareable on
 * macOS); only the repo's adapter dir is mounted. Reproduces the gid-reuse case
 * (DDE_GID maps onto an existing differently-named group), which is why adapters
 * must resolve the group name instead of hardcoding `dde`.
 */
#[Group('e2e')]
final class AdapterScriptTest extends TestCase
{
    public function testAdaptersRewriteServicesToResolvedDdeUserAndGroup(): void
    {
        $adaptersDir = \dirname(__DIR__, 2).'/resources/adapters';

        $script = <<<'SH'
            set -e
            # Reproduce DDE_GID=20: the dde user joins the pre-existing gid-20
            # group (named `dialout` on Debian), exactly as the dde entrypoint
            # does in the real container. `id -gn dde` must then yield `dialout`,
            # never the literal `dde`, or php-fpm would fail on an unknown group.
            echo "dde:x:501:20:dde:/home/dde:/bin/sh" >> /etc/passwd

            # nginx adapter detects via the binary; stub it (slim has no nginx).
            printf '#!/bin/sh\n' > /usr/local/bin/nginx && chmod +x /usr/local/bin/nginx

            mkdir -p /fix/etc/nginx/directive.d /fix/etc/php/8.4/fpm/pool.d
            printf 'include /etc/nginx/directive.d/*.conf;\nevents {}\n' > /fix/etc/nginx/nginx.conf
            printf 'user app app;\n' > /fix/etc/nginx/directive.d/05-user.conf
            printf '[www]\nuser = app\ngroup = app\nlisten = /tmp/php-fpm.sock\nlisten.owner = app\nlisten.group = app\n' > /fix/etc/php/8.4/fpm/pool.d/www.conf

            export DDE_NGINX_CONF_ROOT=/fix/etc/nginx
            export DDE_PHP_FPM_POOL_ROOT=/fix/etc/php
            for adapter in /adapters/nginx.sh /adapters/php-fpm.sh; do
                . "$adapter"
                if type detect >/dev/null 2>&1 && detect; then
                    configure
                fi
                unset -f detect configure 2>/dev/null || true
            done

            echo "===NGINX==="
            cat /fix/etc/nginx/directive.d/05-user.conf
            echo "===FPM==="
            cat /fix/etc/php/8.4/fpm/pool.d/www.conf
            SH;

        $process = new Process([
            'docker', 'run', '--rm',
            '-v', $adaptersDir.':/adapters:ro',
            'debian:stable-slim',
            'sh', '-c', $script,
        ]);
        $process->setTimeout(120);
        $process->run();

        $this->assertTrue(
            $process->isSuccessful(),
            sprintf("adapter run failed:\nSTDOUT: %s\nSTDERR: %s", $process->getOutput(), $process->getErrorOutput()),
        );

        $output = $process->getOutput();
        [$nginx, $fpm] = explode('===FPM===', explode('===NGINX===', $output, 2)[1], 2);

        $this->assertStringContainsString('user dde dialout;', $nginx, 'nginx worker user must become the dde user with its resolved group');
        $this->assertStringNotContainsString('user app', $nginx);

        $this->assertStringContainsString('user = dde', $fpm);
        $this->assertStringContainsString('group = dialout', $fpm);
        $this->assertStringContainsString('listen.owner = dde', $fpm);
        $this->assertStringContainsString('listen.group = dialout', $fpm);
        $this->assertStringNotContainsString('= app', $fpm);
    }
}
